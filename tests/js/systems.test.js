import { describe, expect, test, vi } from 'vitest'

// A small stand-in for the state lib/CoreMap.php serves: enough systems
// to exercise the lookups, with gb and gbc sharing a mimetype on purpose.
const fixture = vi.hoisted(() => ({
	systems: {
		nes: {
			label: 'Nintendo Entertainment System',
			short: 'NES',
			mime: 'application/x-nes-rom',
			extensions: ['nes'],
			core: 'fceumm',
			bios: ['disksys.rom'],
			aliases: ['famicom', 'nintendoentertainmentsystem'],
		},
		snes: {
			label: 'Super Nintendo Entertainment System',
			short: 'SNES',
			mime: 'application/vnd.nintendo.snes.rom',
			extensions: ['sfc', 'smc'],
			core: 'snes9x',
			bios: [],
			aliases: ['supernintendo', 'supernintendoentertainmentsystem'],
		},
		gb: {
			label: 'Game Boy',
			short: 'GB',
			mime: 'application/x-gameboy-rom',
			extensions: ['gb'],
			core: 'gambatte',
			bios: ['gb_bios.bin'],
			aliases: ['gameboy'],
		},
		gbc: {
			label: 'Game Boy Color',
			short: 'GBC',
			mime: 'application/x-gameboy-rom',
			extensions: ['gbc'],
			core: 'gambatte',
			bios: [],
			aliases: ['gameboycolor'],
		},
		ngp: {
			label: 'Neo Geo Pocket',
			short: 'Neo Geo Pocket',
			mime: 'application/x-neo-geo-pocket-rom',
			extensions: ['ngp', 'ngc'],
			core: 'mednafen_ngp',
			bios: [],
			aliases: ['neogeopocket'],
		},
		gba: {
			label: 'Game Boy Advance',
			short: 'Game Boy Advance',
			mime: 'application/x-gba-rom',
			extensions: ['gba'],
			core: 'mgba',
			bios: ['gba_bios.bin'],
			aliases: ['gameboyadvance'],
		},
	},
	folderWords: {
		noise: ['games', 'roms'],
		vendors: ['nintendo'],
	},
}))

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => fixture[key] ?? fallback,
}))

const { biosForSystem, romMimes, systemForFolderPath, systemFromBytes } = await import('../../src/systems.js')

describe('romMimes', () => {
	test('lists every mimetype once', () => {
		expect(romMimes().sort()).toEqual([
			'application/vnd.nintendo.snes.rom',
			'application/x-gameboy-rom',
			'application/x-gba-rom',
			'application/x-neo-geo-pocket-rom',
			'application/x-nes-rom',
		])
	})
})

describe('biosForSystem', () => {
	test('hands over the files a system asks for', () => {
		expect(biosForSystem('nes')).toEqual(['disksys.rom'])
	})

	test('hands over nothing for a system that needs none', () => {
		expect(biosForSystem('snes')).toEqual([])
	})

	test('hands over nothing for a system it never heard of', () => {
		expect(biosForSystem('neogeo')).toEqual([])
	})
})

describe('systemForFolderPath', () => {
	test('reads the system straight off a folder name', () => {
		expect(systemForFolderPath('/Games/SNES/NHL 96.zip')?.id).toBe('snes')
	})

	test('follows a No-Intro platform name through its dash', () => {
		expect(systemForFolderPath('/ROMs/Nintendo - Super Nintendo Entertainment System/game.sfc')?.id).toBe('snes')
	})

	test('sees past a vendor prefix', () => {
		expect(systemForFolderPath('/Roms/Nintendo NES/game.zip')?.id).toBe('nes')
	})

	test('trims noise words off the end', () => {
		expect(systemForFolderPath('/Emulation/SNES Games/game.zip')?.id).toBe('snes')
	})

	test('lets the deepest folder speak first', () => {
		expect(systemForFolderPath('/NES/GB/game.zip')?.id).toBe('gb')
	})

	test('stays quiet when no folder names a system', () => {
		expect(systemForFolderPath('/Documents/notes.txt')).toBeNull()
	})
})

// The server reads the same marks in RomHeader::systemOf, for the library
// listing, and the two have to agree: a system only the server knows is a
// game the library offers and the player then refuses to launch.
describe('systemFromBytes', () => {
	const rom = (size = 64) => new Uint8Array(size)

	const withSnesHeader = (offset) => {
		const bytes = rom(offset + 32)
		// The complement and the checksum are written to cancel out, which
		// is what says a header is really there rather than nearby bytes
		// happening to read like one.
		bytes[offset + 0x1C] = 0x34
		bytes[offset + 0x1D] = 0x12
		bytes[offset + 0x1E] = 0xCB
		bytes[offset + 0x1F] = 0xED
		return bytes
	}

	test('reads the mark of a Neo Geo Pocket cartridge', () => {
		const bytes = rom(64)
		for (const [i, c] of [...'COPYRIGHT BY SNK CORPORATION'].entries()) {
			bytes[i] = c.charCodeAt(0)
		}
		expect(systemFromBytes(bytes)?.id).toBe('ngp')
	})

	test('takes the third-party licence line too', () => {
		const bytes = rom(64)
		for (const [i, c] of [...'LICENSED BY SNK CORPORATION'].entries()) {
			bytes[i] = c.charCodeAt(0)
		}
		expect(systemFromBytes(bytes)?.id).toBe('ngp')
	})

	test('reads a Super Nintendo header where the cartridge maps it low', () => {
		expect(systemFromBytes(withSnesHeader(0x7FC0))?.id).toBe('snes')
	})

	test('reads one where the cartridge maps it high', () => {
		expect(systemFromBytes(withSnesHeader(0xFFC0))?.id).toBe('snes')
	})

	test('reads one a copier pushed along by its own 512 bytes', () => {
		expect(systemFromBytes(withSnesHeader(0x7FC0 + 0x200))?.id).toBe('snes')
	})

	test('leaves a file of nothing unclaimed', () => {
		// Zero against zero cancels to zero, not to 0xFFFF: the sum has to
		// be earned. This is the check that keeps it from claiming anything
		// large enough to reach the offset.
		expect(systemFromBytes(rom(0x10000))).toBeNull()
	})

	test('answers a mark before a sum', () => {
		// A Game Boy Advance dump long enough to reach the Super Nintendo
		// offsets could carry a sum that happens to cancel. The mark wins.
		const bytes = withSnesHeader(0x7FC0)
		bytes[0x04] = 0x24
		bytes[0x05] = 0xFF
		bytes[0x06] = 0xAE
		bytes[0x07] = 0x51
		expect(systemFromBytes(bytes)?.id).toBe('gba')
	})
})
