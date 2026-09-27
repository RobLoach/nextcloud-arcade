<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\SetupChecks\ArcadeSetupCheck;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

/**
 * The admin overview check: it blames the server's background jobs when
 * they deserve it, and stays quiet when they do not.
 */
class ArcadeSetupCheckTest extends TestCase {
	private const NOW = 1_000_000_000;

	private string $mode = 'cron';
	private int $lastCron = self::NOW - 60;
	private bool $queued = false;
	private bool $distributedCache = true;
	private bool $localCache = true;

	private function check(): ArcadeSetupCheck {
		$l = $this->createStub(IL10N::class);
		$l->method('t')->willReturnCallback(
			fn (string $text, $parameters = []): string => vsprintf($text, is_array($parameters) ? $parameters : [$parameters]),
		);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string
				=> ($app === 'core' && $key === 'backgroundjobs_mode') ? $this->mode : $default,
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int
				=> ($app === 'core' && $key === 'lastcron') ? $this->lastCron : $default,
		);

		$jobList = $this->createStub(IJobList::class);
		$jobList->method('getJobsIterator')->willReturnCallback(
			fn (): array => $this->queued ? [$this->createStub(IJob::class)] : [],
		);

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturnCallback(fn (): bool => $this->distributedCache);
		$cacheFactory->method('isLocalCacheAvailable')->willReturnCallback(fn (): bool => $this->localCache);

		return new ArcadeSetupCheck($l, $appConfig, $jobList, $time, $cacheFactory);
	}

	public function testItIsASystemCheckWithAName(): void {
		$this->assertSame('system', $this->check()->getCategory());
		$this->assertNotSame('', $this->check()->getName());
	}

	public function testHealthyCronIsASuccess(): void {
		$result = $this->check()->run();
		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}

	public function testAjaxModeIsAWarningWithTheDocLink(): void {
		$this->mode = 'ajax';
		$result = $this->check()->run();
		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('AJAX', (string)$result->getDescription());
		$this->assertSame(ArcadeSetupCheck::DOC_LINK, $result->getLinkToDoc());
	}

	public function testCronThatNeverRanIsAWarning(): void {
		$this->lastCron = 0;
		$result = $this->check()->run();
		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('never run', (string)$result->getDescription());
	}

	public function testCronGoneQuietForOverAnHourIsAWarning(): void {
		$this->lastCron = self::NOW - ArcadeSetupCheck::MAX_CRON_AGE - 1;
		$result = $this->check()->run();
		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertSame(ArcadeSetupCheck::DOC_LINK, $result->getLinkToDoc());
	}

	public function testAnHourOldCronIsStillFine(): void {
		$this->lastCron = self::NOW - ArcadeSetupCheck::MAX_CRON_AGE;
		$this->assertSame(SetupResult::SUCCESS, $this->check()->run()->getSeverity());
	}

	public function testQueuedWorkIsNamedInTheWarning(): void {
		$this->mode = 'ajax';
		$this->queued = true;
		$description = (string)$this->check()->run()->getDescription();
		$this->assertStringContainsString('waiting in the queue', $description);
	}

	public function testQueuedWorkUnderHealthyCronIsOnlyWorthAnInfo(): void {
		$this->queued = true;
		$result = $this->check()->run();
		$this->assertSame(SetupResult::INFO, $result->getSeverity());
	}

	public function testNoMemoryCacheAtAllIsAWarningAboutRescanning(): void {
		$this->distributedCache = false;
		$this->localCache = false;
		$result = $this->check()->run();
		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('rescans the game library on every request', (string)$result->getDescription());
		$this->assertSame(ArcadeSetupCheck::CACHE_DOC_LINK, $result->getLinkToDoc());
	}

	public function testALocalCacheAloneIsCacheEnough(): void {
		$this->distributedCache = false;
		$this->assertSame(SetupResult::SUCCESS, $this->check()->run()->getSeverity());
	}

	public function testBrokenCronOutranksTheMissingCache(): void {
		$this->mode = 'ajax';
		$this->distributedCache = false;
		$this->localCache = false;
		$description = (string)$this->check()->run()->getDescription();
		$this->assertStringContainsString('AJAX', $description, 'the jobs warning comes first; the cache one waits its turn');
	}
}
