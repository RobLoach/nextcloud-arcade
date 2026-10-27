<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\CoreMap;
use OCA\Arcade\Db\GameMapper;
use OCP\Config\IUserConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Lock\ILockingProvider;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Brings the saves of versions up to 0.48.7 over to the layout RetroArch
 * keeps, which is the only one read now.
 *
 * Those versions filed a game's saves in a folder of its own, under the
 * system it belongs to:
 *
 *   Saves/Nintendo/Mario/Auto.state, Slot 1.state, Slot 1.png, Mario.srm
 *
 * RetroArch files them by kind and by the core that plays them:
 *
 *   Saves/states/FCEUmm/Mario.state.auto, Mario.state1, Mario.state1.png
 *   Saves/saves/FCEUmm/Mario.srm
 *
 * Nothing reads the old folders any more, so without this every save state
 * and every in-game save made before the upgrade would be lost to the
 * player -- and the first save after it would start the game afresh.
 *
 * Done in the user's own request, and only there: the first time anything
 * asks after their saves -- the library, the sidebar, the player -- and
 * before any is read or written, so a game is never started on a blank
 * cartridge. Not in the upgrade, nor in a background job or occ, nor in
 * an administrator's request that happens to touch them: Nextcloud keeps
 * a file's versions with it through a move only in a request of its
 * owner, and elsewhere leaves them behind under the old path, with an
 * error logged for every folder emptied. Nothing reads the old layout in
 * the meantime, so nothing is lost by waiting for the user to come by.
 *
 * Which game a folder holds is read off the games table where it can be:
 * the path noted there names the system and the core. A zip's saves go by
 * the name of the game inside where it is noted, and by the zip's own
 * name where it is not -- StateService renames them the first time the
 * player says which game is inside, so no zip is opened here, in the
 * middle of somebody's request. A folder the table knows nothing of goes
 * by its own names.
 *
 * Nothing is overwritten and nothing is lost: a file that would land on
 * one already there, or cannot be moved, stays where it is, and is
 * logged. A user is converted once -- once nothing failed that might go
 * through on another try.
 */
class SavesLayoutConverter {
	/** In the user config: the user's saves are in the layout RetroArch keeps. */
	private const DONE = 'saves_layout_converted';

	/** In the user config: how many runs could not move everything. */
	private const ATTEMPTS = 'saves_layout_attempts';
	/** Runs after which what cannot be moved is left where it is for good. */
	private const MAX_ATTEMPTS = 3;

	/** How long a request waits for another process moving the same saves. */
	private const LOCK_WAIT_MS = 30000;
	private const LOCK_POLL_MS = 250;

	/** What the player keeps in a saves folder now: not a game's folder. */
	private const OURS = [StateService::SAVES, StateService::STATES, 'config', 'thumbnails', 'manifest.server'];

	/** @var array<string, true> users seen to this request, converted or not */
	private array $seen = [];
	/** @var array<string, true> users whose move was waited for in vain this request */
	private array $timedOut = [];

	public function __construct(
		private IRootFolder $rootFolder,
		private IUserConfig $userConfig,
		private SettingsService $settingsService,
		private GameMapper $gameMapper,
		private CloudSyncManifest $manifest,
		private ILockingProvider $lockingProvider,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private int $lockWaitMs = self::LOCK_WAIT_MS,
	) {
	}

