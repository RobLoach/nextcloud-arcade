<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\CoreMap;
use OCA\Arcade\Db\GameMapper;
use OCA\Arcade\Listener\MetadataListener;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;

/**
 * Stores emulator save states and battery saves in the user's saves
 * folder, laid out the way RetroArch lays out its own, so that the folder
 * can be shared with RetroArch through its Cloud Sync over WebDAV:
 *
 *     Saves/manifest.server
 *     Saves/saves/FCEUmm/Mario.srm
 *     Saves/states/FCEUmm/Mario.state1
 *     Saves/states/FCEUmm/Mario.state1.png
 *     Saves/states/FCEUmm/Mario.state.auto
 *
 * The folders under saves/ and states/ are named after the core, which is
 * what RetroArch does unless its "sort by core name" settings are turned
 * off. A save RetroArch filed without them, straight in saves/ or states/,
 * is read from there and written back there.
 *
 * Every write and delete is recorded in manifest.server, which is all
 * RetroArch reads to know what changed on the server.
 *
 * Versions before this kept states in the app data; nothing is read from
 * there any more, but what was left there is still removed along with
 * its game or its user.
 */
class StateService {
	/** The numbered slots the player offers to save into: 1 to this. */
	public const SLOTS = 3;
	/** The slot the player writes by itself: RetroArch's .state.auto. */
	public const AUTO_SLOT = -1;
	/**
	 * The highest slot RetroArch saves into. Slots the player does not
	 * offer are still listed, so what RetroArch put there can be loaded
	 * and removed, but nothing is written to them.
	 */
	public const HIGHEST_SLOT = 999;

	/** Where battery saves live under the saves folder. */
	public const SAVES = 'saves';
	/** Where save states and their screenshots live under the saves folder. */
	public const STATES = 'states';

	/** The highest slot of the app data of earlier versions. */
	private const LEGACY_HIGHEST_SLOT = 6;

	/**
	 * The folders of the app data, found once per request. A null means
	 * not looked for yet; the user folder may be looked for and not be
	 * there.
	 */
	private ?ISimpleFolder $statesRoot = null;
	/** @var array<string, ISimpleFolder|false> */
	private array $userStates = [];
	/**
	 * The saves folder of each user, once resolved this request. A false
	 * means looked for and not there.
	 *
	 * @var array<string, Folder|false>
	 */
	private array $roots = [];
	/**
	 * The file id of each game already looked up this request, by user
	 * then ROM path. A false means looked for and not there.
	 *
	 * @var array<string, array<string, int|false>>
	 */
	private array $fileIds = [];
	/**
	 * The games already checked for a rename this request, by user then
	 * ROM path.
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $followed = [];

	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IRootFolder $rootFolder,
		private SettingsService $settingsService,
		private IFilesMetadataManager $metadataManager,
		private GameMapper $gameMapper,
		private CloudSyncManifest $manifest,
		private LoggerInterface $logger,
	) {
	}

	public function save(string $userId, string $romPath, int $slot, string $data): void {
		$this->write($userId, $romPath, self::STATES, self::stateName(self::stemOf($romPath), $slot), $data);
		$this->remember($userId, $romPath);
	}

	public function saveThumbnail(string $userId, string $romPath, int $slot, string $data): void {
		$this->write($userId, $romPath, self::STATES, self::stateName(self::stemOf($romPath), $slot) . '.png', $data);
	}

	public function load(string $userId, string $romPath, int $slot): ?string {
		return $this->find($userId, $romPath, self::STATES, self::stateName(self::stemOf($romPath), $slot))?->getContent();
	}

	public function loadThumbnail(string $userId, string $romPath, int $slot): ?string {
		return $this->find($userId, $romPath, self::STATES, self::stateName(self::stemOf($romPath), $slot) . '.png')?->getContent();
	}

	/**
	 * SRAM is the in-game battery save (e.g. an RPG's own save file),
	 * stored once per user and game.
	 */
	public function saveSram(string $userId, string $romPath, string $data): void {
		$this->write($userId, $romPath, self::SAVES, self::sramName($romPath), $data);
		$this->remember($userId, $romPath);
	}

