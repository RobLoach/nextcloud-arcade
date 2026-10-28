<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Service\UserFilesystems;
use OCP\Files\ISetupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * A walk over every user lets go of each one's files when done with them,
 * but never where somebody signed in still needs their own.
 */
class UserFilesystemsTest extends TestCase {
	private int $tornDown = 0;

	private function filesystems(bool $signedIn = false, bool $alreadySetUp = false): UserFilesystems {
		$setup = $this->createStub(ISetupManager::class);
		$setup->method('isSetupComplete')->willReturn($alreadySetUp);
		$setup->method('tearDown')->willReturnCallback(function (): void {
			$this->tornDown++;
		});
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => $uid === 'alice' ? $this->createStub(IUser::class) : null,
		);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $this->createStub(IUser::class) : null);
		return new UserFilesystems($setup, $users, $session);
	}

	public function testABackgroundJobLetsGoOfEachUser(): void {
		$this->assertSame(3, $this->filesystems()->forUser('alice', fn () => 3));
		$this->assertSame(1, $this->tornDown);
	}

	public function testItLetsGoEvenWhenTheWorkFails(): void {
		try {
			$this->filesystems()->forUser('alice', function (): void {
				throw new \RuntimeException('broken');
			});
			$this->fail('the failure is passed on');
		} catch (\RuntimeException) {
		}
		$this->assertSame(1, $this->tornDown);
	}

	public function testARequestOfSomebodySignedInHoldsOn(): void {
		// The administration settings run the same walk in the
		// administrator's own request, which still needs their files.
		$this->filesystems(signedIn: true)->forUser('alice', fn () => null);
		$this->assertSame(0, $this->tornDown);
	}

	public function testFilesSetUpBeforeAreLeftAlone(): void {
		$this->filesystems(alreadySetUp: true)->forUser('alice', fn () => null);
		$this->assertSame(0, $this->tornDown);
	}

	public function testAUserThatIsNotThereIsNoReasonToTearDown(): void {
		$this->filesystems()->forUser('nobody', fn () => null);
		$this->assertSame(0, $this->tornDown);
	}
}
