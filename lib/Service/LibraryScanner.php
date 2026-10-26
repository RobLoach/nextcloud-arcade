<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\BackgroundJob\RefreshMetadata;
use OCA\Arcade\BackgroundJob\ScanLibraries;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Scanning a games library afresh, the way the Refresh button of the
 * library page does: the folder walked again rather than answered from
 * the cache, and what the games say about themselves read once more in
 * the background -- Nextcloud only reads that when a file is written.
 *
 * The settings pages ask for it from beside the library folder: a player
 * for their own library, an administrator for everybody's.
 */
class LibraryScanner {
	public function __construct(
		private SettingsService $settingsService,
		private LibraryService $libraryService,
		private IRootFolder $rootFolder,
		private IJobList $jobList,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Scan the library of one user now. Says how many games it holds, or
	 * null when the library folder is not there.
	 */
	public function scan(string $userId): ?int {
		$settings = $this->settingsService->getUserSettings($userId);
		$path = (string)$settings['library_folder'];
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$folder = Folders::folderAt($userFolder, $path);
		if ($folder === null) {
			return null;
		}
		$games = $this->libraryService->getGames($userId, $folder, $userFolder, $path, $settings, true);
		$this->readMetadata($userId);
		return count($games);
	}

	/**
	 * Scan the library of everybody, in the background: one request is no
	 * place to walk every library of an instance. Says whether it was
	 * queued, rather than already waiting.
	 */
	public function scanEveryone(): bool {
		if ($this->jobList->has(ScanLibraries::class, null)) {
			return false;
		}
		$this->jobList->add(ScanLibraries::class, null);
		return true;
	}

	/**
	 * What the background job runs: the library of every user who has
	 * signed in, one after another. A library that cannot be scanned is
	 * logged and the rest carry on. Says how many were scanned.
	 */
	public function scanAll(): int {
		$scanned = 0;
		$this->userManager->callForSeenUsers(function (IUser $user) use (&$scanned): void {
			try {
				if ($this->scan($user->getUID()) !== null) {
					$scanned++;
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Could not scan a games library', [
					'user' => $user->getUID(),
					'exception' => $e,
				]);
			}
		});
		return $scanned;
	}

	/** Read again what the games of a user say about themselves, once. */
	private function readMetadata(string $userId): void {
		$argument = ['userId' => $userId];
		if (!$this->jobList->has(RefreshMetadata::class, $argument)) {
			$this->jobList->add(RefreshMetadata::class, $argument);
		}
	}
}