	public function loadSram(string $userId, string $romPath): ?string {
		return $this->find($userId, $romPath, self::SAVES, self::sramName($romPath))?->getContent();
	}

	/** Whether the game has a battery save at all, without reading it. */
	public function hasSram(string $userId, string $romPath): bool {
		return $this->find($userId, $romPath, self::SAVES, self::sramName($romPath)) !== null;
	}

	/**
	 * Remove the battery save of a game, so one with an in-game save file
	 * can be started over. Says whether there was one to remove.
	 *
	 * Every place it could be is cleared, not only the one loadSram reads:
	 * a delete has to be final, and a copy left behind would be handed
	 * back the moment the first one is gone.
	 */
	public function deleteSram(string $userId, string $romPath): bool {
		$removed = $this->deleteAppData($userId, self::sramFileName($this->key($userId, $romPath)));
		return $this->removeEverywhere($userId, $romPath, self::SAVES, self::sramName($romPath)) || $removed;
	}

	public function delete(string $userId, string $romPath, int $slot): bool {
		$name = self::stateName(self::stemOf($romPath), $slot);
		$this->removeEverywhere($userId, $romPath, self::STATES, $name . '.png');
		return $this->removeEverywhere($userId, $romPath, self::STATES, $name);
	}

	/**
	 * Drop everything kept for one game of one user, for when the game
	 * itself is deleted. The registry says what the game is filed under,
	 * so even a game whose file is already gone is found.
	 */
	public function deleteAllForGame(string $userId, string $romPath): void {
		$keys = [$this->key($userId, $romPath) => true];
		foreach ($this->gameMapper->entriesOf($userId) as $key => $entry) {
			if ($entry['path'] === $romPath) {
				$keys[$key] = true;
			}
		}
		// PHP turns array keys that look like numbers into numbers, and the
		// file id keys are exactly that; the files are named by the string.
		$keys = array_map(strval(...), array_keys($keys));
		foreach ($keys as $key) {
			$this->deleteLegacy($userId, $key);
		}
		$this->forgetKeys($userId, ...$keys);
		$this->removeGame($userId, $romPath);
	}

	/**
	 * Drop everything kept for a game that is gone for good, known only by
	 * the id its file had. The path the game last had is remembered next
	 * to it, and that is what its saves are named after.
	 */
	public function deleteAllForFileId(string $userId, int $fileId): void {
		$key = (string)$fileId;
		$was = $this->gamesOf($userId)[$key] ?? null;
		$this->deleteLegacy($userId, $key);
		if (is_string($was)) {
			$this->removeGame($userId, $was);
		}
		$this->forgetKeys($userId, $key);
	}

	/**
	 * Drop everything kept for a user, for when the user is deleted. Their
	 * own files, and so their saves folder, are removed by Nextcloud itself.
	 */
	public function deleteAllForUser(string $userId): void {
		$this->gameMapper->deleteAllForUser($userId);
		try {
			$this->statesRoot()->getFolder(self::userKey($userId))->delete();
		} catch (NotFoundException) {
			// Nothing was ever stored for this user.
		}
	}

