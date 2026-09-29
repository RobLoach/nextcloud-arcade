<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\CoreMap;
use OCA\Arcade\Listener\MetadataListener;
use OCA\Arcade\Search\SearchBinaryOperator;
use OCA\Arcade\Search\SearchComparison;
use OCA\Arcade\Search\SearchQuery;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchQuery;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

/**
 * Finds the games of a library folder, and puts them in the order and on
 * the page that was asked for.
 *
 * Scanning is one query against the file cache, and the result is cached
 * against the etag of the folder: Nextcloud moves that along whenever
 * anything inside changes, which makes the cache correct without a
 * lifetime to guess at.
 */
class LibraryService {
	/**
	 * What a library is walked to, unless an administrator says otherwise.
	 * SettingsService offers these as the defaults of the instance, so the
	 * limits arrive with everything else a scan is given.
	 */
	public const MAX_GAMES = 5000;
	public const MAX_DEPTH = 6;
	public const CACHE_TTL = 24 * 3600;
	/**
	 * How much of a home folder the onboarding suggestions read: the
	 * first hits, in path order, say plenty about where the ROMs live.
	 */
	public const SUGGEST_SCAN_LIMIT = 2000;
	/** How many folders are worth offering. */
	public const SUGGEST_TOP = 3;
	/** Bumped when the shape of a cached entry changes. */
	private const CACHE_VERSION = 7;
	/** Bumped when the shape of a cached fallbacks entry changes. */
	private const FALLBACKS_CACHE_VERSION = 1;
	/**
	 * Extensions that mean something else at least as often as they mean
	 * a game, so they only count with corroboration.
	 */
	private const CONTESTED = ['md' => true];
	/**
	 * Ids asked after in one query. Oracle refuses a list of more than
	 * a thousand, so a big library is asked about in chunks.
	 */
	public const ID_CHUNK = 500;

	/**
	 * The screenshots of a user, by the game they were taken of.
	 *
	 * @var array<string, array<string, array{id: int, mtime: int}>>
	 */
	private array $screenshots = [];

	/**
	 * The etag of each folder asked about, by user and path. One request
	 * asks for the thumbnails folder twice over -- for the cache key and
	 * for thumbnailsVersion() -- and an etag cannot change within a
	 * request. The user is part of the key because the same path names a
	 * different folder in every user's files, and the service is one
	 * object for the whole request.
	 *
	 * @var array<string, string>
	 */
	private array $folderEtags = [];

	/** The cache this service reads and writes, opened once per request. */
	private ?ICache $cache = null;

	public function __construct(
		private ICacheFactory $cacheFactory,
		private ThumbnailService $thumbnailService,
		private StateService $stateService,
		private IFilesMetadataManager $metadataManager,
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $tagObjectMapper,
	) {
	}

	/**
	 * Attach the system tags of each ROM, as the Files app has them. A
	 * chunk of the library per query, small enough for Oracle.
	 *
	 * Tags come and go without the folder changing, so they are looked up
	 * on every request rather than kept in the cached scan.
	 *
	 * @param list<array<string, mixed>> $games
	 */
	public function addTags(array &$games): void {
		$fileIds = array_map(
			static fn (int $id): string => (string)$id,
			array_values(array_filter(array_column($games, 'id'))),
		);
		if ($fileIds === []) {
			return;
		}
		try {
			$tagIdsByFile = [];
			foreach (array_chunk($fileIds, self::ID_CHUNK) as $chunk) {
				$tagIdsByFile += $this->tagObjectMapper->getTagIdsForObjects($chunk, 'files');
			}
			$tagIds = [];
			foreach ($tagIdsByFile as $ids) {
				foreach ($ids as $tagId) {
					$tagIds[(string)$tagId] = true;
				}
			}
			$names = [];
			foreach (array_chunk(array_map('strval', array_keys($tagIds)), self::ID_CHUNK) as $chunk) {
				foreach ($this->tagManager->getTagsByIds($chunk) as $tag) {
					// Tags an administrator keeps out of sight in the Files
					// app stay out of sight here too.
					if ($tag->isUserVisible()) {
						$names[$tag->getId()] = $tag->getName();
					}
				}
			}
		} catch (\Throwable) {
			// Tags are a filter, not the library: the games still list
			// without them.
			return;
		}
		foreach ($games as &$game) {
			$tags = [];
			foreach ($tagIdsByFile[(string)($game['id'] ?? '')] ?? [] as $tagId) {
				if (isset($names[$tagId])) {
					$tags[] = $names[$tagId];
				}
			}
			sort($tags, SORT_NATURAL | SORT_FLAG_CASE);
			$game['tags'] = $tags;
		}
	}

