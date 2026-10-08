<?php

declare(strict_types=1);

namespace OCA\Arcade\Controller;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\BackgroundJob\RefreshMetadata;
use OCA\Arcade\Service\Folders;
use OCA\Arcade\Service\LibraryService;
use OCA\Arcade\Service\RecentService;
use OCA\Arcade\Service\SettingsService;
use OCA\Files\Event\LoadSidebar;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IRequest;

/**
 * @psalm-suppress UnusedClass
 */
class PageController extends ArcadeController {
	private const MAX_PAGE_SIZE = 500;

	/**
	 * How many games a shelf above the library holds. The same as the
	 * recently played keeps, so the rows are the same length.
	 */
	private const SHELF_SIZE = 12;

	public function __construct(
		string $appName,
		IRequest $request,
		private IInitialState $initialState,
		private SettingsService $settingsService,
		private LibraryService $libraryService,
		private RecentService $recentService,
		private IRootFolder $rootFolder,
		private IJobList $jobList,
		private IEventDispatcher $eventDispatcher,
		protected ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(string $file = '', int $fileId = 0): TemplateResponse {
		// Play links carry the file id, which survives renames and moves;
		// ?file= keeps working for old bookmarks. The frontend is path-based
		// throughout, so the id is resolved to a path right here, and an id
		// that resolves to nothing behaves like an unknown path.
		if ($fileId > 0 && $this->userId !== null) {
			$userFolder = $this->rootFolder->getUserFolder($this->userId);
			$node = $userFolder->getFirstNodeById($fileId);
			if ($node !== null) {
				$file = $userFolder->getRelativePath($node->getPath()) ?? $file;
			}
		}
		$this->initialState->provideInitialState('file', $file);
		$this->settingsService->providePlayerState($this->initialState, $this->userId);

		// The Files sidebar, so the player's actions menu can open it on a
		// game. The class name above is a plain compile-time string, but the
		// event is only worth dispatching when the Files app is really here.
		if (class_exists(LoadSidebar::class)) {
			$this->eventDispatcher->dispatchTyped(new LoadSidebar());
		}

		// The emulator's Content Security Policy needs are added globally by
		// the CSPListener, so the default policy applies here.
		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}

	/**
	 * List the ROMs found in the user's games library folder, one page at a
	 * time. The full scan is cached, so paging through a large library only
	 * walks the folders once.
	 *
	 * The response carries the user's play stats under 'stats', by file id
	 * and only for games of this library, and sorts by them for the 'plays'
	 * (how often) and 'playtime' (how long) sort keys.
	 */
	// Browsing fires a request per page, filter keystroke and sort flip,
	// and a busy minute of that stays well under a hundred; 240 leaves
	// legitimate bursts untouched while a scripted crawl of the cached
	// scan is still cut off.
	#[NoAdminRequired]
	#[UserRateLimit(limit: 240, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/arcade/library')]
	public function library(
		int $offset = 0,
		int $limit = 60,
		string $sort = 'name',
		string $order = 'asc',
		string $search = '',
		string $system = '',
		string $tag = '',
		bool $refresh = false,
	): JSONResponse {
		if (($error = $this->requireUser()) !== null) {
			return $error;
		}
		$settings = $this->settingsService->getUserSettings($this->userId);
		$folderPath = $settings['library_folder'];
		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		$folder = Folders::folderAt($userFolder, $folderPath);
		if ($folder === null) {
			return $this->respond([
				'folder' => $folderPath,
				'exists' => false,
				'total' => 0,
				'libraryTotal' => 0,
				'offset' => 0,
				'limit' => $limit,
				'systems' => [],
				'tags' => [],
				'continuePlaying' => [],
				'recentlyAdded' => [],
				'favorites' => [],
				'stats' => [],
				'games' => [],
				'thumbnailsVersion' => '',
				'rescanning' => false,
			]);
		}

		$games = $this->libraryService->getGames($this->userId, $folder, $userFolder, $folderPath, $settings, $refresh);
		// The tags of the Files app change without touching the folder, so
		// they are put on outside the cached scan.
		$this->libraryService->addTags($games);
		$libraryTotal = count($games);
		if ($refresh) {
			// Rescanning is the moment to ask what the games that were
			// already here say about themselves. Nextcloud reads that only
			// when a file is written, so nothing else ever asks.
			$this->queueMetadata();
		}
		// What each game of this library was played for. The stats of games
		// that are gone would only weigh the payload down.
		$stats = array_intersect_key(
			$this->recentService->stats($this->userId),
			array_column($games, 'id', 'id'),
		);
		$continuePlaying = $this->getContinuePlaying($games, $stats);
		$favorites = $this->getFavorites($games, $stats);
		$recentlyAdded = $this->getRecentlyAdded($games, $stats);
		// The systems of the whole library, so the filter keeps offering
		// them while a filter is active.
		$systems = array_values(array_unique(array_column($games, 'system')));
		sort($systems);
		// Likewise the tags, but only the ones a game here actually carries:
		// the rest of the instance's tags are no filter of this library.
		$tags = [];
		foreach ($games as $game) {
			foreach ($game['tags'] ?? [] as $name) {
				$tags[$name] = true;
			}
		}
		$tags = array_map('strval', array_keys($tags));
		sort($tags, SORT_NATURAL | SORT_FLAG_CASE);

		$games = $this->libraryService->filterGames($games, $search, $system, $tag);
		$this->libraryService->sortGames($games, $sort, $order, $stats);

		$limit = max(1, min(self::MAX_PAGE_SIZE, $limit));
		$offset = max(0, min($offset, max(0, count($games) - 1)));

		// Only for what is about to be shown, and outside the cached scan:
		// screenshots and save states change as games are played.
		$page = array_slice($games, $offset, $limit);
		$this->libraryService->addFallbackImages($this->userId, $userFolder, $settings, $page, $continuePlaying, $favorites, $recentlyAdded);

		return $this->respond([
			'folder' => $folderPath,
			'exists' => true,
			'total' => count($games),
			'libraryTotal' => $libraryTotal,
			'offset' => $offset,
			'limit' => $limit,
			'truncated' => $libraryTotal >= (int)($settings['max_games'] ?? LibraryService::MAX_GAMES),
			'systems' => $systems,
			'tags' => $tags,
			'continuePlaying' => $continuePlaying,
			'recentlyAdded' => $recentlyAdded,
			'favorites' => $favorites,
			'stats' => $stats,
			'games' => $page,
			// The one value every thumbnail preview URL is versioned by,
			// so a replaced image escapes the browser's immutable cache.
			'thumbnailsVersion' => $this->libraryService->thumbnailsVersion($this->userId, $userFolder, $settings),
			// Asking for a rescan again while one is still going would do
			// nothing, so the page is told not to offer it -- however the
			// running one was started, in whichever tab.
			'rescanning' => $this->isRescanning(),
		]);
	}

	/**
	 * Where a first library could be: the folders of this user that
	 * already hold ROMs, best first, for the onboarding panel to offer.
	 * Anything under the configured library folder is left out, and the
	 * list is empty when nothing is found.
	 */
	// Suggestions only appear while onboarding, a handful of loads at
	// most, but each one searches the whole home folder; 30 an hour is
	// plenty for any real first run and stops the search being used to
	// hammer the file cache.
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/arcade/suggest')]
	public function suggest(): JSONResponse {
		if (($error = $this->requireUser()) !== null) {
			return $error;
		}
		$settings = $this->settingsService->getUserSettings($this->userId);
		$userFolder = $this->rootFolder->getUserFolder($this->userId);
		return new JSONResponse([
			'suggestions' => $this->libraryService->suggestFolders(
				$userFolder,
				(string)$settings['library_folder'],
			),
		]);
	}

	/**
	 * The listing, with an ETag so a browser that already holds it is told
	 * so in a 304 instead of being sent it again.
	 *
	 * The ETag is a hash of what is about to be sent, which by construction
	 * covers everything the response depends on: the library and thumbnails
	 * folders (through the cached scan), the query parameters, and the
	 * parts that move without the folder changing -- tags, favorites, the
	 * recently played and their stats, and the fallback images. A 304 is
	 * therefore only given when the full payload would be identical.
	 *
	 * @param array<string, mixed> $payload
	 */
	private function respond(array $payload): JSONResponse {
		$encoded = json_encode($payload);
		if ($encoded === false) {
			// Nothing to hash; send the payload the way it always went.
			return new JSONResponse($payload);
		}
		$etag = md5($encoded);
		if ($this->clientHasCurrent($etag)) {
			$response = new JSONResponse([], Http::STATUS_NOT_MODIFIED);
		} else {
			$response = new JSONResponse($payload);
		}
		$response->setETag($etag);
		// The default Cache-Control says no-store, under which a browser
		// never asks "has it changed?". no-cache lets it keep a copy as
		// long as it revalidates with If-None-Match before showing it.
		$response->addHeader('Cache-Control', 'no-cache, must-revalidate');
		return $response;
	}

	/**
	 * Whether the If-None-Match of the request already names this ETag.
	 *
	 * The AppFramework compares them too when writing the status line, but
	 * that lives outside the public API, so it is not left to chance here.
	 */
	private function clientHasCurrent(string $etag): bool {
		$header = trim($this->request->getHeader('If-None-Match'));
		if ($header === '') {
			return false;
		}
		if ($header === '*') {
			return true;
		}
		foreach (explode(',', $header) as $candidate) {
			$candidate = trim($candidate);
			// A weak comparison is enough for a GET: same bytes, same page.
			if (str_starts_with($candidate, 'W/')) {
				$candidate = substr($candidate, 2);
			}
			if (trim($candidate, '"') === $etag) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Ask for the ROMs to be read, unless that is already waiting to
	 * happen. The job works out for itself which games are missing it.
	 */
	private function queueMetadata(): void {
		if (!$this->isRescanning()) {
			$this->jobList->add(RefreshMetadata::class, ['userId' => (string)$this->userId]);
		}
	}

	/**
	 * Whether a rescan of this user's library is waiting or under way.
	 *
	 * The job stays in the list while it waits and while it runs, and it
	 * puts itself back for as long as there are games left to read, so it
	 * answers for the whole rescan rather than for one batch of it. The
	 * page asks so it can leave the Refresh button alone until the last
	 * one has finished.
	 */
	private function isRescanning(): bool {
		return $this->jobList->has(RefreshMetadata::class, ['userId' => (string)$this->userId]);
	}

	/**
	 * The games played last, in the order they were played, drawn from the
	 * library so they carry everything the library knows. A game that is
	 * gone, or that lives outside the library folder, is left out.
	 *
	 * @param list<array<string, mixed>> $games
	 * @param array<int, array<string, int>> $stats what they were played for
	 * @return list<array<string, mixed>>
	 */
	private function getContinuePlaying(array $games, array $stats): array {
		$byId = [];
		foreach ($games as $game) {
			$byId[$game['id'] ?? 0] = $game;
		}

		$recent = [];
		foreach ($this->recentService->get((string)$this->userId) as $id) {
			if (isset($byId[$id])) {
				$recent[] = [...$byId[$id], ...($stats[$id] ?? [])];
			}
		}
		return $recent;
	}

	/**
	 * The newest games of the library, by the time the file was last
	 * written. What somebody filling a library up wants to see: the shelf
	 * answers "did the thing I just uploaded arrive?" without a search.
	 *
	 * @param list<array<string, mixed>> $games
	 * @param array<int, array<string, int>> $stats what they were played for
	 * @return list<array<string, mixed>>
	 */
	private function getRecentlyAdded(array $games, array $stats): array {
		usort($games, static fn (array $a, array $b): int => ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0));
		$newest = array_slice($games, 0, self::SHELF_SIZE);
		return array_map(
			static fn (array $game): array => [...$game, ...($stats[$game['id'] ?? 0] ?? [])],
			$newest,
		);
	}

	/**
	 * The games of the library the user has starred, in the Files app or
	 * here -- it is the same star. Matched by file id, so a game keeps it
	 * when renamed or moved, and no lookup of its own is needed.
	 *
	 * @param list<array<string, mixed>> $games
	 * @param array<int, array<string, int>> $stats what they were played for
	 * @return list<array<string, mixed>>
	 */
	private function getFavorites(array $games, array $stats): array {
		$ids = $this->recentService->favoriteIds((string)$this->userId);
		if ($ids === []) {
			return [];
		}
		$favorites = [];
		foreach ($games as $game) {
			if (isset($ids[$game['id'] ?? 0])) {
				$favorites[] = [...$game, ...($stats[$game['id'] ?? 0] ?? [])];
			}
		}
		// The most played first, which is what a favorite is about.
		usort($favorites, static fn (array $a, array $b): int => ($b['seconds'] ?? 0) <=> ($a['seconds'] ?? 0));
		return $favorites;
	}
}
