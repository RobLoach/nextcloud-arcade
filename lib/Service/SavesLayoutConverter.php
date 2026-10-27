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
 * Not done in the upgrade itself: Nextcloud upgrades with the apps that
 * keep a file's versions and its trash not started, and every move then
 * logs an error. A user's saves are converted the first time anything asks
 * after them -- before any is read or written, so a game is never started
 * on a blank cartridge -- and a background job converts the rest.
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

	/** How long a request waits for another process moving the same saves. */
	private const LOCK_WAIT_MS = 30000;
	private const LOCK_POLL_MS = 250;

	/** What the player keeps in a saves folder now: not a game's folder. */
	private const OURS = [StateService::SAVES, StateService::STATES, 'config', 'thumbnails', 'manifest.server'];

	/** @var array<string, true> users seen to this request, converted or not */
	private array $seen = [];

	public function __construct(
		private IRootFolder $rootFolder,
		private IUserConfig $userConfig,
		private SettingsService $settingsService,
		private GameMapper $gameMapper,
		private CloudSyncManifest $manifest,
		private ILockingProvider $lockingProvider,
		private LoggerInterface $logger,
		private int $lockWaitMs = self::LOCK_WAIT_MS,
	) {
	}

	/**
	 * Convert a user's saves unless that was done already. Says how many
	 * files were moved and how many left where they were; null when there
	 * was nothing to do.
	 *
	 * Another process moving the same saves is waited for: answering from
	 * the new place before it is through would start a game on a blank
	 * cartridge.
	 *
	 * @return ?array{0: int, 1: int}
	 * @throws SavesBeingMovedException when the other process takes too long
	 */
	public function convertIfNeeded(string $userId): ?array {
		if (isset($this->seen[$userId])) {
			return null;
		}
		if ($this->userConfig->getValueBool($userId, Application::APP_ID, self::DONE)) {
			$this->seen[$userId] = true;
			return null;
		}
		$lock = 'arcade/saves-layout/' . $userId;
		$this->acquire($lock);
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
			// next; one whose new place is taken never will be.
			if ($failed === 0) {
				$this->userConfig->setValueBool($userId, Application::APP_ID, self::DONE, true);
			}
			return [$moved, $taken + $failed];
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @throws SavesBeingMovedException */
	private function acquire(string $lock): void {
		for ($waited = 0; ; $waited += self::LOCK_POLL_MS) {
			try {
				$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
				return;
			} catch (LockedException $e) {
				if ($waited >= $this->lockWaitMs) {
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

		$games = $this->knownGames($userId);

		$moved = 0;
		$taken = 0;
		$failed = 0;
		$changes = [];
		foreach ($this->gameFolders($root) as $relative => $folder) {
			$game = $this->gameOf($games, $relative);
			[$core, $stem] = $game === null
				? $this->guessed($relative, $folder)
				: $this->fromTheTable($game);
			[$plan, $extra] = $this->plan($folder, $stem);
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
	 * The games the table knows, by the folder those versions kept their
	 * saves in: exactly, as they wrote it, and without regard to case, as
	 * they also found it -- and by the game's name alone, for a folder from
	 * before the system was part of the path.
	 *
	 * @return array{exact: array<string, array>, folded: array<string, array>, byName: array<string, list<array>>}
	 */
	private function knownGames(string $userId): array {
		$games = ['exact' => [], 'folded' => [], 'byName' => []];
		foreach ($this->gameMapper->entriesOf($userId) as $entry) {
			$path = $entry['path'];
			$system = CoreMap::shortNameForPath($path);
			$stem = StateService::stemOf($path);
			$relative = $system === '' ? $stem : "$system/$stem";
			$games['exact'][$relative] = $entry;
			$games['folded'][mb_strtolower($relative)] ??= $entry;
			$games['byName'][mb_strtolower($stem)][] = $entry;
		}
		return $games;
	}

	/**
	 * The game a folder of saves belongs to, or null when the table knows
	 * none -- or more than one by that name, which is no answer either.
	 *
	 * @param array{exact: array<string, array>, folded: array<string, array>, byName: array<string, list<array>>} $games
	 * @return ?array{path: string, md5: string, core: string, name: string}
	 */
	private function gameOf(array $games, string $relative): ?array {
		$byName = $games['byName'][mb_strtolower(basename($relative))] ?? [];
		return $games['exact'][$relative]
			?? $games['folded'][mb_strtolower($relative)]
			?? (count($byName) === 1 ? $byName[0] : null);
	}

	/**
	 * The folders of games in the old layout, by their path under the saves
	 * folder: one under each system folder, and one at the top for a game
	 * no system could be told for.
	 *
	 * @return array<string, Folder>
	 */
	private function gameFolders(Folder $root): array {
		$games = [];
		foreach ($root->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (!$node instanceof Folder || in_array($name, self::OURS, true) || str_starts_with($name, '.')) {
				continue;
			}
			if ($this->isSystemFolder($name)) {
				foreach ($node->getDirectoryListing() as $game) {
					if ($game instanceof Folder && $this->holdsSaves($game)) {
						$games["$name/{$game->getName()}"] = $game;
					}
				}
			} elseif ($this->holdsSaves($node)) {
				$games[$name] = $node;
			}
		}
		return $games;
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

	/**
	 * The core and the name of a game the table knows: the core the player
	 * noted, or the one its path names; the name its saves go by, or the
	 * file's own.
	 *
	 * @param array{path: string, md5: string, core: string, name: string} $game
	 * @return array{0: string, 1: string}
	 */
	private function fromTheTable(array $game): array {
		return [
			CoreMap::CORE_NAMES[$game['core']] ?? CoreMap::coreNameForPath($game['path']),
			$game['name'] !== '' ? $game['name'] : StateService::stemOf($game['path']),
		];
	}

	/**
	 * The core and the name of a game the table does not know, read off
	 * the folders: the system folder names the core, the game's folder
	 * its name.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function guessed(string $relative, Folder $folder): array {
		$parts = explode('/', $relative);
		$core = '';
		if (count($parts) === 2) {
			foreach (CoreMap::SYSTEMS as $system) {
				if ($system['short'] === $parts[0]) {
					$core = CoreMap::CORE_NAMES[$system['core']] ?? '';
					break;
				}
			}
		}
		return [$core, $folder->getName()];
	}

	/**
	 * Where each file of a game's folder goes, as [file, kind, new name],
	 * and the battery saves that go nowhere. A game has only one: the one
	 * named after it, or else the first there is, as those versions read
	 * it; the others are left where they are.
	 *
	 * @return array{0: list<array{0: File, 1: string, 2: string}>, 1: list<File>}
	 */
	private function plan(Folder $folder, string $stem): array {
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
		usort($batteries, fn (File $a, File $b): int => (int)($b->getName() === "{$folder->getName()}.srm") - (int)($a->getName() === "{$folder->getName()}.srm"));
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
