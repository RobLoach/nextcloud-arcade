<?php

declare(strict_types=1);

namespace OCA\Arcade\BackgroundJob;

use OCA\Arcade\Service\CleanupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Brings every Cloud Sync manifest up to date once, when an administrator
 * asks for it from the maintenance section; the weekly sweep does the same
 * by itself.
 *
 * @psalm-suppress UnusedClass
 */
class ReconcileManifests extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private CleanupService $cleanupService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		$this->cleanupService->reconcileManifests(null, false, function (string $line): void {
			$this->logger->info($line);
		});
	}
}
