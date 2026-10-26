<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Command\Status;
use OCA\Arcade\Db\GameMapper;
use OCA\Arcade\Db\PlayMapper;
use OCA\Arcade\Service\Maintenance;
use OCA\Arcade\Service\StateService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What `occ arcade:status` reports: what no file listing shows, put to the
 * users it belongs to, and what earlier versions left behind.
 */
class StatusTest extends TestCase {
	/** @var array<string, int> games with saves, by user */
	private array $games = [];
	/** @var array<string, array{plays: int, seconds: int}> */
	private array $plays = [];
	/** What earlier versions left in the app data. */
	private array $appData = ['files' => 0, 'bytes' => 0];
	/** What is wrong with the tables. */
	private array $problems = [];

	private function tester(): CommandTester {
		$stateService = $this->createStub(StateService::class);
		$stateService->method('appDataSaves')->willReturnCallback(fn (): array => $this->appData);

		$gameMapper = $this->createStub(GameMapper::class);
		$gameMapper->method('countsByUser')->willReturnCallback(fn (): array => $this->games);

		$playMapper = $this->createStub(PlayMapper::class);
		$playMapper->method('totalsByUser')->willReturnCallback(fn (): array => $this->plays);

		$maintenance = $this->createStub(Maintenance::class);
		$maintenance->method('databaseProblems')->willReturnCallback(fn (): array => $this->problems);

		return new CommandTester(new Status($stateService, $gameMapper, $playMapper, $maintenance));
	}

	public function testEveryUserWithSavesOrPlaysIsReportedWithTheTotals(): void {
		$this->games = ['bob' => 2, 'alice' => 3];
		$this->plays = ['alice' => ['plays' => 5, 'seconds' => 3900], 'carol' => ['plays' => 1, 'seconds' => 30]];
		$tester = $this->tester();

		$tester->execute([]);

		$display = $tester->getDisplay();
		$this->assertMatchesRegularExpression('/alice\s+3\s+5\s+1h 05m/', $display);
		$this->assertMatchesRegularExpression('/bob\s+2\s+0\s+0s/', $display);
		$this->assertMatchesRegularExpression('/carol\s+0\s+1\s+30s/', $display);
		$this->assertMatchesRegularExpression('/Total\s+5\s+6\s+1h 05m/', $display);
		// In order of the users, whichever table they came from.
		$this->assertLessThan(strpos($display, 'bob'), strpos($display, 'alice'));
	}

	public function testNothingStoredSaysSo(): void {
		$tester = $this->tester();
		$tester->execute([]);
		$this->assertStringContainsString('Nothing is stored', $tester->getDisplay());
	}

	public function testWhatEarlierVersionsLeftIsReportedWithTheWayToRemoveIt(): void {
		$this->appData = ['files' => 4, 'bytes' => 3 * 1024 * 1024];
		$tester = $this->tester();

		$tester->execute([]);

		$display = $tester->getDisplay();
		$this->assertStringContainsString('4 save files (3.0 MB)', $display);
		$this->assertStringContainsString('arcade:cleanup --legacy', $display);
	}

	public function testNothingLeftSaysNothingAboutIt(): void {
		$this->games = ['alice' => 1];
		$tester = $this->tester();
		$tester->execute([]);
		$this->assertStringNotContainsString('--legacy', $tester->getDisplay());
	}

	public function testJsonSaysTheSameThingsByMachine(): void {
		$this->games = ['alice' => 3];
		$this->plays = ['alice' => ['plays' => 5, 'seconds' => 60]];
		$this->appData = ['files' => 1, 'bytes' => 10];
		$tester = $this->tester();

		$tester->execute(['--json' => true]);

		$status = json_decode($tester->getDisplay(), true);
		$this->assertSame(['users', 'totals', 'appDataSaves'], array_keys($status));
		$this->assertSame([['user' => 'alice', 'games' => 3, 'plays' => 5, 'seconds' => 60]], $status['users']);
		$this->assertSame(['files' => 1, 'bytes' => 10], $status['appDataSaves']);
	}

	public function testMissingTablesAreSaidPlainlyWithWhatToDo(): void {
		$this->problems = ['The table oc_arcade_games is missing'];
		$tester = $this->tester();

		$this->assertSame(1, $tester->execute([]));

		$display = $tester->getDisplay();
		$this->assertStringContainsString('The table oc_arcade_games is missing', $display);
		$this->assertStringContainsString('occ arcade:repair', $display);
	}
}
