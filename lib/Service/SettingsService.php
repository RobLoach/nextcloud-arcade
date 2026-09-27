<?php

declare(strict_types=1);

namespace OCA\Arcade\Service;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Controls;
use OCA\Arcade\CoreMap;
use OCA\Arcade\CoreOptions;
use OCP\AppFramework\Services\IInitialState;
use OCP\Config\IUserConfig;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;

class SettingsService {
	/** The kinds of image a libretro thumbnail pack holds. */
	public const THUMBNAIL_TYPES = ['boxart', 'title', 'snap', 'logo'];

	/** The same, as they are offered in the settings. */
	public const THUMBNAIL_LABELS = [
		'boxart' => 'Box art',
		'title' => 'Title screen',
		'snap' => 'Screenshot',
		'logo' => 'Logo',
	];

	/** How often a game may save itself, in seconds. 0 leaves it to you. */
	public const AUTOSAVE_INTERVALS = [0, 30, 60, 120, 300, 600];

	/**
	 * What only an administrator sets, and every user is held to: how far
	 * a library is walked, how long the walk is kept, and whether the box
	 * art of the libretro server may be asked for at all -- the one thing
	 * in the app that has the server itself talk to the internet.
	 *
	 * @var array<string, array{min: int, max: int}|bool>
	 */
	public const INSTANCE_ONLY = [
		'fetch_enabled' => true,
		'hash_roms' => true,
		'max_games' => ['min' => 100, 'max' => 100000],
		'max_depth' => ['min' => 1, 'max' => 12],
		'cache_ttl' => ['min' => 60, 'max' => 7 * 24 * 3600],
	];

	/**
	 * The settings that point at a folder. A user's choice is remembered
	 * by the id of the folder, so that moving or renaming it does not
	 * lose the configuration; the path is kept alongside as a fallback.
	 */
	public const FOLDER_SETTINGS = [
		'library_folder',
		'thumbnails_folder',
		'screenshots_folder',
		'saves_folder',
		'system_folder',
	];

	/**
	 * The folder settings an administrator can set for everyone. These
	 * stay paths: a folder id belongs to one user's storage, while an
	 * instance default has to hold for every user.
	 */
	public const INSTANCE_DEFAULTS = self::FOLDER_SETTINGS;

	/**
	 * Settings are read on nearly every request, sometimes several times.
	 * They cannot change within one, so they are parsed once.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $settings = [];

	public function __construct(
		private IUserConfig $userConfig,
		private IAppConfig $appConfig,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * The defaults of the app, with what the administrator set for the
	 * instance on top.
	 *
	 * @return array<string, mixed>
	 */
	public function getDefaults(): array {
		$defaults = $this->appDefaults();
		foreach (self::INSTANCE_DEFAULTS as $key) {
			$value = $this->appConfig->getValueString(Application::APP_ID, $key);
			if ($value !== '') {
				$defaults[$key] = $value;
			}
		}
		foreach (array_keys(self::INSTANCE_ONLY) as $key) {
			$value = $this->appConfig->getValueString(Application::APP_ID, $key);
			if ($value !== '') {
				$defaults[$key] = is_bool($defaults[$key])
					? filter_var($value, FILTER_VALIDATE_BOOLEAN)
					: (int)$value;
			}
		}
		// The options of a core are the same for everybody playing it. The
		// kind of picture a system is shown with is only where a user
		// starts: it is a matter of taste, so it can be changed.
		$defaults['core_options'] = $this->getCoreOptions();
		$defaults['thumbnail_types'] = $this->getThumbnailTypes();
		return $defaults;
	}

	/**
	 * @return array<string, string> system id => kind of image
	 */
	public function getThumbnailTypes(): array {
		return $this->lazyJson('thumbnail_types', $this->sanitizeThumbnailTypes(...));
	}

	/**
	 * A JSON blob stored lazy in the app config, decoded and sanitized;
	 * an empty or unreadable one is just nothing.
	 *
	 * @param callable(array<string, mixed>): array $sanitize
	 */
	private function lazyJson(string $key, callable $sanitize): array {
		$stored = $this->appConfig->getValueString(Application::APP_ID, $key, lazy: true);
		if ($stored === '') {
			return [];
		}
		$decoded = json_decode($stored, true);
		return is_array($decoded) ? $sanitize($decoded) : [];
	}

