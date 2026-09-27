<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Controller\BiosController;
use OCA\Arcade\Service\BiosService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A BiosController whose request body a test can write, php://input being
 * out of reach from here.
 */
class TestableBiosController extends BiosController {
	public string $body = '';

	protected function readBody(int $limit): string|false {
		return substr($this->body, 0, $limit);
	}
}

/**
 * The admin endpoints an administrator manages their BIOS files with. The
 * files live in the administrator's own system folder; the instance-wide
 * store only shows through as the read-only fallback it is.
 */
class BiosControllerTest extends TestCase {
	private BiosService&MockObject $biosService;

	private function controller(string $body = '', ?string $userId = 'admin'): TestableBiosController {
		$this->biosService = $this->createMock(BiosService::class);
		$controller = new TestableBiosController(
			'arcade',
			$this->createStub(IRequest::class),
			$this->biosService,
			$userId,
		);
		$controller->body = $body;
		return $controller;
	}

	public function testGetHandsOutTheFileForTheAskingUser(): void {
		$controller = $this->controller();
		$this->biosService->expects($this->once())->method('readFor')
			->with('admin', 'gb_bios.bin')
			->willReturn('the firmware');

		$response = $controller->get('gb_bios.bin');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('the firmware', $response->getData());
		$this->assertSame('application/octet-stream', $response->getHeaders()['Content-Type']);
	}

	public function testGetWithoutAUserAsksForTheStoreOnly(): void {
		$controller = $this->controller(userId: null);
		$this->biosService->expects($this->once())->method('readFor')
			->with(null, 'gb_bios.bin')
			->willReturn('the firmware');

		$this->assertSame(Http::STATUS_OK, $controller->get('gb_bios.bin')->getStatus());
	}

	public function testGetSaysWhenNobodyHasTheFile(): void {
		$controller = $this->controller();
		$this->biosService->method('readFor')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->get('gb_bios.bin')->getStatus());
	}

	public function testStatusIsTheStatusOfTheAskingAdministrator(): void {
		$controller = $this->controller();
		$status = [
			'folder' => '/System',
			'systems' => [[
				'system' => ['id' => 'gb', 'name' => 'Game Boy'],
				'files' => [['name' => 'gb_bios.bin', 'present' => true, 'source' => 'folder', 'size' => 100]],
			]],
			'extra' => [['name' => 'stray.bin', 'size' => 5, 'source' => 'store']],
		];
		$this->biosService->expects($this->once())->method('statusFor')
			->with('admin')
			->willReturn($status);

		$response = $controller->status();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($status, $response->getData());
	}

	public function testStatusNeedsSomebodyToAskFor(): void {
		$controller = $this->controller(userId: null);
		$this->biosService->expects($this->never())->method('statusFor');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->status()->getStatus());
	}

	public function testUploadRefusesANameNoCoreAsksFor(): void {
		$controller = $this->controller('firmware');
		$this->biosService->expects($this->never())->method('storeUpload');

		$response = $controller->upload('malware.php');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUploadStoresUnderTheCanonicalNameInTheFolder(): void {
		$controller = $this->controller('the firmware');
		$this->biosService->expects($this->once())->method('storeUpload')
			->with('admin', 'gb_bios.bin', 'the firmware')
			->willReturn(true);

		$response = $controller->upload('GB_BIOS.BIN');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['name' => 'gb_bios.bin', 'size' => strlen('the firmware')],
			$response->getData(),
		);
	}

	public function testUploadRefusesAFileTooBigToBeABios(): void {
		$controller = $this->controller(str_repeat('x', 16 * 1024 * 1024 + 1));
		$this->biosService->expects($this->never())->method('storeUpload');

		$response = $controller->upload('gb_bios.bin');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUploadRefusesAnEmptyBody(): void {
		$controller = $this->controller('');
		$this->biosService->expects($this->never())->method('storeUpload');

		$response = $controller->upload('gb_bios.bin');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUploadSaysWhenTheFolderCannotTakeIt(): void {
		$controller = $this->controller('the firmware');
		$this->biosService->method('storeUpload')->willReturn(false);

		$response = $controller->upload('gb_bios.bin');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	public function testRemoveTakesAFileOutOfTheFolder(): void {
		$controller = $this->controller();
		$this->biosService->expects($this->once())->method('deleteFor')
			->with('admin', 'gb_bios.bin')
			->willReturn('deleted');

		$response = $controller->remove('GB_BIOS.bin');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testRemoveRefusesANameNoCoreAsksFor(): void {
		$controller = $this->controller();
		$this->biosService->expects($this->never())->method('deleteFor');

		$response = $controller->remove('../config.php');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testRemoveLeavesTheInstanceStoreToOcc(): void {
		$controller = $this->controller();
		$this->biosService->method('deleteFor')->willReturn('store');

		$response = $controller->remove('gb_bios.bin');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['storeOnly' => true], $response->getData());
	}

	public function testRemoveSaysWhenThereWasNothingToRemove(): void {
		$controller = $this->controller();
		$this->biosService->method('deleteFor')->willReturn('missing');

		$response = $controller->remove('gb_bios.bin');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