	/**
	 * Every slot of a game, its automatic one included and whatever
	 * RetroArch saved into slots the player does not offer. A slot is
	 * marked stale when the ROM is no longer the dump the state was made
	 * from, which the player warns about.
	 *
	 * @return list<array{slot: int, size: int, mtime: int, hasThumbnail: bool, stale?: bool}>
	 */
	public function list(string $userId, string $romPath): array {
		$root = $this->root($userId, false);
		if ($root === null) {
			return [];
		}
		$this->follow($userId, $romPath, $root);
		$stem = self::stemOf($romPath);

		$states = [];
		foreach (self::dirsOf($romPath, self::STATES) as $dir) {
			$files = $this->filesIn($root, $dir);
			foreach ($files as $name => $file) {
				$parsed = self::parseStateName($stem, $name);
				// The first folder to hold a slot answers for it, the same
				// one load() reads it from.
				if ($parsed === null || $parsed['thumbnail'] || isset($states[$parsed['slot']])) {
					continue;
				}
				$states[$parsed['slot']] = [
					'slot' => $parsed['slot'],
					'size' => (int)$file->getSize(),
					'mtime' => $file->getMTime(),
					'hasThumbnail' => isset($files[$name . '.png']),
				];
			}
		}
		ksort($states);
		$states = array_values($states);
		if ($states === [] || !$this->romChanged($userId, $romPath)) {
			return $states;
		}
		foreach ($states as &$state) {
			$state['stale'] = true;
		}
		return $states;
	}

	/**
	 * The most recent save state screenshot of each of the given games, to
	 * stand in for a missing thumbnail.
	 *
	 * @param list<string> $romPaths
	 * @return array<string, array{slot: int, mtime: int}> rom path => slot
	 */
	public function thumbnailIndex(string $userId, array $romPaths): array {
		$root = $romPaths === [] ? null : $this->root($userId, false);
		if ($root === null) {
			return [];
		}
		// Each folder is listed once, however many games are asked about.
		$indexes = [];
		$found = [];
		foreach ($romPaths as $path) {
			$stem = self::stemOf($path);
			foreach (self::dirsOf($path, self::STATES) as $dir) {
				$indexes[$dir] ??= $this->screenshotsIn($root, $dir);
				$shot = $indexes[$dir][$stem] ?? null;
				if ($shot !== null && ($found[$path]['mtime'] ?? -1) < $shot['mtime']) {
					$found[$path] = $shot;
				}
			}
		}
		return $found;
	}

	/**
	 * The newest screenshot of every game in a folder of states, by the
	 * name of the game.
	 *
	 * @return array<string, array{slot: int, mtime: int}>
	 */
	private function screenshotsIn(Folder $root, string $dir): array {
		$index = [];
		foreach ($this->filesIn($root, $dir) as $name => $file) {
			if (!str_ends_with($name, '.png')) {
				continue;
			}
			$at = strrpos($name, '.state');
			if ($at === false || $at === 0) {
				continue;
			}
			$stem = substr($name, 0, $at);
			$parsed = self::parseStateName($stem, $name);
			if ($parsed === null) {
				continue;
			}
			$mtime = $file->getMTime();
			if (($index[$stem]['mtime'] ?? -1) < $mtime) {
				$index[$stem] = ['slot' => $parsed['slot'], 'mtime' => $mtime];
			}
		}
		return $index;
	}

	/**
	 * The name RetroArch gives a state of a slot: .state for slot 0,
	 * .state1 and on for the others, and .state.auto for the automatic
	 * one. Its screenshot is the same with .png added.
	 */
	public static function stateName(string $stem, int $slot): string {
		return match (true) {
			$slot === self::AUTO_SLOT => "$stem.state.auto",
			$slot === 0 => "$stem.state",
			default => "$stem.state$slot",
		};
	}

	/**
	 * Which slot of a game a file in a folder of states is, and whether it
	 * is the screenshot of the slot rather than the state. Null for a file
	 * of another game, or one that is no state at all.
	 *
	 * @return ?array{slot: int, thumbnail: bool}
	 */
	public static function parseStateName(string $stem, string $name): ?array {
		$prefix = "$stem.state";
		if (!str_starts_with($name, $prefix)) {
			return null;
		}
		$rest = substr($name, strlen($prefix));
		$thumbnail = str_ends_with($rest, '.png');
		if ($thumbnail) {
			$rest = substr($rest, 0, -4);
		}
		if ($rest === '') {
			return ['slot' => 0, 'thumbnail' => $thumbnail];
		}
		if ($rest === '.auto') {
			return ['slot' => self::AUTO_SLOT, 'thumbnail' => $thumbnail];
		}
		// Spelled the one way stateName() spells it, or loading it by its
		// number would look for another file.
		if (ctype_digit($rest) && $rest === (string)(int)$rest && (int)$rest <= self::HIGHEST_SLOT) {
			return ['slot' => (int)$rest, 'thumbnail' => $thumbnail];
		}
		return null;
	}

