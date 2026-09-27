<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Db\GameMapper;
use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Service\StateService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;

class StateServiceTest extends TestCase {
	private const USER = 'alice';
	private const GAME = '/Games/NES/Mario.nes';

	/** Everything the app data holds, by folder then file name. */
	private array $appData = [];
	/** Everything the user folder holds, by path. */
	private array $files = [];
	/** The id Nextcloud gave each path, for the paths that have one. */
	private array $ids = [];
	/** What each file id hashes to, for the ROMs that have been read. */
	private array $checksums = [];
	/** The rows of the games table, by user then key, as the mapper keeps them. */
	private array $games = [];

	private function service(string $savesFolder = ''): StateService {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getUserSettings')->willReturn(['saves_folder' => $savesFolder]);

		return new StateService(
			$this->appDataFactory(),
			$this->rootFolder(),
			$settings,
			$this->metadataManager(),
			$this->gameMapper(),
		);
	}

	/** The games table, as the mapper presents it. */
	private function gameMapper(): GameMapper {
		$mapper = $this->createStub(GameMapper::class);
		$mapper->method('entriesOf')->willReturnCallback(
			fn (string $userId): array => array_map(
				static fn (array $row): array => ['path' => $row['path'], 'md5' => $row['md5']],
				$this->games[$userId] ?? [],
			),
		);
		$mapper->method('entryOf')->willReturnCallback(
			function (string $userId, string $key): ?array {
				$row = $this->games[$userId][$key] ?? null;
				return $row === null ? null : ['path' => $row['path'], 'md5' => $row['md5']];
			},
		);
		$mapper->method('set')->willReturnCallback(
			function (string $userId, string $key, int $fileId, string $path, string $checksum): void {
				$this->games[$userId][$key] = ['file_id' => $fileId, 'path' => $path, 'md5' => $checksum];
			},
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
		$mapper->method('deleteAllForUser')->willReturnCallback(
			function (string $userId): void {
				unset($this->games[$userId]);
			},
		);
		return $mapper;
	}

	/**
	 * What Nextcloud knows a ROM hashes to, for the ones the tests say.
	 */
	private function metadataManager(): IFilesMetadataManager {
		$manager = $this->createStub(IFilesMetadataManager::class);
		$manager->method('getMetadata')->willReturnCallback(
			function (int $id): IFilesMetadata {
				$metadata = $this->createStub(IFilesMetadata::class);
				$metadata->method('getString')->willReturn($this->checksums[$id] ?? '');
				return $metadata;
			},
		);
		return $manager;
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
		$appData->method('newFolder')->willReturnCallback(
			function (string $name): ISimpleFolder {
				$this->appData[$name] ??= [];
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
		// Files and the folders beneath, as a real listing gives them: the
		// states folder holds a folder per user, and the app tells them
		// apart from the files left there by older versions.
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
		$folder->method('getFolder')->willReturnCallback(
			function (string $name) use ($path): ISimpleFolder {
				$child = "$path/$name";
				if (!isset($this->appData[$child])) {
					throw new NotFoundException($name);
				}
				return $this->simpleFolder($child);
			},
		);
		$folder->method('newFolder')->willReturnCallback(
			function (string $name) use ($path): ISimpleFolder {
				$child = "$path/$name";
				$this->appData[$child] ??= [];
				return $this->simpleFolder($child);
			},
		);
		$folder->method('delete')->willReturnCallback(
			function () use ($path): void {
				unset($this->appData[$path]);
			},
		);
		return $folder;
	}

	private function simpleFile(string $path, string $name): ISimpleFile {
		$file = $this->createStub(ISimpleFile::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->appData[$path][$name]);
		$file->method('getSize')->willReturnCallback(fn (): int => strlen($this->appData[$path][$name]));
		$file->method('getMTime')->willReturn(1000);
		$file->method('putContent')->willReturnCallback(
			function ($content) use ($path, $name): void {
				$this->appData[$path][$name] = (string)$content;
			},
		);
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
		$folder->method('newFile')->willReturnCallback(
			function (string $name, $content = null) use ($prefix): File {
				$path = $this->join($prefix, $name);
				$this->files[$path] = (string)$content;
				return $this->userFile($path);
			},
		);
		// Files and folders both, as a real listing gives them.
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
		$folder->method('delete')->willReturnCallback(
			function () use ($prefix): void {
				foreach (array_keys($this->files) as $path) {
					if (str_starts_with($path, $prefix . '/') || $path === $prefix) {
						unset($this->files[$path]);
					}
				}
			},
		);
		return $folder;
	}

	private function userFile(string $path): File {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn(basename($path));
		$file->method('getId')->willReturnCallback(fn (): int => $this->ids[$path] ?? 0);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->files[$path]);
		$file->method('getSize')->willReturnCallback(fn (): int => strlen($this->files[$path]));
		$file->method('getMTime')->willReturn(2000);
		$file->method('putContent')->willReturnCallback(
			function ($content) use ($path): void {
				$this->files[$path] = (string)$content;
			},
		);
		$file->method('delete')->willReturnCallback(
			function () use ($path): void {
				unset($this->files[$path]);
			},
		);
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

	// What the states are for.

	public function testAStateIsSavedAndReadBack(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'the state');
		$this->assertSame('the state', $service->load(self::USER, self::GAME, 1));
	}

	public function testStatesOfDifferentUsersAndGamesDoNotMix(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'of alice');
		$service->save('bob', self::GAME, 1, 'of bob');
		$service->save(self::USER, '/Games/Zelda.sfc', 1, 'another game');

		$this->assertSame('of alice', $service->load(self::USER, self::GAME, 1));
		$this->assertSame('of bob', $service->load('bob', self::GAME, 1));
		$this->assertSame('another game', $service->load(self::USER, '/Games/Zelda.sfc', 1));
	}

	public function testSlotsDoNotMix(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, StateService::AUTO_SLOT, 'automatic');
		$service->save(self::USER, self::GAME, 2, 'second');

		$this->assertSame('automatic', $service->load(self::USER, self::GAME, StateService::AUTO_SLOT));
		$this->assertSame('second', $service->load(self::USER, self::GAME, 2));
		$this->assertNull($service->load(self::USER, self::GAME, 3), 'an empty slot holds nothing');
	}

