<?php

declare(strict_types=1);

namespace OCA\Arcade\BackgroundJob;

use OCA\Arcade\Service\CleanupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs the `occ arcade:cleanup` sweep once a week, by itself.
 *
 * The listeners remove saves as their game or user goes, but the trash
 * gives no signal the app can hear when cron or `occ trashbin:cleanup`
 * expires an item (see CleanupListener), so what they miss would sit
 * forever on an instance where nobody runs the occ command. The sweep is
 * a walk over everything the app stores, so once a week is plenty, and
 * whenever cron gets around to it is soon enough.
 *
 * @psalm-suppress UnusedClass
 */
class CleanupSweep extends TimedJob {
	/** A week, in seconds. */
	public const INTERVAL = 7 * 24 * 3600;

	public function __construct(
		ITimeFactory $time,
		private CleanupService $cleanupService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		try {
			$counts = $this->cleanupService->sweep(false, function (string $line): void {
				$this->logger->debug($line);
			});
		} catch (\Throwable $e) {
			// A broken sweep must never break cron; next week is another try.
			$this->logger->error('The save state cleanup sweep failed', ['exception' => $e]);
			return;
		}
		$summary = sprintf(
			'Save state cleanup removed the states of %d users and %d games',
			$counts['users'],
			$counts['games'],
		);
		// A week with nothing to sweep up is the usual week; only a sweep
		// that removed something is worth a line in the log.
		if ($counts['users'] > 0 || $counts['games'] > 0) {
			$this->logger->info($summary);
		} else {
			$this->logger->debug($summary);
		}
	}
}
