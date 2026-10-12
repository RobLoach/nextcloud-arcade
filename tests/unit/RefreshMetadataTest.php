<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\BackgroundJob\RefreshMetadata;
use OCA\Arcade\Listener\MetadataListener;
use OCA\Arcade\Service\LibraryService;
use OCA\Arcade\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\FilesMetadata\Exceptions\FilesMetadataNotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Asking what the games already in a library say about themselves, for the
 * ones nothing has ever asked.
 */
class RefreshMetadataTest extends TestCase {
	private const USER = 'alice';

	/** The games in the library, by id. */
	private array $games = [];
	/** The ids something is already known about. */
	private array $known = [];
	/** The ids that were handed to Nextcloud to be read. */
	private array $refreshed = [];
	/** The jobs queued while running. */
	private array $queued = [];
	/** Ids whose file is no longer there. */
	private array $gone = [];
	/** Ids holding a row that no part of this app wrote. */
	private array $foreign = [];

	/**
	 * A file's metadata as the server hands it over: the keys that were
	 * written, and a refusal for any other.
	 *
	 * @param array<string, string> $values what was written for this file
	 */
	private function metadata(array $values): IFilesMetadata {
		$metadata = $this->createStub(IFilesMetadata::class);
		$metadata->method('hasKey')->willReturnCallback(
			static fn (string $key): bool => isset($values[$key]),
		);
		$metadata->method('getString')->willReturnCallback(
			static function (string $key) use ($values): string {
				if (!isset($values[$key])) {
					throw new FilesMetadataNotFoundException($key);
				}
				return $values[$key];
			},
		);
		return $metadata;
	}

	private function job(): RefreshMetadata {
		$settingsService = $this->createStub(SettingsService::class);
		$settingsService->method('getUserSettings')->willReturn(['library_folder' => '/Games']);

		$libraryService = $this->createStub(LibraryService::class);
		$libraryService->method('getGames')->willReturnCallback(fn (): array => array_values($this->games));

		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('get')->willReturn($this->createStub(Folder::class));
		$userFolder->method('getFirstNodeById')->willReturnCallback(
			fn (int $id): ?Node => isset($this->gone[$id]) ? null : $this->createStub(Node::class),
		);
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$metadataManager = $this->createStub(IFilesMetadataManager::class);
		$metadataManager->method('getMetadataForFiles')->willReturnCallback(
			function (array $ids): array {
				$found = [];
				foreach ($ids as $id) {
					if (isset($this->known[$id])) {
						$found[$id] = $this->metadata([MetadataListener::SYSTEM => 'nes']);
					} elseif (isset($this->foreign[$id])) {
						$found[$id] = $this->metadata(['photos-original_date_time' => '2020']);
					}
					// Anything else holds no row at all, and the server
					// leaves it out of the answer rather than handing
					// back an empty one.
				}
				return $found;
			},
		);
		$metadataManager->method('refreshMetadata')->willReturnCallback(
			function (Node $node, int $process = 1, string $namedEvent = ''): IFilesMetadata {
				$this->refreshed[] = $process;
				return $this->createStub(IFilesMetadata::class);
			},
		);

		$jobList = $this->createStub(IJobList::class);
		$jobList->method('add')->willReturnCallback(
			function (mixed $job, mixed $argument = null): void {
				$this->queued[] = is_string($job) ? $job : $job::class;
			},
		);

		return new RefreshMetadata(
			$this->createStub(ITimeFactory::class),
			$settingsService,
			$libraryService,
			$metadataManager,
			$rootFolder,
			$jobList,
			$this->createStub(LoggerInterface::class),
		);
	}

	private function runJob(mixed $argument = ['userId' => self::USER]): void {
		$job = $this->job();
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, $argument);
	}

	private function library(int $count): void {
		for ($id = 1; $id <= $count; $id++) {
			$this->games[$id] = ['id' => $id, 'path' => "/Games/Game $id.nes", 'basename' => "Game $id.nes"];
		}
	}

	public function testOnlyTheGamesNothingIsKnownAboutAreAskedAbout(): void {
		$this->library(4);
		$this->known = [2 => true, 4 => true];

		$this->runJob();

		$this->assertCount(2, $this->refreshed, 'the two that were never looked at');
	}

	public function testARowThisAppDidNotWriteDoesNotStopTheSearchForWorkToDo(): void {
		// Anything on the instance may file something against a file, so a
		// ROM can hold metadata with none of this app's keys in it. Asking
		// such a row for the system raises rather than answering empty, and
		// unguarded that carried off the whole search: nothing was found to
		// read, and the indexing stopped for the library without a word.
		$this->library(4);
		$this->known = [2 => true];
		$this->foreign = [3 => true];

		$this->runJob();

		$this->assertCount(3, $this->refreshed, 'the foreign row counts as never looked at, and 1 and 4 still come through');
	}

	public function testTheFileItselfIsLeftToAJobOfItsOwn(): void {
		$this->library(1);
		$this->runJob();

		$this->assertSame(
			[IFilesMetadataManager::PROCESS_LIVE],
			$this->refreshed,
			'live, so that only the cheap part happens here',
		);
	}

	public function testALibraryThatIsAllKnownIsLeftAlone(): void {
		$this->library(3);
		$this->known = [1 => true, 2 => true, 3 => true];

		$this->runJob();

		$this->assertSame([], $this->refreshed);
		$this->assertSame([], $this->queued, 'and nothing is asked for again');
	}

	public function testOnlyOneBatchIsDoneAtATime(): void {
		$this->library(RefreshMetadata::BATCH + 10);

		$this->runJob();

		$this->assertCount(RefreshMetadata::BATCH, $this->refreshed);
		$this->assertSame([RefreshMetadata::class], $this->queued, 'and it comes back for the rest');
	}

	public function testAFullBatchComesBackToSeeWhetherThereIsMore(): void {
		// The looking stops as soon as there is a batch, so a full batch
		// cannot tell whether the library is done. The run that finds
		// nothing left is the one that stops.
		$this->library(RefreshMetadata::BATCH);

		$this->runJob();

		$this->assertCount(RefreshMetadata::BATCH, $this->refreshed);
		$this->assertSame([RefreshMetadata::class], $this->queued);
	}

	public function testAGameThatIsNoLongerThereIsSkipped(): void {
		$this->library(2);
		$this->gone = [1 => true];

		$this->runJob();

		$this->assertCount(1, $this->refreshed);
	}

	public function testAJobWithoutAUserDoesNothing(): void {
		$this->library(2);
		$this->runJob([]);
		$this->runJob('not an array');

		$this->assertSame([], $this->refreshed);
	}
}
