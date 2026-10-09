<?php

declare(strict_types=1);

namespace OCA\Arcade;

/**
 * Maps retro console systems to their file extensions, mimetypes and
 * available libretro cores from retroarch-emscripten-build.
 */
class CoreMap {
	/**
	 * The first core listed for each system is the default. Aliases are
	 * normalized folder names (lowercase, alphanumeric only) used to detect
	 * the system from the folder a game is stored in.
	 */
	public const SYSTEMS = [
		'nes' => [
			'label' => 'Nintendo Entertainment System',
			// 'short' is the folder saves and screenshots are filed under,
			// so it is left exactly as it has always been: renaming it
			// would leave every state already written under the old name
			// where the player can no longer find it. What it says on
			// screen is 'display' below.
			'short' => 'Nintendo',
			// Every other system drops only the maker from its label --
			// Sega Genesis to Genesis, Atari Lynx to Lynx. Doing that here
			// leaves "Entertainment System", so the name was left as the
			// maker instead, and a library read "Nintendo" beside "Super
			// Nintendo" and "Game Boy", which are consoles.
			'display' => 'NES',
			'platform' => 'Nintendo - Nintendo Entertainment System',
			'mime' => 'application/x-nes-rom',
			'extensions' => ['nes', 'fds', 'unf', 'unif'],
			'core' => 'fceumm',
			'aliases' => ['nes', 'famicom', 'fc', 'nintendoentertainmentsystem', 'entertainmentsystem', 'fds', 'famicomdisksystem', 'familycomputerdisksystem', 'famicon', 'nintendo8bit', '8bitnintendo'],
			'bios' => [],
		],
		'snes' => [
			'label' => 'Super Nintendo',
			'short' => 'Super Nintendo',
			'platform' => 'Nintendo - Super Nintendo Entertainment System',
			'mime' => 'application/x-snes-rom',
			'extensions' => ['sfc', 'smc'],
			'core' => 'snes9x',
			'aliases' => ['snes', 'sfc', 'superfamicom', 'supernintendo', 'supernes', 'supernintendoentertainmentsystem', 'superentertainmentsystem', 'supernintendo16bit', 'superfamicon'],
			'bios' => [],
		],
		'gb' => [
			'label' => 'Game Boy',
			'short' => 'Game Boy',
			'platform' => 'Nintendo - Game Boy',
			'mime' => 'application/x-gameboy-rom',
			'extensions' => ['gb'],
			'core' => 'gambatte',
			'aliases' => ['gb', 'gameboy', 'dmg', 'gameboyclassic', 'gameboymono'],
			'bios' => ['gb_bios.bin'],
		],
		'gbc' => [
			'label' => 'Game Boy Color',
			'short' => 'Game Boy Color',
			'platform' => 'Nintendo - Game Boy Color',
			'mime' => 'application/x-gameboy-color-rom',
			'extensions' => ['gbc'],
			'core' => 'gambatte',
			'aliases' => ['gbc', 'gameboycolor', 'gameboycolour', 'gbcolor'],
			'bios' => ['gbc_bios.bin'],
		],
		'gba' => [
			'label' => 'Game Boy Advance',
			'short' => 'Game Boy Advance',
			'platform' => 'Nintendo - Game Boy Advance',
			'mime' => 'application/x-gba-rom',
			'extensions' => ['gba'],
			'core' => 'mgba',
			'aliases' => ['gba', 'gameboyadvance', 'gbadvance'],
			'bios' => ['gba_bios.bin'],
		],
		'genesis' => [
			'label' => 'Sega Genesis / Mega Drive',
			'short' => 'Genesis',
			'platform' => 'Sega - Mega Drive - Genesis',
			'mime' => 'application/x-genesis-rom',
			'extensions' => ['md', 'gen', 'smd'],
			'core' => 'genesis_plus_gx',
			'aliases' => ['genesis', 'gen', 'md', 'megadrive', 'segagenesis', 'segamegadrive', 'megadrivegenesis', 'genesismegadrive', 'segamd', 'megadrive16bit'],
			'bios' => ['bios_MD.bin'],
		],
		'sms' => [
			'label' => 'Sega Master System',
			'short' => 'Master System',
			'platform' => 'Sega - Master System - Mark III',
			'mime' => 'application/x-sms-rom',
			'extensions' => ['sms'],
			'core' => 'genesis_plus_gx',
			'aliases' => ['sms', 'mastersystem', 'segamastersystem', 'markiii', 'mark3', 'mastersystemmarkiii', 'sega8bit'],
			'bios' => ['bios.sms'],
		],
		'gamegear' => [
			'label' => 'Sega Game Gear',
			'short' => 'Game Gear',
			'platform' => 'Sega - Game Gear',
			'mime' => 'application/x-gamegear-rom',
			'extensions' => ['gg'],
			'core' => 'genesis_plus_gx',
			'aliases' => ['gg', 'gamegear', 'segagamegear'],
			'bios' => ['bios.gg'],
		],
		'sega32x' => [
			'label' => 'Sega 32X',
			'short' => '32X',
			'platform' => 'Sega - 32X',
			'mime' => 'application/x-sega-32x-rom',
			'extensions' => ['32x'],
			'core' => 'picodrive',
			'aliases' => ['32x', 'sega32x', 'genesis32x', 'megadrive32x', 'super32x', 'mega32x', 'sega32xmega'],
			'bios' => ['32X_G_BIOS.BIN', '32X_M_BIOS.BIN', '32X_S_BIOS.BIN'],
		],
		'pce' => [
			'label' => 'PC Engine / TurboGrafx-16',
			'short' => 'TurboGrafx-16',
			'platform' => 'NEC - PC Engine - TurboGrafx 16',
			'mime' => 'application/x-pc-engine-rom',
			'extensions' => ['pce'],
			'core' => 'mednafen_pce_fast',
			'aliases' => ['pce', 'pcengine', 'turbografx', 'turbografx16', 'tg16', 'pcenngine', 'turbografx16pcengine', 'pcengineturbografx16', 'necpcengine'],
			'bios' => ['syscard3.pce'],
		],
		'lynx' => [
			'label' => 'Atari Lynx',
			'short' => 'Lynx',
			'platform' => 'Atari - Lynx',
			'mime' => 'application/x-lynx-rom',
			'extensions' => ['lnx'],
			'core' => 'handy',
			'aliases' => ['lynx', 'atarilynx', 'lynxhandheld'],
			'bios' => ['lynxboot.img'],
		],
		'ngp' => [
			'label' => 'Neo Geo Pocket',
			'short' => 'Neo Geo Pocket',
			'platform' => 'SNK - Neo Geo Pocket',
			'mime' => 'application/x-neo-geo-pocket-rom',
			'extensions' => ['ngp', 'ngc'],
			'core' => 'mednafen_ngp',
			'aliases' => ['ngp', 'ngpc', 'neogeopocket', 'neogeopocketcolor', 'neogeopocketcolour', 'snkneogeopocket'],
			'bios' => [],
		],
		'wonderswan' => [
			'label' => 'WonderSwan',
			'short' => 'WonderSwan',
			'platform' => 'Bandai - WonderSwan',
			'mime' => 'application/x-wonderswan-rom',
			'extensions' => ['ws', 'wsc'],
			'core' => 'mednafen_wswan',
			'aliases' => ['ws', 'wsc', 'wonderswan', 'wonderswancolor', 'wonderswancolour', 'bandaiwonderswan'],
			'bios' => [],
		],
		'virtualboy' => [
			'label' => 'Virtual Boy',
			'short' => 'Virtual Boy',
			'platform' => 'Nintendo - Virtual Boy',
			'mime' => 'application/x-virtual-boy-rom',
			'extensions' => ['vb'],
			'core' => 'mednafen_vb',
			'aliases' => ['vb', 'virtualboy', 'nintendovirtualboy', 'vboy'],
			'bios' => [],
		],
		'vectrex' => [
			'label' => 'Vectrex',
			'short' => 'Vectrex',
			'platform' => 'GCE - Vectrex',
			'mime' => 'application/x-vectrex-rom',
			'extensions' => ['vec'],
			'core' => 'vecx',
			'aliases' => ['vectrex', 'gcevectrex'],
			'bios' => [],
		],
		'coleco' => [
			'label' => 'ColecoVision',
			'short' => 'ColecoVision',
			'platform' => 'Coleco - ColecoVision',
			'mime' => 'application/x-colecovision-rom',
			'extensions' => ['col'],
			'core' => 'gearcoleco',
			'aliases' => ['coleco', 'colecovision'],
			'bios' => ['colecovision.rom'],
		],
		'psx' => [
			'label' => 'PlayStation',
			'short' => 'PlayStation',
			'platform' => 'Sony - PlayStation',
			'mime' => 'application/x-playstation-rom',
			// Only the single-file disc formats. A .cue names its tracks in
			// other files and a .bin or .iso does not say whose it is, so
			// those wait until multi-file games can be handed over whole.
			'extensions' => ['chd', 'pbp'],
			'core' => 'pcsx_rearmed',
			'aliases' => ['psx', 'ps1', 'psone', 'playstation', 'playstation1', 'sonyplaystation'],
			'bios' => ['scph1001.bin', 'scph5501.bin', 'scph5500.bin', 'scph5502.bin'],
		],
	];

