<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\CoreMap;
use OCA\Arcade\Service\BiosService;
use OCA\Arcade\Service\SettingsService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;

/**
 * The BIOS files an instance offers to every player, and the ones lying
 * in a user's own system folder, which the player reaches for first.
 */
class BiosServiceTest extends TestCase {
	/** What the app data holds, by name. */
	private array $files = [];

	/** What the user's system folder holds, by name. */
	private array $userFiles = [];

	/** The system folder of the user, '' when unset. */
	private string $systemFolder = '/System';

	private function service(): BiosService {
		$folder = $this->createStub(ISimpleFolder::class);
		$folder->method('fileExists')->willReturnCallback(fn (string $name): bool => isset($this->files[$name]));
		$folder->method('getFile')->willReturnCallback(
			function (string $name): ISimpleFile {
				if (!isset($this->files[$name])) {
					throw new NotFoundException($name);
				}
				$file = $this->createStub(ISimpleFile::class);
				$file->method('getName')->willReturn($name);
				$file->method('getContent')->willReturnCallback(fn (): string => $this->files[$name]);
				$file->method('putContent')->willReturnCallback(
					function ($content) use ($name): void {
						$this->files[$name] = (string)$content;
					},
				);
				$file->method('delete')->willReturnCallback(
					function () use ($name): void {
						unset($this->files[$name]);
					},
				);
				return $file;
			},
		);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, $content = null): ISimpleFile {
				$this->files[$name] = (string)$content;
				return $this->createStub(ISimpleFile::class);
			},
		);
		$folder->method('getDirectoryListing')->willReturnCallback(
			function (): array {
				$listing = [];
				foreach (array_keys($this->files) as $name) {
					$file = $this->createStub(ISimpleFile::class);
					$file->method('getName')->willReturn($name);
					$file->method('getSize')->willReturnCallback(fn (): int => strlen($this->files[$name]));
					$listing[] = $file;
				}
				return $listing;
			},
		);

		$appData = $this->createStub(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);
		$appData->method('newFolder')->willReturn($folder);
		$factory = $this->createStub(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);

		$systemFolder = $this->createStub(Folder::class);
		$systemFolder->method('getDirectoryListing')->willReturnCallback(
			fn (): array => array_map($this->userFile(...), array_keys($this->userFiles)),
		);
		$systemFolder->method('newFile')->willReturnCallback(
			function (string $name, $content = null) {
				$this->userFiles[$name] = (string)$content;
				return $this->userFile($name);
			},
		);

		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('get')->willReturnCallback(
			function (string $path) use ($systemFolder): Folder {
				if ($path !== $this->systemFolder) {
					throw new NotFoundException($path);
				}
				return $systemFolder;
			},
		);
		$userFolder->method('newFolder')->willReturn($systemFolder);
		$rootFolder = $this->createStub(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$settings = $this->createStub(SettingsService::class);
		$settings->method('getUserSettings')->willReturnCallback(
			fn (string $userId): array => ['system_folder' => $this->systemFolder],
		);

		return new BiosService($factory, $rootFolder, $settings);
	}

	private function userFile(string $name): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturnCallback(fn (): int => strlen($this->userFiles[$name] ?? ''));
		$file->method('getContent')->willReturnCallback(fn (): string => $this->userFiles[$name] ?? '');
		$file->method('putContent')->willReturnCallback(
			function ($content) use ($name): void {
				$this->userFiles[$name] = (string)$content;
			},
		);
		$file->method('delete')->willReturnCallback(
			function () use ($name): void {
				unset($this->userFiles[$name]);
			},
		);
		return $file;
	}

	/** The status row of one expected file, from whatever system lists it first. */
	private function statusOf(array $status, string $name): array {
		foreach ($status['systems'] as $entry) {
			foreach ($entry['files'] as $file) {
				if ($file['name'] === $name) {
					return $file;
				}
			}
		}
		$this->fail("$name is not in the status at all");
	}

	public function testEveryNameACoreAsksForIsAllowed(): void {
		foreach (CoreMap::SYSTEMS as $id => $system) {
			foreach ($system['bios'] as $name) {
				$this->assertTrue(BiosService::isKnown($name), "$name is asked for by $id");
			}
		}
	}

	public function testNothingElseCanBePutThere(): void {
		$service = $this->service();
		$this->assertFalse($service->write('../../secret.txt', 'no'));
		$this->assertFalse($service->write('anything.bin', 'no'));
		$this->assertSame([], $this->files, 'nothing was written');
	}

	public function testAFileTheInstanceHoldsIsHandedOut(): void {
		$service = $this->service();
		$this->assertTrue($service->write('gb_bios.bin', 'the firmware'));
		$this->assertSame('the firmware', $service->read('gb_bios.bin'));
		$this->assertSame(['gb_bios.bin'], $service->held());
	}

	public function testAFileTheInstanceHasNotGotIsNothingToWorryAbout(): void {
		$this->assertNull($this->service()->read('gba_bios.bin'));
	}

	public function testTheCanonicalSpellingIsFoundWhateverTheCase(): void {
		$this->assertSame('gb_bios.bin', BiosService::canonicalName('GB_Bios.BIN'));
		$this->assertSame('32X_G_BIOS.BIN', BiosService::canonicalName('32x_g_bios.bin'));
		$this->assertNull(BiosService::canonicalName('anything.bin'));
	}

	public function testEverythingStoredIsListedWithItsSize(): void {
		$service = $this->service();
		$service->write('gb_bios.bin', 'the firmware');
		$this->files['stray.bin'] = 'lost';

		// A wanted file shows up in the status with its stored size.
		$file = $this->statusOf($service->statusFor('admin'), 'gb_bios.bin');
		$this->assertTrue($file['present']);
		$this->assertSame('store', $file['source']);
		$this->assertSame(strlen('the firmware'), $file['size']);
		// The stray is in the store listing too: deleting it is refused
		// the way anything living only in the store is.
		$this->assertSame('store', $service->deleteFor('admin', 'stray.bin'));
	}

	public function testAFileCanBeTakenBack(): void {
		$service = $this->service();
		$service->write('gb_bios.bin', 'the firmware');
		$this->assertTrue($service->remove('gb_bios.bin'));
		$this->assertNull($service->read('gb_bios.bin'));
		$this->assertFalse($service->remove('gb_bios.bin'), 'and only once');
	}

	public function testReadForServesTheFolderFileFirst(): void {
		$this->userFiles['gb_bios.bin'] = 'mine';
		$this->files['gb_bios.bin'] = 'everybody\'s';

		$this->assertSame('mine', $this->service()->readFor('player', 'gb_bios.bin'));
	}

	public function testReadForFindsTheFolderFileWhateverItsCase(): void {
		$this->userFiles['GB_BIOS.BIN'] = 'mine';

		$this->assertSame('mine', $this->service()->readFor('player', 'gb_bios.bin'));
	}

	public function testReadForFallsBackToTheStore(): void {
		$this->files['gb_bios.bin'] = 'everybody\'s';

		$this->assertSame('everybody\'s', $this->service()->readFor('player', 'gb_bios.bin'));
	}

	public function testReadForWithoutAUserOnlyKnowsTheStore(): void {
		$this->userFiles['gb_bios.bin'] = 'somebody\'s';
		$this->files['gb_bios.bin'] = 'everybody\'s';

		$this->assertSame('everybody\'s', $this->service()->readFor(null, 'gb_bios.bin'));
	}

	public function testReadForRefusesANameNoCoreAsksFor(): void {
		$this->userFiles['notes.txt'] = 'todo';
		$this->files['notes.txt'] = 'todo';

		$this->assertNull($this->service()->readFor('player', 'notes.txt'));
	}

	public function testReadForSaysNothingWhenNobodyHasTheFile(): void {
		$this->assertNull($this->service()->readFor('player', 'gb_bios.bin'));
	}

	public function testStatusFindsAFolderFileWhateverItsCase(): void {
		$this->userFiles['GB_BIOS.BIN'] = 'the firmware';

		$file = $this->statusOf($this->service()->statusFor('admin'), 'gb_bios.bin');

		$this->assertTrue($file['present']);
		$this->assertSame('folder', $file['source']);
		$this->assertSame(strlen('the firmware'), $file['size']);
	}

	public function testStatusFallsBackToTheStore(): void {
		$this->files['gb_bios.bin'] = 'the firmware';

		$file = $this->statusOf($this->service()->statusFor('admin'), 'gb_bios.bin');

		$this->assertTrue($file['present']);
		$this->assertSame('store', $file['source']);
		$this->assertSame(strlen('the firmware'), $file['size']);
	}

	public function testStatusPrefersTheFolderOverTheStore(): void {
		$this->userFiles['gb_bios.bin'] = 'mine';
		$this->files['gb_bios.bin'] = 'everybody\'s';

		$file = $this->statusOf($this->service()->statusFor('admin'), 'gb_bios.bin');

		$this->assertSame('folder', $file['source'], 'the player reads the folder first');
		$this->assertSame(strlen('mine'), $file['size']);
	}

	public function testStatusSaysWhatIsMissingEverywhere(): void {
		$file = $this->statusOf($this->service()->statusFor('admin'), 'gb_bios.bin');

		$this->assertFalse($file['present']);
		$this->assertNull($file['source']);
		$this->assertSame(0, $file['size']);
	}

	public function testUploadGoesIntoTheSystemFolderNotTheStore(): void {
		$this->assertTrue($this->service()->storeUpload('admin', 'gb_bios.bin', 'the firmware'));
		$this->assertSame(['gb_bios.bin' => 'the firmware'], $this->userFiles);
		$this->assertSame([], $this->files, 'the store is occ arcade:bios territory');
	}

	public function testUploadReplacesAWronglyCasedFileUnderTheCanonicalName(): void {
		$this->userFiles['GB_BIOS.BIN'] = 'old';

		$this->assertTrue($this->service()->storeUpload('admin', 'gb_bios.bin', 'new'));

		$this->assertSame(['gb_bios.bin' => 'new'], $this->userFiles, 'no duplicate spelling is left behind');
	}

	public function testUploadOverwritesAnExactMatchInPlace(): void {
		$this->userFiles['gb_bios.bin'] = 'old';

		$this->assertTrue($this->service()->storeUpload('admin', 'gb_bios.bin', 'new'));

		$this->assertSame(['gb_bios.bin' => 'new'], $this->userFiles);
	}

	public function testUploadRefusesANameNoCoreAsksFor(): void {
		$this->assertFalse($this->service()->storeUpload('admin', 'anything.bin', 'no'));
		$this->assertSame([], $this->userFiles);
	}

	public function testUploadFailsWithoutASystemFolder(): void {
		$this->systemFolder = '';
		$this->assertFalse($this->service()->storeUpload('admin', 'gb_bios.bin', 'the firmware'));
	}

	public function testDeleteMatchesTheFolderFileWhateverItsCase(): void {
		$this->userFiles['GB_Bios.bin'] = 'the firmware';

		$this->assertSame('deleted', $this->service()->deleteFor('admin', 'gb_bios.bin'));

		$this->assertSame([], $this->userFiles);
	}

	public function testDeleteLeavesAStoreOnlyFileAloneAndSaysSo(): void {
		$this->files['gb_bios.bin'] = 'the firmware';

		$this->assertSame('store', $this->service()->deleteFor('admin', 'gb_bios.bin'));

		$this->assertSame(['gb_bios.bin' => 'the firmware'], $this->files, 'the store was not touched');
	}

	public function testDeleteSaysWhenThereWasNothingAnywhere(): void {
		$this->assertSame('missing', $this->service()->deleteFor('admin', 'gb_bios.bin'));
	}
}
