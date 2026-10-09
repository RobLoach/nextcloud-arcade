<?php

declare(strict_types=1);

namespace OCA\Arcade\Tests\Unit;

use OCA\Arcade\Service\SettingsService;
use OCP\Config\IUserConfig;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class SettingsServiceTest extends TestCase {
	private const USER = 'alice';

	private function service(string $stored = '', ?IRootFolder $rootFolder = null): SettingsService {
		$config = $this->createStub(IUserConfig::class);
		$config->method('getValueString')->willReturn($stored);
		return new SettingsService(
			$config,
			$this->createStub(IAppConfig::class),
			$rootFolder ?? $this->emptyRootFolder(),
		);
	}

	/** A root folder whose user folder holds nothing at all. */
	private function emptyRootFolder(): IRootFolder {
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('get')->willThrowException(new NotFoundException());
		$userFolder->method('getFirstNodeById')->willReturn(null);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		return $root;
	}

	/** A folder as it would sit in the user folder. */
	private function folderNode(int $id, string $path): Folder {
		$node = $this->createStub(Folder::class);
		$node->method('getId')->willReturn($id);
		$node->method('getPath')->willReturn('/' . self::USER . '/files' . $path);
		return $node;
	}

	/**
	 * A root folder whose user folder holds the given folders.
	 *
	 * @param array<int, Folder> $folders folder id => folder
	 */
	private function rootFolderWith(array $folders): IRootFolder {
		$prefix = '/' . self::USER . '/files';
		$userFolder = $this->createStub(Folder::class);
		$userFolder->method('get')->willReturnCallback(
			static function (string $path) use ($folders, $prefix): Folder {
				foreach ($folders as $folder) {
					if ($folder->getPath() === $prefix . '/' . trim($path, '/')) {
						return $folder;
					}
				}
				throw new NotFoundException();
			},
		);
		$userFolder->method('getFirstNodeById')->willReturnCallback(
			static fn (int $id): ?Folder => $folders[$id] ?? null,
		);
		$userFolder->method('getRelativePath')->willReturnCallback(
			static fn (string $path): ?string => substr($path, strlen($prefix)),
		);
		$root = $this->createStub(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);
		return $root;
	}

	/**
	 * @return array<string, mixed> the settings as they are stored
	 */
	private function save(array $settings, ?IRootFolder $rootFolder = null): array {
		$config = $this->createMock(IUserConfig::class);
		$saved = '';
		$config->method('setValueString')->willReturnCallback(
			function (string $user, string $app, string $key, string $value) use (&$saved): bool {
				$saved = $value;
				return true;
			},
		);
		$config->method('getValueString')->willReturnCallback(static fn (): string => $saved);
		$service = new SettingsService(
			$config,
			$this->createStub(IAppConfig::class),
			$rootFolder ?? $this->emptyRootFolder(),
		);
		$service->setUserSettings(self::USER, $settings);
		return json_decode($saved, true) ?? [];
	}

	/**
	 * @return array<string, array<string, string>> the core options as stored
	 */
	private function saveInstance(array $settings): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$saved = '';
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false) use (&$saved): bool {
				if ($key === 'core_options') {
					$this->assertTrue($lazy, 'the core options blob is stored lazy');
					$saved = $value;
				}
				return true;
			},
		);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key): string => $key === 'core_options' ? $saved : '',
		);
		(new SettingsService($this->createStub(IUserConfig::class), $appConfig, $this->emptyRootFolder()))
			->setInstanceDefaults($settings);
		return json_decode($saved, true) ?? [];
	}

	/**
	 * @return array<string, mixed> the instance settings as they are stored
	 */
	private function saveInstanceValues(array $settings): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$saved = [];
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$saved): bool {
				$saved[$key] = $value;
				return true;
			},
		);
		// By reference: an arrow function would capture the empty array.
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key) use (&$saved): string {
				return $saved[$key] ?? '';
			},
		);
		$service = new SettingsService($this->createStub(IUserConfig::class), $appConfig, $this->emptyRootFolder());
		$service->setInstanceDefaults($settings);
		return $service->getInstanceDefaults();
	}

	public function testDefaultsAreReturnedWithoutStoredSettings(): void {
		$settings = $this->service()->getUserSettings(self::USER);
		$this->assertFalse($settings['video_smooth']);
		$this->assertSame(3, $settings['fastforward_ratio']);
		$this->assertFalse($settings['rewind_enabled'], 'rewinding costs performance, so it is asked for');
		$this->assertSame(0, $settings['runahead_frames'], 'and so is run-ahead');
		$this->assertSame(100, $settings['volume'], 'the game exactly as recorded');
		$this->assertSame(64, $settings['audio_latency']);
		$this->assertFalse($settings['pause_when_hidden'], 'a background tab does not pause unless asked');
		$this->assertFalse($settings['autoload_on_start'], 'a game does not resume by itself unless asked');
		$this->assertSame(0, $settings['autosave_interval'], 'and does not save by itself either');
		$this->assertTrue($settings['respond_to_global_events']);
		$this->assertSame('/Games', $settings['library_folder']);
		$this->assertSame('', $settings['saves_folder']);
		$this->assertSame([], $settings['core_options']);
	}

	public function testAPartialSaveLeavesTheOtherSettingsAlone(): void {
		// The onboarding posts the library folder by itself; whatever else
		// the user had set has to survive that.
		$config = $this->createMock(IUserConfig::class);
		$saved = json_encode(['thumbnails_folder' => '/Thumbs', 'video_smooth' => true]);
		$config->method('getValueString')->willReturnCallback(static fn (): string => $saved);
		$config->method('setValueString')->willReturnCallback(
			function (string $user, string $app, string $key, string $value) use (&$saved): bool {
				$saved = $value;
				return true;
			},
		);
		$service = new SettingsService($config, $this->createStub(IAppConfig::class), $this->emptyRootFolder());

		$service->setUserSettings(self::USER, ['library_folder' => '/ROMs']);

		$written = json_decode($saved, true);
		$this->assertSame('/ROMs', $written['library_folder']);
		$this->assertSame('/Thumbs', $written['thumbnails_folder'], 'the folder nobody posted stays');
		$this->assertTrue($written['video_smooth'], 'and so does the toggle');
	}

	public function testBrokenStoredSettingsFallBackToTheDefaults(): void {
		$this->assertSame(
			$this->service()->getDefaults(),
			$this->service('not json at all')->getUserSettings(self::USER),
		);
	}

	public function testStoredSettingsOverrideTheDefaults(): void {
		$stored = json_encode(['video_smooth' => true, 'library_folder' => '/Roms']);
		$settings = $this->service($stored)->getUserSettings(self::USER);
		$this->assertTrue($settings['video_smooth']);
		$this->assertSame('/Roms', $settings['library_folder']);
		// Untouched settings keep their default.
		$this->assertSame(3, $settings['fastforward_ratio']);
	}

	public function testAStaleRowCannotShadowTheInstanceSettings(): void {
		// Early versions saved the whole form, core options and all.
		$stored = json_encode([
			'video_smooth' => true,
			'core_options' => ['snes9x' => ['snes9x_overclock' => '150%']],
			'max_games' => 123,
		]);
		$config = $this->createMock(IUserConfig::class);
		$config->method('getValueString')->willReturn($stored);
		$written = null;
		$config->expects($this->once())->method('setValueString')->willReturnCallback(
			function (string $user, string $app, string $key, string $value) use (&$written): bool {
				$written = json_decode($value, true);
				return true;
			},
		);
		$service = new SettingsService($config, $this->createStub(IAppConfig::class), $this->emptyRootFolder());

		$settings = $service->getUserSettings(self::USER);

		$this->assertSame([], $settings['core_options'], 'the core options are the administrator\'s');
		$this->assertSame(5000, $settings['max_games'], 'and so are the instance limits');
		$this->assertTrue($settings['video_smooth'], 'while the user\'s own choices stay');
		$this->assertSame(['video_smooth' => true], $written, 'the row is trued up so it cannot shadow again');
	}

	public function testFoldersAreNormalized(): void {
		$saved = $this->save([
			'library_folder' => 'Games/Roms/',
			'saves_folder' => '  /Saves  ',
			'screenshots_folder' => '',
		]);
		$this->assertSame('/Games/Roms', $saved['library_folder']);
		$this->assertSame('/Saves', $saved['saves_folder']);
		$this->assertSame('', $saved['screenshots_folder'], 'an empty folder disables the feature');
	}

	public function testFoldersCannotEscapeTheUserFolder(): void {
		$saved = $this->save(['library_folder' => '../../etc', 'thumbnails_folder' => 'a/../../b']);
		$this->assertArrayNotHasKey('library_folder', $saved);
		$this->assertArrayNotHasKey('thumbnails_folder', $saved);
	}

	public function testTheLibraryFolderCannotBeEmptied(): void {
		$saved = $this->save(['library_folder' => '']);
		$this->assertArrayNotHasKey('library_folder', $saved);
	}

	public function testAFolderThatExistsIsRememberedByItsId(): void {
		$root = $this->rootFolderWith([42 => $this->folderNode(42, '/Games')]);
		$saved = $this->save(['library_folder' => '/Games'], $root);
		$this->assertSame('/Games', $saved['library_folder']);
		$this->assertSame(42, $saved['library_folder_id']);
	}

	public function testAFolderThatDoesNotExistYetIsStoredAsAPathOnly(): void {
		$saved = $this->save(['library_folder' => '/Games']);
		$this->assertSame('/Games', $saved['library_folder']);
		$this->assertArrayNotHasKey('library_folder_id', $saved);
	}

	public function testTheSettingsFollowAFolderThatWasMoved(): void {
		// The folder that was picked as /Games now lives at /Retro/Games.
		$root = $this->rootFolderWith([42 => $this->folderNode(42, '/Retro/Games')]);
		$stored = json_encode(['library_folder' => '/Games', 'library_folder_id' => 42]);
		$settings = $this->service($stored, $root)->getUserSettings(self::USER);
		$this->assertSame('/Retro/Games', $settings['library_folder']);
	}

	public function testAPathStoredBeforeIdsWereKeptIsMigratedOnRead(): void {
		$config = $this->createMock(IUserConfig::class);
		$stored = json_encode(['library_folder' => '/Games']);
		$config->method('getValueString')->willReturn($stored);
		$migrated = '';
		$config->method('setValueString')->willReturnCallback(
			function (string $user, string $app, string $key, string $value) use (&$migrated): bool {
				$migrated = $value;
				return true;
			},
		);
		$root = $this->rootFolderWith([42 => $this->folderNode(42, '/Games')]);
		$service = new SettingsService($config, $this->createStub(IAppConfig::class), $root);
		$this->assertSame('/Games', $service->getUserSettings(self::USER)['library_folder']);
		$this->assertSame(42, json_decode($migrated, true)['library_folder_id'] ?? null);
	}

	public function testAFolderThatIsGoneFallsBackToItsLastKnownPath(): void {
		// Id 42 no longer resolves: the folder was deleted.
		$stored = json_encode(['library_folder' => '/Games', 'library_folder_id' => 42]);
		$settings = $this->service($stored, $this->emptyRootFolder())->getUserSettings(self::USER);
		$this->assertSame('/Games', $settings['library_folder']);
	}

	public function testTheIdIsKeptOutOfTheEffectiveSettings(): void {
		$root = $this->rootFolderWith([42 => $this->folderNode(42, '/Games')]);
		$stored = json_encode(['library_folder' => '/Games', 'library_folder_id' => 42]);
		$settings = $this->service($stored, $root)->getUserSettings(self::USER);
		$this->assertArrayNotHasKey('library_folder_id', $settings);
	}

	public function testPickingADifferentFolderReplacesTheId(): void {
		$root = $this->rootFolderWith([
			42 => $this->folderNode(42, '/Games'),
			7 => $this->folderNode(7, '/Roms'),
		]);
		$saved = $this->save(['library_folder' => '/Roms'], $root);
		$this->assertSame(7, $saved['library_folder_id']);
	}

	public function testFastForwardRatioIsClamped(): void {
		// Stored as JSON, where a round number comes back as an integer.
		$this->assertEquals(5, $this->save(['fastforward_ratio' => 1000])['fastforward_ratio']);
		$this->assertEquals(1, $this->save(['fastforward_ratio' => -5])['fastforward_ratio']);
		$this->assertEquals(2.5, $this->save(['fastforward_ratio' => '2.5'])['fastforward_ratio']);
		$this->assertArrayNotHasKey('fastforward_ratio', $this->save(['fastforward_ratio' => 'fast']));
	}

	public function testAudioSettingsAreClamped(): void {
		$this->assertSame(100, $this->save(['volume' => 500])['volume']);
		$this->assertSame(0, $this->save(['volume' => -40])['volume']);
		$this->assertSame(35, $this->save(['volume' => '35'])['volume']);
		$this->assertArrayNotHasKey('volume', $this->save(['volume' => 'loud']));

		$this->assertSame(256, $this->save(['audio_latency' => 5000])['audio_latency']);
		$this->assertSame(16, $this->save(['audio_latency' => 0])['audio_latency']);
		$this->assertSame(96, $this->save(['audio_latency' => '96'])['audio_latency']);
	}

	public function testPlayerTogglesAreStoredAsBooleans(): void {
		$saved = $this->save([
			'scale_integer' => 'true',
			'rewind_enabled' => 'true',
			'pause_when_hidden' => '0',
			'autoload_on_start' => false,
		]);
		$this->assertTrue($saved['scale_integer']);
		$this->assertTrue($saved['rewind_enabled']);
		$this->assertFalse($saved['pause_when_hidden']);
		$this->assertFalse($saved['autoload_on_start']);
	}

	public function testRunAheadTakesOnlyAFewFrames(): void {
		$this->assertSame(0, $this->save(['runahead_frames' => 0])['runahead_frames']);
		$this->assertSame(2, $this->save(['runahead_frames' => '2'])['runahead_frames']);
		$this->assertSame(3, $this->save(['runahead_frames' => 10])['runahead_frames']);
		$this->assertSame(0, $this->save(['runahead_frames' => -1])['runahead_frames']);
		$this->assertArrayNotHasKey('runahead_frames', $this->save(['runahead_frames' => 'lots']));
	}

	public function testTheAutosaveIntervalTakesOnlyTheOfferedValues(): void {
		$this->assertSame(0, $this->save(['autosave_interval' => 0])['autosave_interval']);
		$this->assertSame(30, $this->save(['autosave_interval' => '30'])['autosave_interval']);
		$this->assertSame(600, $this->save(['autosave_interval' => 600])['autosave_interval']);
		// Anything else would be a value the settings never offered.
		$this->assertArrayNotHasKey('autosave_interval', $this->save(['autosave_interval' => 45]));
		$this->assertArrayNotHasKey('autosave_interval', $this->save(['autosave_interval' => -60]));
		$this->assertArrayNotHasKey('autosave_interval', $this->save(['autosave_interval' => 'often']));
	}

	public function testResumingByItselfIsStoredAsABoolean(): void {
		$this->assertTrue($this->save(['autoload_on_start' => 'true'])['autoload_on_start']);
		$this->assertFalse($this->save(['autoload_on_start' => '0'])['autoload_on_start']);
	}

	public function testTheKeyboardIsBoundToTheControllerOutOfTheBox(): void {
		$settings = $this->service()->getUserSettings(self::USER);
		$this->assertSame('ArrowUp', $settings['buttons']['up']);
		$this->assertSame('KeyX', $settings['buttons']['a']);
		$this->assertSame('Space', $settings['hotkeys']['pause']);
	}

	public function testRebindingAKeyLeavesTheOthersWhereTheyWere(): void {
		$saved = $this->save(['buttons' => ['a' => 'KeyM', 'made_up' => 'KeyN']]);
		$this->assertSame('KeyM', $saved['buttons']['a'], 'the key that was set');
		$this->assertSame('KeyZ', $saved['buttons']['b'], 'and the rest as they were');
		$this->assertArrayNotHasKey('made_up', $saved['buttons']);
	}

	public function testTheKindOfPictureIsAUsersToSet(): void {
		// It is a matter of taste, unlike the options of a core: the
		// administration page only says where everybody starts.
		$saved = $this->save(['thumbnail_types' => ['nes' => 'title', 'snes' => 'made up']]);
		$this->assertSame(['nes' => 'title'], $saved['thumbnail_types'] ?? null);
	}

	public function testWhatBelongsToTheInstanceIsNotAUsersToSet(): void {
		$saved = $this->save([
			'fetch_enabled' => false,
			'max_games' => 10,
			'max_depth' => 99,
			'cache_ttl' => 1,
		]);
		foreach (['fetch_enabled', 'max_games', 'max_depth', 'cache_ttl'] as $key) {
			$this->assertArrayNotHasKey($key, $saved);
		}
	}

	public function testTheLimitsOfAScanAreHeldToWhatIsSensible(): void {
		$saved = $this->saveInstanceValues([
			'max_games' => 10_000_000,
			'max_depth' => 0,
			'cache_ttl' => 30,
		]);
		$this->assertSame(100000, $saved['max_games']);
		$this->assertSame(1, $saved['max_depth']);
		$this->assertSame(60, $saved['cache_ttl']);
	}

	public function testLookingUpBoxArtCanBeTurnedOffForEverybody(): void {
		$this->assertFalse($this->saveInstanceValues(['fetch_enabled' => false])['fetch_enabled']);
		$this->assertTrue($this->saveInstanceValues(['fetch_enabled' => true])['fetch_enabled']);
	}

	public function testCoreOptionsAreNotAUsersToSet(): void {
		// They hold for everybody playing a core, so they live with the
		// administration settings.
		$saved = $this->save(['core_options' => ['fceumm' => ['fceumm_palette' => 'wavebeam']]]);
		$this->assertArrayNotHasKey('core_options', $saved);
	}

	public function testUnknownSettingsAreDropped(): void {
		$this->assertArrayNotHasKey('evil', $this->save(['evil' => 'value']));
	}

	public function testTheJsonBlobsAreStoredAndReadLazily(): void {
		// Nextcloud preloads every non-lazy app config value on every
		// request of the instance; these blobs are big and only needed
		// when the app itself runs, so they stay out of that pile. The
		// small scalar settings are not worth the second query.
		$appConfig = $this->createMock(IAppConfig::class);
		$setLazy = $getLazy = [];
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false) use (&$setLazy): bool {
				$setLazy[$key] = $lazy;
				return true;
			},
		);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '', bool $lazy = false) use (&$getLazy): string {
				$getLazy[$key] = $lazy;
				return '';
			},
		);
		$service = new SettingsService($this->createStub(IUserConfig::class), $appConfig, $this->emptyRootFolder());
		$service->setInstanceDefaults([
			'core_options' => [],
			'thumbnail_types' => [],
			'library_folder' => '/Games',
			'fetch_enabled' => true,
		]);
		$service->getCoreOptions();
		$service->getThumbnailTypes();
		$this->assertTrue($setLazy['core_options']);
		$this->assertTrue($setLazy['thumbnail_types']);
		$this->assertFalse($setLazy['library_folder']);
		$this->assertFalse($setLazy['fetch_enabled']);
		$this->assertTrue($getLazy['core_options']);
		$this->assertTrue($getLazy['thumbnail_types']);
		$this->assertFalse($getLazy['library_folder']);
	}

	public function testCoreOptionsAreCheckedAgainstWhatTheCoresOffer(): void {
		$saved = $this->saveInstance([
			'core_options' => [
				'fceumm' => [
					'fceumm_palette' => 'wavebeam',
					'fceumm_palette_made_up' => 'value',
					'fceumm_region' => 'Klingon',
				],
				'made_up_core' => ['option' => 'value'],
			],
		]);
		$this->assertSame(['fceumm' => ['fceumm_palette' => 'wavebeam']], $saved);
	}

	public function testTheFolderHintsSayWhatAnUnsetFieldLeavesInForce(): void {
		// The administration page renders these as the placeholder of an
		// empty field. They have to be what a user without a setting of
		// their own actually gets, or the page states a default that is
		// not the one in force.
		$service = $this->service();
		$fallbacks = $service->folderFallbacks();
		$effective = $service->getDefaults();

		$this->assertNotSame([], $fallbacks);
		foreach ($fallbacks as $key => $hint) {
			$this->assertSame($effective[$key], $hint, "$key is hinted as what it falls back to");
		}
		$this->assertSame('/Games', $fallbacks['library_folder'], 'the one that matters is named');
	}

	public function testAVolumeSetInDecibelsIsReadForwardAsAPercentage(): void {
		// The setting used to be the gain RetroArch takes, from -20 to 10.
		// Read as a percentage, the old default of 0 -- the game exactly
		// as recorded -- would have meant silence for everybody who had
		// ever opened the settings page.
		$this->assertSame(100, $this->service('{"audio_volume":0}')->getUserSettings('player')['volume']);
		$this->assertEqualsWithDelta(50, $this->service('{"audio_volume":-6}')->getUserSettings('player')['volume'], 2);
		$this->assertEqualsWithDelta(10, $this->service('{"audio_volume":-20}')->getUserSettings('player')['volume'], 2);
	}

	public function testAVolumeAlreadyAPercentageIsLeftAlone(): void {
		$settings = $this->service('{"volume":30,"audio_volume":0}')->getUserSettings('player');
		$this->assertSame(30, $settings['volume']);
		$this->assertArrayNotHasKey('audio_volume', $settings, 'the old key does not travel on');
	}

	public function testTheVolumeDefaultsToTheGameAsRecorded(): void {
		$this->assertSame(100, $this->service()->getDefaults()['volume']);
	}
}
