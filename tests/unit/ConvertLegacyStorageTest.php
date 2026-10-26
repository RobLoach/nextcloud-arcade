<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Db\PlayMapper;
use OCA\Arcade\Migration\ConvertLegacyStorage;
use OCP\Config\IUserConfig;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\ITagManager;
use OCP\ITags;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The one-time conversion at upgrade: what older versions left behind goes
 * to where it lives now, and whatever cannot be matched stays exactly
 * where it is.
 */
class ConvertLegacyStorageTest extends TestCase {
	private const USER = 'alice';
	private const GAME = '/Games/NES/Mario.nes';

	/** The users of the instance. */
	private array $users = [self::USER];
	/** Everything the user folder holds, by path. */
	private array $files = [];
	/** The id Nextcloud gave each path, for the paths that have one. */
	private array $ids = [];
	/** The rows of the plays table, by user then file id. */
	private array $plays = [];
	/** What is kept in the user config, by user then key. */
	private array $configs = [];
	/** The file ids each user has starred in Files. */
	private array $starred = [];

	private function convert(): void {
		$step = new ConvertLegacyStorage(
			$this->rootFolder(),
			$this->userConfig(),
			$this->tagManager(),
			$this->playMapper(),
		);
		$step->run($this->createStub(IOutput::class));
	}



	private function userConfig(): IUserConfig {
		$config = $this->createStub(IUserConfig::class);
		$config->method('getValuesByUsers')->willReturnCallback(
			function (string $app, string $key): array {
				$values = [];
				foreach ($this->configs as $userId => $stored) {
					if (isset($stored[$key])) {
						$values[$userId] = $stored[$key];
					}
				}
				return $values;
			},
		);
		$config->method('deleteUserConfig')->willReturnCallback(
			function (string $userId, string $app, string $key): void {
				unset($this->configs[$userId][$key]);
			},
		);
		return $config;
	}

	private function tagManager(): ITagManager {
		$manager = $this->createStub(ITagManager::class);
		$manager->method('load')->willReturnCallback(
			function (string $type, array $ids, bool $includeShared, ?string $userId): ITags {
				$tags = $this->createStub(ITags::class);
				$tags->method('addToFavorites')->willReturnCallback(
					function ($id) use ($userId): bool {
						$this->starred[$userId][(int)$id] = true;
						return true;
					},
				);
				return $tags;
			},
		);
		return $manager;
	}


	private function playMapper(): PlayMapper {
		$mapper = $this->createStub(PlayMapper::class);
		$mapper->method('importPlay')->willReturnCallback(
			function (string $userId, int $fileId, int $plays, int $seconds, int $time): void {
				$this->plays[$userId][$fileId] ??= ['plays' => $plays, 'seconds' => $seconds, 'time' => $time];
			},
		);
		return $mapper;
	}

	// The app data, as a tree of simple folders.




	// The files of the user, as a flat map of paths.

	private function rootFolder(): IRootFolder {
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($this->userFolder(''));
		return $root;
	}