	/**
	 * Games without a thumbnail fall back to a picture of themselves: the
	 * most recent of the screenshots taken of them and the screenshots of
	 * their save states.
	 *
	 * @param array<string, mixed> $settings
	 * @param list<array<string, mixed>> ...$lists every list the page shows,
	 *                                             so a game is worked out once
	 */
	public function addFallbackImages(
		string $userId,
		Folder $userFolder,
		array $settings,
		array &...$lists,
	): void {
		$missing = [];
		foreach ($lists as $games) {
			foreach ($games as $game) {
				if (empty($game['thumbnails'])) {
					$missing[$game['path']] = $game;
				}
			}
		}
		if ($missing === []) {
			return;
		}

		$fallbacks = $this->fallbacksFor($userId, $userFolder, $settings, $missing);

		foreach ($lists as &$games) {
			foreach ($games as &$game) {
				if (empty($game['thumbnails']) && isset($fallbacks[$game['path']])) {
					$game['fallback'] = $fallbacks[$game['path']];
				}
			}
			unset($game);
		}
	}

	/**
	 * The fallback of every art-less game, from the cache when nothing it
	 * is built from has changed. With a saves folder set, working it out
	 * costs a folder listing per art-less game, which is too much to pay
	 * on every request. A new screenshot or state thumbnail moves the etag
	 * of its folder -- Nextcloud propagates etags up the tree -- so the
	 * key goes stale exactly when the answer does.
	 *
	 * @param array<string, mixed> $settings
	 * @param array<string, array<string, mixed>> $missing the art-less games, by path
	 * @return array<string, array{type: string, fileId?: int, slot?: int}>
	 */
	private function fallbacksFor(string $userId, Folder $userFolder, array $settings, array $missing): array {
		$cache = $this->cache();
		$key = implode('|', [
			'fallbacks',
			self::FALLBACKS_CACHE_VERSION,
			$userId,
			$this->folderEtag($userId, $userFolder, (string)($settings['screenshots_folder'] ?? '')),
			$this->folderEtag($userId, $userFolder, (string)($settings['saves_folder'] ?? '')),
		]);
		$cached = $cache->get($key);
		if (is_array($cached) && is_array($cached['paths'] ?? null) && is_array($cached['fallbacks'] ?? null)
			&& array_diff_key($missing, array_fill_keys($cached['paths'], true)) === []) {
			// Only when the entry answers for every game asked about now: a
			// game that lost its thumbnail since is not in an older entry.
			return $cached['fallbacks'];
		}

		// The listing asks three times over -- for the page, the recently
		// played and the favorites -- and the folder cannot change in
		// between, so it is walked once.
		$screenshots = $this->screenshots[$userId] ??= $this->indexScreenshots($userFolder, $settings);
		$states = $this->stateService->thumbnailIndex($userId, array_keys($missing));

		// Worked out once per game, however many of the lists it is in.
		$fallbacks = [];
		foreach ($missing as $path => $game) {
			$screenshot = null;
			foreach ($this->thumbnailService->screenshotKeys($game['basename']) as $name) {
				if (isset($screenshots[$name])) {
					$screenshot = $screenshots[$name];
					break;
				}
			}
			$state = $states[$path] ?? null;

			if ($screenshot !== null && ($state === null || $screenshot['mtime'] >= $state['mtime'])) {
				$fallbacks[$path] = ['type' => 'screenshot', 'fileId' => $screenshot['id']];
			} elseif ($state !== null) {
				$fallbacks[$path] = ['type' => 'state', 'slot' => $state['slot']];
			}
		}

		// The paths answered for ride along, so an entry that knows nothing
		// of a game is never taken for "looked, and there is none".
		$cache->set(
			$key,
			['paths' => array_keys($missing), 'fallbacks' => $fallbacks],
			(int)($settings['cache_ttl'] ?? self::CACHE_TTL),
		);
		return $fallbacks;
	}

