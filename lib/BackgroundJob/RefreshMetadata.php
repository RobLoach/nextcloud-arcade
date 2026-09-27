<?php

declare(strict_types=1);

namespace OCA\Arcade\BackgroundJob;

use OCA\Arcade\Listener\MetadataListener;
use OCA\Arcade\Service\Folders;
use OCA\Arcade\Service\LibraryService;
use OCA\Arcade\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use Psr\Log\LoggerInterface;

/**
 * Reads what a ROM says about itself for the games that have never been
 * asked.
 *
 * Nextcloud only reads a file's metadata when the file is written, so a
 * library that was already there when the app arrived is never looked at.
 * Rescanning the library folder asks for this job, which walks the games a
 * batch at a time and hands each one to Nextcloud as though it had just
 * been written: the cheap part is done at once, and the reading of the file
 * itself is queued behind it, one job per game.
 *
 * @psalm-suppress UnusedClass
 */
class RefreshMetadata extends QueuedJob {
	/**
	 * Games handed over in one run. Each one only queues a job of its own
	 * -- the file is opened there, not here -- while working out which are
	 * left asks after the whole library, so the batch is big enough that
	 * the asking is not the bulk of the work.
	 */
	public const BATCH = 200;

	public function __construct(
		ITimeFactory $time,
		private SettingsService $settingsService,
		private LibraryService $libraryService,
		private IFilesMetadataManager $metadataManager,
		private IRootFolder $rootFolder,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$userId = is_array($argument) ? (string)($argument['userId'] ?? '') : '';
		if ($userId === '') {
			return;
		}
		try {
			$this->refreshFor($userId);
		} catch (\Throwable $e) {
			$this->logger->error('Could not read what the ROMs say about themselves', ['exception' => $e]);
		}
	}

	private function refreshFor(string $userId): void {
		$missing = $this->missing($userId);
		if ($missing === []) {
			return;
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		foreach ($missing as $id) {
			$node = $userFolder->getFirstNodeById($id);
			if ($node === null) {
				continue;
			}
			// Live, so that only the naming of the system is done here and
			// the reading of the file is left to a job of its own.
			$this->metadataManager->refreshMetadata($node, IFilesMetadataManager::PROCESS_LIVE);
		}

		// A full batch means the looking stopped early, so there is more.
		if (count($missing) >= self::BATCH) {
			$this->jobList->add(self::class, ['userId' => $userId]);
		}
	}

	/**
	 * How many games are asked after in one go. Asking after every game of
	 * a library at once would mean thousands of ids in one query, and all
	 * that is wanted is the next batch of them.
	 */
	private const CHUNK = 500;

	/**
	 * The ids of the games nothing is known about yet, up to a batch of
	 * them: the library is walked a chunk at a time and the walking stops
	 * as soon as there is enough to be getting on with.
	 *
	 * @return list<int>
	 */
	private function missing(string $userId): array {
		$settings = $this->settingsService->getUserSettings($userId);
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$folder = Folders::folderAt($userFolder, $settings['library_folder']);
		if ($folder === null) {
			return [];
		}

		$games = $this->libraryService->getGames(
			$userId,
			$folder,
			$userFolder,
			$settings['library_folder'],
			$settings,
			false,
		);
		$ids = array_values(array_filter(array_column($games, 'id')));

		$missing = [];
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$known = [];
			foreach ($this->metadataManager->getMetadataForFiles($chunk) as $id => $metadata) {
				// The system is set the moment a game is looked at, so a
				// game without it has never been looked at.
				if ($metadata->getString(MetadataListener::SYSTEM) !== '') {
					$known[(int)$id] = true;
				}
			}
			foreach ($chunk as $id) {
				if (!isset($known[$id])) {
					$missing[] = $id;
				}
			}
			if (count($missing) >= self::BATCH) {
				return array_slice($missing, 0, self::BATCH);
			}
		}
		return $missing;
	}
}