	public function testSavesFollowAGameThatIsRenamed(): void {
		$this->files[ltrim(self::GAME, '/')] = 'the rom';
		$this->ids[ltrim(self::GAME, '/')] = 101;
		$service = $this->service('/Saves');
		$service->save(self::USER, self::GAME, 1, 'a save');
		$service->saveSram(self::USER, self::GAME, 'a battery save');

		// The same file, under another name, in another folder.
		$renamed = '/Games/NES/Super Mario Bros.nes';
		unset($this->files[ltrim(self::GAME, '/')], $this->ids[ltrim(self::GAME, '/')]);
		$this->files[ltrim($renamed, '/')] = 'the rom';
		$this->ids[ltrim($renamed, '/')] = 101;

		$this->assertSame('a save', $service->load(self::USER, $renamed, 1), 'the save is found again');
		$this->assertSame('a battery save', $service->loadSram(self::USER, $renamed));
		$this->assertSame([1], array_column($service->list(self::USER, $renamed), 'slot'));
	}

	public function testGamesThatShareANameAreNotConfused(): void {
		$this->files['Games/NES/Mario.nes'] = 'one';
		$this->ids['Games/NES/Mario.nes'] = 101;
		$this->files['Games/SNES/Mario.nes'] = 'another';
		$this->ids['Games/SNES/Mario.nes'] = 202;

		$service = $this->service();
		$service->save(self::USER, '/Games/NES/Mario.nes', 1, 'the nes save');
		$service->save(self::USER, '/Games/SNES/Mario.nes', 1, 'the snes save');

		$this->assertSame('the nes save', $service->load(self::USER, '/Games/NES/Mario.nes', 1));
		$this->assertSame('the snes save', $service->load(self::USER, '/Games/SNES/Mario.nes', 1));
	}

