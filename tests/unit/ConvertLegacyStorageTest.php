<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Db\GameMapper;
use OCA\Arcade\Db\PlayMapper;
use OCA\Arcade\Migration\ConvertLegacyStorage;
use OCP\Config\IUserConfig;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ITagManager;
use OCP\ITags;
use OCP\IUser;
use OCP\IUserManager;
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
	/** Everything the app data holds, by folder then file name. */
	private array $appData = [];
	/** Everything the user folder holds, by path. */
	private array $files = [];
	/** The id Nextcloud gave each path, for the paths that have one. */
	private array $ids = [];
	/** The rows of the games table, by user then key, as the mapper keeps them. */
	private array $games = [];
	/** The rows of the plays table, by user then file id. */
	private array $plays = [];
	/** What is kept in the user config, by user then key. */
	private array $configs = [];
	/** The file ids each user has starred in Files. */
	private array $starred = [];

	private function convert(): void {
		$step = new ConvertLegacyStorage(
			$this->appDataFactory(),
			$this->rootFolder(),
			$this->userManager(),
			$this->userConfig(),
			$this->tagManager(),
			$this->gameMapper(),
			$this->playMapper(),
		);
		$step->run($this->createStub(IOutput::class));
	}

	/** Where the states of a user live in the app data. */
	private function stateFolderOf(string $userId): string {
		return 'states/' . hash('sha256', $userId);
	}

	private function userManager(): IUserManager {
		$manager = $this->createStub(IUserManager::class);
		$manager->method('callForAllUsers')->willReturnCallback(
			function (\Closure $callback): void {
				foreach ($this->users as $uid) {
					$user = $this->createStub(IUser::class);
					$user->method('getUID')->willReturn($uid);
					$callback($user);
				}
			},
		);
		return $manager;
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

	private function gameMapper(): GameMapper {
		$mapper = $this->createStub(GameMapper::class);
		$mapper->method('entriesOf')->willReturnCallback(
			fn (string $userId): array => array_map(
				static fn (array $row): array => ['path' => $row['path'], 'md5' => $row['md5']],
				$this->games[$userId] ?? [],
			),
		);
		$mapper->method('importEntry')->willReturnCallback(
			function (string $userId, string $key, int $fileId, string $path, string $checksum): void {
				$this->games[$userId][$key] ??= ['file_id' => $fileId, 'path' => $path, 'md5' => $checksum];
			},
		);
		$mapper->method('remove')->willReturnCallback(
			function (string $userId, string ...$keys): void {
				foreach ($keys as $key) {
					unset($this->games[$userId][$key]);
				}
			},
		);
		return $mapper;
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

	private function appDataFactory(): IAppDataFactory {
		$appData = $this->createStub(IAppData::class);
		$appData->method('getFolder')->willReturnCallback(
			function (string $name): ISimpleFolder {
				if (!isset($this->appData[$name])) {
					throw new NotFoundException($name);
				}
				return $this->simpleFolder($name);
			},
		);

		$factory = $this->createStub(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);
		return $factory;
	}

	private function simpleFolder(string $path): ISimpleFolder {
		$folder = $this->createStub(ISimpleFolder::class);
		$folder->method('getName')->willReturn(basename($path));
		$folder->method('fileExists')->willReturnCallback(
			fn (string $name): bool => isset($this->appData[$path][$name]),
		);
		$folder->method('getFile')->willReturnCallback(
			function (string $name) use ($path): ISimpleFile {
				if (!isset($this->appData[$path][$name])) {
					throw new NotFoundException($name);
				}
				return $this->simpleFile($path, $name);
			},
		);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, $content = null) use ($path): ISimpleFile {
				$this->appData[$path][$name] = (string)$content;
				return $this->simpleFile($path, $name);
			},
		);
		$folder->method('getDirectoryListing')->willReturnCallback(
			function () use ($path): array {
				$nodes = [];
				foreach (array_keys($this->appData[$path] ?? []) as $name) {
					if (is_string($this->appData[$path][$name])) {
						$nodes[] = $this->simpleFile($path, $name);
					}
				}
				foreach (array_keys($this->appData) as $other) {
					if (dirname($other) === $path) {
						$nodes[] = $this->simpleFolder($other);
					}
				}
				return $nodes;
			},
		);
		return $folder;
	}

	private function simpleFile(string $path, string $name): ISimpleFile {
		$file = $this->createStub(ISimpleFile::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->appData[$path][$name]);
		$file->method('delete')->willReturnCallback(
			function () use ($path, $name): void {
				unset($this->appData[$path][$name]);
			},
		);
		return $file;
	}

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

	public function testAGamesJsonIsBroughtIntoTheTableAndRemoved(): void {
		// As an earlier version would have left it: an entry that still
		// carried its checksum, one written as a bare path before the
		// checksum was kept, and one keyed by the hash of its path from
		// before the file id was used.
		$userFolder = $this->stateFolderOf(self::USER);
		$pathHash = hash('sha256', '/Games/Kirby.gb');
		$this->appData['states'] = [];
		$this->appData[$userFolder] = [
			'games.json' => json_encode([
				'101' => ['path' => self::GAME, 'md5' => 'the dump'],
				'202' => '/Games/Zelda.sfc',
				$pathHash => ['path' => '/Games/Kirby.gb', 'md5' => ''],
			]),
		];
		// A row written since the table exists wins over the file.
		$this->games[self::USER]['202'] = ['file_id' => 202, 'path' => '/Games/Zelda II.sfc', 'md5' => 'newer'];

		$this->convert();

		$this->assertSame(self::GAME, $this->games[self::USER]['101']['path']);
		$this->assertSame('the dump', $this->games[self::USER]['101']['md5']);
		$this->assertSame(101, $this->games[self::USER]['101']['file_id']);
		$this->assertSame('/Games/Zelda II.sfc', $this->games[self::USER]['202']['path'], 'the table already knew better');
		$this->assertSame('/Games/Kirby.gb', $this->games[self::USER][$pathHash]['path']);
		$this->assertSame(0, $this->games[self::USER][$pathHash]['file_id'], 'a hash is no file id');
		$this->assertArrayNotHasKey('games.json', $this->appData[$userFolder], 'and the file is gone');
	}

	// States filed under the hash of their path move to the id of their file.

	public function testPathHashedStatesAreRenamedToTheFileId(): void {
		$this->files[ltrim(self::GAME, '/')] = 'the rom';
		$this->ids[ltrim(self::GAME, '/')] = 101;
		$hash = hash('sha256', self::GAME);
		$userFolder = $this->stateFolderOf(self::USER);
		$this->appData['states'] = [];
		$this->appData[$userFolder] = [
			"$hash-1.state" => 'a save',
			"$hash-1.png" => 'a picture',
			"$hash.srm" => 'a battery save',
		];
		$this->games[self::USER][$hash] = ['file_id' => 0, 'path' => self::GAME, 'md5' => 'the dump'];

		$this->convert();

		$this->assertSame(
			['101-1.png' => 'a picture', '101-1.state' => 'a save', '101.srm' => 'a battery save'],
			$this->sorted($this->appData[$userFolder]),
		);
		$this->assertSame(
			['101' => ['file_id' => 101, 'path' => self::GAME, 'md5' => 'the dump']],
			$this->games[self::USER],
			'the registry entry follows its files',
		);
	}

	public function testNothingIsOverwrittenByTheRenaming(): void {
		$this->files[ltrim(self::GAME, '/')] = 'the rom';
		$this->ids[ltrim(self::GAME, '/')] = 101;
		$hash = hash('sha256', self::GAME);
		$userFolder = $this->stateFolderOf(self::USER);
		$this->appData['states'] = [];
		$this->appData[$userFolder] = [
			"$hash-1.state" => 'an old save',
			'101-1.state' => 'saved since',
		];
		$this->games[self::USER][$hash] = ['file_id' => 0, 'path' => self::GAME, 'md5' => ''];

		$this->convert();

		$this->assertSame('saved since', $this->appData[$userFolder]['101-1.state']);
		$this->assertSame('an old save', $this->appData[$userFolder]["$hash-1.state"], 'the old file stays');
		$this->assertArrayHasKey($hash, $this->games[self::USER], 'and is not forgotten');
	}

	public function testAGameThatIsGoneKeepsTheNamesItHad(): void {
		// The path resolves to nothing, so there is no file id to move to.
		$hash = hash('sha256', '/Games/Gone.nes');
		$userFolder = $this->stateFolderOf(self::USER);
		$this->appData['states'] = [];
		$this->appData[$userFolder] = ["$hash-1.state" => 'a save'];
		$this->games[self::USER][$hash] = ['file_id' => 0, 'path' => '/Games/Gone.nes', 'md5' => ''];

		$this->convert();

		$this->assertSame('a save', $this->appData[$userFolder]["$hash-1.state"]);
		$this->assertArrayHasKey($hash, $this->games[self::USER]);
	}

	// The flat files of the shared states root move in with their user.

	public function testFlatLegacyFilesMoveIntoTheFolderOfTheirUser(): void {
		$legacy = hash('sha256', self::USER . '|' . self::GAME);
		$userFolder = $this->stateFolderOf(self::USER);
		$this->appData['states'] = [
			"$legacy-1.state" => 'an old state',
			"$legacy.srm" => 'an old battery save',
			'0000000000000000000000000000000000000000000000000000000000000000-1.state' => 'whose?',
		];
		$this->appData[$userFolder] = [
			'games.json' => json_encode(['101' => self::GAME]),
		];

		$this->convert();

		$this->assertSame('an old state', $this->appData[$userFolder]['101-1.state']);
		$this->assertSame('an old battery save', $this->appData[$userFolder]['101.srm']);
		$this->assertArrayNotHasKey("$legacy-1.state", $this->appData['states']);
		$this->assertSame(
			'whose?',
			$this->appData['states']['0000000000000000000000000000000000000000000000000000000000000000-1.state'],
			'a file that matches no game of no user stays put',
		);
	}

	public function testAFlatFileDoesNotOverwriteWhatWasSavedSince(): void {
		$legacy = hash('sha256', self::USER . '|' . self::GAME);
		$userFolder = $this->stateFolderOf(self::USER);
		$this->appData['states'] = ["$legacy-1.state" => 'an old state'];
		$this->appData[$userFolder] = ['101-1.state' => 'saved since'];
		$this->games[self::USER]['101'] = ['file_id' => 101, 'path' => self::GAME, 'md5' => ''];

		$this->convert();

		$this->assertSame('saved since', $this->appData[$userFolder]['101-1.state']);
		$this->assertSame('an old state', $this->appData['states']["$legacy-1.state"], 'the flat file stays');
	}

	public function testTheFolderOfAUserThatIsGoneIsLeftAlone(): void {
		$this->appData['states'] = [];
		$this->appData['states/' . hash('sha256', 'nobody')] = [
			'games.json' => '{"101": "/Games/Mario.nes"}',
		];

		$this->convert();

		$this->assertSame(
			'{"101": "/Games/Mario.nes"}',
			$this->appData['states/' . hash('sha256', 'nobody')]['games.json'],
			'occ arcade:cleanup is the one that removes those',
		);
	}

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
		$legacy = hash('sha256', self::USER . '|' . self::GAME);
		$this->files[ltrim(self::GAME, '/')] = 'the rom';
		$this->ids[ltrim(self::GAME, '/')] = 101;
		$this->appData['states'] = ["$legacy-1.state" => 'an old state'];
		$this->appData[$this->stateFolderOf(self::USER)] = [
			'games.json' => json_encode([hash('sha256', self::GAME) => self::GAME]),
		];
		$this->configs[self::USER] = [
			'stats' => json_encode([101 => ['seconds' => 300, 'plays' => 2, 'time' => 6000]]),
			'favorites' => json_encode([['path' => self::GAME, 'plays' => 2]]),
		];

		$this->convert();
		$was = [$this->appData, $this->files, $this->games, $this->plays, $this->configs, $this->starred];
		$this->convert();

		$this->assertSame(
			$was,
			[$this->appData, $this->files, $this->games, $this->plays, $this->configs, $this->starred],
		);
	}

	/** Sorted by name, so a listing can be compared whole. */
	private function sorted(array $files): array {
		ksort($files);
		return $files;
	}
}