	/**
	 * @param list<array<string, mixed>> $games
	 * @return list<array<string, mixed>>
	 */
	public function filterGames(array $games, string $search, string $system, string $tag = ''): array {
		$search = trim($search);
		if ($search !== '') {
			$games = array_filter(
				$games,
				static fn (array $game): bool => mb_stripos($game['basename'], $search) !== false,
			);
		}
		if ($system !== '') {
			$games = array_filter(
				$games,
				static fn (array $game): bool => $game['system'] === $system,
			);
		}
		if ($tag !== '') {
			$games = array_filter(
				$games,
				static fn (array $game): bool => in_array($tag, $game['tags'] ?? [], true),
			);
		}
		return array_values($games);
	}

	/**
	 * The scanned games, from the cache when the library and thumbnails
	 * folders have not changed since.
	 *
	 * @param array<string, mixed> $settings
	 * @return list<array<string, mixed>>
	 */
	public function getGames(string $userId, Folder $folder, Folder $userFolder, string $folderPath, array $settings, bool $refresh): array {
		$cache = $this->cache();
		// Nextcloud propagates etags up the tree, so the library folder's
		// etag changes whenever anything inside it does.
		$key = implode('|', [
			self::CACHE_VERSION,
			$userId,
			$folderPath,
			$folder->getEtag(),
			$settings['thumbnails_folder'],
			$this->folderEtag($userId, $userFolder, $settings['thumbnails_folder']),
			(string)($settings['max_games'] ?? self::MAX_GAMES),
			(string)($settings['max_depth'] ?? self::MAX_DEPTH),
		]);
		if (!$refresh) {
			$cached = $this->inflate($cache->get($key));
			if ($cached !== null) {
				return $cached;
			}
		}

		$games = [];
		// Zipped ROMs are extracted in the browser when launched, and a
		// .bin says nothing about whose game it is.
		$extensionMap = CoreMap::libraryExtensions();
		$limits = [
			'games' => (int)($settings['max_games'] ?? self::MAX_GAMES),
			'depth' => (int)($settings['max_depth'] ?? self::MAX_DEPTH),
		];
		$this->findRoms($folder, $userFolder, $extensionMap, $games, $limits);
		$this->addWhatWasRead($games);
		$this->addThumbnails($games, $userFolder, $settings['thumbnails_folder'], $folderPath);

		// Only what the list draws is worth keeping, and it is kept small:
		// memcached drops anything over a megabyte without a word, and a
		// library that large would rescan on every request without noticing.
		$cache->set($key, $this->deflate($games), (int)($settings['cache_ttl'] ?? self::CACHE_TTL));
		return $games;
	}

	/**
	 * The games as they are cached: without what a line of PHP can put
	 * back, and packed, so five thousand of them stay well under the
	 * megabyte a memcached entry is allowed.
	 *
	 * @param list<array<string, mixed>> $games
	 */
	private function deflate(array $games): mixed {
		$lean = array_map(static function (array $game): array {
			// The basename is the last segment of the path.
			unset($game['basename']);
			return $game;
		}, $games);
		$packed = CachePacker::pack($lean);
		// A library that cannot be packed is cached as it always was.
		return is_array($packed) ? $games : $packed;
	}

	/**
	 * A cached entry back into games, whichever way it was stored: packed
	 * by deflate(), or a plain array from before the packing existed or
	 * from a deflate() that could not compress.
	 *
	 * @return list<array<string, mixed>>|null null when there is no usable entry
	 */
	private function inflate(mixed $cached): ?array {
		$cached = CachePacker::unpack($cached);
		if ($cached === null) {
			return null;
		}
		foreach ($cached as &$game) {
			if (!is_array($game) || !isset($game['path'])) {
				return null;
			}
			if (!isset($game['basename'])) {
				$slash = strrpos($game['path'], '/');
				// Slotted back where the scan put it, so a cache hit is the
				// same response byte for byte.
				$game = array_merge(
					['id' => $game['id'] ?? 0, 'path' => $game['path']],
					['basename' => $slash === false ? $game['path'] : substr($game['path'], $slash + 1)],
					$game,
				);
			}
		}
		return array_values($cached);
	}

