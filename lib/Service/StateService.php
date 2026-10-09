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
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Stores emulator save states, keyed by user and ROM path, so every
 * Nextcloud user has their own save states per game.
 *
 * States live in the app data folder by default, in a folder per user so
 * that everything of a user can be dropped at once. When the user configures
 * a saves folder, they are stored there instead, as regular files like
 * "Saves/Mario/Slot 1.state" with "Slot 1.png" next to them — per user by
 * nature, since the folder is in the user's own files.
 */
class StateService {
	/** The slots a game can be saved into. */
	public const SLOTS = 3;
	/** The slot the player writes by itself, kept apart from the numbered ones. */
	public const AUTO_SLOT = 0;
	/**
	 * Earlier versions offered more slots. They are still listed, so what
	 * they hold can be loaded and removed, but nothing is written to them.
	 */
	public const HIGHEST_SLOT = 6;

	/**
	 * Removing one game asks for the folders of the app data a few dozen
	 * times over. They cannot change within a request, so they are found
	 * once. A null means not looked for yet; the user folder may be looked
	 * for and not be there.
	 */
	private ?ISimpleFolder $statesRoot = null;
	/** @var array<string, ISimpleFolder|false> */
	private array $userStates = [];
	/**
	 * The games a user has saves for, by name. The library listing asks
	 * three times over -- for the page, the recently played and the
	 * favorites -- and the answer cannot change in between.
	 *
	 * @var array<string, array<string, Folder>>
	 */
	private array $gameFolders = [];
	/**
	 * The saves folder of each game already resolved this request, by user
	 * then ROM path. The states listing resolves and lists the same folder
	 * twice over -- once for the slots, once for the battery save -- and
	 * it cannot move in between. A false means looked for and not there.
	 *
	 * @var array<string, array<string, Folder|false>>
	 */
	private array $resolvedGameFolders = [];

	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IRootFolder $rootFolder,
		private SettingsService $settingsService,
		private IFilesMetadataManager $metadataManager,
		private GameMapper $gameMapper,
	) {
	}

	public function save(string $userId, string $romPath, int $slot, string $data): void {
		$folder = $this->getGameFolder($userId, $romPath, true);
		if ($folder !== null) {
			$this->writeNode($folder, $this->slotName($slot) . '.state', $data);
			$this->remember($userId, $romPath);
			return;
		}
		$this->writeAppData($userId, self::fileName($this->key($userId, $romPath), $slot, 'state'), $data, $romPath);
	}

	public function saveThumbnail(string $userId, string $romPath, int $slot, string $data): void {
		$folder = $this->getGameFolder($userId, $romPath, true);
		if ($folder !== null) {
			$this->writeNode($folder, $this->slotName($slot) . '.png', $data);
			return;
		}
		$this->writeAppData($userId, self::fileName($this->key($userId, $romPath), $slot, 'png'), $data);
	}

	public function load(string $userId, string $romPath, int $slot): ?string {
		$folder = $this->getGameFolder($userId, $romPath, false);
		if ($folder !== null) {
			return $this->readNode($folder, $this->slotName($slot) . '.state');
		}
		return $this->readAppData($userId, self::fileName($this->key($userId, $romPath), $slot, 'state'));
	}

	public function loadThumbnail(string $userId, string $romPath, int $slot): ?string {
		$folder = $this->getGameFolder($userId, $romPath, false);
		if ($folder !== null) {
			return $this->readNode($folder, $this->slotName($slot) . '.png');
		}
		return $this->readAppData($userId, self::fileName($this->key($userId, $romPath), $slot, 'png'));
	}

	/**
	 * SRAM is the in-game battery save (e.g. an RPG's own save file),
	 * stored once per user and game, next to the save states.
	 */
	public function saveSram(string $userId, string $romPath, string $data): void {
		$folder = $this->getGameFolder($userId, $romPath, true);
		if ($folder !== null) {
			$this->writeNode($folder, $this->sramNodeName($romPath), $data);
			$this->remember($userId, $romPath);
			return;
		}
		$this->writeAppData($userId, self::sramFileName($this->key($userId, $romPath)), $data, $romPath);
	}

	/**
	 * The battery save, from the game's folder when there is one and from
	 * the app data otherwise -- the same order the save states are read in.
	 * A user who played before configuring a saves folder has their battery
	 * save in the app data, and it stays reachable until the folder that
	 * replaces it holds one.
	 */
	public function loadSram(string $userId, string $romPath): ?string {
		$folder = $this->getGameFolder($userId, $romPath, false);
		if ($folder !== null) {
			return $this->findSram($folder, $romPath)?->getContent();
		}
		return $this->readAppData($userId, self::sramFileName($this->key($userId, $romPath)));
	}

	/**
	 * Whether the game has a battery save at all, looked for the same way
	 * loadSram finds it, without reading it.
	 */
	public function hasSram(string $userId, string $romPath): bool {
		$folder = $this->getGameFolder($userId, $romPath, false);
		if ($folder !== null) {
			return $this->findSram($folder, $romPath) !== null;
		}
		$states = $this->userStates($userId, false);
		return $states !== null && $states->fileExists(self::sramFileName($this->key($userId, $romPath)));
	}

	/**
	 * Remove the battery save of a game, so one with an in-game save file
	 * can be started over. Says whether there was one to remove.
	 *
	 * Both places are cleared, not only the one loadSram would read from:
	 * a delete has to be final, and leaving an app-data battery save behind
	 * would hand it back the day the saves folder is emptied or unset.
	 */
	public function deleteSram(string $userId, string $romPath): bool {
		$removed = $this->deleteAppData($userId, self::sramFileName($this->key($userId, $romPath)));
		$folder = $this->getGameFolder($userId, $romPath, false);
		$sram = $folder === null ? null : $this->findSram($folder, $romPath);
		if ($sram !== null) {
			$sram->delete();
			$removed = true;
		}
		return $removed;
	}

	public function delete(string $userId, string $romPath, int $slot): bool {
		$folder = $this->getGameFolder($userId, $romPath, false);
		if ($folder !== null) {
			$this->deleteNode($folder, $this->slotName($slot) . '.png');
			return $this->deleteNode($folder, $this->slotName($slot) . '.state');
		}
		$key = $this->key($userId, $romPath);
		$this->deleteAppData($userId, self::fileName($key, $slot, 'png'));
		return $this->deleteAppData($userId, self::fileName($key, $slot, 'state'));
	}

	/**
	 * Drop everything kept for one game of one user, wherever it lives, for
	 * when the game itself is deleted. The registry says what the game is
	 * filed under, so even a game whose file is already gone is found.
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
			foreach (self::slots() as $slot) {
				$this->deleteAppData($userId, self::fileName($key, $slot, 'state'));
				$this->deleteAppData($userId, self::fileName($key, $slot, 'png'));
			}
			$this->deleteAppData($userId, self::sramFileName($key));
		}
		$this->forgetKeys($userId, ...$keys);
		// And the folder of the game in the user's own saves folder.
		$folder = $this->getGameFolder($userId, $romPath, false);
		if ($folder !== null) {
			$folder->delete();
			// Or the memo would hand the deleted folder back later on.
			unset($this->resolvedGameFolders[$userId][$romPath]);
		}
	}

	/**
	 * Drop everything kept for a game that is gone for good, known only by
	 * the id its file had. What is in the app data is filed under that id,
	 * and the path the game last had is remembered next to it.
	 */
	public function deleteAllForFileId(string $userId, int $fileId): void {
		$key = (string)$fileId;
		$was = $this->gamesOf($userId)[$key] ?? null;
		foreach (self::slots() as $slot) {
			$this->deleteAppData($userId, self::fileName($key, $slot, 'state'));
			$this->deleteAppData($userId, self::fileName($key, $slot, 'png'));
		}
		$this->deleteAppData($userId, self::sramFileName($key));
		if (is_string($was)) {
			$folder = $this->getGameFolder($userId, $was, false);
			if ($folder !== null) {
				$folder->delete();
				// Or the memo would hand the deleted folder back later on.
				unset($this->resolvedGameFolders[$userId][$was]);
			}
		}
		$this->forgetKeys($userId, $key);
	}

	/**
	 * Drop everything kept for a user, for when the user is deleted. Their
	 * own files, and so any saves folder, are removed by Nextcloud itself.
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
	 * A slot is marked stale when the ROM is no longer the dump the state
	 * was made from, which the player warns about.
	 *
	 * @return list<array{slot: int, size: int, mtime: int, hasThumbnail: bool, stale?: bool}>
	 */
	public function list(string $userId, string $romPath): array {
		$folder = $this->getGameFolder($userId, $romPath, false);
		$states = $folder !== null || $this->savesFolderPath($userId) !== ''
			? ($folder === null ? [] : $this->listFolder($folder))
			: $this->listAppData($userId, $romPath);
		if ($states === [] || !$this->romChanged($userId, $romPath)) {
			return $states;
		}
		foreach ($states as &$state) {
			$state['stale'] = true;
		}
		return $states;
	}

	/**
	 * The slots of a game kept in the user's own saves folder. One listing
	 * answers for every slot, rather than asking after each name in turn.
	 *
	 * @return list<array{slot: int, size: int, mtime: int, hasThumbnail: bool}>
	 */
	private function listFolder(Folder $folder): array {
		$files = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File) {
				$files[$node->getName()] = $node;
			}
		}

		$states = [];
		foreach (self::slots() as $slot) {
			$file = $files[$this->slotName($slot) . '.state'] ?? null;
			if ($file === null) {
				continue;
			}
			$states[] = [
				'slot' => $slot,
				'size' => (int)$file->getSize(),
				'mtime' => $file->getMTime(),
				'hasThumbnail' => isset($files[$this->slotName($slot) . '.png']),
			];
		}
		return $states;
	}

	/**
	 * The same, for the states kept in the app data, where a game is filed
	 * under the id of its file.
	 *
	 * @return list<array{slot: int, size: int, mtime: int, hasThumbnail: bool}>
	 */
	private function listAppData(string $userId, string $romPath): array {
		$mine = $this->namesOf($this->userStates($userId, false));
		$key = $this->key($userId, $romPath);

		$states = [];
		foreach (self::slots() as $slot) {
			$file = $mine[self::fileName($key, $slot, 'state')] ?? null;
			if ($file === null) {
				continue;
			}
			$states[] = [
				'slot' => $slot,
				'size' => $file->getSize(),
				'mtime' => $file->getMTime(),
				'hasThumbnail' => isset($mine[self::fileName($key, $slot, 'png')]),
			];
		}
		return $states;
	}

	/**
	 * What a folder of the app data holds, by name.
	 *
	 * @return array<string, ISimpleFile>
	 */
	private function namesOf(?ISimpleFolder $folder): array {
		$files = [];
		foreach ($folder?->getDirectoryListing() ?? [] as $file) {
			$files[$file->getName()] = $file;
		}
		return $files;
	}

	/**
	 * The most recent save state screenshot of each of the given games, to
	 * stand in for a missing thumbnail.
	 *
	 * @param list<string> $romPaths
	 * @return array<string, array{slot: int, mtime: int}> rom path => slot
	 */
	public function thumbnailIndex(string $userId, array $romPaths): array {
		if ($romPaths === []) {
			return [];
		}
		return $this->savesFolderPath($userId) === ''
			? $this->appDataThumbnailIndex($userId, $romPaths)
			: $this->savesFolderThumbnailIndex($userId, $romPaths);
	}

	/**
	 * @param list<string> $romPaths
	 * @return array<string, array{slot: int, mtime: int}>
	 */
	private function appDataThumbnailIndex(string $userId, array $romPaths): array {
		// One listing, so looking a game up afterwards costs nothing.
		$mtimes = [];
		$userFolder = $this->userStates($userId, false);
		foreach ($userFolder?->getDirectoryListing() ?? [] as $file) {
			$mtimes[$file->getName()] = $file->getMTime();
		}

		$found = [];
		foreach ($romPaths as $path) {
			$key = $this->key($userId, $path);
			foreach (self::slots() as $slot) {
				$mtime = $mtimes[self::fileName($key, $slot, 'png')] ?? null;
				if ($mtime !== null && ($found[$path]['mtime'] ?? -1) < $mtime) {
					$found[$path] = ['slot' => $slot, 'mtime' => $mtime];
				}
			}
		}
		return $found;
	}

	/**
	 * The folder of every game the saves folder holds, by the name of the
	 * game.
	 *
	 * Saves are filed under the system of the game, so the folders of the
	 * saves folder are systems, holding the games -- except for the ones
	 * written before the system was part of the path, which are games
	 * themselves. Both are taken, and the listing stops there: going deeper
	 * would be walking somebody's files for nothing.
	 *
	 * @return array<string, Folder>
	 */
	private function gameFoldersIn(Folder $saves): array {
		$systems = [];
		foreach (CoreMap::SYSTEMS as $system) {
			$systems[mb_strtolower($system['short'])] = true;
		}

		$games = [];
		foreach ($saves->getDirectoryListing() as $node) {
			if (!$node instanceof Folder) {
				continue;
			}
			$name = mb_strtolower($node->getName());
			if (!isset($systems[$name])) {
				$games[$name] ??= $node;
				continue;
			}
			foreach ($node->getDirectoryListing() as $child) {
				if ($child instanceof Folder) {
					$games[mb_strtolower($child->getName())] ??= $child;
				}
			}
		}
		return $games;
	}

	/**
	 * @param list<string> $romPaths
	 * @return array<string, array{slot: int, mtime: int}>
	 */
	private function savesFolderThumbnailIndex(string $userId, array $romPaths): array {
		$savesPath = $this->savesFolderPath($userId);
		$saves = Folders::forUser($this->rootFolder, $userId, $savesPath);
		if ($saves === null) {
			return [];
		}
		$gameFolders = $this->gameFolders[$userId] ??= $this->gameFoldersIn($saves);
		// The names the screenshots of the slots are written under.
		$slotsByName = [];
		foreach (self::slots() as $slot) {
			$slotsByName[$this->slotName($slot) . '.png'] = $slot;
		}

		$found = [];
		foreach ($romPaths as $path) {
			$stem = mb_strtolower(pathinfo(basename($path), PATHINFO_FILENAME));
			$folder = $gameFolders[$stem] ?? null;
			if ($folder === null) {
				continue;
			}
			foreach ($folder->getDirectoryListing() as $node) {
				$slot = $slotsByName[$node->getName()] ?? null;
				if ($slot === null) {
					continue;
				}
				$mtime = $node->getMTime();
				if (($found[$path]['mtime'] ?? -1) < $mtime) {
					$found[$path] = ['slot' => $slot, 'mtime' => $mtime];
				}
			}
		}
		return $found;
	}

	/**
	 * Where the saves of a game live: under the system it belongs to, so
	 * that two games of the same name do not share a folder.
	 */
	public static function gameFolderPath(string $savesPath, string $romPath): string {
		$stem = self::stemOf($romPath);
		$system = CoreMap::shortNameForPath($romPath);
		$folder = trim($savesPath, '/');
		return $system === '' ? "$folder/$stem" : "$folder/$system/$stem";
	}

	/** The name of a game without its folders and extension: what its files are called after. */
	public static function stemOf(string $romPath): string {
		return pathinfo(basename($romPath), PATHINFO_FILENAME);
	}

	private function savesFolderPath(string $userId): string {
		return $this->settingsService->getUserSettings($userId)['saves_folder'];
	}

	/**
	 * The folder holding a game's states in the user's files, or null when
	 * the saves folder is not configured (or, unless $create is set, when
	 * the folders do not exist yet).
	 */
	private function getGameFolder(string $userId, string $romPath, bool $create): ?Folder {
		$found = $this->resolvedGameFolders[$userId][$romPath] ?? null;
		if ($found instanceof Folder) {
			return $found;
		}
		if ($found === false && !$create) {
			return null;
		}
		$folder = $this->lookupGameFolder($userId, $romPath, $create);
		$this->resolvedGameFolders[$userId][$romPath] = $folder ?? false;
		return $folder;
	}

	/** The resolving behind getGameFolder(), which remembers the answer. */
	private function lookupGameFolder(string $userId, string $romPath, bool $create): ?Folder {
		$savesPath = $this->savesFolderPath($userId);
		if ($savesPath === '') {
			return null;
		}
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$path = self::gameFolderPath($savesPath, $romPath);

		try {
			$node = $userFolder->get($path);
			if ($node instanceof Folder) {
				return $node;
			}
		} catch (NotFoundException) {
			// Not there yet; perhaps under the name the game had before.
		}
		$followed = $this->followGame($userId, $userFolder, $savesPath, $romPath, $path);
		if ($followed !== null) {
			return $followed;
		}
		return $create ? $this->makeFolder($userFolder, $path) : null;
	}

	/**
	 * A game that was renamed or moved does not lose its saves: the app data
	 * remembers the path each file id last had, so the folder is found under
	 * the old name and brought along to the new one.
	 */
	private function followGame(
		string $userId,
		Folder $userFolder,
		string $savesPath,
		string $romPath,
		string $path,
	): ?Folder {
		$id = Folders::fileId($this->rootFolder, $userId, $romPath);
		$was = $id === null ? null : ($this->gamesOf($userId)[(string)$id] ?? null);
		if ($was === null || $was === $romPath) {
			return null;
		}
		try {
			$node = $userFolder->get(self::gameFolderPath($savesPath, $was));
		} catch (NotFoundException) {
			return null;
		}
		if (!$node instanceof Folder) {
			return null;
		}
		try {
			if (!$userFolder->nodeExists($path) && $this->makeFolder($userFolder, dirname($path)) !== null) {
				$node->move($userFolder->getPath() . '/' . $path);
			}
		} catch (\Throwable) {
			// Keeping the folder where it is beats losing the saves.
		}
		return $node;
	}

	/** Creates the folders of a path one level at a time. */
	private function makeFolder(Folder $userFolder, string $path): ?Folder {
		$folder = $userFolder;
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
		$this->rememberGame($userId, $romPath, $this->key($userId, $romPath), $this->checksumOf($userId, $romPath));
	}

	/**
	 * What the ROM hashes to, as far as Nextcloud knows. Empty for most:
	 * it is only there when the upload brought a checksum, or when the
	 * instance works them out.
	 */
	private function checksumOf(string $userId, string $romPath): string {
		$id = Folders::fileId($this->rootFolder, $userId, $romPath);
		if ($id === null) {
			return '';
		}
		try {
			return $this->metadataManager->getMetadata($id)->getString(MetadataListener::CHECKSUM);
		} catch (\Throwable) {
			return '';
		}
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
	 * The battery save of a game's folder. It is named after the game, but
	 * a game that was renamed has one under its old name, and there is only
	 * ever one in the folder, so any .srm answers.
	 */
	private function findSram(Folder $folder, string $romPath): ?File {
		try {
			$node = $folder->get($this->sramNodeName($romPath));
			if ($node instanceof File) {
				return $node;
			}
		} catch (NotFoundException) {
			// Perhaps under the name the game had before.
		}
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File && str_ends_with(strtolower($node->getName()), '.srm')) {
				return $node;
			}
		}
		return null;
	}

	private function writeNode(Folder $folder, string $name, string $data): void {
		try {
			$node = $folder->get($name);
			if ($node instanceof File) {
				$node->putContent($data);
			}
		} catch (NotFoundException) {
			$folder->newFile($name, $data);
		}
	}

	private function readNode(Folder $folder, string $name): ?string {
		try {
			$node = $folder->get($name);
			return $node instanceof File ? $node->getContent() : null;
		} catch (NotFoundException) {
			return null;
		}
	}

	private function deleteNode(Folder $folder, string $name): bool {
		try {
			$folder->get($name)->delete();
			return true;
		} catch (NotFoundException) {
			return false;
		}
	}

	private function writeAppData(string $userId, string $name, string $data, string $romPath = ''): void {
		$folder = $this->userStates($userId, true);
		if ($folder === null) {
			return;
		}
		try {
			$folder->getFile($name)->putContent($data);
		} catch (NotFoundException) {
			$folder->newFile($name, $data);
		}
		if ($romPath !== '') {
			$this->remember($userId, $romPath);
		}
	}

	/**
	 * The file names hide which game they belong to, so the games a user has
	 * states for are kept in a table of their own. That is what tells apart a
	 * state whose game is gone from one whose game is merely not being played.
	 */
	private function rememberGame(string $userId, string $romPath, string $key, string $checksum): void {
		$this->gameMapper->set($userId, $key, $this->fileIdOfKey($key), $romPath, $checksum);
	}

	/**
	 * Drop what is remembered of a game, by the names it is filed under.
	 */
	private function forgetKeys(string $userId, string ...$keys): void {
		$this->gameMapper->remove($userId, ...$keys);
	}

	/**
	 * The games a user has states for, by their path.
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
	 * The upgrade moved every one it could match to a user and a game; the
	 * rest hold no record of whose they are, so they are only ever counted.
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

	private function readAppData(string $userId, string $name): ?string {
		$folder = $this->userStates($userId, false);
		try {
			if ($folder !== null && $folder->fileExists($name)) {
				return $folder->getFile($name)->getContent();
			}
		} catch (NotFoundException) {
			// Gone between the look and the read.
		}
		return null;
	}

	private function deleteAppData(string $userId, string $name): bool {
		$folder = $this->userStates($userId, false);
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

	private function userStates(string $userId, bool $create): ?ISimpleFolder {
		$found = $this->userStates[$userId] ?? null;
		if ($found instanceof ISimpleFolder) {
			return $found;
		}
		if ($found === false && !$create) {
			return null;
		}
		$root = $this->statesRoot();
		try {
			return $this->userStates[$userId] = $root->getFolder(self::userKey($userId));
		} catch (NotFoundException) {
			if (!$create) {
				$this->userStates[$userId] = false;
				return null;
			}
			return $this->userStates[$userId] = $root->newFolder(self::userKey($userId));
		}
	}

	/**
	 * The slots a game can have, the automatic one first.
	 *
	 * @return list<int>
	 */
	public static function slots(): array {
		return [self::AUTO_SLOT, ...range(1, self::HIGHEST_SLOT)];
	}

	/** How a slot is named in the user's saves folder. */
	private function slotName(int $slot): string {
		return $slot === self::AUTO_SLOT ? 'Auto' : "Slot $slot";
	}

	private function sramNodeName(string $romPath): string {
		return self::stemOf($romPath) . '.srm';
	}

	/** How a state or screenshot of a slot is named in the app data. */
	public static function fileName(string $key, int $slot, string $extension): string {
		return $key . '-' . $slot . '.' . $extension;
	}

	/** How the battery save is named in the app data. */
	public static function sramFileName(string $key): string {
		return $key . '.srm';
	}

	/**
	 * What a game is filed under in the app data: the id Nextcloud gave the
	 * file, so renaming or moving a ROM does not lose its saves. Games that
	 * are no longer there keep the name they had, which is how what they
	 * left behind is still found and removed.
	 */
	private function key(string $userId, string $romPath): string {
		$id = Folders::fileId($this->rootFolder, $userId, $romPath);
		return $id === null ? $this->pathKey($romPath) : (string)$id;
	}

	/** What a game is filed under when its file (and so its id) is gone. */
	private function pathKey(string $romPath): string {
		return hash('sha256', $romPath);
	}

	/** What a user's states folder in the app data is named after. */
	public static function userKey(string $userId): string {
		return hash('sha256', $userId);
	}
}
