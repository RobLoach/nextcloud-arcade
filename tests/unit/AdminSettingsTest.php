<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Service\BiosService;
use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Settings\Admin;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The admin settings page hides the BIOS section when the administrator
 * looking at it has no system folder to manage: the template gets the
 * resolved folder of that very administrator, '' when there is none.
 * (getForm() itself leans on Util and the server, so what is under test
 * here is the value it hands the template.)
 */
class AdminSettingsTest extends TestCase {
	private function admin(?string $userId, array $settings): Admin {
		$session = $this->createStub(IUserSession::class);
		if ($userId === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createStub(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$session->method('getUser')->willReturn($user);
		}
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getUserSettings')
			->with($userId ?? $this->anything())
			->willReturn($settings);
		$biosService = new BiosService(
			$this->createStub(IAppDataFactory::class),
			$this->createStub(IRootFolder::class),
			$settingsService,
		);
		return new Admin($settingsService, $biosService, $session);
	}

	public function testTheResolvedSystemFolderOfTheAdministratorIsHandedOver(): void {
		$admin = $this->admin('admin', ['system_folder' => '/System']);
		$this->assertSame('/System', $admin->systemFolder());
	}

	public function testNoSystemFolderMeansAnEmptyStringAndAHiddenSection(): void {
		$admin = $this->admin('admin', ['system_folder' => '']);
		$this->assertSame('', $admin->systemFolder());
	}

	public function testNoUserMeansAnEmptyStringAsWell(): void {
		$admin = $this->admin(null, []);
		$this->assertSame('', $admin->systemFolder());
	}
}
