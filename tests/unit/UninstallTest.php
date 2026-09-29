<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Command\Uninstall;
use OCA\Arcade\CoreMap;
use OCA\Arcade\Migration\UninstallCleanup;
use OCP\Config\IUserConfig;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What `occ arcade:uninstall` takes with it, and what it asks first.
 */
class UninstallTest extends TestCase {
	private const OCTET_STREAM = 7;

	/** The extensions whose files were put back to another mimetype. */
	private array $reverted = [];
	/** The names of the app data folders that were deleted. */
	private array $deleted = [];
	private array $removedJobs = [];
	private array $clearedCaches = [];
	private array $forgottenApps = [];
	/** The tables of the app that were dropped. */
	private array $droppedTables = [];
	/** Set when the app data has never been written to. */
	private bool $withoutAppData = false;

	private function tester(): CommandTester {
		$config = $this->createStub(IUserConfig::class);
		$config->method('deleteApp')->willReturnCallback(
			function (string $app): void {
				$this->forgottenApps[] = "preferences:$app";
			},
		);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('deleteApp')->willReturnCallback(
			function (string $app): void {
				$this->forgottenApps[] = "appconfig:$app";
			},
		);

		$appData = $this->createStub(IAppData::class);
		$appData->method('getDirectoryListing')->willReturnCallback(
			function (): array {
				if ($this->withoutAppData) {
					throw new \RuntimeException('there is no such folder');
				}
				return array_map(fn (string $name): ISimpleFolder => $this->folder($name), ['states']);
			},
		);
		$appDataFactory = $this->createStub(IAppDataFactory::class);
		$appDataFactory->method('get')->willReturn($appData);

		$jobList = $this->createStub(\OCP\BackgroundJob\IJobList::class);
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

		$mimeTypeLoader = $this->createStub(IMimeTypeLoader::class);
		$mimeTypeLoader->method('getId')->willReturnCallback(
			static fn (string $mimetype): int => $mimetype === 'application/octet-stream' ? self::OCTET_STREAM : 99,
		);
		$mimeTypeLoader->method('updateFilecache')->willReturnCallback(
			function (string $extension, int $mimeTypeId): int {
				$this->reverted[$extension] = $mimeTypeId;
				return 1;
			},
		);

		$connection = $this->createStub(IDBConnection::class);
		$connection->method('tableExists')->willReturn(true);
		$connection->method('dropTable')->willReturnCallback(
			function (string $table): void {
				$this->droppedTables[] = $table;
			},
		);

		return new CommandTester(new Uninstall(
			$config,
			$appConfig,
			$appDataFactory,
			// The real one: the command and the repair step drop the same
			// things, and that is the point of it being shared.
			new UninstallCleanup($jobList, $cacheFactory),
			$mimeTypeLoader,
			$connection,
		));
	}

	private function folder(string $name): ISimpleFolder {
		$folder = $this->createStub(ISimpleFolder::class);
		$folder->method('getName')->willReturn($name);
		$folder->method('delete')->willReturnCallback(
			function () use ($name): void {
				$this->deleted[] = $name;
			},
		);
		return $folder;
	}

	/**
	 * @return array<string, mixed> everything that was touched
	 */
	private function touched(): array {
		return [
			'reverted' => $this->reverted,
			'deleted' => $this->deleted,
			'tables' => $this->droppedTables,
			'jobs' => $this->removedJobs,
			'caches' => $this->clearedCaches,
			'forgotten' => $this->forgottenApps,
		];
	}

	public function testNothingGoesWithoutBeingAskedFor(): void {
		$tester = $this->tester();
		$tester->execute([], ['interactive' => false]);

		$this->assertStringContainsString('--force', $tester->getDisplay());
		$this->assertSame(
			['reverted' => [], 'deleted' => [], 'tables' => [], 'jobs' => [], 'caches' => [], 'forgotten' => []],
			$this->touched(),
			'being run by a script is not an answer',
		);
	}

	public function testADryRunOnlySays(): void {
		$tester = $this->tester();
		$tester->execute(['--dry-run' => true], ['interactive' => false]);

		$display = $tester->getDisplay();
		$this->assertStringContainsString('Dry run', $display);
		$this->assertStringContainsString('Would remove', $display);
		$this->assertStringContainsString('Would drop the tables', $display);
		$this->assertSame(
			['reverted' => [], 'deleted' => [], 'tables' => [], 'jobs' => [], 'caches' => [], 'forgotten' => []],
			$this->touched(),
		);
	}

	public function testEveryRomGoesBackToWhatItWasBefore(): void {
		$this->tester()->execute(['--force' => true], ['interactive' => false]);

		$this->assertSame(array_keys(CoreMap::extensionMimeMap()), array_keys($this->reverted));
		$this->assertSame([self::OCTET_STREAM], array_values(array_unique($this->reverted)));
	}

	public function testTheSaveStatesAndTheSettingsAreRemoved(): void {
		$this->tester()->execute(['--force' => true], ['interactive' => false]);

		$this->assertSame(['states'], $this->deleted);
		$this->assertSame(
			['arcade_plays', 'arcade_games'],
			$this->droppedTables,
			'the play records and the registry of games with saves',
		);
		$this->assertSame(Application::JOBS, $this->removedJobs, 'every job the app queues');
		$this->assertSame(Application::CACHES, $this->clearedCaches, 'every cache the app fills');
		$this->assertSame(
			['preferences:arcade', 'appconfig:arcade'],
			$this->forgottenApps,
			'the settings of the users go before what Nextcloud files under the app itself',
		);
	}

	public function testAnAppThatWasNeverPlayedWithIsNoTrouble(): void {
		$this->withoutAppData = true;
		$tester = $this->tester();
		$this->assertSame(0, $tester->execute(['--force' => true], ['interactive' => false]));
		$this->assertStringContainsString('No save states', $tester->getDisplay());
		$this->assertSame(['preferences:arcade', 'appconfig:arcade'], $this->forgottenApps, 'the rest still goes');
	}

	public function testTheFilesOfTheUserAreSaidToBeLeftAlone(): void {
		$tester = $this->tester();
		$tester->execute(['--force' => true], ['interactive' => false]);
		$this->assertStringContainsString('left alone', $tester->getDisplay());
	}
}