	/**
	 * Extensions that may hold a ROM but do not say whose.
	 *
	 * A .bin is a Mega Drive game, a 32X game, a ColecoVision game, an
	 * Atari 2600 game, a track of a disc or a firmware blob, so it cannot
	 * be given to a system the way .md or .sfc can. The folder it sits in
	 * is asked first, and failing that the file itself: a cartridge says
	 * which machine it is for in its first bytes.
	 */
	public const AMBIGUOUS = ['bin', 'rom'];

	public static function isAmbiguous(string $extension): bool {
		return in_array(strtolower($extension), self::AMBIGUOUS, true);
	}

	/**
	 * Every extension worth listing in a library: the ones that name their
	 * system, and the ones that need asking about.
	 *
	 * @return array<string, string> extension => system id, or '' when it
	 *                               takes more than the name to know
	 */
	public static function libraryExtensions(): array {
		$extensions = self::extensionSystemMap();
		foreach ([...self::AMBIGUOUS, 'zip'] as $extension) {
			$extensions[$extension] ??= '';
		}
		return $extensions;
	}

	/**
	 * Words that say nothing about which system a folder holds.
	 */
	public const NOISE = [
		'rom', 'roms', 'game', 'games', 'iso', 'isos', 'collection', 'collections',
		'library', 'set', 'sets', 'cart', 'carts', 'cartridge', 'cartridges',
		'backup', 'backups', 'my', 'the', 'emulation', 'emulator', 'emulators',
		'nointro', 'redump', 'tosec', 'goodset', 'usa', 'europe', 'japan', 'world',
	];

