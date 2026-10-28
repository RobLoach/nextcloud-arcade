<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Command\Cleanup;
use OCA\Arcade\Db\GameMapper;
use OCA\Arcade\Db\PlayMapper;
use OCA\Arcade\Service\CleanupService;
use OCA\Arcade\Service\LegacyAppDataSaves;
use OCA\Arcade\Service\StateService;
use OCA\Arcade\Service\UserFilesystems;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\ISetupManager;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What `occ arcade:cleanup` sweeps up: the saves of games and users that
 * no longer exist, and nothing that could still come back. The sweep
 * itself lives in CleanupService, so this covers both at once.
 */
class CleanupTest extends TestCase {
	/** The users the instance still has. */
	private array $users = ['alice'];
	/** The app data state folders, by owner uid (folders keyed by hash). */
	private array $folders = [];
	/** @var array<string, array<string, string>> registry: user => key => path */
	private array $games = [];
	/** The paths that exist in a user's files. */
	private array $paths = [];
	/** The file ids that still resolve somewhere, the trash included. */
	private array $stillAround = [];
	private int $legacy = 0;

	/** The folders deleted, by owner uid. */
	private array $deletedFolders = [];
	/** The games dropped, as "user:path". */
	private array $deletedGames = [];
	/** The registry users dropped wholesale. */
	private array $deletedUsers = [];
	/** @var array<string, list<int>> what each user played, by file id */
	private array $plays = [];
	/** The play records dropped, as "user:file id", or the user alone. */
	private array $forgottenPlays = [];

	private function filesystems(IUserManager $userManager): UserFilesystems {
		return new UserFilesystems(
			$this->createStub(ISetupManager::class),
			$userManager,
			$this->createStub(IUserSession::class),
		);
	}

	private function tester(): CommandTester {
		$stateService = $this->createStub(StateService::class);
		$stateService->method('gamesOf')->willReturnCallback(
			fn (string $userId): array => $this->games[$userId] ?? [],
		);
		$stateService->method('deleteAllForGame')->willReturnCallback(
			function (string $userId, string $path): void {
				$this->deletedGames[] = "$userId:$path";
			},
		);
		$stateService->method('deleteAllForUser')->willReturnCallback(
			function (string $userId): void {
				$this->deletedUsers[] = $userId;
			},
		);
		$legacy = $this->createStub(LegacyAppDataSaves::class);
				$legacy->method('userFolders')->willReturnCallback(
			function (): array {
				$folders = [];
				foreach ($this->folders as $uid => $_) {
					$folder = $this->createStub(Folder::class);
					$folder->method('delete')->willReturnCallback(
						function () use ($uid): void {
							$this->deletedFolders[] = $uid;
						},
					);
					$folders[hash('sha256', (string)$uid)] = $folder;
				}
				return $folders;
			},
		);
		$legacy->method('count')->willReturnCallback(
			fn (): array => ['files' => $this->legacy, 'bytes' => $this->legacy * 100],
		);
		$legacy->method('deleteAll')->willReturnCallback(
			function (): int {
				$removed = $this->legacy;
				$this->legacy = 0;
				return $removed;
			},
		);

		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('callForSeenUsers')->willReturnCallback(
			function (\Closure $callback): void {
				foreach ($this->users as $uid) {
					$user = $this->createStub(IUser::class);
					$user->method('getUID')->willReturn($uid);
					$callback($user);
				}
			},
		);

		$gameMapper = $this->createStub(GameMapper::class);
		$gameMapper->method('countsByUser')->willReturnCallback(
			fn (): array => array_map(count(...), $this->games),
		);

		$playMapper = $this->createStub(PlayMapper::class);
		$playMapper->method('totalsByUser')->willReturnCallback(
			fn (): array => array_map(fn (array $ids): array => ['plays' => count($ids), 'seconds' => 0], $this->plays),
		);
		$playMapper->method('statsOf')->willReturnCallback(
			fn (string $userId): array => array_fill_keys($this->plays[$userId] ?? [], ['seconds' => 0, 'plays' => 1, 'time' => 0]),
		);
		$playMapper->method('removeFiles')->willReturnCallback(
			function (string $userId, int ...$fileIds): void {
				foreach ($fileIds as $id) {
					$this->forgottenPlays[] = "$userId:$id";
				}
			},
		);
		$playMapper->method('deleteAllForUser')->willReturnCallback(
			function (string $userId): void {
				$this->forgottenPlays[] = $userId;
			},
		);

		$storageRoot = $this->createStub(Folder::class);
		$storageRoot->method('getFirstNodeById')->willReturnCallback(
			fn (int $id): ?Node => isset($this->stillAround[$id]) ? $this->createStub(Node::class) : null,
		);
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('nodeExists')->willReturnCallback(
			fn (string $path): bool => isset($this->paths[$path]),
		);
		$userFolder->method('getParent')->willReturn($storageRoot);
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		return new CommandTester(new Cleanup(
			new CleanupService($stateService, $gameMapper, $playMapper, $userManager, $rootFolder, $this->filesystems($userManager), $legacy),
		));
	}

