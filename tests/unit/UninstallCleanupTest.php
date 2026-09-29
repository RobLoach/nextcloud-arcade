<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Migration\UninstallCleanup;
use OCP\BackgroundJob\IJobList;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The step Nextcloud runs when the app is disabled, which is also what a
 * server upgrade does to an app it has outgrown. It may only drop what a
 * user would not miss.
 */
class UninstallCleanupTest extends TestCase {
	/** @var list<string> */
	private array $removedJobs = [];
	/** @var list<string> */
	private array $clearedCaches = [];

	private function runStep(): void {
		$jobList = $this->createStub(IJobList::class);
		$jobList->method('remove')->willReturnCallback(
			function (mixed $job, mixed $argument = null): void {
				$this->removedJobs[] = is_string($job) ? $job : $job::class;
			},
		);

		$cacheFactory = $this->createStub(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(
			function (string $prefix): ICache {
				$cache = $this->createStub(ICache::class);
				$cache->method('clear')->willReturnCallback(
					function (string $keyPrefix = '') use ($prefix): bool {
						$this->clearedCaches[] = $prefix;
						return true;
					},
				);
				return $cache;
			},
		);

		(new UninstallCleanup($jobList, $cacheFactory))->run($this->createStub(IOutput::class));
	}

	public function testEveryJobTheAppQueuesIsDropped(): void {
		$this->runStep();
		$this->assertSame(Application::JOBS, $this->removedJobs);
	}

	public function testEveryCacheTheAppFillsIsCleared(): void {
		$this->runStep();
		$this->assertSame(Application::CACHES, $this->clearedCaches);
	}

	public function testTheStepIsGivenNothingItCouldDeleteUserDataWith(): void {
		// Settings, save states and what was played are reachable through
		// none of what it is constructed with, which is the point: being
		// disabled is not being uninstalled.
		$parameters = (new \ReflectionMethod(UninstallCleanup::class, '__construct'))->getParameters();
		$this->assertSame(
			[IJobList::class, ICacheFactory::class],
			array_map(static fn (\ReflectionParameter $p): string => (string)$p->getType(), $parameters),
		);
	}
}