	/**
	 * The makers, whose name in front of a system says nothing more than the
	 * system does. "Nintendo - Game Boy Advance" and "Nintendo Game Boy
	 * Advance" are the same shelf.
	 */
	public const VENDORS = [
		'nintendo', 'sega', 'snk', 'nec', 'atari', 'bandai', 'coleco', 'gce',
		'hudson', 'sony',
	];

	/**
	 * Detect the system from a folder name.
	 *
	 * Handles what people actually call these folders: short names ("SNES",
	 * "GBA"), spelled out names ("Super Nintendo", "Game Boy Advance"),
	 * No-Intro and Redump platform names whose dash-separated segments are
	 * matched one by one ("Nintendo - Game Boy Advance"), the same without
	 * the dashes ("Nintendo Game Boy Advance"), and any of those with a word
	 * like "ROMs" or "Games" hung off the end.
	 */
	public static function systemForFolderName(string $name): ?string {
		foreach (self::folderCandidates($name) as $candidate) {
			foreach (self::SYSTEMS as $id => $system) {
				if ($candidate === $id || in_array($candidate, $system['aliases'], true)) {
					return $id;
				}
			}
		}
		return null;
	}

	/**
	 * The normalized forms of a folder name worth looking up, in the order
	 * they are worth trying.
	 *
	 * @return list<string>
	 */
	private static function folderCandidates(string $name): array {
		$candidates = [];
		foreach ([$name, ...(preg_split('/\s*[-–_+]\s*/', $name) ?: [])] as $part) {
			$words = array_values(array_filter(
				preg_split('/[^a-z0-9]+/', strtolower($part)) ?: [],
				static fn (string $word): bool => $word !== '',
			));
			if ($words === []) {
				continue;
			}

			// Noise is only trimmed off the ends: "Game" in the middle of
			// "Nintendo Game Boy" is the system, not padding. The maker in
			// front goes too, so "Nintendo Game Boy" reads as "Game Boy".
			$trimmed = $words;
			while ($trimmed !== [] && in_array(end($trimmed), self::NOISE, true)) {
				array_pop($trimmed);
			}
			$lead = $trimmed;
			while (count($lead) > 1 && in_array($lead[0], self::NOISE, true)) {
				array_shift($lead);
			}

			foreach ([$words, $trimmed, $lead] as $form) {
				if ($form === []) {
					continue;
				}
				$candidates[] = implode('', $form);
				if (count($form) > 1 && in_array($form[0], self::VENDORS, true)) {
					$candidates[] = implode('', array_slice($form, 1));
				}
			}
		}
		return array_values(array_unique($candidates));
	}

