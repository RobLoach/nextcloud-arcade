<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\BackgroundJob\FetchThumbnails;
use OCA\Arcade\Service\LibraryService;
use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Service\ThumbnailFetchService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Config\IUserConfig;
use OCP\IPreview;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The job that goes looking for box art: what it hands to the looking, how
 * it reports, and when it asks to be run again.
 */
class FetchThumbnailsTest extends TestCase {
	private const USER = 'alice';

	private ThumbnailFetchService&MockObject $fetchService;
	private IJobList&MockObject $jobList;
	private IPreview&MockObject $preview;
	private INotificationManager&MockObject $notifications;
	private string $status = '';
	/** What the run has found so far, as the user config would keep it. */
	private ?int $tally = null;
	/** @var array<string, mixed> */
	private array $settings = [];
	/** @var list<array<string, mixed>> */
	private array $games = [];
	private bool $libraryExists = true;

	protected function setUp(): void {
		$this->settings = [
			'library_folder' => '/Games',
			'thumbnails_folder' => '/Thumbs',
		];
		$this->games = [];
		$this->libraryExists = true;
		$this->status = '';
		$this->tally = null;
		$this->notifications = $this->createMock(INotificationManager::class);
		$this->fetchService = $this->createMock(ThumbnailFetchService::class);
		// The instance lets the server go looking, unless a test says not.
		$this->fetchService->method('isAllowed')->willReturn(true);
		// The word the job leaves about how it is getting on.
		$this->fetchService->method('report')->willReturnCallback(
			function (string $userId, string $message): void {
				$this->status = $message;
			},
		);
		$this->jobList = $this->createMock(IJobList::class);
		$this->preview = $this->createMock(IPreview::class);
	}

	private function job(): FetchThumbnails {
		$settingsService = $this->createStub(SettingsService::class);
		$settingsService->method('getUserSettings')->willReturnCallback(fn (): array => $this->settings);

		$libraryService = $this->createStub(LibraryService::class);
		$libraryService->method('getGames')->willReturnCallback(fn (): array => $this->games);

		$folder = $this->createStub(Folder::class);
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('get')->willReturnCallback(
			function (string $path) use ($folder): Folder {
				if (!$this->libraryExists) {
					throw new NotFoundException($path);
				}
				return $folder;
			},
		);
		$userFolder->method('newFolder')->willReturn($folder);
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$config = $this->createStub(IUserConfig::class);
		$config->method('setValueInt')->willReturnCallback(
			function (string $user, string $app, string $key, int $value): bool {
				$this->tally = $value;
				return true;
			},
		);
		$config->method('getValueInt')->willReturnCallback(
			fn (string $user, string $app, string $key, int $default = 0): int => $this->tally ?? $default,
		);
		$config->method('deleteUserConfig')->willReturnCallback(
			function (): void {
				$this->tally = null;
			},
		);

		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime());

