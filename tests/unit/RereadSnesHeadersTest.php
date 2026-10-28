<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\BackgroundJob\RereadRoms;
use OCA\Arcade\Listener\MetadataListener;
use OCA\Arcade\Migration\RereadSnesHeaders;
use OCA\Arcade\Service\LibraryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** The file cache, as the test says it is. */
class TestableRereadSnesHeaders extends RereadSnesHeaders {
	/** @var list<int> */
	public array $snesFiles = [];

	protected function snesFileIds(): array {
		return $this->snesFiles;
	}
}

/**
 * Super Nintendo games read before the header could be reached are read
 * again, once, and the libraries told when the reading is through.
 */
class RereadSnesHeadersTest extends TestCase {
	/** @var list<array{0: string, 1: mixed}> jobs still waiting */
	private array $queued = [];
	/** @var array<string, bool> the app config */
	private array $config = [];
	/** @var array<int, string> titles filed, by file id */
	private array $titles = [];
	/** @var list<int> files read, in order */
	private array $read = [];
	private int $forgotten = 0;

	private function jobList(): IJobList {
		$jobs = $this->createStub(IJobList::class);
		$jobs->method('add')->willReturnCallback(function (string $class, mixed $argument = null): void {
			$this->queued[] = [$class, $argument];
		});
		$jobs->method('getJobsIterator')->willReturnCallback(fn (): array => array_map(
			fn (): RereadRoms => $this->createStub(RereadRoms::class),
			$this->queued,
		));
		return $jobs;
	}

	private function appConfig(): IAppConfig {
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(fn (string $app, string $key): bool => $this->config[$key] ?? false);
		$config->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->config[$key] = $value;
			return true;
		});
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});
		return $config;
	}

	private function step(array $snesFiles): TestableRereadSnesHeaders {
		$step = new TestableRereadSnesHeaders(
			$this->createStub(IDBConnection::class),
			$this->createStub(IMimeTypeLoader::class),
			$this->jobList(),
			$this->appConfig(),
		);
		$step->snesFiles = $snesFiles;
		return $step;
	}

	private function job(): RereadRoms {
		$metadata = $this->createStub(IFilesMetadataManager::class);
		$metadata->method('getMetadataForFiles')->willReturnCallback(function (array $ids): array {
			$filed = [];
			foreach ($ids as $id) {
				if (!isset($this->titles[$id])) {
					continue;
				}
				$title = $this->titles[$id];
				$one = $this->createStub(IFilesMetadata::class);
				$one->method('hasKey')->willReturnCallback(fn (string $key): bool => $key === MetadataListener::TITLE);
				$one->method('getString')->willReturn($title);
				$filed[$id] = $one;
			}
			return $filed;
		});
		$metadata->method('refreshMetadata')->willReturnCallback(function (File $node, int $process): IFilesMetadata {
			$this->assertSame(IFilesMetadataManager::PROCESS_LIVE | IFilesMetadataManager::PROCESS_BACKGROUND, $process);
			if ($node->getId() === 2) {
				throw new \RuntimeException('unreadable');
			}
			$this->read[] = $node->getId();
			return $this->createStub(IFilesMetadata::class);
		});
		$root = $this->createStub(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturnCallback(function (int $id) {
			if ($id === 4) {
				// A folder took the id since: nothing to read.
				return $this->createStub(Folder::class);
			}
			if ($id === 5) {
				return null;
			}
			$file = $this->createStub(File::class);
			$file->method('getId')->willReturn($id);
			return $file;
		});
		$library = $this->createStub(LibraryService::class);
		$library->method('forgetEveryScan')->willReturnCallback(function (): void {
			$this->forgotten++;
		});
		return new RereadRoms(
			$this->createStub(ITimeFactory::class),
			$root,
			$metadata,
			$library,
			$this->jobList(),
			$this->appConfig(),
			$this->createStub(LoggerInterface::class),
		);
	}

	/** Run the next queued batch, as cron would: it leaves the list first. */
	private function runNext(): void {
		[, $argument] = array_shift($this->queued);
		$job = $this->job();
		(new \ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}

	public function testEveryGameIsQueuedInBatchesWithoutAskingEachOne(): void {
		$this->step(range(1, 250))->run($this->createStub(IOutput::class));

		$this->assertSame([100, 100, 50], array_map(static fn (array $job): int => count($job[1]['fileIds']), $this->queued));
		$this->assertSame([RereadRoms::class], array_values(array_unique(array_column($this->queued, 0))));
	}

	public function testItIsQueuedOnce(): void {
		$this->step([1])->run($this->createStub(IOutput::class));
		$this->step([1])->run($this->createStub(IOutput::class));
		$this->assertCount(1, $this->queued);
	}

	public function testOnlyTheGamesWithoutATitleAreReadAndOneFailingStopsNothing(): void {
		$this->titles = [6 => 'CHRONO TRIGGER', 3 => ''];
		$this->queued = [[RereadRoms::class, ['fileIds' => [1, 2, 3, 4, 5, 6]]]];

		$this->runNext();

		// 6 has a title; 2 cannot be read; 4 and 5 are no file any more.
		$this->assertSame([1, 3], $this->read);
	}

	public function testTheLibrariesAreToldOnceWhenTheLastBatchIsThrough(): void {
		$this->queued = [
			[RereadRoms::class, ['fileIds' => [1]]],
			[RereadRoms::class, ['fileIds' => [3]]],
			[RereadRoms::class, ['fileIds' => [6]]],
		];
		$this->titles = [6 => 'CHRONO TRIGGER'];

		$this->runNext();
		$this->runNext();
		$this->assertSame(0, $this->forgotten, 'not while batches are still waiting');
		// The last one reads nothing itself, and still says it for the rest.
		$this->runNext();
		$this->assertSame(1, $this->forgotten);
	}

	public function testNothingReadIsNothingToTellTheLibraries(): void {
		$this->titles = [6 => 'CHRONO TRIGGER'];
		$this->queued = [[RereadRoms::class, ['fileIds' => [6]]]];
		$this->runNext();
		$this->assertSame(0, $this->forgotten);
	}
}
