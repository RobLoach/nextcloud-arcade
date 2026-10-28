<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Service\CloudSyncManifest;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CloudSyncManifestTest extends TestCase {
	/** What manifest.server holds, or null when there is none. */
	private ?string $content = null;
	/** How big manifest.server says it is, when not its content's length. */
	private ?int $size = null;

	private function root(): Folder {
		$root = $this->createStub(Folder::class);
		$root->method('getId')->willReturn(7);
		$root->method('get')->willReturnCallback(function (string $name): File {
			if ($this->content === null) {
				throw new NotFoundException($name);
			}
			$file = $this->createStub(File::class);
			$file->method('getSize')->willReturnCallback(fn (): int => $this->size ?? strlen((string)$this->content));
			$file->method('getContent')->willReturnCallback(function (): string {
				if ($this->size !== null) {
					$this->fail('a manifest too big to hold is not read');
				}
				return $this->content;
			});
			$file->method('putContent')->willReturnCallback(function ($content): void {
				$this->content = (string)$content;
			});
			return $file;
		});
		$root->method('newFile')->willReturnCallback(function (string $name, $content): File {
			$this->content = (string)$content;
			return $this->createStub(File::class);
		});
		return $root;
	}

	private function manifest(?ILockingProvider $locking = null): CloudSyncManifest {
		return new CloudSyncManifest(
			$locking ?? $this->createStub(ILockingProvider::class),
			$this->createStub(LoggerInterface::class),
		);
	}

	public function testAManifestIsReadAsRetroArchWritesIt(): void {
		$this->assertSame([
			'saves/FCEUmm/Kirby.srm' => '0d372df0e2f1ceefc132264e1d75b237',
			'states/Gone.state' => '',
		], CloudSyncManifest::parse('[
			{"path": "saves/FCEUmm/Kirby.srm", "hash": "0d372df0e2f1ceefc132264e1d75b237"},
			{"path": "states/Gone.state", "hash": ""}
		]'));
	}

	public function testAnEmptyManifestSaysNothingAndABrokenOneIsNull(): void {
		$this->assertSame([], CloudSyncManifest::parse(''));
		$this->assertNull(CloudSyncManifest::parse('{"path": "saves/x.srm"}'));
		$this->assertNull(CloudSyncManifest::parse('{'));
	}

	public function testTheEntriesAreSortedWithoutCase(): void {
		$rendered = CloudSyncManifest::render(['states/b' => '2', 'saves/A' => '1', 'Saves/c' => '3']);
		$this->assertSame(['saves/A', 'Saves/c', 'states/b'], array_keys(CloudSyncManifest::parse($rendered)));
	}

	public function testChangesAreMergedIntoWhatIsThere(): void {
		$this->content = CloudSyncManifest::render(['config/x.cfg' => 'a', 'saves/y.srm' => 'b']);
		$this->manifest()->record($this->root(), ['saves/y.srm' => 'c', 'states/z.state' => 'd']);
		$this->assertSame(
			['config/x.cfg' => 'a', 'saves/y.srm' => 'c', 'states/z.state' => 'd'],
			CloudSyncManifest::parse($this->content),
		);
	}

	public function testAManifestIsMadeWhenThereIsNone(): void {
		$this->manifest()->record($this->root(), ['saves/y.srm' => 'c']);
		$this->assertSame(['saves/y.srm' => 'c'], CloudSyncManifest::parse($this->content));
	}

	public function testADeleteOfSomethingNeverSyncedIsNotRecorded(): void {
		$this->content = CloudSyncManifest::render(['saves/y.srm' => 'b']);
		$this->manifest()->record($this->root(), ['saves/y.srm' => '', 'states/never.state' => '']);
		$this->assertSame(['saves/y.srm' => ''], CloudSyncManifest::parse($this->content));
	}

	public function testAManifestTooBigToHoldIsLeftAlone(): void {
		$this->content = '[]';
		$this->size = 200 * 1024 * 1024;
		$this->manifest()->record($this->root(), ['saves/y.srm' => 'c']);
		$this->assertSame('[]', $this->content, 'neither read nor written over');
	}

	public function testTheWriteHoldsTheLockAndLetsGo(): void {
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with('arcade/cloudsync/7', ILockingProvider::LOCK_EXCLUSIVE);
		$locking->expects($this->once())->method('releaseLock')
			->with('arcade/cloudsync/7', ILockingProvider::LOCK_EXCLUSIVE);
		$this->manifest($locking)->record($this->root(), ['saves/y.srm' => 'c']);
	}

	public function testNothingToRecordWritesNothing(): void {
		$this->manifest()->record($this->root(), []);
		$this->assertNull($this->content);
	}

	public function testTheAddressRetroArchIsGivenIsTheFolderOverWebDav(): void {
		$urls = $this->createStub(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://cloud.example' . $path);
		$this->assertSame(
			'https://cloud.example/remote.php/dav/files/alice%20b/Games/My%20Saves/',
			CloudSyncManifest::webdavUrl($urls, 'alice b', '/Games/My Saves'),
		);
	}
}