		return new FetchThumbnails(
			$time,
			$settingsService,
			$libraryService,
			$this->fetchService,
			$rootFolder,
			$this->jobList,
			$config,
			$this->preview,
			$this->notifications,
			$this->createStub(LoggerInterface::class),
		);
	}

	/**
	 * A notification whose setters chain, as the real ones do, and whose
	 * subject parameters are kept for the test to look at.
	 *
	 * @param array<string, mixed> $seen filled with the subject parameters
	 */
	private function chainingNotification(array &$seen): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters = []) use (&$seen, $notification): INotification {
				$seen = ['subject' => $subject] + $parameters;
				return $notification;
			},
		);
		return $notification;
	}

	private function runJob(): void {
		$job = $this->job();
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, ['userId' => self::USER]);
	}

	/**
	 * @param int $count how many games have no picture
	 * @return list<array<string, mixed>>
	 */
	private function gamesWithout(int $count): array {
		$games = [];
		for ($i = 1; $i <= $count; $i++) {
			$games[] = ['path' => "/Games/Game $i.nes", 'basename' => "Game $i.nes", 'system' => 'nes'];
		}
		return $games;
	}

	public function testOnlyTheGamesWithoutAPictureAreLookedFor(): void {
		$this->games = [
			['path' => '/Games/Has one.nes', 'basename' => 'Has one.nes', 'system' => 'nes', 'thumbnails' => ['boxart' => 1]],
			['path' => '/Games/Has none.nes', 'basename' => 'Has none.nes', 'system' => 'nes'],
		];

		$this->fetchService->expects($this->once())
			->method('fetch')
			->with(
				self::USER,
				$this->callback(static fn (array $games): bool => count($games) === 1
					&& $games[0]['basename'] === 'Has none.nes'),
			)
			->willReturn(['fetched' => 1, 'missing' => 0, 'tried' => 1]);

		$this->runJob();
	}

	public function testARunStaysSmall(): void {
		$this->games = $this->gamesWithout(100);
		$this->fetchService->method('fetch')
			->with($this->anything(), $this->anything(), $this->anything(), FetchThumbnails::BATCH)
			->willReturn(['fetched' => 0, 'missing' => 100, 'tried' => FetchThumbnails::BATCH]);
		$this->jobList->expects($this->once())->method('add');

		$this->runJob();
		$this->assertLessThanOrEqual(10, FetchThumbnails::BATCH, 'a run is a handful of games, not a library');
	}

	public function testTheJobCarriesOnWhileThereIsMoreToLookFor(): void {
		$this->games = $this->gamesWithout(50);
		$this->fetchService->method('fetch')
			->willReturn(['fetched' => 2, 'missing' => 48, 'tried' => FetchThumbnails::BATCH]);

		$this->jobList->expects($this->once())
			->method('add')
			->with(FetchThumbnails::class, ['userId' => self::USER]);

		$this->runJob();
		$this->assertStringContainsString('still to go', $this->status);
	}

	public function testItStopsOnceItHasBeenThroughEverything(): void {
		$this->games = $this->gamesWithout(3);
		$this->fetchService->method('fetch')
			->willReturn(['fetched' => 2, 'missing' => 1, 'tried' => 3]);

		$this->jobList->expects($this->never())->method('add');

		$this->runJob();
		$this->assertStringContainsString('Found box art for 2 games', $this->status);
	}

	public function testNothingToDoIsSaidRatherThanDone(): void {
		$this->games = [
			['path' => '/Games/Mario.nes', 'basename' => 'Mario.nes', 'system' => 'nes', 'thumbnails' => ['boxart' => 1]],
		];
		$this->fetchService->expects($this->never())->method('fetch');

		$this->runJob();
		$this->assertStringContainsString('Every game has a picture', $this->status);
	}

	public function testWithoutAThumbnailsFolderThereIsNowhereToPutAnything(): void {
		$this->settings['thumbnails_folder'] = '';
		$this->fetchService->expects($this->never())->method('fetch');

		$this->runJob();
		$this->assertStringContainsString('No thumbnails folder', $this->status);
	}

	public function testWithoutALibraryThereIsNothingToLookFor(): void {
		$this->libraryExists = false;
		$this->fetchService->expects($this->never())->method('fetch');

		$this->runJob();
		$this->assertStringContainsString('library folder does not exist', $this->status);
	}

	public function testNewBoxArtIsWarmedAtTheSizesTheLibraryAsksFor(): void {
		$this->games = $this->gamesWithout(1);
		$file = $this->createStub(File::class);
		$this->fetchService->method('fetch')
			->willReturn(['fetched' => 1, 'missing' => 0, 'tried' => 1, 'written' => [$file]]);

		$warmed = [];
		$this->preview->expects($this->exactly(count(FetchThumbnails::PREVIEW_SIZES)))
			->method('getPreview')
			->willReturnCallback(
				function (File $for, int $x, int $y, bool $crop) use ($file, &$warmed) {
					$this->assertSame($file, $for, 'only the file written this run is warmed');
					$this->assertSame($x, $y, 'the library asks for a square');
					$this->assertFalse($crop, 'the library passes a=1, which means no crop');
					$warmed[] = $x;
					return $this->createStub(\OCP\Files\SimpleFS\ISimpleFile::class);
				},
			);

		$this->runJob();
		$this->assertSame([256, 64], $warmed, 'the grid size and the list size');
	}

	public function testAWarmUpThatFailsDoesNotBreakTheJob(): void {
		$this->games = $this->gamesWithout(2);
		$one = $this->createStub(File::class);
		$two = $this->createStub(File::class);
		$this->fetchService->method('fetch')
			->willReturn(['fetched' => 2, 'missing' => 0, 'tried' => 2, 'written' => [$one, $two]]);

		$warmed = [];
		$this->preview->method('getPreview')->willReturnCallback(
			function (File $for) use ($one, &$warmed) {
				$warmed[] = $for;
				if ($for === $one) {
					throw new \RuntimeException('no preview for you');
				}
				return $this->createStub(\OCP\Files\SimpleFS\ISimpleFile::class);
			},
		);

		$this->runJob();
		$this->assertContains($two, $warmed, 'the next file is still warmed');
		$this->assertStringContainsString('Found box art for 2 games', $this->status);
	}

	public function testTroubleIsReportedRatherThanThrown(): void {
		$this->games = $this->gamesWithout(1);
		$this->fetchService->method('fetch')
			->willThrowException(new \RuntimeException('the server said no'));

		$this->runJob();
		$this->assertStringContainsString('Something went wrong', $this->status);
	}

	public function testAFinishedRunSaysSoOnceUnderTheBell(): void {
		$this->games = $this->gamesWithout(3);
		$this->fetchService->method('fetch')
			->willReturn(['fetched' => 2, 'missing' => 1, 'tried' => 3]);

		$seen = [];
		$this->notifications->method('createNotification')
			->willReturn($this->chainingNotification($seen));
		$this->notifications->expects($this->once())->method('notify');

		$this->runJob();
		$this->assertSame('fetch_finished', $seen['subject']);
		$this->assertSame(2, $seen['found']);
	}

	public function testABatchInTheMiddleOfARunKeepsQuiet(): void {
		$this->games = $this->gamesWithout(50);
		$this->fetchService->method('fetch')
			->willReturn(['fetched' => 3, 'missing' => 47, 'tried' => FetchThumbnails::BATCH]);

		$this->notifications->expects($this->never())->method('notify');

		$this->runJob();
		$this->assertSame(3, $this->tally, 'the finds wait for the end of the run');
	}

	public function testTheNotificationCarriesTheTallyOfTheWholeRun(): void {
		// Earlier batches of this run already found five.
		$this->tally = 5;
		$this->games = $this->gamesWithout(2);
		$this->fetchService->method('fetch')
			->willReturn(['fetched' => 2, 'missing' => 0, 'tried' => 2]);

		$seen = [];
		$this->notifications->method('createNotification')
			->willReturn($this->chainingNotification($seen));
		$this->notifications->expects($this->once())->method('notify');

		$this->runJob();
		$this->assertSame(7, $seen['found'], 'the run is told as a whole, not batch by batch');
		$this->assertNull($this->tally, 'a told tally does not leak into the next run');
	}

	public function testARunWhoseLastBatchFetchedEverythingStillGetsItsWord(): void {
		// The last batch filled the library, requeued itself, and this is
		// the requeued job finding nothing left to look for.
		$this->tally = 10;
		$this->games = [
			['path' => '/Games/Mario.nes', 'basename' => 'Mario.nes', 'system' => 'nes', 'thumbnails' => ['boxart' => 1]],
		];

		$seen = [];
		$this->notifications->method('createNotification')
			->willReturn($this->chainingNotification($seen));
		$this->notifications->expects($this->once())->method('notify');

		$this->runJob();
		$this->assertSame(10, $seen['found']);
	}

	public function testANotificationThatFailsDoesNotFailTheJob(): void {
		$this->games = $this->gamesWithout(1);
		$this->fetchService->method('fetch')
			->willReturn(['fetched' => 1, 'missing' => 0, 'tried' => 1]);
		$this->notifications->method('notify')
			->willThrowException(new \RuntimeException('the bell is broken'));

		$this->runJob();
		$this->assertStringContainsString('Found box art for 1 games', $this->status);
	}
}
