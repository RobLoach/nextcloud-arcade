<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Controller\ScreenshotController;
use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Service\ThumbnailService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The gallery of a game, and the one write it allows: a screenshot of the
 * user's own, thrown away by its file id.
 */
class ScreenshotControllerTest extends TestCase {
	private const GAME = '/Games/NES/Mario.nes';

	private Folder&MockObject $screenshots;
	private ThumbnailService&MockObject $thumbnailService;

	/**
	 * @param ?File $found what the screenshots folder holds under the id
	 *                     the request names, or null for nothing of its own
	 * @param ?File $elsewhere what the whole of the user's files holds
	 *                         under that id, for the tests that make sure
	 *                         the lookup never goes that wide
	 */
	private function controller(
		string $screenshotsFolder = '/Screenshots',
		?string $userId = 'alice',
		?File $found = null,
		bool $folderExists = true,
		?File $elsewhere = null,
	): ScreenshotController {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getUserSettings')->willReturn(['screenshots_folder' => $screenshotsFolder]);

		$this->screenshots = $this->createMock(Folder::class);
		$this->screenshots->method('getFirstNodeById')->willReturn($found);
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturn($elsewhere);
		if ($folderExists) {
			$userFolder->method('get')->willReturn($this->screenshots);
		} else {
			$userFolder->method('get')->willThrowException(new NotFoundException());
		}
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->thumbnailService = $this->createMock(ThumbnailService::class);

		return new ScreenshotController(
			'arcade',
			$this->createStub(IRequest::class),
			$settings,
			$this->thumbnailService,
			$rootFolder,
			$userId,
		);
	}

	private function screenshot(): File&MockObject {
		return $this->createMock(File::class);
	}

	public function testAScreenshotOfTheFolderIsDeleted(): void {
		$file = $this->screenshot();
		$file->expects($this->once())->method('delete');
		$controller = $this->controller(found: $file);

		$response = $controller->delete(42);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAFileOutsideTheScreenshotsFolderIsNotDeleted(): void {
		// The lookup runs inside the screenshots folder, so an id naming
		// anything else -- a tax return, a whole folder of photos -- finds
		// nothing here. Were it ever asked of the user's files as a whole,
		// this endpoint would delete anything its user can reach by id,
		// which is what the file below stands in for.
		$elsewhere = $this->screenshot();
		$elsewhere->expects($this->never())->method('delete');
		$controller = $this->controller(found: null, elsewhere: $elsewhere);

		$response = $controller->delete(42);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testTheLookupStaysInsideTheScreenshotsFolder(): void {
		$file = $this->screenshot();
		$controller = $this->controller(found: $file);
		$this->screenshots->expects($this->once())
			->method('getFirstNodeById')
			->with(42)
			->willReturn($file);

		$controller->delete(42);
	}

	public function testNothingIsDeletedWithoutAFileId(): void {
		$controller = $this->controller();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->delete(0)->getStatus());
	}

	public function testNothingIsDeletedWithoutAUser(): void {
		// A public share page reaches the routes but owns no folder.
		$controller = $this->controller(userId: null);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->delete(42)->getStatus());
	}

	public function testNothingIsDeletedWithoutAScreenshotsFolderSet(): void {
		$controller = $this->controller(screenshotsFolder: '');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->delete(42)->getStatus());
	}

	public function testNothingIsDeletedWhenTheFolderIsGone(): void {
		// The setting still names a folder the user has since removed.
		$controller = $this->controller(folderExists: false);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->delete(42)->getStatus());
	}

	public function testTheGalleryIsListedForItsGame(): void {
		$controller = $this->controller();
		$this->thumbnailService->expects($this->once())
			->method('screenshotsFor')
			// By base name: the gallery of a game is the shots named after
			// it, wherever the game itself sits.
			->with($this->screenshots, 'Mario.nes')
			->willReturn([['name' => 'Mario.nes-001.png']]);

		$response = $controller->list(self::GAME);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'folder' => '/Screenshots',
			'screenshots' => [['name' => 'Mario.nes-001.png']],
		], $response->getData());
	}

	public function testAnEmptyGalleryIsListedWithoutAFolderSet(): void {
		// Nothing is wrong with having no screenshots folder, so the panel
		// is told it is empty rather than that it failed.
		$controller = $this->controller(screenshotsFolder: '');

		$response = $controller->list(self::GAME);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['folder' => '', 'screenshots' => []], $response->getData());
	}

	public function testNoGalleryIsListedWithoutAGame(): void {
		$controller = $this->controller();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->list('')->getStatus());
	}
}
