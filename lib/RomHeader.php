<?php

declare(strict_types=1);

namespace OCA\Arcade;

/**
 * The name a game calls itself.
 *
 * Cartridges carry a header with the title the console shows, the region it
 * was sold in, and a few bytes that say which machine it is for. That is
 * worth having for a file named "rom1.gb": the title is what box art is
 * filed under, and the file name may be anything at all.
 *
 * Everything here works on a prefix of the file, and nothing here trusts
 * what it reads: a header that does not check out is no header.
 */
class RomHeader {
	/** Enough for the headers of every system below, SNES HiROM included. */
	public const BYTES = 0x10200;

	/** The Virtual Boy writes its header at the end of the file instead. */
	public const TAIL_BYTES = 0x220;

	/** The systems whose files carry something worth opening them for. */
	private const READABLE = ['gb', 'gbc', 'gba', 'snes', 'genesis', 'lynx', 'ngp', 'nes', 'vb'];

	/** How every Neo Geo Pocket cartridge opens, first or third party. */
	private const SNK_LICENSES = [
		'COPYRIGHT BY SNK CORPORATION',
		'LICENSED BY SNK CORPORATION',
	];

	/**
	 * Whether reading the front of this system's files is worth the read.
	 */
	public static function handles(string $system): bool {
		return in_array($system, self::READABLE, true);
	}

	/**
	 * Which machine a file is for, going by what is written in it.
	 *
	 * This is what a .bin is put to: the name says nothing, but a cartridge
	 * dump carries a mark near its front saying whose it is. Nothing here
	 * guesses -- a file that carries no mark gets no answer.
	 *
	 * @return string|null the system id, or null when the file does not say
	 */
	public static function systemOf(string $data): ?string {
		if (str_starts_with($data, "NES\x1a")) {
			return 'nes';
		}
		if (str_starts_with($data, 'LYNX')) {
			return 'lynx';
		}
		// A Neo Geo Pocket cartridge opens with SNK's licence line, first
		// party or third; either way the line is the mark.
		if (in_array(trim(substr($data, 0, 28)), self::SNK_LICENSES, true)) {
			return 'ngp';
		}
		// A ColecoVision cartridge starts with one of two marks, depending
		// on whether it shows the title screen on the way in.
		if (str_starts_with($data, "\xAA\x55") || str_starts_with($data, "\x55\xAA")) {
			return 'coleco';
		}
		if (substr($data, 0x100, 4) === 'SEGA') {
			// Both are Sega cartridges; the console they name is the
			// difference. The 32X is not one of them any more, and a 32X
			// game will not run without the 32X -- so it is left unclaimed
			// rather than offered as the Mega Drive game it is not.
			return str_contains(substr($data, 0x100, 16), '32X') ? null : 'genesis';
		}
		if (substr($data, 0x104, 4) === "\xCE\xED\x66\x66") {
			// The colour flag of the cartridge, at the end of its title.
			return in_array(ord(substr($data, 0x143, 1) ?: "\x00"), [0x80, 0xC0], true) ? 'gbc' : 'gb';
		}
		if (substr($data, 0x04, 4) === "\x24\xFF\xAE\x51") {
			return 'gba';
		}
		// Last, being the only one of these that is a sum rather than a
		// mark, and so the only one that could come out right by chance.
		return self::superNintendo($data) === null ? null : 'snes';
	}

	/**
	 * @return array{title: string, region: string} empty strings when the
	 *                                              file does not say
	 */
	public static function read(string $data, string $system): array {
		$found = match ($system) {
			'gb', 'gbc' => self::gameBoy($data),
			'gba' => self::gameBoyAdvance($data),
			'snes' => self::superNintendo($data),
			'genesis' => self::megaDrive($data),
			'lynx' => self::lynx($data),
			'ngp' => self::neoGeoPocket($data),
			default => null,
		};
		return $found ?? ['title' => '', 'region' => ''];
	}

	/**
	 * The same, for the one system that writes its header at the end of the
	 * file. $tail is the last TAIL_BYTES of the file, not its front.
	 *
	 * @return array{title: string, region: string} empty strings when the
	 *                                              file does not say
	 */
	public static function readTail(string $tail, string $system): array {
		$found = match ($system) {
			'vb' => self::virtualBoy($tail),
			default => null,
		};
		return $found ?? ['title' => '', 'region' => ''];
	}

	/**
	 * Which cartridge board an iNES file says it needs. There is no title in
	 * an iNES header, but the mapper number is the next best thing to know
	 * about a NES file.
	 *
	 * The number is spread over the high nibbles of bytes 6 and 7, and a
	 * NES 2.0 header, told apart by bits 2-3 of byte 7, keeps a third
	 * nibble in byte 8.
	 *
	 * @return string|null the mapper number, or null when this is not iNES
	 */
	public static function nesMapper(string $data): ?string {
		if (!str_starts_with($data, "NES\x1a") || strlen($data) < 16) {
			return null;
		}
		$mapper = (ord($data[6]) >> 4) | (ord($data[7]) & 0xF0);
		if ((ord($data[7]) & 0x0C) === 0x08) {
			$mapper |= (ord($data[8]) & 0x0F) << 8;
		}
		return (string)$mapper;
	}

	/**
	 * The title sits right after the logo every cartridge has to carry, so
	 * the logo is what says the header is really there.
	 *
	 * @return array{title: string, region: string}|null
	 */
	private static function gameBoy(string $data): ?array {
		if (substr($data, 0x104, 4) !== "\xCE\xED\x66\x66") {
			return null;
		}
		return ['title' => self::text(substr($data, 0x134, 15)), 'region' => ''];
	}

