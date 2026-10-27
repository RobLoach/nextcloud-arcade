<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Db\GameMapper;
use OCA\Arcade\Service\CloudSyncManifest;
use OCA\Arcade\Service\SettingsService;
use OCA\Arcade\Service\StateService;
use OCP\Config\IUserConfig;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\Lock\ILockingProvider;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StateServiceTest extends TestCase {
	private const USER = 'alice';
	private const GAME = '/Games/NES/Mario.nes';

	/** Everything the app data holds, by folder then file name. */
	private array $appData = [];
	/** Everything the user folder holds, by path. */
	private array $files = [];
	/** Every path asked of the user folder, for counting lookups. */
	private array $asked = [];
	/** The id Nextcloud gave each path, for the paths that have one. */
	private array $ids = [];
	/** What each file id hashes to, for the ROMs that have been read. */
	private array $checksums = [];
	/** When each file reached the server, for the ones the tests say. */
	private array $uploads = [];
	/** The files whose storage cannot hash them. */
	private array $cannotHash = [];
	/** Every file written over, in order. */
	private array $writes = [];
	/** When each file of the user was written, for the ones the tests say. */
	private array $mtimes = [];
	/** The rows of the games table, by user then key, as the mapper keeps them. */
	private array $games = [];

	private function service(string $savesFolder = '/Saves'): StateService {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getUserSettings')->willReturn(['saves_folder' => $savesFolder]);

		return new StateService(
			$this->appDataFactory(),
			$this->rootFolder(),
			$settings,
			$this->metadataManager(),
			$this->gameMapper(),
			new CloudSyncManifest(
				$this->createStub(ILockingProvider::class),
				$this->createStub(LoggerInterface::class),
			),
			$this->userConfig(),
			$this->createStub(LoggerInterface::class),
		);
	}

	/** When each user last reconciled, as the user config holds it. */
	private array $reconciled = [];

	private function userConfig(): IUserConfig {
		$config = $this->createStub(IUserConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			fn (string $userId, string $app, string $key): int => $this->reconciled[$userId] ?? 0,
		);
		$config->method('setValueInt')->willReturnCallback(
			function (string $userId, string $app, string $key, int $value): bool {
				$this->reconciled[$userId] = $value;
				return true;
			},
		);
		return $config;
	}

	/** The games table, as the mapper presents it. */
	private function gameMapper(): GameMapper {
		$mapper = $this->createStub(GameMapper::class);
		$mapper->method('entriesOf')->willReturnCallback(
			fn (string $userId): array => array_map(
				static fn (array $row): array => ['path' => $row['path'], 'md5' => $row['md5'], 'core' => $row['core'] ?? '', 'name' => $row['name'] ?? ''],
				$this->games[$userId] ?? [],
			),
		);
		$mapper->method('entryOf')->willReturnCallback(
			function (string $userId, string $key): ?array {
				$row = $this->games[$userId][$key] ?? null;
				return $row === null ? null : ['path' => $row['path'], 'md5' => $row['md5'], 'core' => $row['core'] ?? '', 'name' => $row['name'] ?? ''];
			},
		);
		$mapper->method('set')->willReturnCallback(
			function (string $userId, string $key, int $fileId, string $path, string $checksum, string $core = '', ?string $name = null): void {
				$was = $this->games[$userId][$key] ?? [];
				$this->games[$userId][$key] = [
					'file_id' => $fileId,
					'path' => $path,
					'md5' => $checksum,
					'core' => $core !== '' ? $core : ($was['core'] ?? ''),
					'name' => $name ?? ($was['name'] ?? ''),
				];
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
		// The app data, as the files of the instance see it: where its
		// folders can be listed, which its own folders cannot.
		$root->method('getAppDataDirectoryName')->willReturn('appdata_test');
		$root->method('get')->willReturnCallback(fn (string $path) => $this->userFolder('')->get($path));
		return $root;
	}

	private function userFolder(string $prefix): Folder {
		$folder = $this->createStub(Folder::class);
		$folder->method('getName')->willReturn($prefix === '' ? 'files' : basename($prefix));
		$folder->method('getPath')->willReturn('/' . self::USER . '/files' . ($prefix === '' ? '' : '/' . $prefix));
		$folder->method('getId')->willReturn(crc32($prefix));
		$folder->method('nodeExists')->willReturnCallback(
			fn (string $name): bool => isset($this->files[$this->join($prefix, $name)])
				|| $this->hasChildren($this->join($prefix, $name)),
		);
		$folder->method('get')->willReturnCallback(
			function (string $name) use ($prefix) {
				$path = $this->join($prefix, $name);
				$this->asked[] = $path;
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
		$file->method('getPath')->willReturn('/' . self::USER . '/files/' . $path);
		$file->method('getId')->willReturnCallback(fn (): int => $this->ids[$path] ?? 0);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->files[$path]);
		$file->method('hash')->willReturnCallback(fn (string $type): string|false => isset($this->cannotHash[$path]) ? false : hash($type, $this->files[$path]));
		$file->method('getUploadTime')->willReturnCallback(fn (): int => $this->uploads[$path] ?? 0);
		$file->method('getSize')->willReturnCallback(fn (): int => strlen($this->files[$path]));
		$file->method('getMTime')->willReturnCallback(fn (): int => $this->mtimes[$path] ?? 2000);
		$file->method('putContent')->willReturnCallback(
			function ($content) use ($path): void {
				$this->files[$path] = (string)$content;
				$this->writes[] = $path;
			},
		);
		$file->method('delete')->willReturnCallback(
			function () use ($path): void {
				unset($this->files[$path]);
			},
		);
		$file->method('move')->willReturnCallback(
			function (string $target) use ($path): File {
				$to = substr($target, strlen('/' . self::USER . '/files/'));
				$this->files[$to] = $this->files[$path];
				unset($this->files[$path]);
				return $this->userFile($to);
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

	// Where things go.

	private const STATES = 'Saves/states/FCEUmm';
	private const SAVES = 'Saves/saves/FCEUmm';

	/** What the manifest says, by path. */
	private function manifest(): array {
		return CloudSyncManifest::parse($this->files['Saves/manifest.server'] ?? '') ?? [];
	}

	public function testAStateIsSavedUnderTheCoreTheWayRetroArchNamesIt(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'the state');
		$this->assertSame('the state', $this->files[self::STATES . '/Mario.state1']);
		$this->assertSame('the state', $service->load(self::USER, self::GAME, 1)?->getContent());
	}

	public function testTheSlotsAreNamedAsRetroArchNamesThem(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, StateService::AUTO_SLOT, 'auto');
		$service->save(self::USER, self::GAME, 3, 'three');
		$service->saveThumbnail(self::USER, self::GAME, 3, 'picture');
		$service->saveThumbnail(self::USER, self::GAME, StateService::AUTO_SLOT, 'auto picture');
		$this->assertSame('auto', $this->files[self::STATES . '/Mario.state.auto']);
		$this->assertSame('three', $this->files[self::STATES . '/Mario.state3']);
		$this->assertSame('picture', $this->files[self::STATES . '/Mario.state3.png']);
		$this->assertSame('auto picture', $this->files[self::STATES . '/Mario.state.auto.png']);
		$this->assertSame('picture', $service->loadThumbnail(self::USER, self::GAME, 3)?->getContent());
	}

	public function testTheBatterySaveGoesUnderSaves(): void {
		$service = $this->service();
		$service->saveSram(self::USER, self::GAME, 'sram');
		$this->assertSame('sram', $this->files[self::SAVES . '/Mario.srm']);
		$this->assertSame('sram', $service->loadSram(self::USER, self::GAME)?->getContent());
		$this->assertTrue($service->hasSram(self::USER, self::GAME));
	}

	public function testStatesOfDifferentGamesAndSlotsDoNotMix(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'mario');
		$service->save(self::USER, '/Games/NES/Zelda.nes', 1, 'zelda');
		$service->save(self::USER, self::GAME, 2, 'mario two');
		$this->assertSame('mario', $service->load(self::USER, self::GAME, 1)?->getContent());
		$this->assertSame('zelda', $service->load(self::USER, '/Games/NES/Zelda.nes', 1)?->getContent());
		$this->assertSame('mario two', $service->load(self::USER, self::GAME, 2)?->getContent());
		$this->assertNull($service->load(self::USER, self::GAME, 3)?->getContent());
	}

	public function testEveryWriteIsRecordedInTheManifest(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'the state');
		$service->saveSram(self::USER, self::GAME, 'sram');
		$this->assertSame([
			'saves/FCEUmm/Mario.srm' => md5('sram'),
			'states/FCEUmm/Mario.state1' => md5('the state'),
		], $this->manifest());
	}

	public function testTheManifestIsWrittenTheWayRetroArchWritesIt(): void {
		$this->service()->saveSram(self::USER, self::GAME, 'sram');
		$this->assertSame(
			"[\n  {\n    \"path\": \"saves/FCEUmm/Mario.srm\",\n    \"hash\": \"" . md5('sram') . "\"\n  }\n]\n",
			$this->files['Saves/manifest.server'],
		);
	}

	public function testWhatRetroArchRecordedIsKept(): void {
		$this->files['Saves/manifest.server'] = CloudSyncManifest::render([
			'config/FCEUmm/FCEUmm.opt' => 'abc',
			'states/Zelda.state' => 'def',
		]);
		$this->service()->save(self::USER, self::GAME, 1, 'the state');
		$this->assertSame([
			'config/FCEUmm/FCEUmm.opt' => 'abc',
			'states/FCEUmm/Mario.state1' => md5('the state'),
			'states/Zelda.state' => 'def',
		], $this->manifest());
	}

	public function testAManifestThatCannotBeReadIsLeftAloneAndTheSaveStillHappens(): void {
		$this->files['Saves/manifest.server'] = 'not a manifest';
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'the state');
		$this->assertSame('the state', $service->load(self::USER, self::GAME, 1)?->getContent());
		$this->assertSame('not a manifest', $this->files['Saves/manifest.server']);
	}

	public function testADeleteIsRecordedSoRetroArchDeletesItsCopyToo(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'the state');
		$service->saveThumbnail(self::USER, self::GAME, 1, 'picture');
		$this->assertTrue($service->delete(self::USER, self::GAME, 1));
		$this->assertArrayNotHasKey(self::STATES . '/Mario.state1', $this->files);
		$this->assertArrayNotHasKey(self::STATES . '/Mario.state1.png', $this->files);
		$this->assertSame([
			'states/FCEUmm/Mario.state1' => '',
			'states/FCEUmm/Mario.state1.png' => '',
		], $this->manifest());
		$this->assertFalse($service->delete(self::USER, self::GAME, 1));
	}

	public function testDeletingASlotLeavesTheOthersAlone(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'one');
		$service->save(self::USER, self::GAME, 2, 'two');
		$service->delete(self::USER, self::GAME, 1);
		$this->assertNull($service->load(self::USER, self::GAME, 1)?->getContent());
		$this->assertSame('two', $service->load(self::USER, self::GAME, 2)?->getContent());
	}

	// What RetroArch put there.

	public function testWhatRetroArchFiledWithoutTheCoreIsReadAndWrittenInPlace(): void {
		$this->files['Saves/saves/Mario.srm'] = 'from retroarch';
		$this->files['Saves/states/Mario.state1'] = 'from retroarch';
		$service = $this->service();
		$this->assertSame('from retroarch', $service->loadSram(self::USER, self::GAME)?->getContent());
		$this->assertSame('from retroarch', $service->load(self::USER, self::GAME, 1)?->getContent());

		$service->saveSram(self::USER, self::GAME, 'from arcade');
		$this->assertSame('from arcade', $this->files['Saves/saves/Mario.srm']);
		$this->assertArrayNotHasKey(self::SAVES . '/Mario.srm', $this->files);
		$this->assertSame(['saves/Mario.srm' => md5('from arcade')], $this->manifest());
	}

	public function testTheFolderOfTheCoreWinsOverTheFlatOne(): void {
		$this->files['Saves/states/Mario.state1'] = 'flat';
		$this->files[self::STATES . '/Mario.state1'] = 'by core';
		$this->assertSame('by core', $this->service()->load(self::USER, self::GAME, 1)?->getContent());
	}

	public function testEverySlotRetroArchSavedIsListed(): void {
		$this->files[self::STATES . '/Mario.state'] = 'zero';
		$this->files[self::STATES . '/Mario.state990'] = 'nine ninety';
		$this->files[self::STATES . '/Mario.state990.png'] = 'picture';
		$this->files['Saves/states/Mario.state.auto'] = 'auto';
		$this->files[self::STATES . '/Mario.state2'] = 'two';
		$this->mtimes[self::STATES . '/Mario.state2'] = 3000;

		$states = $this->service()->list(self::USER, self::GAME);
		$this->assertSame([StateService::AUTO_SLOT, 0, 2, 990], array_column($states, 'slot'));
		$this->assertSame(['slot' => 2, 'size' => 3, 'mtime' => 3000, 'hasThumbnail' => false], $states[2]);
		$this->assertTrue($states[3]['hasThumbnail']);
		$this->assertSame('nine ninety', $this->service()->load(self::USER, self::GAME, 990)?->getContent());
	}

	public function testFilesOfOtherGamesAreNotTakenForSlots(): void {
		foreach (['Mario Bros.state1', 'Mario.state01', 'Mario.state1.bak', 'Mario.srm', 'Mario.state1000'] as $name) {
			$this->files[self::STATES . '/' . $name] = 'not mario';
		}
		$this->assertSame([], $this->service()->list(self::USER, self::GAME));
	}

	public function testGamesOfTheSameCoreShareItsFolder(): void {
		$service = $this->service();
		$service->saveSram(self::USER, '/Games/GB/Tetris.gb', 'gb');
		$service->saveSram(self::USER, '/Games/GBC/Pokemon.gbc', 'gbc');
		$this->assertSame('gb', $this->files['Saves/saves/Gambatte/Tetris.srm']);
		$this->assertSame('gbc', $this->files['Saves/saves/Gambatte/Pokemon.srm']);
	}

	public function testAGameThatDoesNotSayItsSystemSavesWithoutACore(): void {
		$service = $this->service();
		$service->save(self::USER, '/Games/Mystery.zip', 1, 'state');
		$this->assertSame('state', $this->files['Saves/states/Mystery.state1']);
	}

	public function testNothingIsSavedWithoutASavesFolder(): void {
		$service = $this->service('');
		$this->assertSame([], $service->list(self::USER, self::GAME));
		$this->assertNull($service->load(self::USER, self::GAME, 1)?->getContent());
		$this->assertFalse($service->hasSram(self::USER, self::GAME));
	}

	public function testTheSavesFolderIsMadeOnTheFirstSave(): void {
		$this->service('/Games/My Saves')->save(self::USER, self::GAME, 1, 'state');
		$this->assertSame('state', $this->files['Games/My Saves/states/FCEUmm/Mario.state1']);
		$this->assertArrayHasKey('Games/My Saves/manifest.server', $this->files);
	}

	// What the player is told.

	public function testASaveIsMarkedWhenTheRomIsNoLongerTheOneItWasMadeFrom(): void {
		$this->files['Games/NES/Mario.nes'] = 'rom';
		$this->ids['Games/NES/Mario.nes'] = 42;
		$this->checksums[42] = 'first';
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'state');
		$this->assertArrayNotHasKey('stale', $service->list(self::USER, self::GAME)[0]);

		$this->checksums[42] = 'second';
		$this->assertTrue($this->service()->list(self::USER, self::GAME)[0]['stale']);
	}

	public function testASaveIsNotMarkedWhenNothingIsKnownOfTheRom(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'state');
		$this->assertArrayNotHasKey('stale', $service->list(self::USER, self::GAME)[0]);
	}

	public function testTheNewestScreenshotOfAGameStandsInForItsThumbnail(): void {
		$this->files[self::STATES . '/Mario.state1.png'] = 'one';
		$this->files['Saves/states/Mario.state.auto.png'] = 'auto';
		$this->mtimes['Saves/states/Mario.state.auto.png'] = 5000;
		$this->files['Saves/states/Gambatte/Tetris.state2.png'] = 'tetris';
		$this->files[self::STATES . '/Zelda.state1'] = 'no picture';

		$index = $this->service()->thumbnailIndex(self::USER, [self::GAME, '/Games/GB/Tetris.gb', '/Games/NES/Zelda.nes']);
		$this->assertSame([
			self::GAME => ['slot' => StateService::AUTO_SLOT, 'mtime' => 5000],
			'/Games/GB/Tetris.gb' => ['slot' => 2, 'mtime' => 2000],
		], $index);
	}

	public function testThereIsNoScreenshotIndexWithoutASavesFolder(): void {
		$this->assertSame([], $this->service('')->thumbnailIndex(self::USER, [self::GAME]));
	}

	// Following and cleaning up.

	public function testSavesFollowAGameThatIsRenamed(): void {
		$this->files['Games/NES/Mario.nes'] = 'rom';
		$this->ids['Games/NES/Mario.nes'] = 42;
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'state');
		$service->saveSram(self::USER, self::GAME, 'sram');

		unset($this->files['Games/NES/Mario.nes'], $this->ids['Games/NES/Mario.nes']);
		$this->files['Games/NES/Super Mario.nes'] = 'rom';
		$this->ids['Games/NES/Super Mario.nes'] = 42;

		$renamed = $this->service();
		$this->assertSame('state', $renamed->load(self::USER, '/Games/NES/Super Mario.nes', 1)?->getContent());
		$this->assertSame('sram', $renamed->loadSram(self::USER, '/Games/NES/Super Mario.nes')?->getContent());
		$this->assertArrayNotHasKey(self::STATES . '/Mario.state1', $this->files);
		$manifest = $this->manifest();
		$this->assertSame('', $manifest['states/FCEUmm/Mario.state1']);
		$this->assertSame(md5('state'), $manifest['states/FCEUmm/Super Mario.state1']);
		$this->assertSame('/Games/NES/Super Mario.nes', $this->games[self::USER]['42']['path']);
	}

	public function testARenameDoesNotOverwriteWhatTheNewNameAlreadyHas(): void {
		$this->files['Games/NES/Mario.nes'] = 'rom';
		$this->ids['Games/NES/Mario.nes'] = 42;
		$this->service()->save(self::USER, self::GAME, 1, 'old');
		unset($this->files['Games/NES/Mario.nes'], $this->ids['Games/NES/Mario.nes']);
		$this->files['Games/NES/Luigi.nes'] = 'rom';
		$this->ids['Games/NES/Luigi.nes'] = 42;
		$this->files[self::STATES . '/Luigi.state1'] = 'luigi';

		$this->assertSame('luigi', $this->service()->load(self::USER, '/Games/NES/Luigi.nes', 1)?->getContent());
		$this->assertSame('old', $this->files[self::STATES . '/Mario.state1']);
	}

	public function testEverythingOfAGameGoesWithIt(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, 1, 'state');
		$service->saveThumbnail(self::USER, self::GAME, 1, 'picture');
		$service->saveSram(self::USER, self::GAME, 'sram');
		$this->files['Saves/states/Mario.state990'] = 'flat';
		$this->files[self::STATES . '/Zelda.state1'] = 'zelda';

		$service->deleteAllForGame(self::USER, self::GAME);
		$this->assertSame(['Saves/manifest.server', self::STATES . '/Zelda.state1'], array_values(array_filter(
			array_keys($this->files),
			static fn (string $path): bool => !str_ends_with($path, '.folder'),
		)));
		$this->assertSame('', $this->manifest()['saves/FCEUmm/Mario.srm']);
		// Never in the manifest, so there is nothing for RetroArch to delete.
		$this->assertArrayNotHasKey('states/Mario.state990', $this->manifest());
		$this->assertSame([], $service->gamesOf(self::USER));
	}

	public function testAGameKnownOnlyByItsIdIsStillCleanedUp(): void {
		$this->files['Games/NES/Mario.nes'] = 'rom';
		$this->ids['Games/NES/Mario.nes'] = 42;
		$this->service()->save(self::USER, self::GAME, 1, 'state');
		unset($this->files['Games/NES/Mario.nes'], $this->ids['Games/NES/Mario.nes']);

		$this->service()->deleteAllForFileId(self::USER, 42);
		$this->assertArrayNotHasKey(self::STATES . '/Mario.state1', $this->files);
		$this->assertSame([], $this->games[self::USER]);
	}

	public function testWhatEarlierVersionsLeftInTheAppDataGoesWithTheGame(): void {
		$this->files['Games/NES/Mario.nes'] = 'rom';
		$this->ids['Games/NES/Mario.nes'] = 42;
		$folder = 'states/' . StateService::userKey(self::USER);
		$this->appData['states'] = [];
		$this->appData[$folder] = ['42-0.state' => 'auto', '42-1.png' => 'pic', '42.srm' => 'sram', '7-1.state' => 'other'];

		$this->service()->deleteAllForGame(self::USER, self::GAME);
		$this->assertSame(['7-1.state' => 'other'], $this->appData[$folder]);
	}

	public function testABatterySaveIsDeletedWhereverItIs(): void {
		$this->files['Saves/saves/Mario.srm'] = 'flat';
		$this->files[self::SAVES . '/Mario.srm'] = 'by core';
		$service = $this->service();
		$this->assertTrue($service->deleteSram(self::USER, self::GAME));
		$this->assertFalse($service->hasSram(self::USER, self::GAME));
		$this->assertFalse($service->deleteSram(self::USER, self::GAME));
	}

	public function testEverythingOfAUserGoesWithThem(): void {
		$this->appData['states'] = [];
		$this->appData['states/' . StateService::userKey(self::USER)] = ['1-1.state' => 'x'];
		$this->games[self::USER] = ['1' => ['file_id' => 1, 'path' => self::GAME, 'md5' => '']];
		$this->service()->deleteAllForUser(self::USER);
		$this->assertArrayNotHasKey('states/' . StateService::userKey(self::USER), $this->appData);
		$this->assertArrayNotHasKey(self::USER, $this->games);
	}

	public function testTheGamesAUserHasSavesForAreKnown(): void {
		$this->files['Games/NES/Mario.nes'] = 'rom';
		$this->ids['Games/NES/Mario.nes'] = 42;
		$this->service()->saveSram(self::USER, self::GAME, 'sram');
		$this->assertSame(['42' => self::GAME], $this->service()->gamesOf(self::USER));
	}

	// The names.

	public function testANameIsReadBackAsTheSlotItWasWrittenFor(): void {
		foreach ([StateService::AUTO_SLOT, 0, 1, 3, 999] as $slot) {
			$name = StateService::stateName('Mario', $slot);
			$this->assertSame(['slot' => $slot, 'thumbnail' => false], StateService::parseStateName('Mario', $name));
			$this->assertSame(['slot' => $slot, 'thumbnail' => true], StateService::parseStateName('Mario', "$name.png"));
		}
		$this->assertNull(StateService::parseStateName('Mario', 'Mario.state-1'));
		$this->assertNull(StateService::parseStateName('Mario', 'Mario.srm'));
	}

	// Not writing what has not changed.

	public function testASaveThatChangesNothingIsNotWrittenAgain(): void {
		$service = $this->service();
		$service->saveSram(self::USER, self::GAME, 'sram');
		$manifest = $this->files['Saves/manifest.server'];
		$this->writes = [];

		$service->saveSram(self::USER, self::GAME, 'sram');
		$this->assertSame([], $this->writes, 'neither the battery save nor the manifest is written again');
		$this->assertSame($manifest, $this->files['Saves/manifest.server']);

		$service->saveSram(self::USER, self::GAME, 'played on');
		$this->assertSame([self::SAVES . '/Mario.srm', 'Saves/manifest.server'], $this->writes);
	}

	public function testAnUnchangedSaveMissingFromTheManifestIsStillRecorded(): void {
		$this->files[self::SAVES . '/Mario.srm'] = 'sram';
		$this->service()->saveSram(self::USER, self::GAME, 'sram');
		$this->assertSame(['saves/FCEUmm/Mario.srm' => md5('sram')], $this->manifest());
	}

	// Saves of the same name on the same core.

	public function testDeletingAGameKeepsTheSavesAnotherGameOfTheSameNameStillUses(): void {
		$this->files['Games/GB/Tetris.gb'] = 'rom';
		$this->files['Games/GB/Tetris.gbc'] = 'rom';
		$service = $this->service();
		$service->saveSram(self::USER, '/Games/GB/Tetris.gb', 'sram');

		unset($this->files['Games/GB/Tetris.gb']);
		$service->deleteAllForGame(self::USER, '/Games/GB/Tetris.gb');
		$this->assertSame('sram', $this->files['Saves/saves/Gambatte/Tetris.srm']);
	}

	public function testAGameTheRegistryKnowsElsewhereKeepsItsSavesToo(): void {
		$this->files['Games/A/Tetris.gb'] = 'rom';
		$this->files['Games/B/Tetris.gb'] = 'rom';
		$this->ids['Games/A/Tetris.gb'] = 1;
		$this->ids['Games/B/Tetris.gb'] = 2;
		$this->service()->saveSram(self::USER, '/Games/A/Tetris.gb', 'a');
		$this->service()->saveSram(self::USER, '/Games/B/Tetris.gb', 'b');

		unset($this->files['Games/A/Tetris.gb'], $this->ids['Games/A/Tetris.gb']);
		$this->service()->deleteAllForFileId(self::USER, 1);
		$this->assertSame('b', $this->files['Saves/saves/Gambatte/Tetris.srm']);
		$this->assertArrayNotHasKey('1', $this->games[self::USER]);
	}

	public function testAPictureOfTheSameNameDoesNotKeepTheSaves(): void {
		$this->files['Games/GB/Tetris.gb'] = 'rom';
		$this->files['Games/GB/Tetris.png'] = 'box art';
		$this->service()->saveSram(self::USER, '/Games/GB/Tetris.gb', 'sram');

		unset($this->files['Games/GB/Tetris.gb']);
		$this->service()->deleteAllForGame(self::USER, '/Games/GB/Tetris.gb');
		$this->assertArrayNotHasKey('Saves/saves/Gambatte/Tetris.srm', $this->files);
	}

	// The core the player ran.

	public function testAZipIsFiledUnderTheCoreThePlayerRanIt(): void {
		$this->files['Games/Mystery.zip'] = 'zip';
		$this->ids['Games/Mystery.zip'] = 9;
		$service = $this->service();
		$service->useCore(self::USER, '/Games/Mystery.zip', 'snes9x');
		$service->save(self::USER, '/Games/Mystery.zip', 1, 'state');
		$this->assertSame('state', $this->files['Saves/states/Snes9x/Mystery.state1']);
		$this->assertSame('snes9x', $this->games[self::USER]['9']['core']);
	}

	public function testTheNotedCoreIsUsedWhenThePlayerDoesNotSay(): void {
		$this->files['Games/Mystery.zip'] = 'zip';
		$this->ids['Games/Mystery.zip'] = 9;
		$player = $this->service();
		$player->useCore(self::USER, '/Games/Mystery.zip', 'snes9x');
		$player->save(self::USER, '/Games/Mystery.zip', 1, 'state');
		$player->saveThumbnail(self::USER, '/Games/Mystery.zip', 1, 'picture');

		// The Files sidebar, and the library, say nothing of the core.
		$sidebar = $this->service();
		$this->assertSame([1], array_column($sidebar->list(self::USER, '/Games/Mystery.zip'), 'slot'));
		$this->assertSame(['/Games/Mystery.zip' => ['slot' => 1, 'mtime' => 2000]], $sidebar->thumbnailIndex(self::USER, ['/Games/Mystery.zip']));

		// And neither does the cleanup once the zip is gone.
		unset($this->files['Games/Mystery.zip'], $this->ids['Games/Mystery.zip']);
		$this->service()->deleteAllForFileId(self::USER, 9);
		$this->assertArrayNotHasKey('Saves/states/Snes9x/Mystery.state1', $this->files);
	}

	public function testACoreNobodyKnowsIsNotTakenAtItsWord(): void {
		$service = $this->service();
		$service->useCore(self::USER, self::GAME, '../../elsewhere');
		$service->save(self::USER, self::GAME, 1, 'state');
		$this->assertSame('state', $this->files[self::STATES . '/Mario.state1']);
	}

	// Putting the manifest right.

	public function testFilesThatGotThereAnotherWayAreRecorded(): void {
		$this->files['Saves/saves/FCEUmm/Zelda.srm'] = 'from the desktop client';
		$this->files['Saves/states/Mario.state3'] = 'uploaded in Files';
		$this->files['Saves/config/FCEUmm.opt'] = 'not ours to record';

		$counts = $this->service()->reconcile(self::USER);

		$this->assertSame(['recorded' => 2, 'removed' => 0], $counts);
		$this->assertSame([
			'saves/FCEUmm/Zelda.srm' => md5('from the desktop client'),
			'states/Mario.state3' => md5('uploaded in Files'),
		], $this->manifest());
	}

	public function testFilesThatAreGoneAreRecordedAsDeletedAndTheRestLeftAlone(): void {
		$this->files['Saves/manifest.server'] = CloudSyncManifest::render([
			'saves/FCEUmm/Gone.srm' => 'abc',
			'states/Old.state' => '',
			'config/retroarch-core-options.cfg' => 'def',
		]);

		$counts = $this->service()->reconcile(self::USER);

		$this->assertSame(['recorded' => 0, 'removed' => 1], $counts);
		$this->assertSame([
			'config/retroarch-core-options.cfg' => 'def',
			'saves/FCEUmm/Gone.srm' => '',
			'states/Old.state' => '',
		], $this->manifest());
	}

	public function testAFileUntouchedSinceTheLastReconcileIsTakenAtItsWordUnlessAskedToBeThorough(): void {
		$this->files[self::SAVES . '/Mario.srm'] = 'changed behind its back';
		$this->files['Saves/manifest.server'] = CloudSyncManifest::render(['saves/FCEUmm/Mario.srm' => md5('before')]);
		$this->reconciled[self::USER] = 3000;

		$this->assertSame(['recorded' => 0, 'removed' => 0], $this->service()->reconcile(self::USER));
		$this->assertSame(['recorded' => 1, 'removed' => 0], $this->service()->reconcile(self::USER, true));
		$this->assertSame(md5('changed behind its back'), $this->manifest()['saves/FCEUmm/Mario.srm']);
	}

	public function testASaveBroughtInBeforeTheManifestWasWrittenAgainIsStillRecorded(): void {
		// Reconciled at 1000; the desktop client brings a new Zelda.srm in
		// at 2000; a save in the player writes the manifest at 3000. The
		// manifest is newer than the save, and that is no reason to pass
		// the save over.
		$this->reconciled[self::USER] = 1000;
		$this->files[self::SAVES . '/Zelda.srm'] = 'from the desktop client';
		$this->mtimes[self::SAVES . '/Zelda.srm'] = 2000;
		$this->files['Saves/manifest.server'] = CloudSyncManifest::render(['saves/FCEUmm/Zelda.srm' => md5('the old one')]);
		$this->mtimes['Saves/manifest.server'] = 3000;

		$this->assertSame(['recorded' => 1, 'removed' => 0], $this->service()->reconcile(self::USER));
		$this->assertSame(md5('from the desktop client'), $this->manifest()['saves/FCEUmm/Zelda.srm']);
		$this->assertGreaterThan(1000, $this->reconciled[self::USER], 'and the time of this one is kept for the next');
	}

	public function testAFileChangedInTheSecondAReconcileBeganIsLookedAtAgain(): void {
		$this->files[self::SAVES . '/Zelda.srm'] = 'first';
		$second = time();
		$this->service()->reconcile(self::USER);

		// Changed in the second the walk began, after it had gone by.
		$this->files[self::SAVES . '/Zelda.srm'] = 'second';
		$this->mtimes[self::SAVES . '/Zelda.srm'] = $second;
		$this->service()->reconcile(self::USER);

		$this->assertSame(md5('second'), $this->manifest()['saves/FCEUmm/Zelda.srm']);
	}

	public function testThereIsNothingToPutRightWithoutASavesFolder(): void {
		$this->assertNull($this->service('')->reconcile(self::USER));
	}

	public function testAManifestThatCannotBeReadIsSaidToBeSo(): void {
		$this->files['Saves/manifest.server'] = 'not a manifest';
		$this->expectException(\RuntimeException::class);
		$this->service()->reconcile(self::USER);
	}

	// What earlier versions left in the app data.

	public function testWhatEarlierVersionsLeftIsMeasuredAndCanBeRemoved(): void {
		// Loose in the states folder, and in the folder of a user -- the one
		// the app data's own listing never shows.
		$states = 'appdata_test/arcade/states';
		$this->files["$states/flat-file"] = '12345';
		$this->files["$states/" . StateService::userKey(self::USER) . '/1-1.state'] = 'abc';
		$this->files["$states/" . StateService::userKey(self::USER) . '/1.srm'] = 'de';
		$this->files["$states/" . hash('sha256', 'nobody') . '/2-0.state'] = 'f';
		$service = $this->service();

		$this->assertSame(['files' => 4, 'bytes' => 11], $service->appDataSaves());
		$this->assertSame(
			[StateService::userKey(self::USER), hash('sha256', 'nobody')],
			array_keys($service->userFolders()),
		);
		$this->assertSame(4, $service->deleteAppDataSaves());
		$this->assertSame(['files' => 0, 'bytes' => 0], $service->appDataSaves());
	}

	// The name the saves of a zipped game go by.

	public function testAZippedGameSavesUnderTheGameInsideItAsRetroArchDoes(): void {
		$this->files['Games/SNES/Super Street Fighter II.zip'] = 'zip';
		$this->ids['Games/SNES/Super Street Fighter II.zip'] = 5;
		$service = $this->service();
		$service->useCore(self::USER, '/Games/SNES/Super Street Fighter II.zip', 'snes9x');
		$service->useName(self::USER, '/Games/SNES/Super Street Fighter II.zip', 'Super Street Fighter II (USA)');
		$service->save(self::USER, '/Games/SNES/Super Street Fighter II.zip', 1, 'state');
		$service->saveSram(self::USER, '/Games/SNES/Super Street Fighter II.zip', 'sram');

		$this->assertSame('state', $this->files['Saves/states/Snes9x/Super Street Fighter II (USA).state1']);
		$this->assertSame('sram', $this->files['Saves/saves/Snes9x/Super Street Fighter II (USA).srm']);
		$this->assertSame('Super Street Fighter II (USA)', $this->games[self::USER]['5']['name']);
	}

	public function testWhatRetroArchSavedForAZippedGameIsFound(): void {
		$this->files['Saves/saves/Snes9x/Super Street Fighter II (USA).srm'] = 'from retroarch';
		$service = $this->service();
		$service->useCore(self::USER, '/Games/Super Street Fighter II.zip', 'snes9x');
		$service->useName(self::USER, '/Games/Super Street Fighter II.zip', 'Super Street Fighter II (USA)');
		$this->assertSame('from retroarch', $service->loadSram(self::USER, '/Games/Super Street Fighter II.zip')?->getContent());
	}

	public function testTheNotedNameIsUsedWhenThePlayerDoesNotSay(): void {
		$this->files['Games/Mystery.zip'] = 'zip';
		$this->ids['Games/Mystery.zip'] = 9;
		$player = $this->service();
		$player->useCore(self::USER, '/Games/Mystery.zip', 'fceumm');
		$player->useName(self::USER, '/Games/Mystery.zip', 'Inner Game (USA)');
		$player->save(self::USER, '/Games/Mystery.zip', 2, 'state');

		$sidebar = $this->service();
		$this->assertSame([2], array_column($sidebar->list(self::USER, '/Games/Mystery.zip'), 'slot'));

		// Renaming the zip keeps the game inside, and the saves with it.
		unset($this->files['Games/Mystery.zip'], $this->ids['Games/Mystery.zip']);
		$this->files['Games/Renamed.zip'] = 'zip';
		$this->ids['Games/Renamed.zip'] = 9;
		$this->assertSame('state', $this->service()->load(self::USER, '/Games/Renamed.zip', 2)?->getContent());
		$this->assertArrayHasKey('Saves/states/FCEUmm/Inner Game (USA).state2', $this->files);

		unset($this->files['Games/Renamed.zip'], $this->ids['Games/Renamed.zip']);
		$this->service()->deleteAllForFileId(self::USER, 9);
		$this->assertArrayNotHasKey('Saves/states/FCEUmm/Inner Game (USA).state2', $this->files);
	}

	public function testANameThatCouldReachAnotherFolderIsNotTakenAtItsWord(): void {
		$service = $this->service();
		foreach (['../Other', 'a/b', '.hidden', ''] as $name) {
			$service->useName(self::USER, self::GAME, $name);
		}
		$service->save(self::USER, self::GAME, 1, 'state');
		$this->assertSame('state', $this->files[self::STATES . '/Mario.state1']);
	}

	public function testSlotZeroIsRetroArchsDefaultState(): void {
		$service = $this->service();
		$service->save(self::USER, self::GAME, 0, 'quick save');
		$this->assertSame('quick save', $this->files[self::STATES . '/Mario.state']);
		$this->assertSame([0], array_column($service->list(self::USER, self::GAME), 'slot'));
	}

	// What the review of the pull request turned up.

	public function testARenamedGameOpenedFromTheSidebarFirstStillBringsItsSaves(): void {
		$this->files['Games/NES/Mario.nes'] = 'rom';
		$this->ids['Games/NES/Mario.nes'] = 42;
		$player = $this->service();
		// The player says the name, which for a ROM is its own.
		$player->useName(self::USER, self::GAME, 'Mario');
		$player->save(self::USER, self::GAME, 1, 'state');
		$this->assertSame('', $this->games[self::USER]['42']['name']);

		unset($this->files['Games/NES/Mario.nes'], $this->ids['Games/NES/Mario.nes']);
		$this->files['Games/NES/Super Mario.nes'] = 'rom';
		$this->ids['Games/NES/Super Mario.nes'] = 42;

		// The sidebar asks first, saying no name.
		$this->assertSame([1], array_column($this->service()->list(self::USER, '/Games/NES/Super Mario.nes'), 'slot'));
		$this->assertArrayHasKey(self::STATES . '/Super Mario.state1', $this->files);
	}

	public function testANameNotedBeforeForAPlainRomIsCleared(): void {
		$this->files['Games/NES/Mario.nes'] = 'rom';
		$this->ids['Games/NES/Mario.nes'] = 42;
		$this->games[self::USER]['42'] = ['file_id' => 42, 'path' => self::GAME, 'md5' => '', 'core' => 'fceumm', 'name' => 'Mario'];
		$player = $this->service();
		$player->useName(self::USER, self::GAME, 'Mario');
		$player->save(self::USER, self::GAME, 1, 'state');
		$this->assertSame('', $this->games[self::USER]['42']['name']);
	}

	public function testAZippedGameThatIsGoneIsCleanedUpUnderItsCoreAndName(): void {
		$this->files['Games/comp.zip'] = 'zip';
		$this->ids['Games/comp.zip'] = 9;
		$player = $this->service();
		$player->useCore(self::USER, '/Games/comp.zip', 'snes9x');
		$player->useName(self::USER, '/Games/comp.zip', 'Game (USA)');
		$player->save(self::USER, '/Games/comp.zip', 1, 'state');
		unset($this->files['Games/comp.zip'], $this->ids['Games/comp.zip']);

		$this->service()->deleteAllForGame(self::USER, '/Games/comp.zip');
		$this->assertArrayNotHasKey('Saves/states/Snes9x/Game (USA).state1', $this->files);
	}

	public function testDeletingAGameKeepsAFlatSaveAnotherCoreShares(): void {
		$this->files['Games/Tetris.gb'] = 'rom';
		$this->files['Games/Tetris.nes'] = 'rom';
		$this->files['Saves/saves/Tetris.srm'] = 'flat, whose is it';
		unset($this->files['Games/Tetris.gb']);

		$this->service()->deleteAllForGame(self::USER, '/Games/Tetris.gb');
		$this->assertSame('flat, whose is it', $this->files['Saves/saves/Tetris.srm']);
	}

	public function testAFileDeeperThanTheWalkOrHiddenIsNotRecordedAsDeleted(): void {
		$deep = 'saves/FCEUmm/a/b/c/Mario.srm';
		$this->files["Saves/$deep"] = 'deep';
		$this->files['Saves/states/.hidden.state'] = 'hidden';
		$this->files['Saves/manifest.server'] = CloudSyncManifest::render([$deep => md5('deep'), 'states/.hidden.state' => md5('hidden')]);

		$this->assertSame(['recorded' => 0, 'removed' => 0], $this->service()->reconcile(self::USER));
		$this->assertSame(md5('deep'), $this->manifest()[$deep]);
	}

	public function testAFileTheStorageCannotHashIsLeftAsItWas(): void {
		$this->files[self::SAVES . '/Mario.srm'] = 'sram';
		$this->cannotHash[self::SAVES . '/Mario.srm'] = true;
		$this->files['Saves/manifest.server'] = CloudSyncManifest::render(['saves/FCEUmm/Mario.srm' => 'abc']);

		$this->service()->reconcile(self::USER, true);
		$this->assertSame('abc', $this->manifest()['saves/FCEUmm/Mario.srm']);
	}

	public function testASaveCopiedInWithAnOldTimeIsStillNoticed(): void {
		$this->files[self::SAVES . '/Mario.srm'] = 'copied in today';
		$this->mtimes[self::SAVES . '/Mario.srm'] = 1000;
		$this->uploads[self::SAVES . '/Mario.srm'] = 5000;
		$this->files['Saves/manifest.server'] = CloudSyncManifest::render(['saves/FCEUmm/Mario.srm' => md5('before')]);
		$this->mtimes['Saves/manifest.server'] = 3000;

		$this->assertSame(['recorded' => 1, 'removed' => 0], $this->service()->reconcile(self::USER));
	}
}