	public function testTheScreenshotOfASaveIsFoundUnderTheSystemOfTheGame(): void {
		// Saves are filed under the system, so the folders of the saves
		// folder are systems holding games, not games.
		$this->files[ltrim(self::GAME, '/')] = 'the rom';
		$this->ids[ltrim(self::GAME, '/')] = 101;
		$service = $this->service('/Saves');
		$service->save(self::USER, self::GAME, 1, 'a save');
		$service->saveThumbnail(self::USER, self::GAME, 1, 'a picture');

		$found = $service->thumbnailIndex(self::USER, [self::GAME]);
		$this->assertSame(1, $found[self::GAME]['slot'] ?? null, 'the slot the picture belongs to');
	}

	public function testTheScreenshotOfAGameSavedBeforeSystemsAreFoundToo(): void {
		$service = $this->service('/Saves');
		// As an older version would have left it: straight in the folder.
		$this->files['Saves/Mario/Slot 1.state'] = 'a save';
		$this->files['Saves/Mario/Slot 1.png'] = 'a picture';

		$found = $service->thumbnailIndex(self::USER, ['/Games/Mario.nes']);
		$this->assertSame(1, $found['/Games/Mario.nes']['slot'] ?? null);
	}

	public function testASaveIsMarkedWhenTheRomIsNoLongerTheOneItWasMadeFrom(): void {
		$this->files[ltrim(self::GAME, '/')] = 'the rom';
		$this->ids[ltrim(self::GAME, '/')] = 101;
		$this->checksums[101] = 'the first dump';

		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'a save');
		$this->assertArrayNotHasKey('stale', $service->list(self::USER, self::GAME)[0]);

