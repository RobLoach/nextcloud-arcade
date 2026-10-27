<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Db\GameMapper;
use OCA\Arcade\Service\CloudSyncManifest;
use OCA\Arcade\Service\SavesBeingMovedException;
use OCA\Arcade\Service\SavesLayoutConverter;
use OCA\Arcade\Service\SettingsService;
use OCP\Config\IUserConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The saves of versions up to 0.48.7 are moved to the layout RetroArch
 * keeps, once, and nothing is overwritten or lost on the way.
 */
class SavesLayoutConverterTest extends TestCase {
	private const USER = 'alice';

	/** The user's files, by path under their root: content, or null for a folder. */
	private array $files = [];
	/** The games table: key => path, md5, core, name. */
	private array $games = [];
	/** What was recorded in the manifest. */
	private array $recorded = [];
	/** What the games table was told. */
	private array $noted = [];
	private bool $done = false;
	/** How many more times the lock is found taken. */
	private int $lockedFor = 0;
	/** Paths whose move fails, as a storage having a bad moment would. */
	private array $failing = [];

	private function converter(string $savesFolder = '/Saves'): SavesLayoutConverter {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getUserSettings')->willReturn(['saves_folder' => $savesFolder]);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($this->folder(''));
		$games = $this->createStub(GameMapper::class);
		$games->method('entriesOf')->willReturnCallback(fn (): array => $this->games);
		$games->method('set')->willReturnCallback(function (string $userId, string $key, int $fileId, string $path, string $checksum, string $core = '', ?string $name = null): void {
			$this->noted[$key] = ['checksum' => $checksum, 'name' => $name];
		});
		$manifest = $this->createStub(CloudSyncManifest::class);
		$manifest->method('record')->willReturnCallback(function (Folder $root, array $changes): void {
			$this->recorded += $changes;
		});
		$config = $this->createStub(IUserConfig::class);
		$config->method('getValueBool')->willReturnCallback(fn (): bool => $this->done);
		$config->method('setValueBool')->willReturnCallback(function (string $userId, string $app, string $key, bool $value): bool {
			$this->done = $value;
			return true;
		});
		$locks = $this->createStub(ILockingProvider::class);
		$locks->method('acquireLock')->willReturnCallback(function (): void {
			if ($this->lockedFor > 0) {
				$this->lockedFor--;
				throw new LockedException('arcade/saves-layout/alice');
			}
		});
		return new SavesLayoutConverter($root, $config, $settings, $games, $manifest, $locks, $this->createStub(LoggerInterface::class), 500);
	}

	// A file tree as small as the converter needs it.

	private function folder(string $path): Folder {
		$folder = $this->createStub(Folder::class);
		$folder->method('getName')->willReturn(basename($path));
		$folder->method('getPath')->willReturn('/' . self::USER . '/files' . ($path === '' ? '' : "/$path"));
		$folder->method('get')->willReturnCallback(function (string $name) use ($path) {
			$full = $this->join($path, $name);
			if (!array_key_exists($full, $this->files)) {
				throw new NotFoundException($full);
			}
			return $this->files[$full] === null ? $this->folder($full) : $this->file($full);
		});
		$folder->method('nodeExists')->willReturnCallback(fn (string $name): bool => array_key_exists($this->join($path, $name), $this->files));
		$folder->method('newFolder')->willReturnCallback(function (string $name) use ($path): Folder {
			$this->files[$this->join($path, $name)] = null;
			return $this->folder($this->join($path, $name));
		});
		$folder->method('getDirectoryListing')->willReturnCallback(function () use ($path): array {
			$nodes = [];
			foreach ($this->files as $known => $content) {
				if (dirname($known) === ($path === '' ? '.' : $path)) {
					$nodes[] = $content === null ? $this->folder($known) : $this->file($known);
				}
			}
			return $nodes;
		});
		$folder->method('delete')->willReturnCallback(function () use ($path): void {
			unset($this->files[$path]);
		});
		return $folder;
	}