	/**
	 * Convert a user's saves unless that was done already. Says how many
	 * files were moved and how many left where they were; null when there
	 * was nothing to do.
	 *
	 * With $wait, another process moving the same saves is waited for, as a
	 * game being loaded or saved must: answered from the new place before it
	 * is through, it would start on a blank cartridge, and be saved over the
	 * real save. Without, the asking goes on at once with what is moved so
	 * far -- a listing of the library has nothing to lose by that -- and the
	 * next asking tries again.
	 *
	 * @return ?array{0: int, 1: int}
	 * @throws SavesBeingMovedException when waited for, and the other
	 *                                  process takes too long
	 */
	public function convertIfNeeded(string $userId, bool $wait = true): ?array {
		if (isset($this->seen[$userId])) {
			return null;
		}
		// Only the user's own request moves their saves: see above.
		if ($this->userSession->getUser()?->getUID() !== $userId) {
			return null;
		}
		// Waited for once in vain, not waited for again this request: a
		// request deleting fifty games would otherwise wait fifty times.
		if (isset($this->timedOut[$userId])) {
			if ($wait) {
				throw new SavesBeingMovedException('The saves are still being moved to the new layout');
			}
			return null;
		}
		if ($this->userConfig->getValueBool($userId, Application::APP_ID, self::DONE)) {
			$this->seen[$userId] = true;
			return null;
		}
		$lock = 'arcade/saves-layout/' . $userId;
		try {
			$this->acquire($lock, $wait ? $this->lockWaitMs : 0);
		} catch (SavesBeingMovedException $e) {
			if ($wait) {
				$this->timedOut[$userId] = true;
				throw $e;
			}
			return null;
		}
		$this->seen[$userId] = true;
		try {
			// Asked again inside the lock: the one that held it may have
			// just finished.
			$this->userConfig->clearCache($userId);
			if ($this->userConfig->getValueBool($userId, Application::APP_ID, self::DONE)) {
				return null;
			}
			$counts = $this->convert($userId);
			if ($counts === null) {
				return null;
			}
			[$moved, $taken, $failed] = $counts;
			// A file that could not be moved this time may be moved the
			// next; one whose new place is taken never will be. Nor will one
			// that failed run after run -- on a share it may not be moved
			// from, say -- and it is left where it is rather than have every
			// request of the user go through the whole move again.
			$attempts = $failed === 0 ? 0 : $this->userConfig->getValueInt($userId, Application::APP_ID, self::ATTEMPTS) + 1;
			if ($failed === 0 || $attempts >= self::MAX_ATTEMPTS) {
				if ($failed > 0) {
					$this->logger->warning("Gave up moving $failed saves of an earlier version after $attempts tries; they are where they were, and listed above", ['user' => $userId]);
				}
				$this->userConfig->setValueBool($userId, Application::APP_ID, self::DONE, true);
				$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::ATTEMPTS);
			} else {
				$this->userConfig->setValueInt($userId, Application::APP_ID, self::ATTEMPTS, $attempts);
			}
			return [$moved, $taken + $failed];
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @throws SavesBeingMovedException when still taken after $waitMs */
	private function acquire(string $lock, int $waitMs): void {
		for ($waited = 0; ; $waited += self::LOCK_POLL_MS) {
			try {
				$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
				return;
			} catch (LockedException $e) {
				if ($waited >= $waitMs) {
					throw new SavesBeingMovedException('The saves are still being moved to the new layout', 0, $e);
				}
				usleep(self::LOCK_POLL_MS * 1000);
			}
		}
	}

	/**
	 * @return ?array{0: int, 1: int, 2: int} files moved, files left because
	 *                                        their new place is taken, and
	 *                                        files that could not be moved;
	 *                                        null without a saves folder
	 */
	private function convert(string $userId): ?array {
		// Without a saves folder there is nothing to convert yet -- and the
		// question is asked again once there is one.
		$savesPath = $this->settingsService->getUserSettings($userId)['saves_folder'] ?? '';
		if (!is_string($savesPath) || $savesPath === '') {
			return null;
		}
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$root = Folders::folderAt($userFolder, $savesPath);
		if ($root === null) {
			return null;
		}

		$moved = 0;
		$taken = 0;
		$failed = 0;
		$changes = [];
		foreach ($this->oldFolders($userId, $userFolder, $root) as $relative => [$folder, $core, $stem, $batteryNames]) {
			[$plan, $extra] = $this->plan($folder, $stem, $batteryNames);
			foreach ($extra as $file) {
				$this->logger->warning('A battery save of an earlier version was left where it is: the game has another', [
					'user' => $userId,
					'from' => "$relative/{$file->getName()}",
				]);
				$taken++;
			}
			foreach ($plan as [$file, $kind, $name]) {
				$dir = $core === '' ? $kind : "$kind/$core";
				$target = "$dir/$name";
				try {
					$into = Folders::make($root, $dir);
					if ($into === null || $into->nodeExists($name)) {
						$this->logger->warning('A save of an earlier version was left where it is: the new place is taken', [
							'user' => $userId,
							'from' => "$relative/{$file->getName()}",
							'to' => $target,
						]);
						$taken++;
						continue;
					}
					$hash = Folders::md5Of($file);
					$file->move($into->getPath() . '/' . $name);
					$changes[$target] = $hash;
					$moved++;
				} catch (\Throwable $e) {
					$this->logger->warning('A save of an earlier version could not be moved, and was left where it is', [
						'user' => $userId,
						'from' => "$relative/{$file->getName()}",
						'to' => $target,
						'exception' => $e,
					]);
					$failed++;
				}
			}
			$this->removeIfEmpty($folder);
		}
		// RetroArch only fetches what the manifest says is there.
		if ($changes !== []) {
			try {
				$this->manifest->record($root, $changes);
			} catch (\Throwable $e) {
				$this->logger->warning('The moved saves could not be recorded for RetroArch Cloud Sync', ['user' => $userId, 'exception' => $e]);
			}
		}
		// A system folder emptied of its games goes too.
		foreach ($root->getDirectoryListing() as $node) {
			if ($node instanceof Folder && $this->isSystemFolder($node->getName())) {
				$this->removeIfEmpty($node);
			}
		}
		return [$moved, $taken, $failed];
	}

	/**
	 * The folders of the old layout to move, each with the core and the name
	 * its saves go by now, and the names its battery save may have, the
	 * likeliest first.
	 *
	 * Found exactly as those versions found a game's folder when loading it,
	 * so whatever they loaded for a game is what is moved for it:
	 *
	 *  1. The folder of the game's path as it is now --
	 *     <saves>/<system>/<name>, or <saves>/<name> for a game of no system
	 *     they could tell, with the case as it is.
	 *  2. Failing that, the folder of the path the game had when it was last
	 *     saved, which they followed to a renamed or moved game.
	 *
	 * Two games of the same name and system shared one folder; it goes to
	 * the first, and the second, going by the same names, finds the saves
	 * all the same.
	 *
	 * A folder in a system folder that no game claims -- a game deleted
	 * since -- moves by its own names: that is the folder those versions
	 * would have loaded had the game come back, and now it is found the
	 * same way. A folder at the top that no game claims is left alone:
	 * they never loaded it for any game with a system, and moved, it would
	 * be found by games they did not give it to.
	 *
	 * @return array<string, array{0: Folder, 1: string, 2: string, 3: list<string>}>
	 */
	private function oldFolders(string $userId, Folder $userFolder, Folder $root): array {
		$entries = $this->gameMapper->entriesOf($userId);
		$current = [];
		foreach (array_keys($entries) as $key) {
			$current[$key] = $this->currentPath($userFolder, (string)$key);
		}

		$folders = [];
		// Every game's folder of its name now first, and only then a folder
		// it was followed to: as those versions, which only went looking for
		// a game's old folder once the one of its name now was not there. A
		// game renamed away from Mario.nes does not get the folder of the new
		// Mario.nes for having been looked at first.
		$served = [];
		foreach ($entries as $key => $entry) {
			$path = $current[$key];
			if ($path === null) {
				continue;
			}
			$relative = self::oldFolderOf($path);
			// A game of no system named like a folder of the new layout --
			// saves.zip -- is not to have that folder taken for its own.
			if (in_array($relative, self::OURS, true)) {
				continue;
			}
			$folder = Folders::folderAt($root, $relative);
			if ($folder === null) {
				continue;
			}
			// There, so loaded: not followed anywhere else, even when it is
			// another game's of the same name too, or holds nothing.
			$served[$key] = true;
			if (!$this->holdsSaves($folder)) {
				continue;
			}
			if (isset($folders[$relative])) {
				// Shared with a game of the same name and system, as those
				// versions shared it: a zip among them is told so too.
				$this->noteZip($userId, (string)$key, $entry, $path);
			} else {
				$folders[$relative] = $this->claim($userId, (string)$key, $entry, $path, $folder);
			}
		}
		foreach ($entries as $key => $entry) {
			$was = $entry['path'];
			if (isset($served[$key]) || $was === '' || $was === $current[$key]) {
				continue;
			}
			$relative = self::oldFolderOf($was);
			if (in_array($relative, self::OURS, true)) {
				continue;
			}
			$folder = Folders::folderAt($root, $relative);
			if ($folder === null || !$this->holdsSaves($folder)) {
				continue;
			}
			if (isset($folders[$relative])) {
				$this->noteZip($userId, (string)$key, $entry, $current[$key] ?? $was);
			} else {
				$folders[$relative] = $this->claim($userId, (string)$key, $entry, $current[$key] ?? $was, $folder);
			}
		}

		foreach ($root->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (!$node instanceof Folder || in_array($name, self::OURS, true) || str_starts_with($name, '.')) {
				continue;
			}
			if (!$this->isSystemFolder($name)) {
				if (!isset($folders[$name]) && $this->holdsSaves($node)) {
					$this->logger->warning('Saves of an earlier version were left where they are: no game had them at the top of the saves folder', [
						'user' => $userId,
						'from' => $name,
					]);
				}
				continue;
			}
			foreach ($node->getDirectoryListing() as $game) {
				$relative = "$name/{$game->getName()}";
				if (!$game instanceof Folder || isset($folders[$relative]) || !$this->holdsSaves($game)) {
					continue;
				}
				$folders[$relative] = [$game, $this->coreOfSystemFolder($name), $game->getName(), [$game->getName() . '.srm']];
			}
		}
		return $folders;
	}

	/**
	 * The name a game's saves go by, as noted with it. A zip whose game inside
	 * is not noted yet goes by the zip's own name, which its saves are moved
	 * under -- and that is noted with it, so StateService knows they are this
	 * zip's to rename once the player says which game is inside.
	 *
	 * @param array{path: string, md5: string, core: string, name: string} $entry
	 */
	private function noteZip(string $userId, string $key, array $entry, string $romPath): string {
		if ($entry['name'] !== '' || strtolower(pathinfo($romPath, PATHINFO_EXTENSION)) !== 'zip') {
			return $entry['name'];
		}
		$stem = StateService::stemOf($romPath);
		$this->gameMapper->set($userId, $key, ctype_digit($key) ? (int)$key : 0, $romPath, $entry['md5'], '', $stem);
		return $stem;
	}

	/**
	 * A game's folder, with the core and the name its saves go by now and
	 * the names its battery save may have.
	 *
	 * A zip whose game inside is not noted yet has its saves go by the zip's
	 * own name -- and that is noted with it, so StateService knows they are
	 * this zip's to rename once the player says which game is inside.
	 *
	 * @param array{path: string, md5: string, core: string, name: string} $entry
	 * @return array{0: Folder, 1: string, 2: string, 3: list<string>}
	 */
	private function claim(string $userId, string $key, array $entry, string $romPath, Folder $folder): array {
		$stem = StateService::stemOf($romPath);
		$name = $this->noteZip($userId, $key, $entry, $romPath);
		return [
			$folder,
			CoreMap::CORE_NAMES[$entry['core']] ?? CoreMap::coreNameForPath($romPath),
			$name !== '' ? $name : $stem,
			array_values(array_unique([$stem . '.srm', $folder->getName() . '.srm'])),
		];
	}

	/**
	 * Where those versions kept a game's saves, under the saves folder: by
	 * the same CoreMap, which names systems and folders as it did then.
	 */
	private static function oldFolderOf(string $romPath): string {
		$system = CoreMap::shortNameForPath($romPath);
		$stem = StateService::stemOf($romPath);
		return $system === '' ? $stem : "$system/$stem";
	}

	/** Where a game is now, by the file id it is filed under; null when gone. */
	private function currentPath(Folder $userFolder, string $key): ?string {
		if (!ctype_digit($key)) {
			return null;
		}
		try {
			$node = $userFolder->getFirstNodeById((int)$key);
			return $node instanceof File ? $userFolder->getRelativePath($node->getPath()) : null;
		} catch (\Throwable) {
			return null;
		}
	}

	private function isSystemFolder(string $name): bool {
		foreach (CoreMap::SYSTEMS as $system) {
			if ($system['short'] === $name) {
				return true;
			}
		}
		return false;
	}

	private function holdsSaves(Folder $folder): bool {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File && self::oldKind($node->getName()) !== null) {
				return true;
			}
		}
		return false;
	}

	private function coreOfSystemFolder(string $name): string {
		foreach (CoreMap::SYSTEMS as $system) {
			if ($system['short'] === $name) {
				return CoreMap::CORE_NAMES[$system['core']] ?? '';
			}
		}
		return '';
	}

	/**
	 * Where each file of a game's folder goes, as [file, kind, new name],
	 * and the battery saves that go nowhere. A game has only one: the first
	 * of the names given that is there, or else the first there is, as
	 * those versions read it; the others are left where they are.
	 *
	 * @param list<string> $batteryNames the names to look for, likeliest first
	 * @return array{0: list<array{0: File, 1: string, 2: string}>, 1: list<File>}
	 */
	private function plan(Folder $folder, string $stem, array $batteryNames): array {
		$plan = [];
		$batteries = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (!$node instanceof File) {
				continue;
			}
			$old = self::oldKind($node->getName());
			if ($old === null) {
				continue;
			}
			[$kind, $slot, $picture] = $old;
			if ($kind === StateService::SAVES) {
				$batteries[] = $node;
				continue;
			}
			$plan[] = [$node, StateService::STATES, StateService::stateName($stem, $slot) . ($picture ? '.png' : '')];
		}
		$rank = static function (File $file) use ($batteryNames): int {
			$at = array_search($file->getName(), $batteryNames, true);
			return $at === false ? count($batteryNames) : $at;
		};
		usort($batteries, static fn (File $a, File $b): int => $rank($a) <=> $rank($b));
		if ($batteries !== []) {
			$plan[] = [array_shift($batteries), StateService::SAVES, "$stem.srm"];
		}
		return [$plan, $batteries];
	}

	/**
	 * What a file of the old layout is: a state of a slot, its picture, or
	 * the battery save. Null for anything else, which is left alone.
	 *
	 * @return ?array{0: string, 1: int, 2: bool} kind, slot, whether a picture
	 */
	private static function oldKind(string $name): ?array {
		if (preg_match('/^(Auto|Slot (\d+))\.(state|png)$/', $name, $match) === 1) {
			$slot = $match[1] === 'Auto' ? StateService::AUTO_SLOT : (int)$match[2];
			return [StateService::STATES, $slot, $match[3] === 'png'];
		}
		if (str_ends_with(strtolower($name), '.srm')) {
			return [StateService::SAVES, 0, false];
		}
		return null;
	}

	private function removeIfEmpty(Folder $folder): void {
		try {
			if ($folder->getDirectoryListing() === []) {
				$folder->delete();
			}
		} catch (\Throwable) {
			// An empty folder left behind is no loss.
		}
	}
}
