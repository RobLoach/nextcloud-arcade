<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\BackgroundJob\CleanupSweep;
use OCA\Arcade\BackgroundJob\ScanLibraries;
use OCA\Arcade\Service\JobHistory;
use OCP\BackgroundJob\IJobRuns;
use OCP\BackgroundJob\JobRun;
use OCP\BackgroundJob\JobStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * How the jobs of the app last went, read off the record Nextcloud keeps.
 */
class JobHistoryTest extends TestCase {
	/** @var list<JobRun> newest first, as Nextcloud hands them over */
	private array $completed = [];
	/** @var list<JobRun> */
	private array $running = [];

	private function history(): JobHistory {
		$runs = $this->createStub(IJobRuns::class);
		$runs->method('runningJobs')->willReturnCallback(function (): \Generator {
			yield from $this->running;
		});
		$runs->method('completedJobs')->willReturnCallback(function (array $statuses, array $classes, int $limit): \Generator {
			$mine = array_values(array_filter($this->completed, fn (JobRun $run): bool => in_array($run->className, $classes, true)));
			if ($mine === []) {
				// What Nextcloud does for a class it has never run.
				throw new \RuntimeException('No class ID found for filtering');
			}
			yield from array_slice($mine, 0, $limit);
		});
		return new JobHistory($runs, $this->createStub(LoggerInterface::class));
	}

	private function jobRun(string $class, JobStatus $status, string $at, ?int $milliseconds = null): JobRun {
		return new JobRun('1', $class, 0, 1, new \DateTimeImmutable($at), $status, $milliseconds);
	}

	public function testTheLastRunOfEachJobIsSaid(): void {
		$this->completed = [
			$this->jobRun(CleanupSweep::class, JobStatus::CRASHED, '2026-10-25 03:00'),
			$this->jobRun(ScanLibraries::class, JobStatus::FAILED, '2026-10-24 12:00', 1500),
			$this->jobRun(ScanLibraries::class, JobStatus::SUCCEEDED, '2026-10-20 12:00', 900),
		];

		$states = $this->history()->states();

		$this->assertSame(
			['status' => 'failed', 'at' => strtotime('2026-10-24 12:00'), 'seconds' => 1.5],
			$states['scan']['last'],
			'the newest run, not the one before it',
		);
		$this->assertSame('crashed', $states['cleanup']['last']['status'] ?? null);
		$this->assertNull($states['cleanup']['last']['seconds'] ?? null, 'a crash took no time anyone measured');
	}

	public function testAJobThatNeverRanIsNoError(): void {
		$states = $this->history()->states();
		$this->assertSame(['scan', 'cleanup', 'thumbnails', 'metadata'], array_keys($states));
		$this->assertNull($states['thumbnails']['last']);
		$this->assertFalse($states['thumbnails']['running']);
	}

	public function testAJobRunningNowIsSaidToBe(): void {
		$this->running = [
			$this->jobRun(ScanLibraries::class, JobStatus::RUNNING, 'now'),
			$this->jobRun('OCA\\Other\\Job', JobStatus::RUNNING, 'now'),
		];
		$states = $this->history()->states();
		$this->assertTrue($states['scan']['running']);
		$this->assertFalse($states['cleanup']['running']);
	}
}
