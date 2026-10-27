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
 * again, once.
 */
class RereadSnesHeadersTest extends TestCase {
	/** @var array<int, string> titles filed, by file id */
	private array $titles = [];
	/** @var list<array{0: string, 1: mixed}> */
	private array $queued = [];
	private bool $done = false;

	private function step(array $snesFiles): TestableRereadSnesHeaders {
		$metadata = $this->createStub(IFilesMetadataManager::class);
		$metadata->method('getMetadata')->willReturnCallback(function (int $id): IFilesMetadata {
			if (!isset($this->titles[$id])) {
				throw new \Exception('nothing filed');
			}
			$filed = $this->createStub(IFilesMetadata::class);
			$filed->method('hasKey')->willReturnCallback(fn (string $key): bool => $key === MetadataListener::TITLE);
			$filed->method('getString')->willReturn($this->titles[$id]);
			return $filed;
		});
		$jobs = $this->createStub(IJobList::class);
		$jobs->method('add')->willReturnCallback(function (string $class, mixed $argument = null): void {
			$this->queued[] = [$class, $argument];
		});
		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(fn (): bool => $this->done);
		$config->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->done = $value;
			return true;
		});
		$step = new TestableRereadSnesHeaders(
			$this->createStub(IDBConnection::class),
			$this->createStub(IMimeTypeLoader::class),
			$metadata,
			$jobs,
			$config,
		);
		$step->snesFiles = $snesFiles;
		return $step;
	}

	public function testOnlyTheGamesWithoutATitleAreReadAgain(): void {
		$this->titles = [2 => 'CHRONO TRIGGER', 3 => ''];

		$this->step([1, 2, 3])->run($this->createStub(IOutput::class));

		$this->assertSame([[RereadRoms::class, ['fileIds' => [1, 3]]]], $this->queued);
		$this->assertTrue($this->done);
	}

	public function testABigLibraryIsReadInBatches(): void {
		$this->step(range(1, 250))->run($this->createStub(IOutput::class));

		$this->assertSame([100, 100, 50], array_map(static fn (array $job): int => count($job[1]['fileIds']), $this->queued));
	}

	public function testItIsDoneOnce(): void {
		$this->done = true;
		$this->step([1])->run($this->createStub(IOutput::class));
		$this->assertSame([], $this->queued);
	}

	public function testTheJobReadsEachGameAndCarriesOnPastOneThatFails(): void {
		$read = [];
		$metadata = $this->createStub(IFilesMetadataManager::class);
		$metadata->method('refreshMetadata')->willReturnCallback(function (File $node, int $process) use (&$read): IFilesMetadata {
			$this->assertSame(IFilesMetadataManager::PROCESS_LIVE | IFilesMetadataManager::PROCESS_BACKGROUND, $process);
			if ($node->getId() === 2) {
				throw new \RuntimeException('unreadable');
			}
			$read[] = $node->getId();
			return $this->createStub(IFilesMetadata::class);
		});
		$root = $this->createStub(IRootFolder::class);
		$root->method('getFirstNodeById')->willReturnCallback(function (int $id) {
			if ($id === 4) {
				// A folder took the id since: nothing to read.
				return $this->createStub(Folder::class);
			}
			$file = $this->createStub(File::class);
			$file->method('getId')->willReturn($id);
			return $id === 5 ? null : $file;
		});
		$cleared = 0;
		$library = $this->createStub(LibraryService::class);
		$library->method('forgetEveryScan')->willReturnCallback(function () use (&$cleared): void {
			$cleared++;
		});
		$job = new RereadRoms($this->createStub(ITimeFactory::class), $root, $metadata, $library, $this->createStub(LoggerInterface::class));

		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
		$this->assertSame([], $read, 'nothing without an argument');
		$this->assertSame(0, $cleared, 'and no library is thrown away for it');

		$run->invoke($job, ['fileIds' => [1, 2, 3, 4, 5]]);

		$this->assertSame([1, 3], $read);
		// The libraries are scanned afresh, to take the titles in.
		$this->assertSame(1, $cleared);
	}
}
