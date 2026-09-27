<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Activity\ActivityPublisher;
use OCA\Arcade\Activity\Provider;
use OCA\Arcade\Service\StateService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What reaches the activity stream, and -- just as much -- what does not.
 */
class ActivityPublisherTest extends TestCase {
	private const USER = 'alice';
	private const GAME = '/Games/Mario.nes';
	private const FILE_ID = 101;

	private IManager&MockObject $manager;
	private IEvent&MockObject $event;

	private function publisher(bool $fileExists = true): ActivityPublisher {
		$this->event = $this->createMock(IEvent::class);
		foreach ([
			'setApp', 'setType', 'setAffectedUser', 'setAuthor', 'setTimestamp',
			'setSubject', 'setObject', 'setGenerateNotification',
		] as $setter) {
			$this->event->method($setter)->willReturnSelf();
		}

		$this->manager = $this->createMock(IManager::class);
		$this->manager->method('generateEvent')->willReturn($this->event);

		$folder = $this->createStub(Folder::class);
		if ($fileExists) {
			$file = $this->createStub(File::class);
			$file->method('getId')->willReturn(self::FILE_ID);
			$folder->method('get')->willReturn($file);
		} else {
			$folder->method('get')->willThrowException(new NotFoundException(self::GAME));
		}
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($folder);

		return new ActivityPublisher($this->manager, $rootFolder, $this->createStub(LoggerInterface::class));
	}

	public function testALaunchIsPublishedAsItsOwnEntry(): void {
		$publisher = $this->publisher();
		$this->manager->expects($this->once())->method('publish');
		$this->event->expects($this->once())->method('setType')
			->with(ActivityPublisher::TYPE)->willReturnSelf();
		$this->event->expects($this->once())->method('setAffectedUser')
			->with(self::USER)->willReturnSelf();
		$this->event->expects($this->once())->method('setAuthor')
			->with(self::USER)->willReturnSelf();
		$this->event->expects($this->once())->method('setSubject')
			->with(Provider::SUBJECT_GAME_STARTED, [
				'game' => ['id' => self::FILE_ID, 'name' => 'Mario.nes', 'path' => self::GAME],
			])->willReturnSelf();
		$this->event->expects($this->once())->method('setObject')
			->with('files', self::FILE_ID, self::GAME)->willReturnSelf();
		$this->event->expects($this->once())->method('setGenerateNotification')
			->with(false)->willReturnSelf();

		$publisher->gameStarted(self::USER, self::GAME);
	}

	public function testASessionUnderAMinuteIsNobodysBusiness(): void {
		$publisher = $this->publisher();
		$this->manager->expects($this->never())->method('generateEvent');
		$this->manager->expects($this->never())->method('publish');

		$publisher->sessionEnded(self::USER, self::GAME, 59);
	}

	public function testASessionOfAMinuteIsPublishedWithItsLength(): void {
		$publisher = $this->publisher();
		$this->manager->expects($this->once())->method('publish');
		$this->event->expects($this->once())->method('setSubject')
			->with(Provider::SUBJECT_SESSION_ENDED, [
				'game' => ['id' => self::FILE_ID, 'name' => 'Mario.nes', 'path' => self::GAME],
				'seconds' => 60,
			])->willReturnSelf();

		$publisher->sessionEnded(self::USER, self::GAME, 60);
	}

	public function testTheAutoSlotNeverReachesTheStream(): void {
		$publisher = $this->publisher();
		$this->manager->expects($this->never())->method('generateEvent');
		$this->manager->expects($this->never())->method('publish');

		$publisher->stateSaved(self::USER, self::GAME, StateService::AUTO_SLOT);
	}

	public function testAManualSaveIsPublishedWithItsSlot(): void {
		$publisher = $this->publisher();
		$this->manager->expects($this->once())->method('publish');
		$this->event->expects($this->once())->method('setSubject')
			->with(Provider::SUBJECT_STATE_SAVED, [
				'game' => ['id' => self::FILE_ID, 'name' => 'Mario.nes', 'path' => self::GAME],
				'slot' => 2,
			])->willReturnSelf();

		$publisher->stateSaved(self::USER, self::GAME, 2);
	}

	public function testAGameWithoutAFileStillGetsAPlainEntry(): void {
		$publisher = $this->publisher(fileExists: false);
		$this->manager->expects($this->once())->method('publish');
		$this->event->expects($this->once())->method('setSubject')
			->with(Provider::SUBJECT_GAME_STARTED, [
				'game' => ['name' => 'Mario.nes', 'path' => self::GAME],
			])->willReturnSelf();
		$this->event->expects($this->once())->method('setObject')
			->with('files', 0, self::GAME)->willReturnSelf();

		$publisher->gameStarted(self::USER, self::GAME);
	}

	public function testActivityTroubleNeverReachesGameplay(): void {
		$publisher = $this->publisher();
		// The Activity app disabled, the event refused -- whatever it was,
		// the stream is decoration and the game must not feel it.
		$this->manager->method('publish')
			->willThrowException(new \RuntimeException('activity is off'));

		$publisher->gameStarted(self::USER, self::GAME);
		$publisher->sessionEnded(self::USER, self::GAME, 600);
		$publisher->stateSaved(self::USER, self::GAME, 1);

		$this->addToAssertionCount(1);
	}
}
