// @vitest-environment happy-dom
import { describe, expect, test, vi } from 'vitest'

const state = {
	libraryFolder: '/Games',
	systems: {
		snes: { label: 'Super Nintendo', short: 'SNES', mime: 'application/vnd.nintendo.snes.rom', extensions: ['sfc', 'smc'], core: 'snes9x', bios: [], aliases: ['snes', 'supernintendo'] },
		genesis: { label: 'Genesis', short: 'Genesis', mime: 'application/x-genesis-rom', extensions: ['md', 'gen'], core: 'genesis_plus_gx', bios: [], aliases: ['genesis', 'megadrive'] },
	},
	ambiguous: ['bin', 'rom'],
	folderWords: { noise: ['games', 'roms'], vendors: ['nintendo', 'sega'] },
}

vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => state[key] ?? fallback }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, one) => one }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const { isGame } = await import('../../src/files.js')

describe('which files the Arcade tab is for', () => {
	test('a ROM, by its type', () => {
		expect(isGame({ path: '/Downloads/chrono.sfc', mime: 'application/vnd.nintendo.snes.rom' })).toBe(true)
	})

	test('a zip whose folder names a system', () => {
		expect(isGame({ path: '/Stuff/SNES/chrono.zip', mime: 'application/zip' })).toBe(true)
	})

	test('a zip in the games library, wherever in it', () => {
		expect(isGame({ path: '/Games/Packs/chrono.zip', mime: 'application/zip' })).toBe(true)
		expect(isGame({ path: '/Games/chrono.bin', mime: 'application/octet-stream' })).toBe(true)
	})

	test('not a zip anywhere else', () => {
		expect(isGame({ path: '/Downloads/photos.zip', mime: 'application/zip' })).toBe(false)
		// Nor one in a folder that only starts like the library.
		expect(isGame({ path: '/Games Backup/photos.zip', mime: 'application/zip' })).toBe(false)
	})

	test('not a README in the library, though .md is a Mega Drive extension', () => {
		expect(isGame({ path: '/Games/README.md', mime: 'text/markdown' })).toBe(false)
	})
})