	private function file(string $path): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn(basename($path));
		$file->method('hash')->willReturnCallback(fn (): string => md5((string)$this->files[$path]));
		$file->method('getContent')->willReturnCallback(fn (): string => (string)$this->files[$path]);
		$file->method('move')->willReturnCallback(function (string $target) use ($path): File {
			if (in_array($path, $this->failing, true)) {
				throw new \OCP\Lock\LockedException($path);
			}
			$to = substr($target, strlen('/' . self::USER . '/files/'));
			$this->files[$to] = $this->files[$path];
			unset($this->files[$path]);
			return $this->file($to);
		});
		return $file;
	}

	private function join(string $path, string $name): string {
		$name = trim($name, '/');
		return $path === '' ? $name : "$path/$name";
	}

	/** Put files in, making the folders above them. */
	private function given(array $files): void {
		foreach ($files as $path => $content) {
			$parts = explode('/', $path);
			for ($i = 1; $i < count($parts); $i++) {
				$this->files[implode('/', array_slice($parts, 0, $i))] ??= null;
			}
			$this->files[$path] = $content;
		}
	}

	/** @return array<string, string> the files, without the folders */
	private function onlyFiles(): array {
		$files = array_filter($this->files, static fn ($content): bool => $content !== null);
		ksort($files);
		return $files;
	}

	public function testAGamesSavesGoWhereRetroArchKeepsThem(): void {
		$this->given([
			'Saves/Nintendo/Mario/Auto.state' => 'auto',
			'Saves/Nintendo/Mario/Slot 1.state' => 'one',
			'Saves/Nintendo/Mario/Slot 1.png' => 'picture',
			'Saves/Nintendo/Mario/Slot 3.state' => 'three',
			'Saves/Nintendo/Mario/Mario.srm' => 'battery',
		]);
		$this->games = ['42' => ['path' => '/Games/NES/Mario.nes', 'md5' => '', 'core' => '', 'name' => '']];

		$this->assertSame([5, 0], $this->converter()->convertIfNeeded(self::USER));

		$this->assertSame([
			'Saves/saves/FCEUmm/Mario.srm' => 'battery',
			'Saves/states/FCEUmm/Mario.state.auto' => 'auto',
			'Saves/states/FCEUmm/Mario.state1' => 'one',
			'Saves/states/FCEUmm/Mario.state1.png' => 'picture',
			'Saves/states/FCEUmm/Mario.state3' => 'three',
		], $this->onlyFiles());
		$this->assertArrayNotHasKey('Saves/Nintendo', $this->files, 'and the emptied folders go');
		$this->assertSame(md5('battery'), $this->recorded['saves/FCEUmm/Mario.srm'], 'recorded for RetroArch');
	}

	public function testAFileAlreadyInTheNewPlaceIsNeitherOverwrittenNorLost(): void {
		$this->given([
			'Saves/Nintendo/Mario/Slot 1.state' => 'old',
			'Saves/states/FCEUmm/Mario.state1' => 'new',
		]);
		$this->games = ['42' => ['path' => '/Games/NES/Mario.nes', 'md5' => '', 'core' => '', 'name' => '']];

		$this->assertSame([0, 1], $this->converter()->convertIfNeeded(self::USER));

		$this->assertSame('new', $this->files['Saves/states/FCEUmm/Mario.state1']);
		$this->assertSame('old', $this->files['Saves/Nintendo/Mario/Slot 1.state']);
	}

	public function testAFolderNoGameIsKnownForGoesByItsNames(): void {
		$this->given(['Saves/Game Boy/Tetris/Slot 2.state' => 'two', 'Saves/Tetris DX/Tetris DX.srm' => 'battery']);

		$this->converter()->convertIfNeeded(self::USER);

		$this->assertSame([
			'Saves/saves/Tetris DX.srm' => 'battery',
			'Saves/states/Gambatte/Tetris.state2' => 'two',
		], $this->onlyFiles());
	}

	public function testTheCoreThePlayerRanWinsOverThePath(): void {
		$this->given(['Saves/Nintendo/Mario/Slot 1.state' => 'one']);
		$this->games = ['42' => ['path' => '/Games/NES/Mario.nes', 'md5' => '', 'core' => 'snes9x', 'name' => '']];

		$this->converter()->convertIfNeeded(self::USER);

		$this->assertArrayHasKey('Saves/states/Snes9x/Mario.state1', $this->files);
	}

	public function testWhatIsNotASaveIsLeftAlone(): void {
		$this->given([
			'Saves/Nintendo/Mario/Slot 1.state' => 'one',
			'Saves/Nintendo/Mario/notes.txt' => 'mine',
			'Saves/states/Zelda.state' => 'already new',
		]);

		$this->converter()->convertIfNeeded(self::USER);

		$this->assertSame('mine', $this->files['Saves/Nintendo/Mario/notes.txt']);
		$this->assertSame('already new', $this->files['Saves/states/Zelda.state']);
	}

	public function testItIsDoneOnce(): void {
		$this->given(['Saves/Nintendo/Mario/Slot 1.state' => 'one']);
		$this->assertSame([1, 0], $this->converter()->convertIfNeeded(self::USER));
		$this->assertTrue($this->done);

		$this->given(['Saves/Nintendo/Luigi/Slot 1.state' => 'later']);
		$this->assertNull($this->converter()->convertIfNeeded(self::USER), 'once done, never again');
		$this->assertArrayHasKey('Saves/Nintendo/Luigi/Slot 1.state', $this->files);
	}

	public function testAnotherProcessAtItIsWaitedFor(): void {
		// The job is moving them as the player asks: the request waits, and
		// answers once they are moved -- never from the empty new place.
		$this->given(['Saves/Nintendo/Mario/Slot 1.state' => 'one']);
		$this->lockedFor = 2;

		$this->assertSame([1, 0], $this->converter()->convertIfNeeded(self::USER));
		$this->assertArrayHasKey('Saves/states/FCEUmm/Mario.state1', $this->files);
	}

	public function testAnotherProcessThatTakesTooLongFailsTheRequest(): void {
		$this->given(['Saves/Nintendo/Mario/Slot 1.state' => 'one']);
		$this->lockedFor = 1000;
		$converter = $this->converter();

		$this->expectException(SavesBeingMovedException::class);
		$converter->convertIfNeeded(self::USER);
	}

	public function testAMoveThatFailedIsTriedAgainNextTime(): void {
		$this->given(['Saves/Nintendo/Mario/Slot 1.state' => 'one', 'Saves/Nintendo/Mario/Slot 2.state' => 'two']);
		$this->failing = ['Saves/Nintendo/Mario/Slot 2.state'];

		$this->assertSame([1, 1], $this->converter()->convertIfNeeded(self::USER));
		$this->assertFalse($this->done, 'not done while a file could still go');

		$this->failing = [];
		$this->assertSame([1, 0], $this->converter()->convertIfNeeded(self::USER));
		$this->assertTrue($this->done);
		$this->assertArrayHasKey('Saves/states/FCEUmm/Mario.state2', $this->files);
	}

	public function testFoldersAreMatchedTheWayThoseVersionsFoundThem(): void {
		// Another case than the ROM's, and a folder from before the system
		// was part of the path: both the same game, so the core and the
		// name come from the table, not from the folders.
		$this->given([
			'Saves/Nintendo/mario/Slot 1.state' => 'one',
			'Saves/Zelda/Slot 2.state' => 'two',
		]);
		$this->games = [
			'1' => ['path' => '/Games/NES/Mario.nes', 'md5' => '', 'core' => '', 'name' => ''],
			'2' => ['path' => '/Games/NES/Zelda.nes', 'md5' => '', 'core' => '', 'name' => ''],
		];

		$this->converter()->convertIfNeeded(self::USER);

		$this->assertSame([
			'Saves/states/FCEUmm/Mario.state1' => 'one',
			'Saves/states/FCEUmm/Zelda.state2' => 'two',
		], $this->onlyFiles());
	}

	public function testABatteryBesideTheOneMovedIsLeftAndSaid(): void {
		$this->given([
			'Saves/Nintendo/Mario/Mario.srm' => 'the one',
			'Saves/Nintendo/Mario/Mario (USA).srm' => 'from before a rename',
		]);
		$this->games = ['1' => ['path' => '/Games/NES/Mario.nes', 'md5' => '', 'core' => '', 'name' => '']];

		$this->assertSame([1, 1], $this->converter()->convertIfNeeded(self::USER));
		$this->assertSame('the one', $this->files['Saves/saves/FCEUmm/Mario.srm']);
		$this->assertSame('from before a rename', $this->files['Saves/Nintendo/Mario/Mario (USA).srm']);
	}

	public function testWithoutASavesFolderItIsAskedAgainLater(): void {
		$this->assertNull($this->converter('')->convertIfNeeded(self::USER));
		$this->assertFalse($this->done);
	}

	public function testAZipsSavesGoByTheNameNotedForIt(): void {
		$this->given(['Saves/Super Nintendo/Pack/Slot 2.state' => 'two', 'Saves/Super Nintendo/Other/Slot 1.state' => 'one']);
		$this->games = [
			'7' => ['path' => '/Games/SNES/Pack.zip', 'md5' => 'abc', 'core' => '', 'name' => 'Chrono Trigger (USA)'],
			'8' => ['path' => '/Games/SNES/Other.zip', 'md5' => '', 'core' => '', 'name' => ''],
		];

		$this->converter()->convertIfNeeded(self::USER);

		// The one whose game inside is noted goes by it; the other by the
		// zip's own name, until the player says what is inside.
		$this->assertArrayHasKey('Saves/states/Snes9x/Chrono Trigger (USA).state2', $this->files);
		$this->assertArrayHasKey('Saves/states/Snes9x/Other.state1', $this->files);
		$this->assertSame([], $this->noted, 'and the table is left as it was');
	}
}