	/**
	 * @param array<string, mixed> $types
	 * @return array<string, string>
	 */
	private function sanitizeThumbnailTypes(array $types): array {
		$sanitized = [];
		foreach ($types as $system => $type) {
			if (isset(CoreMap::SYSTEMS[$system]) && is_string($type) && in_array($type, self::THUMBNAIL_TYPES, true)) {
				$sanitized[$system] = $type;
			}
		}
		return $sanitized;
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	public function getCoreOptions(): array {
		return $this->lazyJson('core_options', $this->sanitizeCoreOptions(...));
	}

	/**
	 * What an administrator can set for the instance.
	 *
	 * @return array<string, string>
	 */
	public function getInstanceDefaults(): array {
		$defaults = [];
		foreach (self::INSTANCE_DEFAULTS as $key) {
			$defaults[$key] = $this->appConfig->getValueString(Application::APP_ID, $key);
		}
		$app = $this->appDefaults();
		foreach (array_keys(self::INSTANCE_ONLY) as $key) {
			$stored = $this->appConfig->getValueString(Application::APP_ID, $key);
			$defaults[$key] = $stored === '' ? $app[$key] : (
				is_bool($app[$key]) ? filter_var($stored, FILTER_VALIDATE_BOOLEAN) : (int)$stored
			);
		}
		return $defaults;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, string>
	 */
	public function setInstanceDefaults(array $settings): array {
		$sanitized = $this->sanitize($settings);
		foreach (self::INSTANCE_DEFAULTS as $key) {
			if (array_key_exists($key, $sanitized)) {
				$this->appConfig->setValueString(Application::APP_ID, $key, (string)$sanitized[$key]);
			}
		}
		foreach (array_keys(self::INSTANCE_ONLY) as $key) {
			if (array_key_exists($key, $sanitized)) {
				$this->appConfig->setValueString(
					Application::APP_ID,
					$key,
					is_bool($sanitized[$key]) ? ($sanitized[$key] ? '1' : '0') : (string)$sanitized[$key],
				);
			}
		}
		// These two are large JSON blobs, so they are stored lazy: Nextcloud
		// preloads every non-lazy app config value on every request of the
		// instance, and these are only needed when the app itself runs.
		// Writing with the lazy flag also moves a value stored non-lazy by
		// an earlier version of the app over to the lazy pile.
		foreach (['core_options', 'thumbnail_types'] as $key) {
			if (array_key_exists($key, $sanitized)) {
				$this->appConfig->setValueString(Application::APP_ID, $key, json_encode($sanitized[$key]), lazy: true);
			}
		}
		$this->settings = [];
		return $this->getInstanceDefaults();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function appDefaults(): array {
		return [
			'video_smooth' => false,
			'scale_integer' => false,
			'fastforward_ratio' => 3,
			// Keeping the past around costs memory and CPU, so nobody pays
			// for rewinding without asking.
			'rewind_enabled' => false,
			'runahead_frames' => 0,
			'audio_volume' => 0,
			'audio_latency' => 64,
			'respond_to_global_events' => true,
			'pause_when_hidden' => true,
			'autosave_on_close' => true,
			'autoload_on_start' => false,
			'autosave_interval' => 0,
			'library_folder' => '/Games',
			'thumbnails_folder' => '',
			'screenshots_folder' => '',
			'saves_folder' => '',
			'system_folder' => '',
			'core_options' => [],
			'fetch_enabled' => true,
			// Reading a whole ROM to hash it is not something to do to an
			// instance without being asked.
			'hash_roms' => false,
			'max_games' => LibraryService::MAX_GAMES,
			'max_depth' => LibraryService::MAX_DEPTH,
			'cache_ttl' => LibraryService::CACHE_TTL,
			'buttons' => Controls::defaultButtons(),
			'hotkeys' => Controls::defaultHotkeys(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getUserSettings(string $userId): array {
		return $this->settings[$userId] ??= $this->readUserSettings($userId);
	}

	/**
	 * The settings of a user, or the defaults when there is no user to
	 * ask for -- a public page, say.
	 *
	 * @return array<string, mixed>
	 */
	public function forUser(?string $userId): array {
		return $userId === null ? $this->getDefaults() : $this->getUserSettings($userId);
	}

	/**
	 * Everything the player needs to know before a file is even named:
	 * the systems, the words that say nothing about one, and the settings
	 * of whoever is looking.
	 */
	public function providePlayerState(IInitialState $initialState, ?string $userId): void {
		$initialState->provideInitialState('systems', CoreMap::SYSTEMS);
		// The words that say nothing about a system, so the browser can
		// read a folder name the way the server does without keeping a
		// copy of the lists.
		$initialState->provideInitialState('folderWords', [
			'noise' => CoreMap::NOISE,
			'vendors' => CoreMap::VENDORS,
		]);
		$initialState->provideInitialState('settings', $this->forUser($userId));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readUserSettings(string $userId): array {
		$defaults = $this->getDefaults();
		$stored = $this->userConfig->getValueString($userId, Application::APP_ID, 'settings', '');
		if ($stored === '') {
			return $defaults;
		}
		$decoded = json_decode($stored, true);
		if (!is_array($decoded)) {
			return $defaults;
		}
		// Early versions saved the whole settings form, so a row from then
		// still carries the core options and the instance-only settings.
		// Those belong to the administrator, and a stale copy here would
		// shadow whatever is set now, so they are dropped -- and the row
		// trued up for good by the write-back below.
		$settings = array_diff_key(
			$decoded,
			array_flip(['core_options', ...array_keys(self::INSTANCE_ONLY)]),
		);
		// The folders are looked up by their ids, so the settings follow
		// them when they are moved or renamed. Anything learned -- a new
		// path, or the id of a folder that was only stored as a path
		// before ids were kept -- is written back right away.
		$resolved = $this->resolveFolders($userId, $settings);
		if ($resolved !== $decoded) {
			$this->userConfig->setValueString($userId, Application::APP_ID, 'settings', json_encode($resolved));
		}
		return array_merge($defaults, $this->sanitize($resolved));
	}

	/**
	 * True up the folder settings against the file cache: a stored id
	 * wins and hands back the current path of its folder, a path without
	 * an id (as stored before ids were kept, or set by hand) is linked
	 * to the id of whatever folder lives there, and an id whose folder
	 * is gone is dropped, leaving the path as it was.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function resolveFolders(string $userId, array $settings): array {
		$userFolder = $this->userFolder($userId);
		if ($userFolder === null) {
			return $settings;
		}
		foreach (self::FOLDER_SETTINGS as $key) {
			$idKey = $key . '_id';
			$id = isset($settings[$idKey]) && is_numeric($settings[$idKey]) ? (int)$settings[$idKey] : 0;
			if ($id > 0) {
				$path = $this->folderPath($userFolder, $id);
				if ($path !== null) {
					$settings[$key] = $path;
					$settings[$idKey] = $id;
					continue;
				}
				// The folder is gone. The path stays as a fallback: if a
				// folder turns up there again -- restored from the trash,
				// say -- it is linked to it below or on a later read.
			}
			unset($settings[$idKey]);
			if (isset($settings[$key]) && is_string($settings[$key]) && $settings[$key] !== '') {
				$folderId = $this->folderId($userFolder, $settings[$key]);
				if ($folderId !== null) {
					$settings[$idKey] = $folderId;
				}
			}
		}
		return $settings;
	}

	private function userFolder(string $userId): ?Folder {
		try {
			return $this->rootFolder->getUserFolder($userId);
		} catch (\Exception) {
			return null;
		}
	}

	/** The current path of a folder, relative to the user folder. */
	private function folderPath(Folder $userFolder, int $id): ?string {
		$node = $userFolder->getFirstNodeById($id);
		if (!$node instanceof Folder) {
			return null;
		}
		$path = $userFolder->getRelativePath($node->getPath());
		if ($path === null) {
			return null;
		}
		$path = '/' . trim($path, '/');
		return $path === '/' ? null : $path;
	}

	/** The id of the folder at a path relative to the user folder. */
	private function folderId(Folder $userFolder, string $path): ?int {
		try {
			$node = $userFolder->get($path);
		} catch (NotFoundException|NotPermittedException) {
			return null;
		}
		return $node instanceof Folder ? $node->getId() : null;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed> the sanitized, effective settings
	 */
	public function setUserSettings(string $userId, array $settings): array {
		$sanitized = $this->sanitize($settings);
		// The options of a core belong to the core, and the rest of these
		// to the instance. The kind of picture a system is shown with is
		// not among them: that one is a matter of taste.
		unset($sanitized['core_options']);
		foreach (array_keys(self::INSTANCE_ONLY) as $key) {
			unset($sanitized[$key]);
		}
		// A folder handed in as a path is remembered by its id as well,
		// so the setting follows the folder if it moves.
		$sanitized = $this->resolveFolders($userId, $sanitized);
		$this->userConfig->setValueString($userId, Application::APP_ID, 'settings', json_encode($sanitized));
		unset($this->settings[$userId]);
		return $this->getUserSettings($userId);
	}

	/**
	 * Keep only known settings keys with valid values.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function sanitize(array $settings): array {
		$sanitized = [];
		foreach ([
			'video_smooth',
			'scale_integer',
			'rewind_enabled',
			'respond_to_global_events',
			'pause_when_hidden',
			'autosave_on_close',
			'autoload_on_start',
		] as $key) {
			if (array_key_exists($key, $settings)) {
				$sanitized[$key] = filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN);
			}
		}
		// Clamping can hand back the bound itself, so the types are forced
		// to stay the same.
		if (array_key_exists('fastforward_ratio', $settings) && is_numeric($settings['fastforward_ratio'])) {
			$sanitized['fastforward_ratio'] = (float)max(1, min(5, (float)$settings['fastforward_ratio']));
		}
		if (array_key_exists('audio_volume', $settings) && is_numeric($settings['audio_volume'])) {
			// RetroArch takes a gain in decibels, where 0 is as recorded.
			$sanitized['audio_volume'] = (float)max(-20, min(10, (float)$settings['audio_volume']));
		}
		if (array_key_exists('autosave_interval', $settings)
			&& is_numeric($settings['autosave_interval'])
			&& in_array((int)$settings['autosave_interval'], self::AUTOSAVE_INTERVALS, true)) {
			$sanitized['autosave_interval'] = (int)$settings['autosave_interval'];
		}
		if (array_key_exists('audio_latency', $settings) && is_numeric($settings['audio_latency'])) {
			$sanitized['audio_latency'] = max(16, min(256, (int)$settings['audio_latency']));
		}
		if (array_key_exists('runahead_frames', $settings) && is_numeric($settings['runahead_frames'])) {
			$sanitized['runahead_frames'] = max(0, min(3, (int)$settings['runahead_frames']));
		}
		foreach (self::INSTANCE_ONLY as $key => $bounds) {
			if (!array_key_exists($key, $settings)) {
				continue;
			}
			if (is_bool($bounds)) {
				$sanitized[$key] = filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN);
			} elseif (is_numeric($settings[$key])) {
				$sanitized[$key] = max($bounds['min'], min($bounds['max'], (int)$settings[$key]));
			}
		}
		// An empty folder means the feature is disabled; the library folder
		// always has one.
		foreach (self::FOLDER_SETTINGS as $key) {
			if (!array_key_exists($key, $settings) || !is_string($settings[$key])) {
				continue;
			}
			$folder = trim(trim($settings[$key]), '/');
			if ($folder === '' && $key !== 'library_folder') {
				$sanitized[$key] = '';
			} elseif ($folder !== '' && !str_contains($folder, '..')) {
				$sanitized[$key] = '/' . $folder;
			}
		}
		if (isset($settings['core_options']) && is_array($settings['core_options'])) {
			$sanitized['core_options'] = $this->sanitizeCoreOptions($settings['core_options']);
		}
		if (isset($settings['thumbnail_types']) && is_array($settings['thumbnail_types'])) {
			$sanitized['thumbnail_types'] = $this->sanitizeThumbnailTypes($settings['thumbnail_types']);
		}
		// A binding that is left out keeps whatever it had, so a page that
		// knows nothing of the keyboard cannot wipe it.
		if (isset($settings['buttons']) && is_array($settings['buttons'])) {
			$sanitized['buttons'] = array_merge(
				Controls::defaultButtons(),
				Controls::sanitize($settings['buttons'], Controls::BUTTONS),
			);
		}
		if (isset($settings['hotkeys']) && is_array($settings['hotkeys'])) {
			$sanitized['hotkeys'] = array_merge(
				Controls::defaultHotkeys(),
				Controls::sanitize($settings['hotkeys'], Controls::HOTKEYS),
			);
		}
		return $sanitized;
	}

	/**
	 * Keep only known cores, options and values. An empty value means the
	 * core decides, so it is dropped rather than stored.
	 *
	 * @param array<string, mixed> $coreOptions
	 * @return array<string, array<string, string>>
	 */
	private function sanitizeCoreOptions(array $coreOptions): array {
		$sanitized = [];
		foreach ($coreOptions as $core => $options) {
			if (!isset(CoreOptions::OPTIONS[$core]) || !is_array($options)) {
				continue;
			}
			foreach ($options as $key => $value) {
				if (isset(CoreOptions::OPTIONS[$core][$key])
					&& is_string($value)
					&& isset(CoreOptions::OPTIONS[$core][$key]['values'][$value])) {
					$sanitized[$core][$key] = $value;
				}
			}
		}
		return $sanitized;
	}
}