	/**
	 * The system of a game at a path: from its extension, or from the
	 * folders it is stored in when the extension does not tell, as for
	 * zipped ROMs. Null when nothing says.
	 */
	public static function systemForPath(string $path): ?string {
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$system = self::extensionSystemMap()[$extension] ?? null;
		if ($system !== null) {
			return $system;
		}
		$folders = array_slice(explode('/', trim($path, '/')), 0, -1);
		foreach (array_reverse($folders) as $folder) {
			$fromFolder = self::systemForFolderName($folder);
			if ($fromFolder !== null) {
				return $fromFolder;
			}
		}
		return null;
	}

	/**
	 * What a system is called on screen: its compact name, which is the
	 * short one unless the short one is the folder's to keep rather than
	 * anything a player would recognise.
	 *
	 * Separate from 'short' because that one names a folder on disk --
	 * see shortNameForPath() -- and a folder cannot be renamed without
	 * taking what is already in it along.
	 */
	public static function displayName(string $system): string {
		$definition = self::SYSTEMS[$system] ?? null;
		if ($definition === null) {
			return $system;
		}
		return $definition['display'] ?? $definition['short'];
	}

	/**
	 * The short name of the system of a game, for the folder its saves and
	 * screenshots are filed under. Empty when the system is unknown, so
	 * that those stay where they always were.
	 */
	public static function shortNameForPath(string $path): string {
		$system = self::systemForPath($path);
		return $system === null ? '' : self::SYSTEMS[$system]['short'];
	}

	/**
	 * @return array<string, string> extension => mimetype
	 */
	public static function extensionMimeMap(): array {
		$map = [];
		foreach (self::SYSTEMS as $system) {
			foreach ($system['extensions'] as $extension) {
				$map[$extension] = $system['mime'];
			}
		}
		return $map;
	}

	/**
	 * @return array<string, string> extension => system id
	 */
	public static function extensionSystemMap(): array {
		$map = [];
		foreach (self::SYSTEMS as $id => $system) {
			foreach ($system['extensions'] as $extension) {
				$map[$extension] = $id;
			}
		}
		return $map;
	}

}