	/**
	 * @return array{title: string, region: string}|null
	 */
	private static function gameBoyAdvance(string $data): ?array {
		if (substr($data, 0x04, 4) !== "\x24\xFF\xAE\x51") {
			return null;
		}
		return ['title' => self::text(substr($data, 0xA0, 12)), 'region' => ''];
	}

	/**
	 * The header is at one of two places, depending on how the cartridge
	 * maps its memory, and either may be pushed along by the 512 bytes a
	 * copier put in front. The checksum and its complement tell which one
	 * is the real header.
	 *
	 * @return array{title: string, region: string}|null
	 */
	private static function superNintendo(string $data): ?array {
		foreach ([0x7FC0, 0xFFC0, 0x7FC0 + 0x200, 0xFFC0 + 0x200] as $offset) {
			$header = substr($data, $offset, 32);
			if (strlen($header) < 32) {
				continue;
			}
			$complement = unpack('v', substr($header, 0x1C, 2));
			$checksum = unpack('v', substr($header, 0x1E, 2));
			if ($complement === false || $checksum === false) {
				continue;
			}
			if ((($complement[1] ^ $checksum[1]) & 0xFFFF) !== 0xFFFF) {
				continue;
			}
			return [
				'title' => self::text(substr($header, 0, 21)),
				'region' => self::snesRegion(ord($header[0x19])),
			];
		}
		return null;
	}

	/**
	 * Mega Drive cartridges carry two names, the one at home and the one
	 * abroad. The one abroad is the one box art is usually filed under.
	 *
	 * @return array{title: string, region: string}|null
	 */
	private static function megaDrive(string $data): ?array {
		if (!str_starts_with(substr($data, 0x100, 4), 'SEGA')) {
			return null;
		}
		$international = self::text(substr($data, 0x150, 48));
		$domestic = self::text(substr($data, 0x120, 48));
		return [
			'title' => $international !== '' ? $international : $domestic,
			'region' => self::megaDriveRegion(self::text(substr($data, 0x1F0, 3))),
		];
	}

	/**
	 * A Lynx file that keeps the 64-byte LNX header opens with its magic and
	 * carries the cartridge's name at 10. Dumps without the header exist,
	 * and those say nothing at all. The header names no region.
	 *
	 * @return array{title: string, region: string}|null
	 */
	private static function lynx(string $data): ?array {
		if (!str_starts_with($data, 'LYNX')) {
			return null;
		}
		return ['title' => self::text(substr($data, 10, 32)), 'region' => ''];
	}

	/**
	 * SNK's licence line is the mark, and the title follows at 0x24. The
	 * header names no region -- the console was sold the same everywhere.
	 *
	 * @return array{title: string, region: string}|null
	 */
	private static function neoGeoPocket(string $data): ?array {
		if (!in_array(trim(substr($data, 0, 28)), self::SNK_LICENSES, true)) {
			return null;
		}
		return ['title' => self::text(substr($data, 0x24, 12)), 'region' => ''];
	}

	/**
	 * The Virtual Boy header sits in the last 544 bytes of the file, title
	 * first. The title may be Shift-JIS; a Japanese one is left unread
	 * rather than mangled, since only plain ASCII is trusted here.
	 *
	 * @return array{title: string, region: string}|null
	 */
	private static function virtualBoy(string $tail): ?array {
		if (strlen($tail) < self::TAIL_BYTES) {
			return null;
		}
		return ['title' => self::text(substr($tail, 0, 20)), 'region' => ''];
	}

	/**
	 * Mega Drive cartridges name their regions by letter, as many as they
	 * were sold in. Said the way a picture of one is filed: all three is
	 * the world.
	 */
	private static function megaDriveRegion(string $letters): string {
		$letters = strtoupper($letters);
		$regions = ['J' => 'Japan', 'U' => 'USA', 'E' => 'Europe'];
		$found = array_values(array_filter(
			$regions,
			static fn (string $letter): bool => str_contains($letters, $letter),
			ARRAY_FILTER_USE_KEY,
		));
		if (count($found) === count($regions)) {
			return 'World';
		}
		return $found[0] ?? '';
	}

	/**
	 * The countries a Super Nintendo cartridge was sold in, as the handful
	 * of codes that matter for a name.
	 */
	private static function snesRegion(int $code): string {
		return match ($code) {
			0x00 => 'Japan',
			0x01 => 'USA',
			0x02 => 'Europe',
			0x03 => 'Sweden',
			0x06 => 'France',
			0x07 => 'Netherlands',
			0x08 => 'Spain',
			0x09 => 'Germany',
			0x0A => 'Italy',
			0x0B => 'China',
			0x0D => 'Korea',
			0x0F => 'Canada',
			0x10 => 'Brazil',
			0x11 => 'Australia',
			default => '',
		};
	}

	/**
	 * Header text is padded with spaces or with nothing at all, and is only
	 * ever plain ASCII. Anything else means this is not a header.
	 */
	private static function text(string $raw): string {
		$text = '';
		foreach (str_split($raw) as $byte) {
			$code = ord($byte);
			if ($code === 0x00) {
				break;
			}
			if ($code < 0x20 || $code > 0x7E) {
				return '';
			}
			$text .= $byte;
		}
		return trim($text);
	}
}