	private function userFolder(string $prefix): Folder {
		$folder = $this->createStub(Folder::class);
		$folder->method('getName')->willReturn($prefix === '' ? 'files' : basename($prefix));
		$folder->method('getPath')->willReturn($prefix === '' ? '' : '/' . $prefix);
		$folder->method('nodeExists')->willReturnCallback(
			fn (string $name): bool => isset($this->files[$this->join($prefix, $name)])
				|| $this->hasChildren($this->join($prefix, $name)),
		);
		$folder->method('get')->willReturnCallback(
			function (string $name) use ($prefix) {
				$path = $this->join($prefix, $name);
				if (isset($this->files[$path])) {
					return $this->userFile($path);
				}
				if ($this->hasChildren($path)) {
					return $this->userFolder($path);
				}
				throw new NotFoundException($path);
			},
		);
		$folder->method('newFolder')->willReturnCallback(
			function (string $name) use ($prefix): Folder {
				$path = $this->join($prefix, $name);
				// Remembered by the files put in it.
				$this->files[$path . '/.folder'] = '';
				return $this->userFolder($path);
			},
		);
		$folder->method('getDirectoryListing')->willReturnCallback(
			function () use ($prefix): array {
				$base = $prefix === '' ? '' : $prefix . '/';
				$nodes = [];
				$folders = [];
				foreach (array_keys($this->files) as $path) {
					if ($base !== '' && !str_starts_with($path, $base)) {
						continue;
					}
					$rest = substr($path, strlen($base));
					$slash = strpos($rest, '/');
					if ($slash === false) {
						if ($rest !== '' && $rest !== '.folder') {
							$nodes[] = $this->userFile($path);
						}
						continue;
					}
					$name = substr($rest, 0, $slash);
					if (!isset($folders[$name])) {
						$folders[$name] = true;
						$nodes[] = $this->userFolder($base . $name);
					}
				}
				return $nodes;
			},
		);
		$folder->method('move')->willReturnCallback(
			function (string $target) use ($prefix): Folder {
				$to = trim($target, '/');
				foreach (array_keys($this->files) as $path) {
					if (str_starts_with($path, $prefix . '/')) {
						$this->files[$to . substr($path, strlen($prefix))] = $this->files[$path];
						unset($this->files[$path]);
					}
				}
				return $this->userFolder($to);
			},
		);
		return $folder;
	}

	private function userFile(string $path): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn(basename($path));
		$file->method('getId')->willReturnCallback(fn (): int => $this->ids[$path] ?? 0);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->files[$path]);
		return $file;
	}

	private function join(string $prefix, string $name): string {
		return $prefix === '' ? trim($name, '/') : $prefix . '/' . trim($name, '/');
	}

	private function hasChildren(string $path): bool {
		foreach (array_keys($this->files) as $known) {
			if (str_starts_with($known, $path . '/')) {
				return true;
			}
		}
		return false;
	}

	// The games registry: games.json goes into the table, and the file goes.

	public function testThePlaysAndFavoritesAreImportedAndTheBlobsGo(): void {
		$this->configs[self::USER] = [
			'stats' => json_encode([
				101 => ['seconds' => 300, 'plays' => 2, 'time' => 6000],
			]),
			'recent' => json_encode([['id' => 101], ['id' => 102]]),
			'favorites' => json_encode([
				['path' => self::GAME, 'seconds' => 999, 'plays' => 9, 'time' => 1],
				['path' => '/Games/Gone.nes', 'seconds' => 10, 'plays' => 1, 'time' => 900],
			]),
		];
		$this->files[ltrim(self::GAME, '/')] = 'the rom';
		$this->ids[ltrim(self::GAME, '/')] = 101;

		$this->convert();

		$this->assertSame(
			['plays' => 2, 'seconds' => 300, 'time' => 6000],
			$this->plays[self::USER][101],
			'the counts win over the favorite of the same game',
		);
		$this->assertSame(1, $this->plays[self::USER][102]['plays'], 'a game only on the recent list counts once');
		$this->assertSame([101 => true], $this->starred[self::USER], 'the favorite that is still there is starred');
		$this->assertSame([], $this->configs[self::USER], 'and the blobs are gone');
	}

	public function testRunningTwiceChangesNothing(): void {
		$this->files[ltrim(self::GAME, '/')] = 'the rom';
		$this->ids[ltrim(self::GAME, '/')] = 101;
		$this->configs[self::USER] = [
			'stats' => json_encode([101 => ['seconds' => 300, 'plays' => 2, 'time' => 6000]]),
			'favorites' => json_encode([['path' => self::GAME, 'plays' => 2]]),
		];

		$this->convert();
		$was = [$this->files, $this->plays, $this->configs, $this->starred];
		$this->convert();

		$this->assertSame($was, [$this->files, $this->plays, $this->configs, $this->starred]);
	}

}