	public function testTheFolderOfAUserThatIsGoneIsRemoved(): void {
		$this->folders = ['alice' => true, 'bob' => true];

		$tester = $this->tester();
		$tester->execute([]);

		$this->assertSame(['bob'], $this->deletedFolders);
		$this->assertStringContainsString(
			'Removed the states of 1 users and 0 games',
			$tester->getDisplay(),
		);
	}

	public function testTheSavesOfAGameThatIsGoneForGoodAreRemoved(): void {
		$this->folders = ['alice' => true];
		$this->games = ['alice' => ['7' => '/Games/Gone.nes', '9' => '/Games/Here.nes']];
		$this->paths = ['/Games/Here.nes' => true];

		$tester = $this->tester();
		$tester->execute([]);

		$this->assertSame(['alice:/Games/Gone.nes'], $this->deletedGames);
		$this->assertStringContainsString(
			'Removing the states of alice for /Games/Gone.nes',
			$tester->getDisplay(),
		);
	}

	public function testAGameSittingRestorablyInTheTrashKeepsItsSaves(): void {
		$this->folders = ['alice' => true];
		$this->games = ['alice' => ['7' => '/Games/Trashed.nes']];
		$this->stillAround = [7 => true];

		$tester = $this->tester();
		$tester->execute([]);

		$this->assertSame([], $this->deletedGames, 'it can come back, saves and all');
	}

	public function testARegistryUserWithoutAFolderIsStillSweptWhenGone(): void {
		// A user whose saves lived in their own files leaves nothing in the
		// app data; only the registry still names them.
		$this->games = ['carol' => ['3' => '/Games/Hers.gb']];

		$tester = $this->tester();
		$tester->execute([]);

		$this->assertSame(['carol'], $this->deletedUsers);
	}

	public function testADryRunReportsWithoutRemoving(): void {
		$this->folders = ['alice' => true, 'bob' => true];
		$this->games = ['alice' => ['7' => '/Games/Gone.nes'], 'carol' => ['3' => '/Games/Hers.gb']];

		$tester = $this->tester();
		$tester->execute(['--dry-run' => true]);

		$this->assertSame([], $this->deletedFolders);
		$this->assertSame([], $this->deletedGames);
		$this->assertSame([], $this->deletedUsers);
		$display = $tester->getDisplay();
		$this->assertStringContainsString('Dry run, nothing is removed.', $display);
		$this->assertStringContainsString('Removed the states of 2 users and 1 games', $display);
	}

	public function testWhatWasPlayedOfAGameThatIsGoneForGoodIsForgotten(): void {
		$this->plays = ['alice' => [7, 8, 9]];
		// 8 sits in the trash and can come back; 9 is still in the library.
		$this->stillAround = [8 => true, 9 => true];

		$tester = $this->tester();
		$tester->execute([]);

		$this->assertSame(['alice:7'], $this->forgottenPlays);
		$this->assertStringContainsString('Forgot what was played of 1 games that no longer exist', $tester->getDisplay());
	}

	public function testWhatAUserThatIsGonePlayedIsForgottenAndCountedOnce(): void {
		$this->games = ['carol' => ['3' => '/Games/Hers.gb']];
		$this->plays = ['carol' => [3], 'dave' => [4]];

		$tester = $this->tester();
		$tester->execute([]);

		$this->assertSame(['carol', 'dave'], $this->forgottenPlays);
		$this->assertStringContainsString('Removed the states of 2 users and 0 games', $tester->getDisplay());
	}

	public function testADryRunForgetsNothingThatWasPlayed(): void {
		$this->plays = ['alice' => [7], 'dave' => [4]];

		$tester = $this->tester();
		$tester->execute(['--dry-run' => true]);

		$this->assertSame([], $this->forgottenPlays);
		$this->assertStringContainsString('Would forget what was played of 1 games', $tester->getDisplay());
	}

	public function testWhatEarlierVersionsLeftIsOnlyCountedUnlessAsked(): void {
		$this->legacy = 4;

		$tester = $this->tester();
		$tester->execute([]);

		$this->assertStringContainsString('4 save files earlier versions left in the app data', $tester->getDisplay());
		$this->assertStringContainsString('Run with --legacy to remove them', $tester->getDisplay());
		$this->assertSame(4, $this->legacy);
	}

	public function testWhatEarlierVersionsLeftGoesWhenAsked(): void {
		$this->legacy = 4;

		$tester = $this->tester();
		$tester->execute(['--legacy' => true]);

		$this->assertSame(0, $this->legacy);
		$this->assertStringContainsString('Removed 4 save files earlier versions left', $tester->getDisplay());
		$this->assertStringNotContainsString('--legacy to remove', $tester->getDisplay());
	}

	public function testADryRunOnlySaysWhatEarlierVersionsLeftWouldGo(): void {
		$this->legacy = 4;

		$tester = $this->tester();
		$tester->execute(['--legacy' => true, '--dry-run' => true]);

		$this->assertSame(4, $this->legacy);
		$this->assertStringContainsString('Removing the 4 save files', $tester->getDisplay());
	}

	public function testNothingToDoSaysSoQuietly(): void {
		$tester = $this->tester();
		$tester->execute([]);

		$this->assertStringContainsString(
			'Removed the states of 0 users and 0 games',
			$tester->getDisplay(),
		);
		$this->assertStringNotContainsString('left in the app data', $tester->getDisplay());
	}
}
