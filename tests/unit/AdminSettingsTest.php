<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Service\BiosService;
use OCA\Arcade\Service\LibraryService;
use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Settings\Admin;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The admin settings page hides the BIOS section when the administrator
 * looking at it has no system folder to manage: the template gets the
 * resolved folder of that very administrator, '' when there is none.
 * (getForm() itself leans on Util and the server, so what is under test
 * here is the value it hands the template.)
 */
class AdminSettingsTest extends TestCase {
	private function admin(?string $userId, array $settings, ?LibraryService $library = null): Admin {
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
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($this->createStub(Folder::class));
		return new Admin(
			$settingsService,
			$biosService,
			$session,
			$library ?? $this->createStub(LibraryService::class),
			$rootFolder,
			$this->createStub(LoggerInterface::class),
		);
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

	public function testThePictureSamplesComeOutOfTheAdministratorsThumbnailsFolder(): void {
		$library = $this->createMock(LibraryService::class);
		$library->expects($this->once())->method('thumbnailSamples')
			->with('admin', $this->anything(), ['thumbnails_folder' => '/Thumbs'])
			->willReturn(['nes' => ['boxart' => 12]]);
		$library->method('thumbnailsVersion')->willReturn('etag');
		$admin = $this->admin('admin', ['thumbnails_folder' => '/Thumbs'], $library);
		$this->assertSame(
			['samples' => ['nes' => ['boxart' => 12]], 'version' => 'etag'],
			$admin->pictureSamples(),
		);
	}

	public function testNoThumbnailsFolderMeansNoPictureSamples(): void {
		$library = $this->createMock(LibraryService::class);
		$library->expects($this->never())->method('thumbnailSamples');
		$this->assertNull($this->admin('admin', ['thumbnails_folder' => ''], $library)->pictureSamples());
		$this->assertNull($this->admin(null, [], $library)->pictureSamples());
	}

	public function testAFolderThatCannotBeReadCostsTheSamplesNotThePage(): void {
		$library = $this->createStub(LibraryService::class);
		$library->method('thumbnailSamples')->willThrowException(new \RuntimeException('down'));
		$admin = $this->admin('admin', ['thumbnails_folder' => '/Thumbs'], $library);
		$this->assertNull($admin->pictureSamples());
	}
}
