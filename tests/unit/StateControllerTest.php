<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Activity\ActivityPublisher;
use OCA\Arcade\Controller\StateController;
use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Service\StateService;
use OCP\AppFramework\Http;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A StateController whose request body a test can write, php://input being
 * out of reach from here.
 */
class TestableStateController extends StateController {
	public string $body = '';

	protected function rawBody(int $limit): string|false {
		return substr($this->body, 0, $limit);
	}
}

/**
 * The endpoints the player manages its saves with, the battery save included.
 */
class StateControllerTest extends TestCase {
	private const GAME = '/Games/NES/Mario.nes';

	private StateService&MockObject $stateService;

	private function controller(
		string $savesFolder = '/Saves',
		?string $userId = 'alice',
		string $body = '',
		bool $fileExists = true,
		string $contentLength = '',
	): TestableStateController {
		$this->stateService = $this->createMock(StateService::class);
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getUserSettings')->willReturn(['saves_folder' => $savesFolder]);
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('nodeExists')->willReturn($fileExists);
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);
		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturn($contentLength);
		$controller = new TestableStateController(
			'arcade',
			$request,
			$this->stateService,
			$settings,
			$this->createStub(ActivityPublisher::class),
			$rootFolder,
			$userId,
		);
		$controller->body = $body;
		return $controller;
	}

	public function testSavingHandsTheBodyToTheSlot(): void {
		$controller = $this->controller(body: 'the state');
		$this->stateService->expects($this->once())->method('save')
			->with('alice', self::GAME, 2, 'the state');

		$response = $controller->save(self::GAME, 2);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['size' => strlen('the state')], $response->getData());
	}

	public function testSavingRefusesAnEmptyBody(): void {
		$controller = $this->controller();
		$this->stateService->expects($this->never())->method('save');

		$response = $controller->save(self::GAME, 2);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	/**
	 * A client that goes away mid-upload leaves a body that is only short,
	 * and a short save state is a broken one. Written out it would replace
	 * a save that worked, so the length the client promised is checked
	 * against the length that arrived.
	 */
	public function testSavingRefusesABodyCutShortOfItsContentLength(): void {
		$controller = $this->controller(body: 'half a st', contentLength: '32');
		$this->stateService->expects($this->never())->method('save');

		$response = $controller->save(self::GAME, 2);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSavingTakesABodyOfExactlyItsContentLength(): void {
		$controller = $this->controller(body: 'the state', contentLength: '9');
		$this->stateService->expects($this->once())->method('save')
			->with('alice', self::GAME, 2, 'the state');

		$this->assertSame(Http::STATUS_OK, $controller->save(self::GAME, 2)->getStatus());
	}

	/**
	 * Something in front of the app may rewrite a request and leave the
	 * header behind the body it describes. That is not the accident being
	 * guarded against, and an honest save should not fail for it.
	 */
	public function testSavingTakesABodyLongerThanItsContentLength(): void {
		$controller = $this->controller(body: 'the whole state', contentLength: '4');
		$this->stateService->expects($this->once())->method('save');

		$this->assertSame(Http::STATUS_OK, $controller->save(self::GAME, 2)->getStatus());
	}

	/**
	 * A body said to be past the ceiling is refused on the strength of
	 * the claim, without reading the ceiling's worth of it first.
	 */
	public function testSavingRefusesALengthPastTheCeilingWithoutReadingIt(): void {
		$controller = $this->controller(body: 'the state', contentLength: '99999999999');
		$this->stateService->expects($this->never())->method('save');

		$response = $controller->save(self::GAME, 2);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSavingTakesABodyWhenNoLengthWasPromised(): void {
		$controller = $this->controller(body: 'the state', contentLength: '');
		$this->stateService->expects($this->once())->method('save');

		$this->assertSame(Http::STATUS_OK, $controller->save(self::GAME, 2)->getStatus());
	}

	/**
	 * The battery save goes through the same door, and is the file a
	 * truncated write would cost the most: it is the game's own save.
	 */
	public function testTheBatterySaveRefusesABodyCutShort(): void {
		$controller = $this->controller(body: 'half', contentLength: '4096');
		$this->stateService->expects($this->never())->method('saveSram');

		$response = $controller->saveSram(self::GAME);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testTheListingSaysWhetherThereIsABatterySave(): void {
		$controller = $this->controller();
		$this->stateService->method('list')->willReturn([]);
		$this->stateService->method('hasSram')
			->with('alice', self::GAME)
			->willReturn(true);

		$data = $controller->list(self::GAME)->getData();

		$this->assertTrue($data['hasSram']);
		$this->assertSame(StateService::SLOTS, $data['slots']);
	}

	public function testDeletingTheBatterySaveRemovesIt(): void {
		$controller = $this->controller();
		$this->stateService->expects($this->once())->method('deleteSram')
			->with('alice', self::GAME)
			->willReturn(true);

		$response = $controller->deleteSram(self::GAME);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDeletingSaysWhenThereIsNoBatterySave(): void {
		$controller = $this->controller();
		$this->stateService->method('deleteSram')->willReturn(false);

		$response = $controller->deleteSram(self::GAME);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testSavingNeedsTheGameToExist(): void {
		// A path that exists nowhere in the user's files must not mint
		// save files for games the user does not have.
		$controller = $this->controller(body: 'the state', fileExists: false);
		$this->stateService->expects($this->never())->method('save');

		$response = $controller->save(self::GAME, 2);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testListingNeedsTheGameToExist(): void {
		$controller = $this->controller(fileExists: false);
		$this->stateService->expects($this->never())->method('list');

		$response = $controller->list(self::GAME);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testDeletingAStateWorksForAGameThatIsGone(): void {
		// Cleaning up after a deleted game targets a path whose file no
		// longer exists; the saves are found by the hash of that path.
		$controller = $this->controller(fileExists: false);
		$this->stateService->expects($this->once())->method('delete')
			->with('alice', self::GAME, 2)
			->willReturn(true);

		$response = $controller->delete(self::GAME, 2);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDeletingTheBatterySaveWorksForAGameThatIsGone(): void {
		$controller = $this->controller(fileExists: false);
		$this->stateService->expects($this->once())->method('deleteSram')
			->with('alice', self::GAME)
			->willReturn(true);

		$response = $controller->deleteSram(self::GAME);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDeletingNeedsASavesFolder(): void {
		$controller = $this->controller('');
		$this->stateService->expects($this->never())->method('deleteSram');

		$response = $controller->deleteSram(self::GAME);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testDeletingNeedsAUser(): void {
		$controller = $this->controller('/Saves', null);
		$this->stateService->expects($this->never())->method('deleteSram');

		$response = $controller->deleteSram(self::GAME);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
