<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Command\Cleanup;
use OCA\Arcade\Db\GameMapper;
use OCA\Arcade\Service\CleanupService;
use OCA\Arcade\Service\StateService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IUser;
use OCP\IUserManager;
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

	private function tester(): CommandTester {
		$stateService = $this->createStub(StateService::class);
		$stateService->method('folderKeyOf')->willReturnCallback(
			static fn (string $userId): string => hash('sha256', $userId),
		);
		$stateService->method('userFolders')->willReturnCallback(
			function (): array {
				$folders = [];
				foreach ($this->folders as $uid => $_) {
					$folder = $this->createStub(ISimpleFolder::class);
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
		$stateService->method('countLegacyFiles')->willReturnCallback(fn (): int => $this->legacy);

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
			new CleanupService($stateService, $gameMapper, $userManager, $rootFolder),
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

	public function testLegacyFilesAreOnlyCounted(): void {
		$this->legacy = 4;

		$tester = $this->tester();
		$tester->execute([]);

		$this->assertStringContainsString('4 files written before', $tester->getDisplay());
	}

	public function testNothingToDoSaysSoQuietly(): void {
		$tester = $this->tester();
		$tester->execute([]);

		$this->assertStringContainsString(
			'Removed the states of 0 users and 0 games',
			$tester->getDisplay(),
		);
		$this->assertStringNotContainsString('files written before', $tester->getDisplay());
	}
}
