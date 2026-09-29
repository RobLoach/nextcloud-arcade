<?php

declare(strict_types=1);

namespace OCA\Arcade;

use OCA\Arcade\AppInfo\Application;
use OCA\Arcade\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;

/**
 * What a client can learn about the app before it asks for anything:
 * which version is installed, which systems it plays, what it can do
 * with a game, and how large a library it will walk.
 *
 * Plainly ICapability, and neither of its two siblings:
 *
 * - IPublicCapability would hand this to anonymous callers of
 *   /ocs/v2.php/cloud/capabilities as well. Every endpoint of the app
 *   is user-scoped and needs a session, so there is nothing an
 *   anonymous caller could do with the answer. A game shared by link
 *   does play for a visitor, but that page is the Viewer loading the
 *   player directly -- it never asks what the app supports -- so
 *   publishing the list would widen what the server tells strangers
 *   about itself and buy nobody anything.
 * - IInitialStateExcludedCapability is for capabilities too expensive
 *   to build on every page load. This one is class constants and the
 *   two instance bounds, which live in the non-lazy app config the
 *   server has already loaded, so it is cheap enough to ride along.
 */
class Capabilities implements ICapability {
	/**
	 * What the app does, as a client can count on it doing. A feature is
	 * listed only once the app really has it, so a client may take a
	 * missing key and a false the same way: whether any of these is
	 * turned on for the player is a setting, not a capability.
	 *
	 * @var array<string, bool>
	 */
	public const FEATURES = [
		// Slots of save state, with a screenshot of each.
		'saveStates' => true,
		// The in-game battery save of a cartridge.
		'battery' => true,
		// Screenshots taken in the player, kept as files.
		'screenshots' => true,
		// Holding a key to run the game backwards.
		'rewind' => true,
		// Frames run ahead of the display to cut input latency.
		'runAhead' => true,
		// Playing, and browsing the library, with a gamepad.
		'gamepad' => true,
		// The collaborative tags of a ROM, as a library filter.
		'tags' => true,
		// What was played lands in the Activity stream.
		'activity' => true,
	];

	public function __construct(
		private IAppManager $appManager,
		private SettingsService $settings,
	) {
	}

	/**
	 * @return array{arcade: array{
	 *     version: string,
	 *     systems: list<array{id: string, name: string, extensions: list<string>, core: string, bios: bool}>,
	 *     features: array<string, bool>,
	 *     limits: array{maxGames: int, maxDepth: int},
	 * }}
	 */
	public function getCapabilities(): array {
		// The instance settings, not the defaults of a user: those decode
		// the lazy app config blobs, which this has no use for and which
		// would make every capabilities call pay for them.
		$instance = $this->settings->getInstanceDefaults();
		return [
			'arcade' => [
				// Read from the app manager rather than written here, so
				// that it is the version actually installed -- and cannot
				// drift from info.xml.
				'version' => $this->appManager->getAppVersion(Application::APP_ID),
				'systems' => self::systems(),
				'features' => self::FEATURES,
				'limits' => [
					'maxGames' => (int)$instance['max_games'],
					'maxDepth' => (int)$instance['max_depth'],
				],
			],
		];
	}

	/**
	 * The systems the app plays, as much of each as a client needs to
	 * know: what to call it, which files belong to it, which core runs
	 * it, and whether it will want a BIOS. The aliases and the folder
	 * words stay out -- those are for reading a library, and the player
	 * page is given them in its initial state.
	 *
	 * @return list<array{id: string, name: string, extensions: list<string>, core: string, bios: bool}>
	 */
	private static function systems(): array {
		$systems = [];
		foreach (CoreMap::SYSTEMS as $id => $system) {
			$systems[] = [
				'id' => $id,
				'name' => $system['label'],
				'extensions' => $system['extensions'],
				'core' => $system['core'],
				// Whether the system asks for one at all, not whether the
				// instance holds it: that is per-file and needs the disk.
				'bios' => $system['bios'] !== [],
			];
		}
		return $systems;
	}
}
