<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\BackgroundJob\RefreshMetadata;
use OCA\Arcade\BackgroundJob\ScanLibraries;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Cache\IScanner;
use OCP\Files\Folder;
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
		private UserFilesystems $userFilesystems,
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
		$this->scanFiles($folder);
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
				// Each library let go of once scanned, so the walk holds one
				// user's files at a time rather than everybody's.
				if ($this->userFilesystems->forUser($user, fn () => $this->scan($user->getUID())) !== null) {
					$scanned++;
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Could not scan a games library', [
					'user' => $user->getUID(),
					'exception' => $e,
				]);
			}
		});
		// The scans just made are kept in the cache of this process. On a
		// server whose cache is APCu that is the background job's own, and
		// the web server would go on answering from what it had before --
		// a library whose folder did not change, but whose games now read
		// differently, would not show it for a day. Every scan is taken
		// afresh on its next visit instead, wherever its cache is.
		if ($scanned > 0) {
			$this->libraryService->forgetEveryScan();
		}
		return $scanned;
	}

	/**
	 * Have Nextcloud look at the library folder on disk again first. Games
	 * copied there some other way than through Nextcloud -- a network
	 * share, rsync, a drive mounted in -- are not in its file cache until
	 * a scan finds them, and a library scan only reads the file cache.
	 * What is unchanged is taken from the cache as it is, so a library
	 * that holds no surprises costs little more than a listing.
	 */
	private function scanFiles(Folder $folder): void {
		try {
			$folder->getStorage()->getScanner()->scan(
				$folder->getInternalPath(),
				IScanner::SCAN_RECURSIVE,
				IScanner::REUSE_ETAG | IScanner::REUSE_SIZE,
			);
		} catch (\Throwable $e) {
			// The library is still scanned from what the cache holds.
			$this->logger->warning('Could not scan the games library folder for files added outside Nextcloud', [
				'path' => $folder->getPath(),
				'exception' => $e,
			]);
		}
	}

	/** Read again what the games of a user say about themselves, once. */
	private function readMetadata(string $userId): void {
		$argument = ['userId' => $userId];
		if (!$this->jobList->has(RefreshMetadata::class, $argument)) {
			$this->jobList->add(RefreshMetadata::class, $argument);
		}
	}
}
