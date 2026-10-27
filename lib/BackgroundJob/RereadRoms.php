<?php

declare(strict_types=1);

namespace OCA\Arcade\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use Psr\Log\LoggerInterface;

/**
 * Reads a batch of ROMs again, from the front, the way the background pass
 * reads a new one: for games an earlier version read wrong and nothing
 * would otherwise look at twice.
 */
class RereadRoms extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private IRootFolder $rootFolder,
		private IFilesMetadataManager $metadataManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument ['fileIds' => list<int>]
	 */
	protected function run($argument): void {
		$ids = is_array($argument) && is_array($argument['fileIds'] ?? null) ? $argument['fileIds'] : [];
		foreach ($ids as $id) {
			try {
				$node = $this->rootFolder->getFirstNodeById((int)$id);
				if (!$node instanceof File) {
					continue;
				}
				// Live and then the reading itself, here and now: this job is
				// already the background.
				$this->metadataManager->refreshMetadata(
					$node,
					IFilesMetadataManager::PROCESS_LIVE | IFilesMetadataManager::PROCESS_BACKGROUND,
				);
			} catch (\Throwable $e) {
				// One file that cannot be read is no reason to leave the rest.
				$this->logger->warning('Could not read a ROM again', ['fileId' => $id, 'exception' => $e]);
			}
		}
	}
}
