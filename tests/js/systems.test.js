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
	},
	folderWords: {
		noise: ['games', 'roms'],
		vendors: ['nintendo'],
	},
}))

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => fixture[key] ?? fallback,
}))

const { biosForSystem, romMimes, systemForFolderPath } = await import('../../src/systems.js')

describe('romMimes', () => {
	test('lists every mimetype once', () => {
		expect(romMimes().sort()).toEqual([
			'application/vnd.nintendo.snes.rom',
			'application/x-gameboy-rom',
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
