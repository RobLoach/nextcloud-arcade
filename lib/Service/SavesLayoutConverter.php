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
 * the path noted there names the system, the core, and for a zip the game
 * inside, which is what its saves go by now. A folder the table knows
 * nothing of goes by its own names.
 *
 * Nothing is overwritten and nothing is lost: a file that would land on
 * one already there, or cannot be moved, stays where it is, and is
 * logged. Each user is converted once.
 */
class SavesLayoutConverter {
	/** In the user config: the user's saves are in the layout RetroArch keeps. */
	private const DONE = 'saves_layout_converted';

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
	) {
	}

	/**
	 * Convert a user's saves unless that was done already. Says how many
	 * files were moved and how many left where they were; null when there
	 * was nothing to do, or another process is at it right now.
	 *
	 * @return ?array{0: int, 1: int}
	 */
	public function convertIfNeeded(string $userId): ?array {
		if (isset($this->seen[$userId])) {
			return null;
		}
		$this->seen[$userId] = true;
		if ($this->userConfig->getValueBool($userId, Application::APP_ID, self::DONE)) {
			return null;
		}
		$lock = 'arcade/saves-layout/' . $userId;
		try {
			$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			// Converted this very moment by another request or the job.
			return null;
		}
		try {
			// Asked again inside the lock: the one that held it may have
			// just finished.
			$this->userConfig->clearCache($userId);
			if ($this->userConfig->getValueBool($userId, Application::APP_ID, self::DONE)) {
				return null;
			}
			$counts = $this->convert($userId);
			if ($counts !== null) {
				$this->userConfig->setValueBool($userId, Application::APP_ID, self::DONE, true);
			}
			return $counts;
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * @return ?array{0: int, 1: int} files moved, and files left where they
	 *                                were; null without a saves folder
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

		// The folder each known game's saves were in, by its path under the
		// saves folder -- the way those versions worked it out.
		$known = [];
		foreach ($this->gameMapper->entriesOf($userId) as $key => $entry) {
			$path = $entry['path'];
			$system = CoreMap::shortNameForPath($path);
			$stem = StateService::stemOf($path);
			$known[$system === '' ? $stem : "$system/$stem"] = ['key' => (string)$key, 'path' => $path, 'core' => $entry['core'], 'md5' => $entry['md5']];
		}

		$moved = 0;
		$kept = 0;
		$changes = [];
		foreach ($this->gameFolders($root) as $relative => $folder) {
			$game = $known[$relative] ?? null;
			[$core, $stem] = $game === null
				? $this->guessed($relative, $folder)
				: $this->fromTheTable($userId, $userFolder, $game);
			foreach ($this->plan($folder, $stem) as [$file, $kind, $name]) {
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
						$kept++;
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
					$kept++;
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
		return [$moved, $kept];
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
	 * The core and the name of a game the table knows: from its path, the
	 * core the player noted, and for a zip the game inside it.
	 *
	 * @param array{key: string, path: string, core: string, md5: string} $game
	 * @return array{0: string, 1: string}
	 */
	private function fromTheTable(string $userId, Folder $userFolder, array $game): array {
		$core = CoreMap::CORE_NAMES[$game['core']] ?? CoreMap::coreNameForPath($game['path']);
		$stem = StateService::stemOf($game['path']);
		if (strtolower(pathinfo($game['path'], PATHINFO_EXTENSION)) === 'zip') {
			$inside = $this->gameInside($userFolder, $game['path']);
			if ($inside !== null) {
				$stem = $inside;
				// Noted, so the saves are found under it from the start,
				// before the player has opened the zip to say so itself.
				$fileId = ctype_digit($game['key']) ? (int)$game['key'] : 0;
				// The checksum goes back as it was: it is what tells a state
				// made from another dump of the game.
				$this->gameMapper->set($userId, $game['key'], $fileId, $game['path'], $game['md5'], '', $inside);
			}
		}
		return [$core, $stem];
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
	 * Where each file of a game's folder goes, as [file, kind, new name].
	 * Only one battery save: the one named after the game, or else the
	 * one there is, as those versions read it.
	 *
	 * @return list<array{0: File, 1: string, 2: string}>
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
			$plan[] = [$batteries[0], StateService::SAVES, "$stem.srm"];
		}
		return $plan;
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

	/**
	 * The name of the game in a zip, the way the player picks it: the first
	 * file whose extension names a system, or else the largest. Null when
	 * the zip cannot be read here.
	 */
	private function gameInside(Folder $userFolder, string $path): ?string {
		if (!class_exists(\ZipArchive::class)) {
			return null;
		}
		try {
			$zip = $userFolder->get($path);
			if (!$zip instanceof File) {
				return null;
			}
			$local = $zip->getStorage()->getLocalFile($zip->getInternalPath());
		} catch (\Throwable $e) {
			$this->logger->debug('Could not open a zip to name its saves', ['exception' => $e]);
			return null;
		}
		$archive = new \ZipArchive();
		if (!is_string($local) || $archive->open($local, \ZipArchive::RDONLY) !== true) {
			return null;
		}
		$extensions = CoreMap::extensionSystemMap();
		$largest = null;
		$largestSize = -1;
		try {
			for ($i = 0; $i < $archive->numFiles; $i++) {
				$entry = $archive->statIndex($i);
				$name = is_array($entry) ? (string)$entry['name'] : '';
				if ($name === '' || str_ends_with($name, '/')) {
					continue;
				}
				if (isset($extensions[strtolower(pathinfo($name, PATHINFO_EXTENSION))])) {
					return pathinfo(basename($name), PATHINFO_FILENAME);
				}
				if ((int)$entry['size'] > $largestSize) {
					$largestSize = (int)$entry['size'];
					$largest = $name;
				}
			}
		} finally {
			$archive->close();
		}
		return $largest === null ? null : pathinfo(basename($largest), PATHINFO_FILENAME);
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