		// The same game, a different dump of it.
		$this->checksums[101] = 'another dump';
		$this->assertTrue($service->list(self::USER, self::GAME)[0]['stale'] ?? false);
	}

	public function testASaveIsNotMarkedWhenNothingIsKnownOfTheRom(): void {
		$this->files[ltrim(self::GAME, '/')] = 'the rom';
		$this->ids[ltrim(self::GAME, '/')] = 101;

		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'a save');
		$this->checksums[101] = 'worked out later';

		$this->assertArrayNotHasKey(
			'stale',
			$service->list(self::USER, self::GAME)[0],
			'a checksum that arrived after the save says nothing about it',
		);
	}

	public function testTheStatesOfAUserAreFoundAsAFolderOfTheirOwn(): void {
		// What the cleanup command walks: a folder per user, told apart
		// from the flat files older versions left in the same place.
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'a save');

		$folders = $service->userFolders();
		$this->assertSame([$service->folderKeyOf(self::USER)], array_keys($folders));
		$this->assertSame(0, $service->countLegacyFiles(), 'nothing flat was left behind');
	}

	public function testThumbnailsAndBatterySavesLiveAlongsideTheStates(): void {
		$service = $this->service();
		$service->saveThumbnail(self::USER, self::GAME, 1, 'a picture');
		$service->saveSram(self::USER, self::GAME, 'a battery save');

		$this->assertSame('a picture', $service->loadThumbnail(self::USER, self::GAME, 1));
		$this->assertSame('a battery save', $service->loadSram(self::USER, self::GAME));
	}

	public function testTheSlotsOfAGameAreListedWithWhatIsKnownOfThem(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, StateService::AUTO_SLOT, 'automatic');
		$service->save(self::USER, self::GAME, 2, 'second');
		$service->saveThumbnail(self::USER, self::GAME, 2, 'a picture');

		$states = $service->list(self::USER, self::GAME);
		$this->assertSame([StateService::AUTO_SLOT, 2], array_column($states, 'slot'));
		$this->assertFalse($states[0]['hasThumbnail']);
		$this->assertTrue($states[1]['hasThumbnail']);
		$this->assertSame(strlen('second'), $states[1]['size']);
	}

	public function testDeletingASlotLeavesTheOthersAlone(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'first');
		$service->saveThumbnail(self::USER, self::GAME, 1, 'a picture');
		$service->save(self::USER, self::GAME, 2, 'second');

		$this->assertTrue($service->delete(self::USER, self::GAME, 1));
		$this->assertNull($service->load(self::USER, self::GAME, 1));
		$this->assertNull($service->loadThumbnail(self::USER, self::GAME, 1), 'the picture goes too');
		$this->assertSame('second', $service->load(self::USER, self::GAME, 2));
		$this->assertFalse($service->delete(self::USER, self::GAME, 1), 'and once is enough');
	}

	public function testTheBatterySaveCanBeDeletedFromTheAppData(): void {
		$service = $this->service();
		$service->saveSram(self::USER, self::GAME, 'a battery save');
		$this->assertTrue($service->hasSram(self::USER, self::GAME));

		$this->assertTrue($service->deleteSram(self::USER, self::GAME));

		$this->assertNull($service->loadSram(self::USER, self::GAME));
		$this->assertFalse($service->hasSram(self::USER, self::GAME));
		$this->assertFalse($service->deleteSram(self::USER, self::GAME), 'and once is enough');
	}

	public function testTheBatterySaveCanBeDeletedFromTheSavesFolder(): void {
		$service = $this->service('/Saves');
		$service->saveSram(self::USER, self::GAME, 'a battery save');
		$this->assertTrue($service->hasSram(self::USER, self::GAME));

		$this->assertTrue($service->deleteSram(self::USER, self::GAME));

		$this->assertArrayNotHasKey('Saves/Nintendo/Mario/Mario.srm', $this->files);
		$this->assertFalse($service->hasSram(self::USER, self::GAME));
		$this->assertFalse($service->deleteSram(self::USER, self::GAME), 'and once is enough');
	}

	public function testABatterySaveUnderTheOldNameOfTheGameIsStillDeleted(): void {
		$service = $this->service('/Saves');
		// As a rename leaves it: the folder followed the game, the battery
		// save inside kept the name the game had.
		$this->files['Saves/Nintendo/Mario/Super Mario Bros.srm'] = 'a battery save';

		$this->assertTrue($service->hasSram(self::USER, self::GAME));
		$this->assertSame('a battery save', $service->loadSram(self::USER, self::GAME));
		$this->assertTrue($service->deleteSram(self::USER, self::GAME));
		$this->assertArrayNotHasKey('Saves/Nintendo/Mario/Super Mario Bros.srm', $this->files);
	}

	public function testAGameWithoutABatterySaveHasNothingToDelete(): void {
		$service = $this->service();
		$this->assertFalse($service->hasSram(self::USER, self::GAME));
		$this->assertFalse($service->deleteSram(self::USER, self::GAME));
	}

	public function testEverythingOfAGameGoesWithIt(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'first');
		$service->save(self::USER, self::GAME, 2, 'second');
		$service->saveSram(self::USER, self::GAME, 'a battery save');
		$service->save(self::USER, '/Games/Zelda.sfc', 1, 'another game');

		$service->deleteAllForGame(self::USER, self::GAME);

		$this->assertSame([], $service->list(self::USER, self::GAME));
		$this->assertNull($service->loadSram(self::USER, self::GAME));
		$this->assertSame('another game', $service->load(self::USER, '/Games/Zelda.sfc', 1));
	}

	public function testAGameFiledUnderItsIdIsStillCleanedUpOnceItIsGone(): void {
		// Filed under the id of its file while it existed. The registry
		// hands that key back as a number -- PHP turns array keys that look
		// like numbers into numbers -- and the cleanup used to trip over it.
		$service = $this->service();
		$this->files[ltrim(self::GAME, '/')] = 'rom bytes';
		$this->ids[ltrim(self::GAME, '/')] = 101;
		$service->save(self::USER, self::GAME, 1, 'a state');
		$service->saveSram(self::USER, self::GAME, 'a battery save');

		// Deleted past the trash: the file and its id are gone, and only
		// the registry still knows what the game was filed under.
		unset($this->files[ltrim(self::GAME, '/')], $this->ids[ltrim(self::GAME, '/')]);
		$service->deleteAllForGame(self::USER, self::GAME);

		$states = $this->appData['states/' . hash('sha256', self::USER)] ?? [];
		$this->assertArrayNotHasKey('101-1.state', $states);
		$this->assertArrayNotHasKey('101.srm', $states);
		$this->assertSame([], $service->gamesOf(self::USER));
	}

	public function testEverythingOfAUserGoesWithThem(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'of alice');
		$service->save('bob', self::GAME, 1, 'of bob');

		$service->deleteAllForUser(self::USER);

		$this->assertNull($service->load(self::USER, self::GAME, 1));
		$this->assertSame('of bob', $service->load('bob', self::GAME, 1), 'and nobody else is touched');
	}

	public function testTheGamesAUserHasStatesForAreKnown(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'first');
		$service->save(self::USER, '/Games/Zelda.sfc', 1, 'another game');

		$games = array_values($service->gamesOf(self::USER));
		sort($games);
		$this->assertSame(['/Games/NES/Mario.nes', '/Games/Zelda.sfc'], $games);

		// Which is what tells the cleanup what is still played.
		$service->deleteAllForGame(self::USER, self::GAME);
		$this->assertSame(['/Games/Zelda.sfc'], array_values($service->gamesOf(self::USER)));
	}

	// With a saves folder, the states are files of the user.

	public function testASavesFolderKeepsTheStatesInTheFilesOfTheUser(): void {
		$service = $this->service('/Saves');
		$service->save(self::USER, self::GAME, 1, 'first');
		$service->save(self::USER, self::GAME, StateService::AUTO_SLOT, 'automatic');
		$service->saveThumbnail(self::USER, self::GAME, 1, 'a picture');
		$service->saveSram(self::USER, self::GAME, 'a battery save');

		// Under the system, so two games of the same name keep apart.
		$this->assertSame('first', $this->files['Saves/Nintendo/Mario/Slot 1.state'] ?? null);
		$this->assertSame('automatic', $this->files['Saves/Nintendo/Mario/Auto.state'] ?? null, 'the automatic slot reads as Auto');
		$this->assertSame('a picture', $this->files['Saves/Nintendo/Mario/Slot 1.png'] ?? null);
		$this->assertSame('a battery save', $this->files['Saves/Nintendo/Mario/Mario.srm'] ?? null);

		$this->assertSame('first', $service->load(self::USER, self::GAME, 1));
		$this->assertSame([StateService::AUTO_SLOT, 1], array_column($service->list(self::USER, self::GAME), 'slot'));
	}

	public function testASavesFolderIsEmptiedWithTheGame(): void {
		$service = $this->service('/Saves');
		$service->save(self::USER, self::GAME, 1, 'first');

		$service->deleteAllForGame(self::USER, self::GAME);
		$this->assertArrayNotHasKey('Saves/Nintendo/Mario/Slot 1.state', $this->files);
	}

	public function testGamesOfTheSameNameOnDifferentSystemsKeepTheirOwnSaves(): void {
		$service = $this->service('/Saves');
		$service->save(self::USER, '/Games/NES/Mario.nes', 1, 'the Nintendo one');
		$service->save(self::USER, '/Games/Genesis/Mario.md', 1, 'the Genesis one');

		$this->assertSame('the Nintendo one', $service->load(self::USER, '/Games/NES/Mario.nes', 1));
		$this->assertSame('the Genesis one', $service->load(self::USER, '/Games/Genesis/Mario.md', 1));
		$this->assertSame('the Nintendo one', $this->files['Saves/Nintendo/Mario/Slot 1.state'] ?? null);
		$this->assertSame('the Genesis one', $this->files['Saves/Genesis/Mario/Slot 1.state'] ?? null);
	}

	public function testAGameThatDoesNotSayItsSystemKeepsSavingWhereItAlwaysDid(): void {
		$service = $this->service('/Saves');
		$service->save(self::USER, '/Games/Unsorted/Mystery.zip', 1, 'a state');
		$this->assertSame('a state', $this->files['Saves/Mystery/Slot 1.state'] ?? null);
	}

	public function testOnlySlotsThatAreOfferedCanBeWritten(): void {
		// The controller refuses the rest, so the service is the last word
		// on what the slots even are.
		$this->assertSame(3, StateService::SLOTS);
		$this->assertSame(0, StateService::AUTO_SLOT);
		$this->assertGreaterThanOrEqual(StateService::SLOTS, StateService::HIGHEST_SLOT);
	}
}
