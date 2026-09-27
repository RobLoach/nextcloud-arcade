<?php

declare(strict_types=1);

namespace OCA\Arcade\Controller;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\BackgroundJob\RefreshMetadata;
use OCA\Arcade\CoreMap;
use OCA\Arcade\Service\Folders;
use OCA\Arcade\Service\LibraryService;
use OCA\Arcade\Service\RecentService;
use OCA\Arcade\Service\SettingsService;
use OCA\Files\Event\LoadSidebar;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
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
class PageController extends Controller {
	private const MAX_PAGE_SIZE = 500;

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
		private ?string $userId,
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
		$this->initialState->provideInitialState('systems', CoreMap::SYSTEMS);
		// The words that say nothing about a system, so the browser can
		// read a folder name the way the server does without keeping a
		// copy of the lists.
		$this->initialState->provideInitialState('folderWords', [
			'noise' => CoreMap::NOISE,
			'vendors' => CoreMap::VENDORS,
		]);
		$this->initialState->provideInitialState(
			'settings',
			$this->userId === null
				? $this->settingsService->getDefaults()
				: $this->settingsService->getUserSettings($this->userId),
		);

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
	#[NoAdminRequired]
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
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
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
				'recent' => [],
				'favorites' => [],
				'stats' => [],
				'games' => [],
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
		$recent = $this->getRecent($games, $stats);
		$favorites = $this->getFavorites($games, $stats);
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
		$this->libraryService->addFallbackImages($this->userId, $userFolder, $settings, $page, $recent, $favorites);

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
			'recent' => $recent,
			'favorites' => $favorites,
			'stats' => $stats,
			'games' => $page,
		]);
	}

	/**
	 * Where a first library could be: the folders of this user that
	 * already hold ROMs, best first, for the onboarding panel to offer.
	 * Anything under the configured library folder is left out, and the
	 * list is empty when nothing is found.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/arcade/suggest')]
	public function suggest(): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
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
		$argument = ['userId' => (string)$this->userId];
		if (!$this->jobList->has(RefreshMetadata::class, $argument)) {
			$this->jobList->add(RefreshMetadata::class, $argument);
		}
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
	private function getRecent(array $games, array $stats): array {
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
