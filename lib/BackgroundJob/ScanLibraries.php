<?php

declare(strict_types=1);

namespace OCA\Arcade\BackgroundJob;

use OCA\Arcade\Service\LibraryScanner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Scans the games library of every user once, when an administrator asks
 * for it from the administration settings.
 *
 * @psalm-suppress UnusedClass
 */
class ScanLibraries extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private LibraryScanner $scanner,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$scanned = $this->scanner->scanAll();
		$this->logger->info("Scanned the games libraries of $scanned users");
	}
}
