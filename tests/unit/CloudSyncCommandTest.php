<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Command\CloudSync;
use OCA\Arcade\Db\GameMapper;
use OCA\Arcade\Service\CleanupService;
use OCA\Arcade\Service\StateService;
use OCA\Arcade\Service\UserFilesystems;
use OCP\Files\IRootFolder;
use OCP\Files\ISetupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What `occ arcade:cloudsync` puts right, and the weekly job with it.
 */
class CloudSyncCommandTest extends TestCase {
	/** What putting each user's manifest right answers, or the error it throws. */
	private array $answers = [];
	/** The users asked about, with whether thoroughly. */
	private array $asked = [];

	private function service(): CleanupService {
		$stateService = $this->createStub(StateService::class);
		$stateService->method('reconcile')->willReturnCallback(
			function (string $userId, bool $full): ?array {
				$this->asked[] = $userId . ($full ? ' (full)' : '');
				$answer = $this->answers[$userId] ?? null;
				if ($answer instanceof \Throwable) {
					throw $answer;
				}
				return $answer;
			},
		);
		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('callForSeenUsers')->willReturnCallback(
			function (\Closure $callback): void {
				foreach (array_keys($this->answers) as $uid) {
					$user = $this->createStub(IUser::class);
					$user->method('getUID')->willReturn((string)$uid);
					$callback($user);
				}
			},
		);
		return new CleanupService(
			$stateService,
			$this->createStub(GameMapper::class),
			$userManager,
			$this->createStub(IRootFolder::class),
			new UserFilesystems(
				$this->createStub(ISetupManager::class),
				$userManager,
				$this->createStub(IUserSession::class),
			),
		);
	}

	public function testEverybodyIsPutRightAndOneBrokenManifestStopsNobody(): void {
		$this->answers = [
			'alice' => ['recorded' => 2, 'removed' => 1],
			'bob' => new \RuntimeException('The Cloud Sync manifest cannot be read'),
			'carol' => null,
			'dave' => ['recorded' => 0, 'removed' => 0],
		];
		$tester = new CommandTester(new CloudSync($this->service()));

		$this->assertSame(0, $tester->execute([]));

		$display = $tester->getDisplay();
		$this->assertStringContainsString('Recorded 2 and marked 1 as deleted in the Cloud Sync manifest of alice', $display);
		$this->assertStringContainsString('Could not put the Cloud Sync manifest of bob right', $display);
		$this->assertStringContainsString('Recorded 2 files and marked 1 as deleted, for 1 users', $display);
		$this->assertSame(['alice', 'bob', 'carol', 'dave'], $this->asked);
	}

	public function testOneUserCanBeHashedThroughout(): void {
		$this->answers = ['alice' => ['recorded' => 0, 'removed' => 0], 'bob' => null];
		$tester = new CommandTester(new CloudSync($this->service()));

		$tester->execute(['--user' => 'alice', '--full' => true]);

		$this->assertSame(['alice (full)'], $this->asked);
	}
}
