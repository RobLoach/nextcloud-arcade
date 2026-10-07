<?php

declare(strict_types=1);

namespace OCA\Arcade\Migration;

use OCA\Arcade\Listener\MetadataListener;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Declares what the app files against a ROM, so Nextcloud knows the keys,
 * indexes them, and lets nothing else write them.
 *
 * Declaring is a repair step on purpose: the manager says so, since it
 * writes a config value that is not meant to be touched on every request.
 *
 * @psalm-suppress UnusedClass
 */
class RegisterMetadata implements IRepairStep {
	public function __construct(
		private IFilesMetadataManager $metadataManager,
	) {
	}

	public function getName(): string {
		return 'Declare what Arcade knows about a ROM';
	}

	public function run(IOutput $output): void {
		// The mapper and the CRC32 are details nobody searches by, so they
		// go unindexed.
		foreach ([
			MetadataListener::SYSTEM => true,
			MetadataListener::TITLE => true,
			MetadataListener::REGION => true,
			MetadataListener::CHECKSUM => true,
			MetadataListener::CRC32 => false,
			MetadataListener::MAPPER => false,
		] as $key => $indexed) {
			$this->metadataManager->initMetadata(
				$key,
				IMetadataValueWrapper::TYPE_STRING,
				$indexed,
				IMetadataValueWrapper::EDIT_FORBIDDEN,
			);
		}
		// Nothing is said about it. Declaring the keys is the same work on
		// every upgrade whether or not anything changed, so a line here
		// only ever repeated the step's own name back.
	}
}
