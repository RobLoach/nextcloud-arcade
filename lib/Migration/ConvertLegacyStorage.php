<?php

declare(strict_types=1);

namespace OCA\Arcade\Migration;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\CoreMap;
use OCA\Arcade\Db\GameMapper;
use OCA\Arcade\Db\PlayMapper;
use OCA\Arcade\Service\Folders;
use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Service\StateService;
use OCP\Config\IUserConfig;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ITagManager;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Brings what older versions of the app left behind over to where it lives
 * now, once, at upgrade -- so no request ever has to look in the old places
 * again.
 *
 * Older versions kept the games registry in a games.json next to the states,
 * filed states under the hash of the ROM's path (and before that flat in the
 * shared states folder, under the hash of user and path), kept saves-folder
 * saves without the system segment, and kept the play records and favorites
 * as JSON blobs of the user config. Each of those is converted here.
 *
 * The step is idempotent: everything is checked before it is touched, so a
 * partial earlier run only leaves less to do. Nothing that cannot be
 * converted is ever deleted or overwritten -- it stays exactly where it is.
 *
 * @psalm-suppress UnusedClass
 */
class ConvertLegacyStorage implements IRepairStep {
	/** Where older versions listed the games a user has states for. */
	private const GAMES_FILE = 'games.json';

	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private IUserConfig $userConfig,
		private ITagManager $tagManager,
		private SettingsService $settingsService,
		private GameMapper $gameMapper,
		private PlayMapper $playMapper,
	) {
	}

	public function getName(): string {
		return 'Convert what older versions of Arcade left behind';
	}

	public function run(IOutput $output): void {
		$this->convertStates($output);
		// The plays go first, so a favorite's counts cannot shadow them.
		$this->convertPlays($output);
		$this->convertFavorites($output);
	}

	/**
	 * The app data: every user folder under the states root gets its
	 * games.json imported, its path-hash names brought to file-id names,
	 * the flat files of its user adopted, and the old saves-folder layout
	 * moved under the system of each game.
	 */
	private function convertStates(IOutput $output): void {
		try {
			$root = $this->appDataFactory->get(Application::APP_ID)->getFolder('states');
		} catch (NotFoundException) {
			// Nothing was ever stored.
			return;
		}

		// The folders are named after the hash of their user, which only
		// the list of users can turn back into one.
		$users = [];
		$this->userManager->callForAllUsers(static function ($user) use (&$users): void {
			$users[StateService::userKey($user->getUID())] = $user->getUID();
		});

		// The files still sitting flat in the states root, listed once.
		$flat = [];
		$folders = [];
		foreach ($root->getDirectoryListing() as $node) {
			if ($node instanceof ISimpleFolder) {
				$folders[$node->getName()] = $node;
			} else {
				$flat[$node->getName()] = true;
			}
		}

		$converted = 0;
		foreach ($folders as $name => $folder) {
			$userId = $users[$name] ?? null;
			if ($userId === null) {
				// A folder of a user that is gone; `occ arcade:cleanup`
				// is the one that removes those.
				continue;
			}
			try {
				$this->convertUserStates($userId, $folder, $root, $flat);
				$converted++;
			} catch (\Throwable) {
				// Whatever could not be converted stays where it is; the
				// next upgrade tries again.
			}
		}
		if ($converted > 0) {
			$output->info("Converted the stored states of $converted users.");
		}
	}

	/**
	 * @param array<string, true> $flat what sits flat in the states root
	 */
	private function convertUserStates(string $userId, ISimpleFolder $folder, ISimpleFolder $root, array &$flat): void {
		$this->importGames($userId, $folder);
		$entries = $this->gameMapper->entriesOf($userId);
		$entries = $this->renameToFileIds($userId, $folder, $entries);
		$this->adoptFlatFiles($userId, $folder, $root, $flat, $entries);
		$this->moveSavesFolders($userId, $entries);
	}

	/**
	 * The registry used to be a games.json next to the states. It goes into
	 * the table, and the file goes. An entry the table already has was
	 * written since, and stays.
	 */
	private function importGames(string $userId, ISimpleFolder $folder): void {
		if (!$folder->fileExists(self::GAMES_FILE)) {
			return;
		}
		$games = json_decode($folder->getFile(self::GAMES_FILE)->getContent(), true);
		foreach (is_array($games) ? $games : [] as $key => $entry) {
			$key = (string)$key;
			// Written as a bare path before the checksum was kept with it.
			$path = is_array($entry) ? ($entry['path'] ?? '') : $entry;
			$checksum = is_array($entry) ? ($entry['md5'] ?? '') : '';
			if (!is_string($path) || $path === '' || !is_string($checksum)) {
				continue;
			}
			$this->gameMapper->importEntry($userId, $key, ctype_digit($key) ? (int)$key : 0, $path, $checksum);
		}
		$folder->getFile(self::GAMES_FILE)->delete();
	}

	/**
	 * States were filed under the hash of the ROM's path before the file id
	 * was used. Where the registry knows the path and the path still resolves
	 * to a file, its files are renamed and the registry entry follows. A name
	 * that is already taken was written since and is not touched, and a path
	 * that no longer resolves keeps everything as it is.
	 *
	 * @param array<string, array{path: string, md5: string}> $entries
	 * @return array<string, array{path: string, md5: string}>
	 */
	private function renameToFileIds(string $userId, ISimpleFolder $folder, array $entries): array {
		foreach ($entries as $key => $entry) {
			$key = (string)$key;
			if (ctype_digit($key) || $entry['path'] === '') {
				continue;
			}
			$id = Folders::fileId($this->rootFolder, $userId, $entry['path']);
			if ($id === null) {
				// The game is gone; its files keep the name they had.
				continue;
			}
			$clean = true;
			foreach ($this->suffixes() as $suffix) {
				if (!$folder->fileExists($key . $suffix)) {
					continue;
				}
				if ($folder->fileExists($id . $suffix)) {
					// Written since under the new name; the old file stays.
					$clean = false;
					continue;
				}
				$folder->newFile($id . $suffix, $folder->getFile($key . $suffix)->getContent());
				$folder->getFile($key . $suffix)->delete();
			}
			$this->gameMapper->importEntry($userId, (string)$id, $id, $entry['path'], $entry['md5']);
			$entries[(string)$id] ??= $entry;
			if ($clean) {
				$this->gameMapper->remove($userId, $key);
				unset($entries[$key]);
			}
		}
		return $entries;
	}

	/**
	 * Before the states were kept in a folder per user, they sat flat in the
	 * states root, under the hash of user and path. For every game the
	 * registry knows, matching flat files move into the user's folder under
	 * the current name. Unmatched flat files stay put.
	 *
	 * @param array<string, true> $flat
	 * @param array<string, array{path: string, md5: string}> $entries
	 */
	private function adoptFlatFiles(string $userId, ISimpleFolder $folder, ISimpleFolder $root, array &$flat, array $entries): void {
		if ($flat === []) {
			return;
		}
		foreach ($entries as $key => $entry) {
			if ($entry['path'] === '') {
				continue;
			}
			$legacy = hash('sha256', $userId . '|' . $entry['path']);
			foreach ($this->suffixes() as $suffix) {
				if (!isset($flat[$legacy . $suffix])) {
					continue;
				}
				if ($folder->fileExists($key . $suffix)) {
					// Saved since; the flat file stays where it is.
					continue;
				}
				$folder->newFile($key . $suffix, $root->getFile($legacy . $suffix)->getContent());
				$root->getFile($legacy . $suffix)->delete();
				unset($flat[$legacy . $suffix]);
			}
		}
	}

	/**
	 * Saves in the user's own saves folder were filed straight under the
	 * name of the game before the system became part of the path. A folder
	 * under the old layout moves under the system -- unless something
	 * already lives at the new place, in which case it stays.
	 *
	 * @param array<string, array{path: string, md5: string}> $entries
	 */
	private function moveSavesFolders(string $userId, array $entries): void {
		$savesPath = $this->settingsService->getUserSettings($userId)['saves_folder'] ?? '';
		if (!is_string($savesPath) || $savesPath === '') {
			return;
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$saves = Folders::folderAt($userFolder, trim($savesPath, '/'));
		} catch (\Throwable) {
			return;
		}
		if ($saves === null) {
			return;
		}
		// One listing says which old-layout folders there are at all.
		$present = [];
		foreach ($saves->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$present[$node->getName()] = true;
			}
		}

		$base = trim($savesPath, '/');
		foreach ($entries as $entry) {
			if ($entry['path'] === '') {
				continue;
			}
			$system = CoreMap::shortNameForPath($entry['path']);
			$stem = StateService::stemOf($entry['path']);
			if ($system === '' || !isset($present[$stem])) {
				// A game that does not say its system saves where it always did.
				continue;
			}
			// Where the service files the game today: "$base/$system/$stem".
			$gamePath = StateService::gameFolderPath($savesPath, $entry['path']);
			try {
				$node = $userFolder->get("$base/$stem");
				if (!$node instanceof Folder || $userFolder->nodeExists($gamePath)) {
					continue;
				}
				try {
					$target = $saves->get($system);
				} catch (NotFoundException) {
					$target = $saves->newFolder($system);
				}
				if (!$target instanceof Folder) {
					continue;
				}
				$node->move($userFolder->getPath() . '/' . $gamePath);
			} catch (\Throwable) {
				// Keeping the folder where it is beats losing the saves.
			}
		}
	}

	/**
	 * The plays used to be two JSON blobs of the user config: the counts
	 * under 'stats', the order under 'recent'. They go into the table, and
	 * the blobs go. A row that is already there has counted on since, and
	 * wins.
	 */
	private function convertPlays(IOutput $output): void {
		$stats = $this->userConfig->getValuesByUsers(Application::APP_ID, 'stats');
		$recent = $this->userConfig->getValuesByUsers(Application::APP_ID, 'recent');
		$userIds = array_unique([...array_keys($stats), ...array_keys($recent)]);
		foreach ($userIds as $userId) {
			$userId = (string)$userId;
			$userStats = $stats[$userId] ?? null;
			$userRecent = $recent[$userId] ?? null;
			$this->importPlays(
				$userId,
				is_string($userStats) ? $userStats : '',
				is_string($userRecent) ? $userRecent : '',
			);
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, 'stats');
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, 'recent');
		}
		if ($userIds !== []) {
			$output->info('Brought the play records of ' . count($userIds) . ' users into the table.');
		}
	}

	private function importPlays(string $userId, string $storedStats, string $storedRecent): void {
		$stats = json_decode($storedStats, true);
		$stats = is_array($stats) ? $stats : [];
		foreach ($stats as $id => $counted) {
			$id = (int)$id;
			if ($id === 0 || !is_array($counted)) {
				continue;
			}
			$this->playMapper->importPlay(
				$userId,
				$id,
				(int)($counted['plays'] ?? 0),
				(int)($counted['seconds'] ?? 0),
				(int)($counted['time'] ?? 0),
			);
		}

		// A game on the recent list was played even if its counts were
		// trimmed away; its place in the order is kept by spacing the
		// moments just below now.
		$recent = json_decode($storedRecent, true);
		$now = time();
		foreach (is_array($recent) ? array_values($recent) : [] as $index => $entry) {
			$id = (int)(is_array($entry) ? ($entry['id'] ?? 0) : 0);
			if ($id === 0 || isset($stats[$id]) || isset($stats[(string)$id])) {
				continue;
			}
			$this->playMapper->importPlay($userId, $id, 1, 0, $now - $index);
		}
	}

	/**
	 * Favorites used to be a list of ours, kept by path. They are the stars
	 * of the Files app now, so the ones that were set are handed over, and
	 * what those games were played for is kept.
	 */
	private function convertFavorites(IOutput $output): void {
		$favorites = $this->userConfig->getValuesByUsers(Application::APP_ID, 'favorites');
		$handed = 0;
		foreach ($favorites as $userId => $stored) {
			$userId = (string)$userId;
			$entries = is_string($stored) ? json_decode($stored, true) : null;
			$entries = is_array($entries) ? array_values($entries) : [];
			if ($entries !== []) {
				$tags = $this->tagManager->load('files', [], false, $userId);
				if ($tags === null) {
					// The blob stays, and the next upgrade tries again.
					continue;
				}
				foreach ($entries as $entry) {
					if (!is_array($entry)) {
						continue;
					}
					$path = (string)($entry['path'] ?? '');
					if ($path === '') {
						continue;
					}
					$id = Folders::fileId($this->rootFolder, $userId, $path);
					if ($id === null) {
						continue;
					}
					$tags->addToFavorites($id);
					$this->playMapper->importPlay(
						$userId,
						$id,
						(int)($entry['plays'] ?? 0),
						(int)($entry['seconds'] ?? 0),
						(int)($entry['time'] ?? 0),
					);
				}
			}
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, 'favorites');
			$handed++;
		}
		if ($handed > 0) {
			$output->info("Handed the favorites of $handed users to the Files app.");
		}
	}

	/**
	 * Every name a game's files can carry after its key, spelled by the
	 * service that writes them: one state and one screenshot per slot, and
	 * the battery save.
	 *
	 * @return list<string>
	 */
	private function suffixes(): array {
		$suffixes = [StateService::sramFileName('')];
		foreach (StateService::slots() as $slot) {
			$suffixes[] = StateService::fileName('', $slot, 'state');
			$suffixes[] = StateService::fileName('', $slot, 'png');
		}
		return $suffixes;
	}

}
