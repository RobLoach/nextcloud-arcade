<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\BackgroundJob\CleanupSweep;
use OCA\Arcade\BackgroundJob\FetchThumbnails;
use OCA\Arcade\BackgroundJob\RefreshMetadata;
use OCA\Arcade\BackgroundJob\ScanLibraries;
use OCP\BackgroundJob\IJobRuns;
use OCP\BackgroundJob\JobStatus;
use Psr\Log\LoggerInterface;

/**
 * How the background jobs of the app last went, from the record Nextcloud
 * keeps of every run. A job that fails says so only in the log otherwise,
 * where nobody looks until something is already missing.
 *
 * @psalm-type JobState = array{
 *     running: bool,
 *     last: ?array{status: 'succeeded'|'failed'|'crashed', at: int, seconds: ?float},
 * }
 */
class JobHistory {
	/** The jobs, by the name the settings page and occ know them by. */
	public const JOBS = [
		'scan' => ScanLibraries::class,
		'cleanup' => CleanupSweep::class,
		'thumbnails' => FetchThumbnails::class,
		'metadata' => RefreshMetadata::class,
	];

	public function __construct(
		private IJobRuns $jobRuns,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether each job is running now, and how its last run ended; null
	 * for a job that has not finished a run Nextcloud still remembers.
	 *
	 * @return array<key-of<self::JOBS>, JobState>
	 */
	public function states(): array {
		$running = [];
		try {
			foreach ($this->jobRuns->runningJobs() as $run) {
				$running[$run->className] = true;
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not list the running background jobs', ['exception' => $e]);
		}

		$states = [];
		foreach (self::JOBS as $name => $class) {
			$states[$name] = [
				'running' => isset($running[$class]),
				'last' => $this->lastRunOf($class),
			];
		}
		return $states;
	}

	/**
	 * @param class-string $class
	 * @return ?array{status: 'succeeded'|'failed'|'crashed', at: int, seconds: ?float}
	 */
	private function lastRunOf(string $class): ?array {
		try {
			foreach ($this->jobRuns->completedJobs([], [$class], 1) as $run) {
				return [
					'status' => match ($run->status) {
						JobStatus::FAILED => 'failed',
						JobStatus::CRASHED => 'crashed',
						default => 'succeeded',
					},
					'at' => $run->startedAt->getTimestamp(),
					'seconds' => $run->duration === null ? null : $run->duration / 1000,
				];
			}
		} catch (\RuntimeException) {
			// Asked for by a class that never ran, the record has no name
			// for it yet, and says so by throwing.
		} catch (\Throwable $e) {
			$this->logger->warning('Could not read how a background job went', ['job' => $class, 'exception' => $e]);
		}
		return null;
	}
}
