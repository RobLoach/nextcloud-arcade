<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Listener\CleanupListener;
use OCA\Arcade\Service\RecentService;
use OCA\Arcade\Service\StateService;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupListenerTest extends TestCase {
	private StateService&MockObject $stateService;
	private RecentService&MockObject $recentService;
	private IRootFolder&MockObject $rootFolder;
	private IUserSession&MockObject $userSession;
	private CleanupListener $listener;
	/** What looking the file id up in the user's storage finds. */
	private ?File $foundById = null;

	protected function setUp(): void {
		$this->stateService = $this->createMock(StateService::class);
		$this->recentService = $this->createMock(RecentService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);

		// The deleted file is still around when the trash caught it, which
		// only its id can say: the id survives the move into the trash.
		$home = $this->createStub(Folder::class);
		$home->method('getFirstNodeById')->willReturnCallback(fn (): ?File => $this->foundById);
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('getParent')->willReturn($home);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->listener = new CleanupListener(
			$this->stateService,
			$this->recentService,
			$this->rootFolder,
			$this->userSession,
			$this->createStub(LoggerInterface::class),
		);
	}

	private function file(string $path, int $id = 101): File {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn(basename($path));
		$file->method('getPath')->willReturn($path);
		$file->method('getId')->willReturn($id);
		$file->method('getOwner')->willReturn($user);
		return $file;
	}

	private function fileEvent(string $path, int $id = 101): NodeDeletedEvent {
		return new NodeDeletedEvent($this->file($path, $id));
	}

	private function actingUser(?string $uid): void {
		if ($uid === null) {
			$this->userSession->method('getUser')->willReturn(null);
			return;
		}
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testAGameInTheTrashKeepsItsSaves(): void {
		// Its file id still leads somewhere -- the trash -- so it can be
		// restored, and a player who restores it has not asked to lose
		// their saves.
		$this->foundById = $this->file('/alice/files_trashbin/files/Mario.nes.d1700000000');
		$this->stateService->expects($this->never())->method('deleteAllForGame');
		$this->stateService->expects($this->never())->method('deleteAllForFileId');

		$this->listener->handle($this->fileEvent('/alice/files/Games/NES/Mario.nes'));
	}

	public function testDeletingAGameTakesItsSavesWithIt(): void {
		// The id leads nowhere any more: no trashbin, or a deletion that
		// went past it. Either way the game is gone for good.
		$this->stateService->expects($this->once())
			->method('deleteAllForGame')
			->with('alice', '/Games/NES/Mario.nes');

		$this->listener->handle($this->fileEvent('/alice/files/Games/NES/Mario.nes'));
	}

	public function testEmptyingTheTrashTakesTheSavesWithIt(): void {
		// The signal of the trash names its path and nothing more; the
		// acting user is whose trash it is, and the node can still be read
		// for the file id the states are filed under.
		$this->actingUser('alice');
		$this->rootFolder->method('get')
			->with('/alice/files_trashbin/files/Mario.nes.d1700000000')
			->willReturn($this->file('/alice/files_trashbin/files/Mario.nes.d1700000000'));
		$this->stateService->expects($this->once())
			->method('deleteAllForFileId')
			->with('alice', 101);

		$this->listener->trashItemDeleted(['path' => '/files_trashbin/files/Mario.nes.d1700000000']);
	}

	public function testAFolderExpungedFromTheTrashTakesEveryGameInIt(): void {
		// A folder trashed whole is expunged whole; the games it holds are
		// never spoken of again, so their states go now.
		$this->actingUser('alice');
		$folder = $this->createStub(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([
			$this->file('/alice/files_trashbin/files/Games.d1700000000/Mario.nes', 101),
			$this->file('/alice/files_trashbin/files/Games.d1700000000/Notes.txt', 102),
			$this->file('/alice/files_trashbin/files/Games.d1700000000/Zelda.sfc', 103),
		]);
		$this->rootFolder->method('get')->willReturn($folder);

		$deleted = [];
		$this->stateService->method('deleteAllForFileId')
			->willReturnCallback(function (string $userId, int $id) use (&$deleted): void {
				$deleted[] = $id;
			});

		$this->listener->trashItemDeleted(['path' => '/files_trashbin/files/Games.d1700000000']);
		$this->assertSame([101, 103], $deleted);
	}

	public function testTheTrashOfNobodyIsLeftToTheSweep(): void {
		// The expiry jobs run with no user; whose trash the path names is
		// anyone's guess, so nothing is touched. `occ arcade:cleanup` tells
		// a gone game from a trashed one by the same file id.
		$this->actingUser(null);
		$this->rootFolder->expects($this->never())->method('get');
		$this->stateService->expects($this->never())->method('deleteAllForFileId');

		$this->listener->trashItemDeleted(['path' => '/files_trashbin/files/Mario.nes.d1700000000']);
	}

	public function testWhatLeavesTheTrashWithoutBeingAGameIsLeftAlone(): void {
		$this->actingUser('alice');
		$this->rootFolder->method('get')
			->willReturn($this->file('/alice/files_trashbin/files/Notes.txt.d1700000000'));
		$this->stateService->expects($this->never())->method('deleteAllForFileId');

		$this->listener->trashItemDeleted(['path' => '/files_trashbin/files/Notes.txt.d1700000000']);
	}

	public function testTheTrashSignalNeverGetsInTheWayOfTheDeletion(): void {
		$this->actingUser('alice');
		$this->rootFolder->method('get')
			->willThrowException(new \RuntimeException('the storage said no'));

		$this->listener->trashItemDeleted(['path' => '/files_trashbin/files/Mario.nes.d1700000000']);
		$this->addToAssertionCount(1);
	}

	public function testAGameDeletedFromInsideTheTrashIsGoneForGood(): void {
		// The server does not fire node events for the trash today, but if
		// it ever does, the answer is the same as for the signal.
		$this->stateService->expects($this->once())
			->method('deleteAllForFileId')
			->with('alice', 101);

		$this->listener->handle(
			$this->fileEvent('/alice/files_trashbin/files/Mario.nes.d1700000000'),
		);
	}

	public function testAZippedGameCountsToo(): void {
		$this->stateService->expects($this->once())
			->method('deleteAllForGame')
			->with('alice', '/Games/NHL 96.zip');

		$this->listener->handle($this->fileEvent('/alice/files/Games/NHL 96.zip'));
	}

	public function testAnythingThatIsNotAGameIsLeftAlone(): void {
		$this->stateService->expects($this->never())->method('deleteAllForGame');

		$this->listener->handle($this->fileEvent('/alice/files/Documents/Notes.txt'));
		$this->listener->handle($this->fileEvent('/alice/files/Games/Cover.png'));
	}

	public function testAFolderSaysNothingAboutWhatWasInIt(): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$folder = $this->createStub(Folder::class);
		$folder->method('getName')->willReturn('NES');
		$folder->method('getPath')->willReturn('/alice/files/Games/NES');
		$folder->method('getOwner')->willReturn($user);

		$this->stateService->expects($this->never())->method('deleteAllForGame');
		$this->listener->handle(new NodeDeletedEvent($folder));
	}

	public function testAFileOutsideTheFilesOfItsOwnerIsLeftAlone(): void {
		$this->stateService->expects($this->never())->method('deleteAllForGame');
		$this->listener->handle($this->fileEvent('/somewhere/else/Mario.nes'));
	}

	public function testDeletingAUserTakesEverythingOfTheirs(): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$this->stateService->expects($this->once())
			->method('deleteAllForUser')
			->with('alice');
		$this->recentService->expects($this->once())
			->method('deleteAllForUser')
			->with('alice');

		$this->listener->handle(new UserDeletedEvent($user));
	}

	public function testAnythingElseIsNotItsBusiness(): void {
		$this->stateService->expects($this->never())->method('deleteAllForGame');
		$this->stateService->expects($this->never())->method('deleteAllForUser');

		$this->listener->handle(new Event());
	}

	public function testCleaningUpNeverGetsInTheWayOfTheDeletion(): void {
		$this->stateService->method('deleteAllForGame')
			->willThrowException(new \RuntimeException('the storage said no'));

		// No exception leaves the listener, or Nextcloud would fail the
		// deletion over something that is only housekeeping.
		$this->listener->handle($this->fileEvent('/alice/files/Games/Mario.nes'));
		$this->addToAssertionCount(1);
	}
}
