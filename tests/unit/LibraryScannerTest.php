<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\BackgroundJob\RefreshMetadata;
use OCA\Arcade\BackgroundJob\ScanLibraries;
use OCA\Arcade\Service\LibraryScanner;
use OCA\Arcade\Service\LibraryService;
use OCA\Arcade\Service\SettingsService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Scanning a games library afresh, for one user now or for everybody in
 * the background.
 */
class LibraryScannerTest extends TestCase {
	/** The library folder each user has, and whether it is there. */
	private array $libraries = ['alice' => true, 'bob' => false];
	/** The jobs queued, as class => list of arguments. */
	private array $jobs = [];
	/** Who was scanned, and whether past the cache. */
	private array $scanned = [];

	private function scanner(): LibraryScanner {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getUserSettings')->willReturn(['library_folder' => '/Games']);

		$library = $this->createStub(LibraryService::class);
		$library->method('getGames')->willReturnCallback(
			function (string $userId, Folder $folder, Folder $userFolder, string $path, array $settings, bool $refresh): array {
				if ($userId === 'carol') {
					throw new \RuntimeException('storage unavailable');
				}
				$this->scanned[] = $userId . ($refresh ? ' (afresh)' : '');
				return [['name' => 'Mario.nes'], ['name' => 'Zelda.nes']];
			},
		);

		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(function (string $userId): Folder {
			$userFolder = $this->createStub(Folder::class);
			$userFolder->method('get')->willReturnCallback(function () use ($userId): Folder {
				if (!($this->libraries[$userId] ?? false)) {
					throw new NotFoundException('/Games');
				}
				return $this->createStub(Folder::class);
			});
			return $userFolder;
		});

		$jobList = $this->createStub(IJobList::class);
		$jobList->method('has')->willReturnCallback(
			fn (string $class, $argument): bool => in_array($argument, $this->jobs[$class] ?? [], true),
		);
		$jobList->method('add')->willReturnCallback(function (string $class, $argument): void {
			$this->jobs[$class][] = $argument;
		});

		$users = $this->createStub(IUserManager::class);
		$users->method('callForSeenUsers')->willReturnCallback(function (\Closure $callback): void {
			foreach (array_keys($this->libraries) as $uid) {
				$user = $this->createStub(IUser::class);
				$user->method('getUID')->willReturn((string)$uid);
				$callback($user);
			}
		});

		return new LibraryScanner($settings, $library, $root, $jobList, $users, $this->createStub(LoggerInterface::class));
	}

	public function testALibraryIsScannedPastTheCacheAndItsGamesReadAgain(): void {
		$this->assertSame(2, $this->scanner()->scan('alice'));
		$this->assertSame(['alice (afresh)'], $this->scanned);
		$this->assertSame([['userId' => 'alice']], $this->jobs[RefreshMetadata::class]);
	}

	public function testTheGamesAreReadAgainOnlyOnceWhileWaiting(): void {
		$scanner = $this->scanner();
		$scanner->scan('alice');
		$scanner->scan('alice');
		$this->assertCount(1, $this->jobs[RefreshMetadata::class]);
	}

	public function testALibraryFolderThatIsNotThereScansNothing(): void {
		$this->assertNull($this->scanner()->scan('bob'));
		$this->assertSame([], $this->scanned);
	}

	public function testEverybodyIsScannedInTheBackgroundOnce(): void {
		$scanner = $this->scanner();
		$this->assertTrue($scanner->scanEveryone());
		$this->assertFalse($scanner->scanEveryone());
		$this->assertSame([null], $this->jobs[ScanLibraries::class]);
	}

	public function testTheBackgroundScanCarriesOnPastALibraryThatFails(): void {
		$this->libraries = ['alice' => true, 'bob' => false, 'carol' => true, 'dave' => true];
		$this->assertSame(2, $this->scanner()->scanAll());
		$this->assertSame(['alice (afresh)', 'dave (afresh)'], $this->scanned);
	}
}