	/** The name of a game without its folders and extension: what its files are called after. */
	public static function stemOf(string $romPath): string {
		return pathinfo(basename($romPath), PATHINFO_FILENAME);
	}

	/**
	 * The folders, under the saves folder, where a file of a game may be:
	 * the one named after its core first, which is where new files go,
	 * then the one RetroArch uses when it does not sort by core.
	 *
	 * @param string $kind self::SAVES or self::STATES
	 * @return list<string>
	 */
	public static function dirsOf(string $romPath, string $kind): array {
		$core = CoreMap::coreNameForPath($romPath);
		return $core === '' ? [$kind] : ["$kind/$core", $kind];
	}

	private static function sramName(string $romPath): string {
		return self::stemOf($romPath) . '.srm';
	}

	/**
	 * The saves folder of a user, made when asked to; null when none is
	 * set, or none is there and none was to be made.
	 */
	private function root(string $userId, bool $create): ?Folder {
		$found = $this->roots[$userId] ?? null;
		if ($found instanceof Folder) {
			return $found;
		}
		if ($found === false && !$create) {
			return null;
		}
		$path = $this->settingsService->getUserSettings($userId)['saves_folder'];
		if ($path === '') {
			return null;
		}
		$root = Folders::forUser($this->rootFolder, $userId, $path);
		if ($root === null && $create) {
			$root = $this->makeFolder($this->rootFolder->getUserFolder($userId), $path);
		}
		$this->roots[$userId] = $root ?? false;
		return $root;
	}

	/**
	 * A file of a game, from the first of its folders that holds it.
	 *
	 * @return ?File
	 */
	private function find(string $userId, string $romPath, string $kind, string $name): ?File {
		$root = $this->root($userId, false);
		if ($root === null) {
			return null;
		}
		$this->follow($userId, $romPath, $root);
		return $this->locate($root, $romPath, $kind, $name)[0] ?? null;
	}

	/**
	 * @return array{0: File, 1: string}|null the file and its path under the root
	 */
	private function locate(Folder $root, string $romPath, string $kind, string $name): ?array {
		foreach (self::dirsOf($romPath, $kind) as $dir) {
			try {
				$node = $root->get("$dir/$name");
			} catch (NotFoundException) {
				continue;
			}
			if ($node instanceof File) {
				return [$node, "$dir/$name"];
			}
		}
		return null;
	}

	/**
	 * Write a file of a game: over the one there is, wherever that is, or
	 * into the folder of its core when there is none yet.
	 */
	private function write(string $userId, string $romPath, string $kind, string $name, string $data): void {
		$root = $this->root($userId, true);
		if ($root === null) {
			throw new NotFoundException('The saves folder cannot be made');
		}
		$this->follow($userId, $romPath, $root);
		$found = $this->locate($root, $romPath, $kind, $name);
		if ($found !== null) {
			[$file, $path] = $found;
			$file->putContent($data);
		} else {
			$dir = self::dirsOf($romPath, $kind)[0];
			$folder = $this->makeFolder($root, $dir);
			if ($folder === null) {
				throw new NotFoundException("$dir is not a folder");
			}
			$folder->newFile($name, $data);
			$path = "$dir/$name";
		}
		$this->record($root, [$path => md5($data)]);
	}