	/**
	 * The version the thumbnails of this user are at: the etag of their
	 * thumbnails folder, which Nextcloud moves along whenever any image
	 * under it changes. The listing hands it to the browser to append to
	 * every preview URL -- /core/preview is served immutable for a day,
	 * so without a changing URL a replaced image would keep showing its
	 * old picture out of the browser cache.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function thumbnailsVersion(string $userId, Folder $userFolder, array $settings): string {
		return $this->folderEtag($userId, $userFolder, (string)($settings['thumbnails_folder'] ?? ''));
	}

	/**
	 * The cache the scan and the fallbacks share. One request asks for it
	 * twice over, and which cache the instance offers cannot change in
	 * between.
	 */
	private function cache(): ICache {
		return $this->cache ??= Caches::create($this->cacheFactory, Application::CACHE_LIBRARY);
	}

	private function folderEtag(string $userId, Folder $userFolder, string $path): string {
		if ($path === '') {
			return '';
		}
		$memo = $userId . '|' . $path;
		$known = $this->folderEtags[$memo] ?? null;
		if ($known !== null) {
			return $known;
		}
		try {
			return $this->folderEtags[$memo] = $userFolder->get($path)->getEtag();
		} catch (NotFoundException) {
			return $this->folderEtags[$memo] = '';
		}
	}

