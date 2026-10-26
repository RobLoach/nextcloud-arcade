<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\Db\GameMapper;
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
 *
 * The weekly job also puts the Cloud Sync manifests right, which is the
 * other thing that drifts while nobody is looking.
 */
class CleanupService {
	public function __construct(
		private StateService $stateService,
		private GameMapper $gameMapper,
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
		private UserFilesystems $userFilesystems,
	) {
	}

	/**
	 * Walk everything the app stores and remove what belongs to games and
	 * users that no longer exist. Each removal is told to $report as it
	 * happens; with $dryRun nothing is removed, only reported and counted.
	 *
	 * With $legacy, what versions before 0.48.8 left in the app data goes
	 * too, all of it: nothing reads it any more.
	 *
	 * @param ?\Closure(string): void $report told about each removal
	 * @return array{users: int, games: int, appData: array{files: int, bytes: int}, appDataRemoved: int}
	 *         appData is what is left there afterwards
	 */
	public function sweep(bool $dryRun = false, ?\Closure $report = null, bool $legacy = false): array {
		$report ??= static function (string $line): void {
		};

		// The folders are named after the user they belong to, so the users
		// there are tell which folders are still spoken for. Only a user who
		// has signed in at least once -- "seen", answered from the local
		// database -- can have a folder, where callForAllUsers would also ask
		// every backend (an LDAP one at length) about users who cannot.
		$keys = [];
		$this->userManager->callForSeenUsers(function ($user) use (&$keys): void {
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

		$appData = $this->stateService->appDataSaves();
		$appDataRemoved = 0;
		if ($legacy && $appData['files'] > 0) {
			$report("Removing the {$appData['files']} save files earlier versions left in the app data");
			if (!$dryRun) {
				$appDataRemoved = $this->stateService->deleteAppDataSaves();
				$appData = $this->stateService->appDataSaves();
			}
		}

		return [
			'users' => $users,
			'games' => $games,
			'appData' => $appData,
			'appDataRemoved' => $appDataRemoved,
		];
	}

	/**
	 * Bring the Cloud Sync manifest of every user with a saves folder, or
	 * of the one named, in line with what the folder holds: the files that
	 * got there some other way than through the player are recorded, and
	 * the ones that are gone recorded as deleted.
	 *
	 * @param ?string $userId the one user, or null for everybody seen
	 * @param bool $full whether to hash every file, not only the new ones
	 * @param ?\Closure(string): void $report told about each user put right
	 * @return array{users: int, recorded: int, removed: int}
	 */
	public function reconcileManifests(?string $userId = null, bool $full = false, ?\Closure $report = null): array {
		$report ??= static function (string $line): void {
		};
		$userIds = [];
		if ($userId !== null) {
			$userIds[] = $userId;
		} else {
			$this->userManager->callForSeenUsers(function ($user) use (&$userIds): void {
				$userIds[] = $user->getUID();
			});
		}

		$totals = ['users' => 0, 'recorded' => 0, 'removed' => 0];
		foreach ($userIds as $each) {
			try {
				$counts = $this->userFilesystems->forUser($each, fn () => $this->stateService->reconcile($each, $full));
			} catch (\Throwable $e) {
				// One user's broken manifest is theirs to sort out, and no
				// reason to leave everybody else's as it is.
				$report("Could not put the Cloud Sync manifest of $each right: {$e->getMessage()}");
				continue;
			}
			if ($counts === null || ($counts['recorded'] === 0 && $counts['removed'] === 0)) {
				continue;
			}
			$report("Recorded {$counts['recorded']} and marked {$counts['removed']} as deleted in the Cloud Sync manifest of $each");
			$totals['users']++;
			$totals['recorded'] += $counts['recorded'];
			$totals['removed'] += $counts['removed'];
		}
		return $totals;
	}

	/**
	 * @param \Closure(string): void $report
	 */
	private function cleanUser(string $userId, bool $dryRun, \Closure $report): int {
		// One user's files held at a time, however many users there are.
		return $this->userFilesystems->forUser($userId, fn (): int => $this->cleanFilesOf($userId, $dryRun, $report));
	}

	/**
	 * @param \Closure(string): void $report
	 */
	private function cleanFilesOf(string $userId, bool $dryRun, \Closure $report): int {
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
			if (ctype_digit((string)$key) && Folders::stillAround($this->rootFolder, $userId, (int)$key)) {
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
}