	/** Remove a file of a game from every folder it may be in. */
	private function removeEverywhere(string $userId, string $romPath, string $kind, string $name): bool {
		$root = $this->root($userId, false);
		if ($root === null) {
			return false;
		}
		$removed = [];
		foreach (self::dirsOf($romPath, $kind) as $dir) {
			try {
				$root->get("$dir/$name")->delete();
				$removed["$dir/$name"] = '';
			} catch (NotFoundException) {
				// Not in this one.
			}
		}
		$this->record($root, $removed);
		return $removed !== [];
	}

	/** Remove every state, screenshot and battery save of a game. */
	private function removeGame(string $userId, string $romPath): void {
		$root = $this->root($userId, false);
		if ($root === null) {
			return;
		}
		$stem = self::stemOf($romPath);
		$removed = [];
		foreach ([self::STATES, self::SAVES] as $kind) {
			foreach (self::dirsOf($romPath, $kind) as $dir) {
				foreach ($this->filesIn($root, $dir) as $name => $file) {
					if (self::belongsTo($stem, $kind, $name)) {
						$file->delete();
						$removed["$dir/$name"] = '';
					}
				}
			}
		}
		$this->record($root, $removed);
	}

	/** Whether a file of a folder of saves or states is one of a game. */
	private static function belongsTo(string $stem, string $kind, string $name): bool {
		return $kind === self::SAVES
			? $name === "$stem.srm"
			: self::parseStateName($stem, $name) !== null;
	}

	/**
	 * The files of a folder under the root, by name; none when there is no
	 * such folder.
	 *
	 * @return array<string, File>
	 */
	private function filesIn(Folder $root, string $dir): array {
		$folder = Folders::folderAt($root, $dir);
		$files = [];
		foreach ($folder?->getDirectoryListing() ?? [] as $node) {
			if ($node instanceof File) {
				$files[$node->getName()] = $node;
			}
		}
		return $files;
	}

	/**
	 * A game that was renamed or moved does not lose its saves: the
	 * registry remembers the path each file id last had, so its files are
	 * found under the old name and brought along to the new one.
	 */
	private function follow(string $userId, string $romPath, Folder $root): void {
		if (isset($this->followed[$userId][$romPath])) {
			return;
		}
		$this->followed[$userId][$romPath] = true;
		$id = $this->fileIdOf($userId, $romPath);
		$was = $id === null ? null : ($this->gamesOf($userId)[(string)$id] ?? null);
		if ($was === null || $was === $romPath) {
			return;
		}
		$oldStem = self::stemOf($was);
		$newStem = self::stemOf($romPath);
		$moved = [];
		foreach ([self::STATES, self::SAVES] as $kind) {
			$targets = self::dirsOf($romPath, $kind);
			foreach (self::dirsOf($was, $kind) as $dir) {
				// What RetroArch filed by core goes to the folder of the
				// core of the game now; what it filed flat stays flat.
				$target = $dir === $kind ? $kind : $targets[0];
				foreach ($this->filesIn($root, $dir) as $name => $file) {
					if (!self::belongsTo($oldStem, $kind, $name)) {
						continue;
					}
					$newName = $newStem . substr($name, strlen($oldStem));
					if ("$dir/$name" === "$target/$newName") {
						continue;
					}
					try {
						$folder = $this->makeFolder($root, $target);
						if ($folder === null || $folder->nodeExists($newName)) {
							continue;
						}
						$hash = md5($file->getContent());
						$file->move($folder->getPath() . '/' . $newName);
						$moved["$dir/$name"] = '';
						$moved["$target/$newName"] = $hash;
					} catch (\Throwable $e) {
						// Keeping the file where it is beats losing it. The
						// renamed game then does not see the save, which is
						// worth more than a debug line.
						$this->logger->warning('Could not move a save of a renamed game', [
							'from' => "$dir/$name",
							'to' => "$target/$newName",
							'exception' => $e,
						]);
					}
				}
			}
		}
		$this->record($root, $moved);
		$this->remember($userId, $romPath);
	}

