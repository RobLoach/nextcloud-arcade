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
		string $core = '',
		string $f = '',
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
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => match ($key) {
				'core' => $core,
				'f' => $f,
				default => $default,
			},
		);
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
			->with('alice', self::GAME, 2, 'the state')
			->willReturn(1234);

		$response = $controller->save(self::GAME, 2);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// The moment it was written, which the player tells its own save
		// apart from somebody else's later one by.
		$this->assertSame(['size' => strlen('the state'), 'mtime' => 1234], $response->getData());
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

	/**
	 * The automatic slot and the numbered ones the player offers are
	 * written; slot 0 and the far ones are RetroArch's, only to be read.
	 */
	public function testOnlyTheSlotsThePlayerOffersAreWritten(): void {
		foreach ([StateService::AUTO_SLOT => Http::STATUS_OK, 1 => Http::STATUS_OK, StateService::SLOTS => Http::STATUS_OK,
			0 => Http::STATUS_BAD_REQUEST, StateService::SLOTS + 1 => Http::STATUS_BAD_REQUEST, -2 => Http::STATUS_BAD_REQUEST] as $slot => $status) {
			$response = $this->controller(body: 'the state')->save(self::GAME, $slot);
			$this->assertSame($status, $response->getStatus(), "slot $slot");
		}
	}

	public function testAnySlotRetroArchSavedIntoCanBeLoaded(): void {
		$controller = $this->controller();
		// Asked for, and found empty: a slot turned away is never asked.
		$this->stateService->expects($this->once())->method('load')->with('alice', self::GAME, 990)->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->get(self::GAME, 990)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->get(self::GAME, StateService::HIGHEST_SLOT + 1)->getStatus());
	}

	public function testTheCoreThePlayerRanIsHandedOn(): void {
		$controller = $this->controller(body: 'the state', core: 'snes9x');
		$this->stateService->expects($this->once())->method('useCore')->with('alice', self::GAME, 'snes9x');

		$controller->save(self::GAME, 1);
	}

	public function testTheManifestIsPutRightOnRequest(): void {
		$controller = $this->controller();
		$this->stateService->expects($this->once())->method('reconcile')->with('alice', true)
			->willReturn(['recorded' => 3, 'removed' => 1]);

		$response = $controller->reconcile(true);

		$this->assertSame(['recorded' => 3, 'removed' => 1], $response->getData());
	}

	public function testPuttingTheManifestRightSaysWhyItCannot(): void {
		$controller = $this->controller();
		$this->stateService->method('reconcile')->willReturnOnConsecutiveCalls(
			null,
			$this->throwException(new \RuntimeException('unreadable')),
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->reconcile()->getStatus());
		$this->assertSame(Http::STATUS_CONFLICT, $controller->reconcile()->getStatus());
	}

	/**
	 * The player sends the path in base64url, so a name like this one is
	 * not turned away by a firewall in front of the server.
	 */
	public function testThePathCanArriveInBase64Url(): void {
		$path = "/ROMs/NES/Kirby's Adventure (USA) (Rev 1).nes";
		$encoded = rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
		$controller = $this->controller(body: 'the state', f: $encoded);
		$this->stateService->expects($this->once())->method('save')
			->with('alice', $path, 2, 'the state');

		$controller->save('', 2);
	}

	public function testAPathThatIsNotBase64UrlIsNoPath(): void {
		$controller = $this->controller(body: 'the state', f: '!!not base64!!');
		$this->stateService->expects($this->never())->method('save');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->save('', 2)->getStatus());
	}
}
