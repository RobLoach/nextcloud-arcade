<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Activity\ActivityPublisher;
use OCA\Arcade\Db\PlayMapper;
use OCA\Arcade\Service\RecentService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\ITagManager;
use OCP\ITags;
use PHPUnit\Framework\TestCase;

class RecentServiceTest extends TestCase {
	private const USER = 'alice';

	/** The rows of the plays table, by file id, as the mapper keeps them. */
	private array $plays = [];
	/** Stands in for the autoincrementing id, which breaks ties. */
	private int $sequence = 0;
	/** The file ids the user has starred in Files, as keys. */
	private array $starred = [];
	/** The files the user has, by path. */
	private array $files = [
		'/Games/Mario.nes' => 101,
		'/Games/Zelda.sfc' => 102,
		'/Games/NES/Mario.nes' => 103,
		'/Games/SNES/Zelda.sfc' => 104,
		'/Games/Nintendo - Super Nintendo Entertainment System/NHL 96.zip' => 105,
		'/Games/Unsorted/Mystery.zip' => 106,
	];

	private function service(?ActivityPublisher $publisher = null): RecentService {
		// Anything else the tests play is a file of its own.
		for ($i = 1; $i <= 20; $i++) {
			$this->files["/Games/Game $i.nes"] ??= 200 + $i;
		}

		$tags = $this->createStub(ITags::class);
		$tags->method('getFavorites')->willReturnCallback(fn (): array => array_keys($this->starred));
		$tags->method('addToFavorites')->willReturnCallback(
			function ($id): bool {
				$this->starred[(int)$id] = true;
				return true;
			},
		);
		$tags->method('removeFromFavorites')->willReturnCallback(
			function ($id): bool {
				unset($this->starred[(int)$id]);
				return true;
			},
		);
		$tagManager = $this->createStub(ITagManager::class);
		$tagManager->method('load')->willReturn($tags);

		$folder = $this->createStub(Folder::class);
		$folder->method('get')->willReturnCallback(
			function (string $path): File {
				if (!isset($this->files[$path])) {
					throw new NotFoundException($path);
				}
				$file = $this->createStub(File::class);
				$file->method('getId')->willReturn($this->files[$path]);
				return $file;
			},
		);
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($folder);

		return new RecentService(
			$tagManager,
			$rootFolder,
			$this->playMapper(),
			$publisher ?? $this->createStub(ActivityPublisher::class),
		);
	}

	/**
	 * The table, as the mapper presents it: rows counted in place, ordered
	 * by the moment last played and then by the newest row.
	 */
	private function playMapper(): PlayMapper {
		$mapper = $this->createStub(PlayMapper::class);
		$mapper->method('recentFileIds')->willReturnCallback(
			function (string $userId, int $limit): array {
				$rows = array_filter($this->plays, static fn (array $row): bool => $row['plays'] > 0);
				uasort(
					$rows,
					static fn (array $a, array $b): int => [$b['time'], $b['seq']] <=> [$a['time'], $a['seq']],
				);
				return array_slice(array_keys($rows), 0, $limit);
			},
		);
		$mapper->method('statsOf')->willReturnCallback(
			fn (string $userId): array => array_map(
				static fn (array $row): array => [
					'seconds' => $row['seconds'],
					'plays' => $row['plays'],
					'time' => $row['time'],
				],
				$this->plays,
			),
		);
		$mapper->method('recordPlay')->willReturnCallback(
			function (string $userId, int $fileId, int $time): void {
				if (isset($this->plays[$fileId])) {
					$this->plays[$fileId]['plays']++;
					// As the mapper does it: played again within the same
					// second, the game still moves to the front.
					$this->plays[$fileId]['time'] = max($this->plays[$fileId]['time'] + 1, $time);
					return;
				}
				$this->plays[$fileId] = ['plays' => 1, 'seconds' => 0, 'time' => $time, 'seq' => ++$this->sequence];
			},
		);
		$mapper->method('addSeconds')->willReturnCallback(
			function (string $userId, int $fileId, int $seconds, int $time): void {
				if (isset($this->plays[$fileId])) {
					$this->plays[$fileId]['seconds'] += $seconds;
					return;
				}
				$this->plays[$fileId] = ['plays' => 0, 'seconds' => $seconds, 'time' => $time, 'seq' => ++$this->sequence];
			},
		);
		$mapper->method('importPlay')->willReturnCallback(
			function (string $userId, int $fileId, int $plays, int $seconds, int $time): void {
				$this->plays[$fileId] ??= [
					'plays' => $plays,
					'seconds' => $seconds,
					'time' => $time,
					'seq' => ++$this->sequence,
				];
			},
		);
		$mapper->method('deleteAllForUser')->willReturnCallback(
			function (string $userId): void {
				$this->plays = [];
			},
		);
		return $mapper;
	}

	public function testNothingIsRememberedToStartWith(): void {
		$this->assertSame([], $this->service()->get(self::USER));
	}

	public function testAGameThatIsGoneIsNotRemembered(): void {
		$service = $this->service();
		$service->record(self::USER, '/Games/Nothing There.nes');
		$this->assertSame([], $service->get(self::USER), 'there is no id to remember it by');
	}

	public function testGamesAreRememberedNewestFirst(): void {
		$service = $this->service();
		$service->record(self::USER, '/Games/NES/Mario.nes');
		$service->record(self::USER, '/Games/SNES/Zelda.sfc');

		// By file id: what a game is called is the library's to say, and
		// changes when it is renamed.
		$this->assertSame([104, 103], $service->get(self::USER));
	}