	/**
	 * Tell RetroArch what changed. A save that is written but not recorded
	 * still plays in the browser, so a manifest that cannot be written is
	 * logged rather than allowed to lose the save.
	 *
	 * @param array<string, string> $changes
	 */
	private function record(Folder $root, array $changes): void {
		try {
			$this->manifest->record($root, $changes);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not update the Cloud Sync manifest', [
				'exception' => $e,
			]);
		}
	}

	/** Creates the folders of a path one level at a time. */
	private function makeFolder(Folder $base, string $path): ?Folder {
		$folder = $base;
		foreach (explode('/', trim($path, '/')) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			try {
				$node = $folder->get($segment);
				if (!$node instanceof Folder) {
					return null;
				}
				$folder = $node;
			} catch (NotFoundException) {
				$folder = $folder->newFolder($segment);
			}
		}
		return $folder;
	}

	/** Notes where a game is now, so it can be followed if it moves. */
	private function remember(string $userId, string $romPath): void {
		$key = $this->key($userId, $romPath);
		$this->gameMapper->set($userId, $key, $this->fileIdOfKey($key), $romPath, $this->checksumOf($userId, $romPath));
	}

	/**
	 * What the ROM hashes to, as far as Nextcloud knows. Empty for most:
	 * it is only there when the upload brought a checksum, or when the
	 * instance works them out.
	 */
	private function checksumOf(string $userId, string $romPath): string {
		$id = $this->fileIdOf($userId, $romPath);
		if ($id === null) {
			return '';
		}
		try {
			// Read each time, never kept: this is the figure that says a
			// game was re-dumped, and a memo of it would be a memo of the
			// answer it exists to notice changing.
			return $this->metadataManager->getMetadata($id)->getString(MetadataListener::CHECKSUM);
		} catch (\Throwable $e) {
			// An empty checksum is how this says "no idea", and the caller
			// treats that as the ROM being unchanged. So a failure here is
			// not nothing: it is the check that notices a re-dumped game
			// quietly answering no.
			$this->logger->debug('Could not read the checksum of a ROM', [
				'path' => $romPath,
				'exception' => $e,
			]);
			return '';
		}
	}

	/**
	 * The file id of a game, looked up once per request.
	 *
	 * @return int|null null when there is no such file
	 */
	private function fileIdOf(string $userId, string $romPath): ?int {
		if (!isset($this->fileIds[$userId][$romPath])) {
			$this->fileIds[$userId][$romPath] = Folders::fileId($this->rootFolder, $userId, $romPath) ?? false;
		}
		$id = $this->fileIds[$userId][$romPath];
		return $id === false ? null : $id;
	}

	/**
	 * Whether the ROM has changed since its states were written: a state
	 * belongs to the exact dump it was made from, and loading it into
	 * another one goes wrong in ways that look like a broken save.
	 */
	private function romChanged(string $userId, string $romPath): bool {
		$was = $this->gameMapper->entryOf($userId, $this->key($userId, $romPath))['md5'] ?? '';
		$now = $this->checksumOf($userId, $romPath);
		return $was !== '' && $now !== '' && $was !== $now;
	}

	/**
	 * Drop what is remembered of a game, by the names it is filed under.
	 */
	private function forgetKeys(string $userId, string ...$keys): void {
		$this->gameMapper->remove($userId, ...$keys);
	}

	/**
	 * The games a user has saves for, by their path. That is what tells
	 * apart a save whose game is gone from one whose game is merely not
	 * being played.
	 *
	 * @return array<string, string>
	 */
	public function gamesOf(string $userId): array {
		$games = [];
		foreach ($this->gameMapper->entriesOf($userId) as $key => $entry) {
			if ($entry['path'] !== '') {
				$games[$key] = $entry['path'];
			}
		}
		return $games;
	}

	/**
	 * The id of the file a key stands for: the keys are the file id as a
	 * string, or the hash of a path for the games that never had one, and a
	 * hash is nothing to count with.
	 */
	private function fileIdOfKey(string $key): int {
		return ctype_digit($key) ? (int)$key : 0;
	}

	// What earlier versions left in the app data, which is only ever
	// removed now.

	/**
	 * The folders of the app data holding states, by the key of their user.
	 *
	 * @return array<string, ISimpleFolder>
	 */
	public function userFolders(): array {
		$folders = [];
		foreach ($this->statesRoot()->getDirectoryListing() as $node) {
			if ($node instanceof ISimpleFolder) {
				$folders[$node->getName()] = $node;
			}
		}
		return $folders;
	}

	public function folderKeyOf(string $userId): string {
		return self::userKey($userId);
	}

	/**
	 * How many files written before the states were kept per user are left.
	 * They hold no record of whose they are, so they are only ever counted.
	 */
	public function countLegacyFiles(): int {
		$count = 0;
		foreach ($this->statesRoot()->getDirectoryListing() as $node) {
			if (!$node instanceof ISimpleFolder) {
				$count++;
			}
		}
		return $count;
	}

	/** Remove what the app data holds of a game, under one of its keys. */
	private function deleteLegacy(string $userId, string $key): void {
		foreach (self::legacySlots() as $slot) {
			$this->deleteAppData($userId, self::fileName($key, $slot, 'state'));
			$this->deleteAppData($userId, self::fileName($key, $slot, 'png'));
		}
		$this->deleteAppData($userId, self::sramFileName($key));
	}

	private function deleteAppData(string $userId, string $name): bool {
		$folder = $this->userStates($userId);
		try {
			if ($folder !== null && $folder->fileExists($name)) {
				$folder->getFile($name)->delete();
				return true;
			}
		} catch (NotFoundException) {
			// Gone between the look and the delete.
		}
		return false;
	}

	private function statesRoot(): ISimpleFolder {
		if ($this->statesRoot !== null) {
			return $this->statesRoot;
		}
		$appData = $this->appDataFactory->get(Application::APP_ID);
		try {
			return $this->statesRoot = $appData->getFolder('states');
		} catch (NotFoundException) {
			return $this->statesRoot = $appData->newFolder('states');
		}
	}

	private function userStates(string $userId): ?ISimpleFolder {
		$found = $this->userStates[$userId] ?? null;
		if ($found !== null) {
			return $found === false ? null : $found;
		}
		try {
			return $this->userStates[$userId] = $this->statesRoot()->getFolder(self::userKey($userId));
		} catch (NotFoundException) {
			$this->userStates[$userId] = false;
			return null;
		}
	}

	/**
	 * The slots the app data was filed under, the automatic one (0 back
	 * then) first.
	 *
	 * @return list<int>
	 */
	public static function legacySlots(): array {
		return range(0, self::LEGACY_HIGHEST_SLOT);
	}

	/** How a state or screenshot of a slot was named in the app data. */
	public static function fileName(string $key, int $slot, string $extension): string {
		return $key . '-' . $slot . '.' . $extension;
	}

	/** How the battery save was named in the app data. */
	public static function sramFileName(string $key): string {
		return $key . '.srm';
	}

	/**
	 * What a game is filed under in the registry: the id Nextcloud gave
	 * the file, so renaming or moving a ROM does not lose track of it.
	 * Games that are no longer there keep the name they had, which is how
	 * what they left behind is still found and removed.
	 */
	private function key(string $userId, string $romPath): string {
		$id = $this->fileIdOf($userId, $romPath);
		return $id === null ? hash('sha256', $romPath) : (string)$id;
	}

	/** What a user's states folder in the app data is named after. */
	public static function userKey(string $userId): string {
		return hash('sha256', $userId);
	}
}
