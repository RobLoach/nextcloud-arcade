<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\Db\GameMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserManager;

/**
 * Removes save states whose game or user is gone.
 *
 * The listeners take care of that as it happens, but a folder full of games
 * deleted in one go, or anything removed while the app was disabled, leaves
 * states behind that nothing points at any more. The same sweep answers to
 * `occ arcade:cleanup` and to the weekly background job.
 */
class CleanupService {
	public function __construct(
		private StateService $stateService,
		private GameMapper $gameMapper,
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * Walk everything the app stores and remove what belongs to games and
	 * users that no longer exist. Each removal is told to $report as it
	 * happens; with $dryRun nothing is removed, only reported and counted.
	 *
	 * @param ?\Closure(string): void $report told about each removal
	 * @return array{users: int, games: int, legacy: int}
	 */
	public function sweep(bool $dryRun = false, ?\Closure $report = null): array {
		$report ??= static function (string $line): void {
		};

		// The folders are named after the user they belong to, so the users
		// there are tell which folders are still spoken for.
		$keys = [];
		$this->userManager->callForAllUsers(function ($user) use (&$keys): void {
			$keys[$this->stateService->folderKeyOf($user->getUID())] = $user->getUID();
		});

		$users = 0;
		$games = 0;
		$cleaned = [];
		foreach ($this->stateService->userFolders() as $key => $folder) {
			$userId = $keys[$key] ?? null;
			if ($userId === null) {
				$report("Removing the states of a user that no longer exists ($key)");
				if (!$dryRun) {
					$folder->delete();
				}
				$users++;
				continue;
			}
			$cleaned[$userId] = true;
			$games += $this->cleanUser($userId, $dryRun, $report);
		}

		// A user whose saves live in their own saves folder leaves nothing
		// in the app data; the registry is what still names them.
		$existing = array_flip($keys);
		foreach (array_keys($this->gameMapper->countsByUser()) as $userId) {
			$userId = (string)$userId;
			if (isset($cleaned[$userId])) {
				continue;
			}
			if (!isset($existing[$userId])) {
				$report("Removing the states of a user that no longer exists ($userId)");
				if (!$dryRun) {
					$this->stateService->deleteAllForUser($userId);
				}
				$users++;
				continue;
			}
			$games += $this->cleanUser($userId, $dryRun, $report);
		}

		return [
			'users' => $users,
			'games' => $games,
			'legacy' => $this->stateService->countLegacyFiles(),
		];
	}

	/**
	 * @param \Closure(string): void $report
	 */
	private function cleanUser(string $userId, bool $dryRun, \Closure $report): int {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (NotFoundException|\Throwable) {
			return 0;
		}
		$removed = 0;
		foreach ($this->stateService->gamesOf($userId) as $key => $path) {
			if ($userFolder->nodeExists($path)) {
				continue;
			}
			// A game in the trash can come back; only one that is nowhere in
			// the user's storage at all is truly gone. The keys are the file
			// id, except for games that never had one.
			if (ctype_digit((string)$key) && $this->stillAround($userFolder, (int)$key)) {
				continue;
			}
			$report("Removing the states of $userId for $path");
			if (!$dryRun) {
				$this->stateService->deleteAllForGame($userId, $path);
			}
			$removed++;
		}
		return $removed;
	}

	/**
	 * Whether the file still exists anywhere in the user's storage -- the
	 * trash included, which is the whole point of asking by id.
	 */
	private function stillAround(Folder $userFolder, int $fileId): bool {
		try {
			return $userFolder->getParent()->getFirstNodeById($fileId) !== null;
		} catch (\Throwable) {
			// When in doubt, the saves stay.
			return true;
		}
	}
}
