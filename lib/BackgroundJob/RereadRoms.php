<?php

declare(strict_types=1);

namespace OCA\Arcade\BackgroundJob;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Listener\MetadataListener;
use OCA\Arcade\Service\LibraryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads a batch of ROMs again, from the front, the way the background pass
 * reads a new one: for games an earlier version read wrong and nothing
 * would otherwise look at twice. A game with a title already was read
 * right, and is left alone.
 */
class RereadRoms extends QueuedJob {
	/** In the app config: a batch read something the libraries have yet to take in. */
	private const CHANGED = 'reread_changed';

	public function __construct(
		ITimeFactory $time,
		private IRootFolder $rootFolder,
		private IFilesMetadataManager $metadataManager,
		private LibraryService $libraryService,
		private IJobList $jobList,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument ['fileIds' => list<int>]
	 */
	protected function run($argument): void {
		$ids = is_array($argument) && is_array($argument['fileIds'] ?? null)
			? array_map(intval(...), $argument['fileIds'])
			: [];
		if ($ids === []) {
			return;
		}
		$read = false;
		foreach ($this->untitled($ids) as $id) {
			try {
				$node = $this->rootFolder->getFirstNodeById($id);
				if (!$node instanceof File) {
					continue;
				}
				// Live and then the reading itself, here and now: this job is
				// already the background.
				$this->metadataManager->refreshMetadata(
					$node,
					IFilesMetadataManager::PROCESS_LIVE | IFilesMetadataManager::PROCESS_BACKGROUND,
				);
				$read = true;
			} catch (\Throwable $e) {
				// One file that cannot be read is no reason to leave the rest.
				$this->logger->warning('Could not read a ROM again', ['fileId' => $id, 'exception' => $e]);
			}
		}
		if ($read) {
			$this->appConfig->setValueBool(Application::APP_ID, self::CHANGED, true);
		}
		$this->letTheLibrariesKnow();
	}

	/**
	 * The files of the batch without a title, asked of the metadata in one
	 * go rather than a file at a time.
	 *
	 * @param list<int> $ids
	 * @return list<int>
	 */
	private function untitled(array $ids): array {
		try {
			$filed = $this->metadataManager->getMetadataForFiles($ids);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not tell which ROMs have a title', ['exception' => $e]);
			return $ids;
		}
		return array_values(array_filter($ids, static function (int $id) use ($filed): bool {
			$metadata = $filed[$id] ?? null;
			return $metadata === null
				|| !$metadata->hasKey(MetadataListener::TITLE)
				|| $metadata->getString(MetadataListener::TITLE) === '';
		}));
	}

	/**
	 * A library keeps its list until its folder changes, and reading a
	 * file changes nothing of the folder: without this, the titles just
	 * read would not reach it -- nor the box art they find -- for a day.
	 * Once, when the last batch is through, rather than every library
	 * thrown away after every batch.
	 */
	private function letTheLibrariesKnow(): void {
		foreach ($this->jobList->getJobsIterator(self::class, 1, 0) as $waiting) {
			// Not the last batch: the last one says it for all of them.
			return;
		}
		if ($this->appConfig->getValueBool(Application::APP_ID, self::CHANGED)) {
			$this->appConfig->deleteKey(Application::APP_ID, self::CHANGED);
			$this->libraryService->forgetEveryScan();
		}
	}
}