	/**
	 * Sorting by 'plays' or 'playtime' needs the play stats, which are the
	 * user's rather than the library's, so they are handed in: how often a
	 * game was started for 'plays', how long it was played for 'playtime'.
	 * A game never played sorts after every game played, whichever way the
	 * played ones are ordered.
	 *
	 * @param list<array<string, mixed>> $games
	 * @param array<int, array<string, int>> $stats plays and seconds by file id
	 */
	public function sortGames(array &$games, string $sort, string $order, array $stats = []): void {
		$direction = $order === 'desc' ? -1 : 1;
		if ($sort === 'plays' || $sort === 'playtime') {
			$key = $sort === 'plays' ? 'plays' : 'seconds';
			usort($games, static function (array $a, array $b) use ($direction, $stats, $key): int {
				$statA = (int)($stats[$a['id'] ?? 0][$key] ?? 0);
				$statB = (int)($stats[$b['id'] ?? 0][$key] ?? 0);
				// The games never played stay behind the games played,
				// whichever way the played ones are turned.
				if (($statA > 0) !== ($statB > 0)) {
					return $statA > 0 ? -1 : 1;
				}
				$result = ($statA <=> $statB) * $direction;
				// Fall back to the name, so the order is always stable.
				return $result === 0 ? strcasecmp($a['basename'], $b['basename']) : $result;
			});
			return;
		}
		usort($games, static function (array $a, array $b) use ($sort, $direction): int {
			$result = match ($sort) {
				'system' => strcasecmp($a['system'], $b['system']),
				'size' => $a['size'] <=> $b['size'],
				'mtime' => $a['mtime'] <=> $b['mtime'],
				default => 0,
			};
			// Fall back to the name, so the order is always stable.
			if ($result === 0) {
				$result = strcasecmp($a['basename'], $b['basename']);
				return $sort === 'name' ? $result * $direction : $result;
			}
			return $result * $direction;
		});
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, array{id: int, mtime: int}>
	 */
	private function indexScreenshots(Folder $userFolder, array $settings): array {
		if ($settings['screenshots_folder'] === '') {
			return [];
		}
		// No screenshots folder, no screenshots.
		$folder = Folders::folderAt($userFolder, $settings['screenshots_folder']);
		return $folder === null ? [] : $this->thumbnailService->indexScreenshots($folder);
	}

	/**
	 * Attach the matching images to each game, by type.
	 *
	 * @param list<array<string, mixed>> $games
	 */
	private function addThumbnails(array &$games, Folder $userFolder, string $thumbnailsPath, string $libraryPath): void {
		if ($thumbnailsPath === '') {
			return;
		}
		$thumbnails = Folders::folderAt($userFolder, $thumbnailsPath);
		if ($thumbnails === null) {
			return;
		}
		$index = $this->thumbnailService->buildIndex($thumbnails);
		foreach ($games as &$game) {
			$found = $this->thumbnailService->forGameNamed(
				$index,
				$game['system'],
				$this->thumbnailService->subfolderOf($game['path'], $libraryPath),
				$game['basename'],
				$game['title'] ?? '',
			);
			if ($found !== []) {
				$game['thumbnails'] = $found;
			}
		}
	}

	/**
	 * Attach what was read of each ROM: the name the cartridge gives
	 * itself, the region it was sold in, and -- for a .bin, whose name says
	 * nothing -- which machine it turned out to be for.
	 *
	 * @param list<array<string, mixed>> $games
	 */
	private function addWhatWasRead(array &$games): void {
		$known = $this->knownOf($games);
		foreach ($games as &$game) {
			$read = $known[$game['id']] ?? null;
			if ($read === null) {
				continue;
			}
			foreach (['title', 'region'] as $key) {
				if ($read[$key] !== '') {
					$game[$key] = $read[$key];
				}
			}
			// A name that says nothing leaves the folder guessing, and what
			// was read of the file itself settles it either way.
			$ambiguous = CoreMap::isAmbiguous(pathinfo($game['basename'], PATHINFO_EXTENSION));
			if ($read['system'] !== '' && (($game['system'] ?? '') === '' || $ambiguous)) {
				$game['system'] = $read['system'];
			}
		}
	}

	/**
	 * What each cartridge says about itself, for the games that have been
	 * read. A chunk of the library per query, small enough for Oracle.
	 *
	 * @param list<array<string, mixed>> $games
	 * @return array<int, array{title: string, region: string, system: string}>
	 */
	private function knownOf(array $games): array {
		$ids = array_values(array_filter(array_column($games, 'id')));
		if ($ids === []) {
			return [];
		}
		$known = [];
		try {
			foreach (array_chunk($ids, self::ID_CHUNK) as $chunk) {
				foreach ($this->metadataManager->getMetadataForFiles($chunk) as $id => $metadata) {
					$known[(int)$id] = [
						'title' => $metadata->getString(MetadataListener::TITLE),
						'region' => $metadata->getString(MetadataListener::REGION),
						'system' => $metadata->getString(MetadataListener::SYSTEM),
					];
				}
			}
		} catch (\Throwable) {
			// Nothing has been read of the ROMs yet, which is no reason to
			// go without the thumbnails that do match.
		}
		return $known;
	}

	/**
	 * One query against the file cache finds every ROM under the library,
	 * however deep it goes and whatever storage a folder of it is mounted
	 * from -- where walking the folders cost a query for each one of them.
	 *
	 * OCP\Files\Cache\IFileAccess::getByAncestorInStorage() was measured
	 * against this on a five thousand game library: 63ms where the search
	 * costs 141ms, of a 235ms scan. Not taken, on three counts. It walks one
	 * storage, so the ROMs of a folder shared into the library go missing. It
	 * reads the file cache table straight, so a team folder's advanced
	 * permissions never apply and it hands back files the user is refused. And
	 * it filters on mimetype alone, where .zip, .bin, .rom and .md carry none
	 * of the app's own -- a tenth of that library went missing with it.
	 *
	 * @param array<string, string> $extensionMap extension => system id
	 * @param list<array{path: string, basename: string, system: string}> $games
	 * @param array{games: int, depth: int} $limits how far this scan goes
	 */
	private function findRoms(
		Folder $folder,
		Folder $userFolder,
		array $extensionMap,
		array &$games,
		array $limits,
	): void {
		// Unlimited on purpose: the truncation below sorts by path first,
		// and that determinism only holds when the query saw everything
		// up to max_games.
		$nodes = $folder->search($this->romQuery($extensionMap));
		// The database answers in whatever order suits it; the walk this
		// replaces went folder by folder. Sorting by path keeps the scan
		// deterministic, so a library over max_games always keeps the same
		// games rather than a different slice each time.
		usort($nodes, static fn ($a, $b): int => strcmp($a->getPath(), $b->getPath()));

		foreach ($nodes as $node) {
			if (count($games) >= $limits['games']) {
				return;
			}
			// A folder named like a ROM matches on its name, but is no game.
			if ($node instanceof Folder) {
				continue;
			}
			$relative = $folder->getRelativePath($node->getPath());
			if ($relative === null) {
				continue;
			}
			// The folder names between the library root and the file, for
			// how deep it sits and for what shelf it sits on.
			$parents = explode('/', trim($relative, '/'));
			array_pop($parents);
			if (count($parents) > $limits['depth']) {
				continue;
			}
			$extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
			if (!isset($extensionMap[$extension])) {
				continue;
			}
			$system = $this->systemFor($extension, $parents, $extensionMap, $node->getMimetype());
			if ($system === null) {
				continue;
			}
			// A zip that nothing names is still a zip; a .bin that nothing
			// names waits for its first bytes to be read.
			if ($system === '' && $extension === 'zip') {
				$system = 'zip';
			}
			$games[] = [
				'id' => $node->getId(),
				'path' => $userFolder->getRelativePath($node->getPath()),
				'basename' => $node->getName(),
				'system' => $system,
				'size' => $node->getSize(),
				'mtime' => $node->getMTime(),
			];
		}
	}

	/**
	 * The system of a file, as the scan and the suggestions read it: the
	 * extension when it names one, otherwise the nearest folder name that
	 * does -- neither a zip nor a .bin reveals its system, but the folder
	 * it is stored in often does, e.g. "Games/SNES/NHL 96.zip".
	 *
	 * Null means the file is no game at all: ".md" is a Mega Drive dump
	 * to Sega and a Markdown note to everyone else, so the extension
	 * alone proves nothing -- the mimetype or the folder has to agree,
	 * or every readme in the account would shelve itself as a game.
	 *
	 * @param list<string> $parents folder names above the file, top first
	 * @param array<string, string> $extensionMap extension => system id
	 */
	private function systemFor(string $extension, array $parents, array $extensionMap, string $mime = ''): ?string {
		$system = $extensionMap[$extension] ?? '';
		if ($system !== '' && isset(self::CONTESTED[$extension])) {
			if ($mime === (CoreMap::SYSTEMS[$system]['mime'] ?? null)) {
				return $system;
			}
			foreach (array_reverse($parents) as $parent) {
				if (CoreMap::systemForFolderName($parent) === $system) {
					return $system;
				}
			}
			return null;
		}
		if ($system === '') {
			foreach (array_reverse($parents) as $parent) {
				$fromFolder = CoreMap::systemForFolderName($parent);
				if ($fromFolder !== null) {
					return $fromFolder;
				}
			}
		}
		return $system;
	}

	/**
	 * Where the ROMs of a user already are, for the first run: the same
	 * one search findRoms() makes, but over the whole home folder, boiled
	 * down to the few folders worth offering as a library.
	 *
	 * The grouping is deliberately simple. Every folder holding ROMs
	 * starts as a candidate. Then, deepest first, a candidate whose games
	 * all belong to one system is folded into its parent when that parent
	 * holds ROMs of its own or at least two such single-system children:
	 * /ROMs with /ROMs/SNES and /ROMs/GB inside becomes one suggestion
	 * for /ROMs, while a lone /Downloads/GB stays its own suggestion
	 * rather than dragging all of /Downloads in.
	 *
	 * @param string $excludeFolder the configured library folder, whose
	 *                              games need no suggesting
	 * @return list<array{path: string, games: int, systems: list<string>}>
	 *         best first, at most SUGGEST_TOP of them
	 */
	public function suggestFolders(Folder $userFolder, string $excludeFolder): array {
		$extensionMap = CoreMap::libraryExtensions();
		// Capped in the SQL itself, so a home folder full of matches never
		// hands more than the scan limit across. The query is unordered,
		// which makes the capped subset arbitrary -- tolerable here, where
		// any few hundred ROMs say plenty about where the ROMs live.
		$nodes = $userFolder->search($this->romQuery($extensionMap, self::SUGGEST_SCAN_LIMIT));
		// Path order, so the suggestions from what the query handed over
		// are stable; the slice stays as a belt to the query's braces.
		usort($nodes, static fn ($a, $b): int => strcmp($a->getPath(), $b->getPath()));
		$nodes = array_slice($nodes, 0, self::SUGGEST_SCAN_LIMIT);

		/** @var array<string, array{games: int, systems: array<string, true>}> $candidates */
		$candidates = [];
		foreach ($nodes as $node) {
			// A folder named like a ROM matches on its name, but is no game.
			if ($node instanceof Folder) {
				continue;
			}
			$relative = $userFolder->getRelativePath($node->getPath());
			if ($relative === null) {
				continue;
			}
			$extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
			if (!isset($extensionMap[$extension])) {
				continue;
			}
			// What is already the library needs no suggesting.
			if ($excludeFolder !== ''
				&& ($relative === $excludeFolder || str_starts_with($relative, $excludeFolder . '/'))) {
				continue;
			}
			$parents = explode('/', trim($relative, '/'));
			array_pop($parents);
			// A file loose in the home folder has no folder to offer: the
			// home folder itself cannot be the library.
			if ($parents === []) {
				continue;
			}
			$system = $this->systemFor($extension, $parents, $extensionMap, $node->getMimetype());
			if ($system === null) {
				continue;
			}
			$path = '/' . implode('/', $parents);
			$candidates[$path] ??= ['games' => 0, 'systems' => []];
			$candidates[$path]['games']++;
			if (isset(CoreMap::SYSTEMS[$system])) {
				$candidates[$path]['systems'][$system] = true;
			}
		}

		return $this->groupSuggestions($candidates);
	}

	/**
	 * Roll the folders holding ROMs up into the few worth offering, as
	 * suggestFolders() documents.
	 *
	 * @param array<string, array{games: int, systems: array<string, true>}> $candidates
	 * @return list<array{path: string, games: int, systems: list<string>}>
	 */
	private function groupSuggestions(array $candidates): array {
		// Deepest first, so a chain of folders rolls up one level at a time.
		$paths = array_keys($candidates);
		usort($paths, static fn (string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/'));

		// Counted before any merging, so two system folders find each other
		// under a parent that holds no ROMs of its own.
		$singleSystemChildren = [];
		$directHits = array_fill_keys($paths, true);
		foreach ($paths as $path) {
			if (count($candidates[$path]['systems']) === 1) {
				$parent = self::parentOf($path);
				$singleSystemChildren[$parent] = ($singleSystemChildren[$parent] ?? 0) + 1;
			}
		}

		foreach ($paths as $path) {
			if (!isset($candidates[$path]) || count($candidates[$path]['systems']) !== 1) {
				continue;
			}
			$parent = self::parentOf($path);
			if ($parent === '') {
				// The home folder cannot be the library.
				continue;
			}
			if (!isset($directHits[$parent]) && ($singleSystemChildren[$parent] ?? 0) < 2) {
				continue;
			}
			$candidates[$parent] ??= ['games' => 0, 'systems' => []];
			$candidates[$parent]['games'] += $candidates[$path]['games'];
			$candidates[$parent]['systems'] += $candidates[$path]['systems'];
			unset($candidates[$path]);
		}

		$suggestions = [];
		foreach ($candidates as $path => $candidate) {
			$systems = array_keys($candidate['systems']);
			sort($systems);
			$suggestions[] = ['path' => $path, 'games' => $candidate['games'], 'systems' => $systems];
		}
		usort($suggestions, static fn (array $a, array $b): int =>
			($b['games'] <=> $a['games']) ?: strcmp($a['path'], $b['path']));
		return array_slice($suggestions, 0, self::SUGGEST_TOP);
	}

	/** The folder above a path, empty at the top. */
	private static function parentOf(string $path): string {
		$slash = strrpos($path, '/');
		return $slash === false || $slash === 0 ? '' : substr($path, 0, $slash);
	}

	/**
	 * What a ROM looks like to the file cache: one of the mimetypes the app
	 * registers, or -- for files uploaded before the app was installed and
	 * never run through occ maintenance:mimetype:update-db -- a name ending
	 * in one of the extensions those mimetypes are registered for. Either
	 * way it is the extension that decides above, so nothing shows up or
	 * goes missing over what the mimetype column happens to say.
	 *
	 * The system a game belongs to is not asked of the database: the
	 * arcade-system metadata is indexed and the search interfaces do take
	 * metadata comparisons, but it is only written once a file has been
	 * through the metadata events, so games uploaded before the app -- the
	 * very ones the extension clauses are here for -- would vanish from a
	 * filtered page. The scan is cached whole and filtered in PHP instead.
	 *
	 * @param array<string, string> $extensionMap extension => system id
	 * @param int $limit rows the database hands back at most, 0 for all;
	 *                   see SearchQuery for what a capped query trades away
	 */
	private function romQuery(array $extensionMap, int $limit = 0): ISearchQuery {
		$clauses = [];
		foreach (array_unique(array_values(CoreMap::extensionMimeMap())) as $mime) {
			$clauses[] = new SearchComparison(ISearchComparison::COMPARE_EQUAL, 'mimetype', $mime);
		}
		foreach (array_keys($extensionMap) as $extension) {
			$clauses[] = new SearchComparison(ISearchComparison::COMPARE_LIKE, 'name', '%.' . $extension);
		}
		return new SearchQuery(new SearchBinaryOperator(ISearchBinaryOperator::OPERATOR_OR, $clauses), $limit);
	}
}