	public function testPlayingAgainMovesAGameBackToTheFront(): void {
		$service = $this->service();
		$service->record(self::USER, '/Games/Mario.nes');
		$service->record(self::USER, '/Games/Zelda.sfc');
		$service->record(self::USER, '/Games/Mario.nes');

		$this->assertSame([101, 102], $service->get(self::USER), 'a game is not remembered twice');
	}

	public function testOnlyTheLastTwelveGamesAreListed(): void {
		$service = $this->service();
		for ($i = 1; $i <= 20; $i++) {
			$service->record(self::USER, "/Games/Game $i.nes");
		}
		$recent = $service->get(self::USER);
		$this->assertCount(12, $recent);
		$this->assertSame(220, $recent[0], 'the twentieth game, played last');
		$this->assertSame(209, $recent[11]);
	}

	public function testPlayTimeAddsUpAcrossSessions(): void {
		$service = $this->service();
		$service->record(self::USER, '/Games/Mario.nes');
		$service->addPlayTime(self::USER, '/Games/Mario.nes', 300);
		$service->addPlayTime(self::USER, '/Games/Mario.nes', 120);
		$this->assertSame(420, $service->stats(self::USER)[101]['seconds']);
	}

	public function testPlayTimeSurvivesPlayingAgain(): void {
		$service = $this->service();
		$service->record(self::USER, '/Games/Mario.nes');
		$service->addPlayTime(self::USER, '/Games/Mario.nes', 300);
		$service->record(self::USER, '/Games/Mario.nes');

		$counted = $service->stats(self::USER)[101];
		$this->assertSame(300, $counted['seconds'], 'the time played is carried over');
		$this->assertSame(2, $counted['plays']);
	}

	public function testWhatAGameWasPlayedForOutlivesTheRecentList(): void {
		$service = $this->service();
		$service->record(self::USER, '/Games/Mario.nes');
		$service->addPlayTime(self::USER, '/Games/Mario.nes', 300);
		// Pushed off the end of the list of twelve.
		for ($i = 1; $i <= 20; $i++) {
			$service->record(self::USER, "/Games/Game $i.nes");
		}

		$this->assertNotContains(101, $service->get(self::USER), 'it is out of the recent list');
		$this->assertSame(
			300,
			$service->stats(self::USER)[101]['seconds'] ?? null,
			'but what it was played for is still counted, under the id of the file',
		);
	}

	public function testAGameOnlyEverReportedForIsNotCalledRecent(): void {
		// A session whose start was never seen still counts its time, but
		// the game was not started here, and the recent list says what was.
		$service = $this->service();
		$service->addPlayTime(self::USER, '/Games/Mario.nes', 300);

		$this->assertSame([], $service->get(self::USER));
		$this->assertSame(300, $service->stats(self::USER)[101]['seconds']);
	}

	public function testAForgottenTabDoesNotCountForHours(): void {
		$service = $this->service();
		$service->record(self::USER, '/Games/Mario.nes');
		$service->addPlayTime(self::USER, '/Games/Mario.nes', 10 * 3600);
		$this->assertSame(4 * 3600, $service->stats(self::USER)[101]['seconds']);
	}

	public function testNegativePlayTimeIsIgnored(): void {
		$service = $this->service();
		$service->record(self::USER, '/Games/Mario.nes');
		$service->addPlayTime(self::USER, '/Games/Mario.nes', -60);
		$this->assertSame(0, $service->stats(self::USER)[101]['seconds']);
	}

	public function testEverythingOfAUserGoesWithThem(): void {
		$service = $this->service();
		$service->record(self::USER, '/Games/Mario.nes');
		$service->deleteAllForUser(self::USER);
		$this->assertSame([], $service->get(self::USER));
		$this->assertSame([], $service->stats(self::USER));
	}

	public function testALaunchIsToldToTheActivityStream(): void {
		$publisher = $this->createMock(ActivityPublisher::class);
		$publisher->expects($this->once())->method('gameStarted')
			->with(self::USER, '/Games/Mario.nes');

		$this->service($publisher)->record(self::USER, '/Games/Mario.nes');
	}

	public function testAGameThatIsGoneIsNoActivityEither(): void {
		$publisher = $this->createMock(ActivityPublisher::class);
		$publisher->expects($this->never())->method('gameStarted');

		$this->service($publisher)->record(self::USER, '/Games/Nothing There.nes');
	}

	public function testASessionIsToldToTheActivityStreamClamped(): void {
		$publisher = $this->createMock(ActivityPublisher::class);
		// The stream hears the same clamped figure the table keeps.
		$publisher->expects($this->once())->method('sessionEnded')
			->with(self::USER, '/Games/Mario.nes', 4 * 3600);

		$this->service($publisher)->addPlayTime(self::USER, '/Games/Mario.nes', 10 * 3600);
	}

	public function testAFavoriteIsTheStarOfTheFilesApp(): void {
		$service = $this->service();

		$this->assertTrue($service->toggleFavorite(self::USER, '/Games/Mario.nes'));
		$this->assertSame([101], array_keys($this->starred), 'starred by file id, where Files keeps it');
		$this->assertSame([101 => true], $service->favoriteIds(self::USER));

		$this->assertFalse($service->toggleFavorite(self::USER, '/Games/Mario.nes'));
		$this->assertSame([], $this->starred);
	}

	public function testAGameStarredInFilesIsAFavoriteHereToo(): void {
		$this->starred[102] = true;
		$this->assertSame([102 => true], $this->service()->favoriteIds(self::USER));
	}

	public function testAGameThatIsGoneCannotBeStarred(): void {
		$service = $this->service();
		$this->assertFalse($service->toggleFavorite(self::USER, '/Games/Nothing There.nes'));
		$this->assertSame([], $this->starred);
	}

}
