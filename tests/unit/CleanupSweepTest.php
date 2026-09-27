<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\BackgroundJob\CleanupSweep;
use OCA\Arcade\Service\CleanupService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The weekly sweep: the same cleanup as `occ arcade:cleanup`, run by cron,
 * quiet unless it removed something and harmless when it breaks.
 */
class CleanupSweepTest extends TestCase {
	/** The sweep calls made, as [dryRun]. */
	private array $swept = [];
	/** What the sweep will report. */
	private array $counts = ['users' => 0, 'games' => 0, 'legacy' => 0];
	private ?\Throwable $failure = null;
	/** The log lines, as [level, message]. */
	private array $logged = [];

	private function job(): CleanupSweep {
		$cleanupService = $this->createStub(CleanupService::class);
		$cleanupService->method('sweep')->willReturnCallback(
			function (bool $dryRun = false, ?\Closure $report = null): array {
				if ($this->failure !== null) {
					throw $this->failure;
				}
				$this->swept[] = $dryRun;
				return $this->counts;
			},
		);

		$logger = $this->createStub(LoggerInterface::class);
		foreach (['info', 'debug', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, (string)$message];
				},
			);
		}

		return new CleanupSweep($this->createStub(ITimeFactory::class), $cleanupService, $logger);
	}

	private function runJob(): void {
		$job = $this->job();
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}

	/** @return list<string> the messages logged at a level */
	private function loggedAt(string $level): array {
		$messages = [];
		foreach ($this->logged as [$at, $message]) {
			if ($at === $level) {
				$messages[] = $message;
			}
		}
		return $messages;
	}

	public function testItRunsOnceAWeekWheneverCronGetsAroundToIt(): void {
		$job = $this->job();
		$this->assertSame(7 * 24 * 3600, $job->getInterval());
		$this->assertFalse($job->isTimeSensitive(), 'any time that week will do');
	}

	public function testItRunsTheSweepForReal(): void {
		$this->runJob();
		$this->assertSame([false], $this->swept, 'once, and not as a dry run');
	}

	public function testASweepThatRemovedSomethingIsWorthAnInfoLine(): void {
		$this->counts = ['users' => 1, 'games' => 3, 'legacy' => 0];
		$this->runJob();

		$this->assertSame(
			['Save state cleanup removed the states of 1 users and 3 games'],
			$this->loggedAt('info'),
		);
	}

	public function testAnUneventfulSweepOnlySpeaksAtDebug(): void {
		$this->runJob();

		$this->assertSame([], $this->loggedAt('info'));
		$this->assertNotSame([], $this->loggedAt('debug'), 'still on record for anyone who asks');
	}

	public function testABrokenSweepIsLoggedAndNeverBreaksCron(): void {
		$this->failure = new \RuntimeException('the app data is on fire');
		$this->runJob();

		$this->assertNotSame([], $this->loggedAt('error'));
		$this->assertSame([], $this->loggedAt('info'));
	}
}
