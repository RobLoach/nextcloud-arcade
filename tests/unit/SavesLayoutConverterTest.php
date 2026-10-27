<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Db\GameMapper;
use OCA\Arcade\Service\CloudSyncManifest;
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
	private bool $locked = false;

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
			if ($this->locked) {
				throw new LockedException('arcade/saves-layout/alice');
			}
		});
		return new SavesLayoutConverter($root, $config, $settings, $games, $manifest, $locks, $this->createStub(LoggerInterface::class));
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

	public function testItIsDoneOnceAndNotWhileAnotherIsAtIt(): void {
		$this->given(['Saves/Nintendo/Mario/Slot 1.state' => 'one']);

		$this->locked = true;
		$this->assertNull($this->converter()->convertIfNeeded(self::USER));
		$this->assertFalse($this->done, 'another process holds the lock');

		$this->locked = false;
		$this->assertSame([1, 0], $this->converter()->convertIfNeeded(self::USER));
		$this->assertTrue($this->done);

		$this->given(['Saves/Nintendo/Luigi/Slot 1.state' => 'later']);
		$this->assertNull($this->converter()->convertIfNeeded(self::USER), 'once done, never again');
		$this->assertArrayHasKey('Saves/Nintendo/Luigi/Slot 1.state', $this->files);
	}

	public function testWithoutASavesFolderItIsAskedAgainLater(): void {
		$this->assertNull($this->converter('')->convertIfNeeded(self::USER));
		$this->assertFalse($this->done);
	}

	public function testAZipsSavesGoByTheGameInsideWithItsChecksumKept(): void {
		// The zip cannot be opened here, so they go by the zip's own name:
		// what the table knows is kept as it was.
		$this->given(['Saves/Super Nintendo/Pack/Slot 2.state' => 'two']);
		$this->games = ['7' => ['path' => '/Games/SNES/Pack.zip', 'md5' => 'abc', 'core' => '', 'name' => '']];

		$this->converter()->convertIfNeeded(self::USER);

		$this->assertArrayHasKey('Saves/states/Snes9x/Pack.state2', $this->files);
		$this->assertSame([], $this->noted, 'nothing noted without a name read from the zip');
	}
}
