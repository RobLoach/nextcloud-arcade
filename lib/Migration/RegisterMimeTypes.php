<?php

declare(strict_types=1);

namespace OCA\Arcade\Migration;

use OCA\Arcade\CoreMap;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IMimeTypeLoader;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Updates the file cache so ROMs uploaded before the app was enabled get
 * their proper mimetype instead of application/octet-stream.
 *
 * Extensions the server already maps to something of its own are not
 * taken over -- ".md" is Markdown to Nextcloud before it is a Mega Drive
 * dump -- and files an earlier version of this step retyped are pointed
 * back at the server's type.
 */
class RegisterMimeTypes implements IRepairStep {
	public function __construct(
		private IMimeTypeLoader $mimeTypeLoader,
		private IMimeTypeDetector $mimeTypeDetector,
	) {
	}

	public function getName(): string {
		return 'Register Arcade ROM mimetypes';
	}

	public function run(IOutput $output): void {
		$mappings = $this->mimeTypeDetector->getAllMappings();
		$updated = 0;
		$healed = 0;
		foreach (CoreMap::extensionMimeMap() as $extension => $mime) {
			$claimed = $mappings[$extension] ?? [];
			if ($claimed !== [] && !in_array($mime, $claimed, true)) {
				$serverMime = $claimed[0];
				if (is_string($serverMime) && $serverMime !== '') {
					$healed += $this->mimeTypeLoader->updateFilecache(
						$extension,
						$this->mimeTypeLoader->getId($serverMime),
					);
				}
				continue;
			}
			$mimeId = $this->mimeTypeLoader->getId($mime);
			$updated += $this->mimeTypeLoader->updateFilecache($extension, $mimeId);
		}
		// Only when there was something to say. Every upgrade ran this and
		// announced nought files, which is noise in the one place an
		// administrator is reading for something that went wrong.
		if ($updated > 0) {
			$output->info("Updated the mimetype of $updated files");
		}
		if ($healed > 0) {
			$output->info("Gave $healed files their server mimetype back");
		}
	}
}
