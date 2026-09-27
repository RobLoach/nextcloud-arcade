<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\CoreMap;
use OCA\Arcade\Listener\CSPListener;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The CSP allowances the emulator needs are added for anyone who can use
 * the app, and for no one else.
 */
class CSPListenerTest extends TestCase {
	private IUserSession&MockObject $userSession;
	private IAppManager&MockObject $appManager;
	private IRequest&MockObject $request;
	private IShareManager&MockObject $shareManager;
	private CSPListener $listener;

	protected function setUp(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getPathInfo')->willReturn('/apps/files');
		$this->shareManager = $this->createMock(IShareManager::class);
		$this->listener = new CSPListener($this->userSession, $this->appManager, $this->request, $this->shareManager);
	}

	/** The event's constructor wants OC internals, so it is mocked whole. */
	private function event(): AddContentSecurityPolicyEvent&MockObject {
		return $this->createMock(AddContentSecurityPolicyEvent::class);
	}

	private function user(): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		return $user;
	}

	/** A listener seeing an anonymous request for the given share page. */
	private function anonymousListener(string $pathInfo): CSPListener {
		$this->userSession->method('getUser')->willReturn(null);
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willReturn($pathInfo);
		return new CSPListener($this->userSession, $this->appManager, $request, $this->shareManager);
	}

	/** A share resolving to the given node under the token used in the tests. */
	private function shareOf(Node $node): void {
		$share = $this->createStub(IShare::class);
		$share->method('getNode')->willReturn($node);
		$this->shareManager->method('getShareByToken')
			->with('AbCdEfGh')
			->willReturn($share);
	}

	private function file(string $mimetype, string $name = 'file'): File {
		$file = $this->createStub(File::class);
		$file->method('getMimetype')->willReturn($mimetype);
		$file->method('getName')->willReturn($name);
		return $file;
	}

	public function testAUserWithTheAppGetsThePolicy(): void {
		$user = $this->user();
		$this->userSession->method('getUser')->willReturn($user);
		$this->appManager->method('isEnabledForUser')
			->with('arcade', $user)
			->willReturn(true);

		$event = $this->event();
		$event->expects($this->once())
			->method('addPolicy')
			->with($this->isInstanceOf(EmptyContentSecurityPolicy::class));

		$this->listener->handle($event);
	}

	public function testNobodyLoggedInGetsNoPolicy(): void {
		// The login page and every other page without a user keep the
		// default policy.
		$this->userSession->method('getUser')->willReturn(null);
		$this->appManager->expects($this->never())->method('isEnabledForUser');

		$event = $this->event();
		$event->expects($this->never())->method('addPolicy');

		$this->listener->handle($event);
	}

	public function testASharedRomPlaysForAnonymousVisitors(): void {
		// A game shared by link opens in the Viewer without a login, so its
		// share page keeps the allowances the emulator needs.
		$this->shareOf($this->file(CoreMap::extensionMimeMap()['nes']));
		$listener = $this->anonymousListener('/s/AbCdEfGh');

		$event = $this->event();
		$event->expects($this->once())
			->method('addPolicy')
			->with($this->isInstanceOf(EmptyContentSecurityPolicy::class));

		$listener->handle($event);
	}

	public function testASharedDocumentGetsNoPolicy(): void {
		// A share of anything the emulator would not play keeps the
		// instance's default policy.
		$this->shareOf($this->file('application/pdf'));
		$listener = $this->anonymousListener('/s/AbCdEfGh');

		$event = $this->event();
		$event->expects($this->never())->method('addPolicy');

		$listener->handle($event);
	}

	public function testASharedFolderHoldingARomGetsThePolicy(): void {
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([
			$this->file('text/plain', 'readme.txt'),
			// No ROM mimetype: uploaded before the app registered them,
			// so the extension has to speak for it.
			$this->file('application/octet-stream', 'Mario.nes'),
		]);
		$this->shareOf($folder);
		$listener = $this->anonymousListener('/s/AbCdEfGh');

		$event = $this->event();
		$event->expects($this->once())
			->method('addPolicy')
			->with($this->isInstanceOf(EmptyContentSecurityPolicy::class));

		$listener->handle($event);
	}

	public function testASharedFolderWithoutGamesGetsNoPolicy(): void {
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([
			$this->file('image/png', 'photo.png'),
			$this->file('application/pdf', 'paper.pdf'),
		]);
		$this->shareOf($folder);
		$listener = $this->anonymousListener('/s/AbCdEfGh');

		$event = $this->event();
		$event->expects($this->never())->method('addPolicy');

		$listener->handle($event);
	}

	public function testAnUnknownTokenGetsNoPolicy(): void {
		$this->shareManager->method('getShareByToken')
			->willThrowException(new ShareNotFound());
		$listener = $this->anonymousListener('/s/AbCdEfGh');

		$event = $this->event();
		$event->expects($this->never())->method('addPolicy');

		$listener->handle($event);
	}

	public function testARequestWithoutAPathGetsNoPolicy(): void {
		// getPathInfo() throws on requests it cannot make sense of; those
		// render no share page and keep the default policy.
		$this->userSession->method('getUser')->willReturn(null);
		$request = $this->createMock(IRequest::class);
		$request->method('getPathInfo')->willThrowException(new \Exception('no path'));
		$listener = new CSPListener($this->userSession, $this->appManager, $request, $this->shareManager);

		$event = $this->event();
		$event->expects($this->never())->method('addPolicy');

		$listener->handle($event);
	}

	public function testAUserWithoutTheAppGetsNoPolicy(): void {
		// "Enable app for specific groups" leaves everyone else untouched.
		$user = $this->user();
		$this->userSession->method('getUser')->willReturn($user);
		$this->appManager->method('isEnabledForUser')
			->with('arcade', $user)
			->willReturn(false);

		$event = $this->event();
		$event->expects($this->never())->method('addPolicy');

		$this->listener->handle($event);
	}

	public function testAnythingElseIsNotItsBusiness(): void {
		$this->userSession->expects($this->never())->method('getUser');

		$this->listener->handle(new Event());
		$this->addToAssertionCount(1);
	}
}
