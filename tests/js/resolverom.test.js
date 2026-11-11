// @vitest-environment happy-dom
import { describe, expect, test, vi } from 'vitest'
import { zipSync } from 'fflate'

vi.mock('nostalgist', () => ({ Nostalgist: {} }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))
vi.mock('@nextcloud/files/dav', () => ({ defaultRemoteURL: '', defaultRootPath: '' }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path, generateFilePath: (path) => path }))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => (key === 'systems'
		? {
			snes: { label: 'SNES', extensions: ['sfc'], core: 'snes9x', bios: [] },
			gb: { label: 'Game Boy', extensions: ['gb'], core: 'gambatte', bios: [] },
		}
		: fallback),
}))

const { resolveRom } = await import('../../src/player.js')
const { HEADER_BYTES } = await import('../../src/systems.js')

// A Super Nintendo header in the last place one can be: high-mapped, and
// pushed along by a copier's 512 bytes.
const snesDump = (size) => {
	const bytes = new Uint8Array(size)
	const offset = 0xFFC0 + 0x200
	bytes[offset + 0x1C] = 0x34
	bytes[offset + 0x1D] = 0x12
	bytes[offset + 0x1E] = 0xCB
	bytes[offset + 0x1F] = 0xED
	return bytes
}

describe('a game that is not in an archive', () => {
	test('is told by its header, reading only the front of it', async () => {
		const blob = new Blob([snesDump(4 * 1024 * 1024)])
		const whole = vi.spyOn(blob, 'arrayBuffer')
		const slice = vi.spyOn(blob, 'slice')
		const { rom, system } = await resolveRom(blob, 'Game.bin', null)
		expect(system?.id).toBe('snes')
		expect(whole).not.toHaveBeenCalled()
		expect(slice).toHaveBeenCalledWith(0, HEADER_BYTES)
		expect(rom.name).toBe('Game.bin')
		expect(rom.size).toBe(blob.size)
	})

	test('reads far enough for every header it could carry', () => {
		expect(HEADER_BYTES).toBeGreaterThanOrEqual(0xFFC0 + 0x200 + 32)
	})

	test('is handed on as it is when it already is the file', async () => {
		const file = new File([new Uint8Array(16)], 'Tetris.gb')
		const { rom, system } = await resolveRom(file, 'Tetris.gb', null)
		expect(rom).toBe(file)
		expect(system?.id).toBe('gb')
	})

	test('falls back on the folder when it carries no mark', async () => {
		const hint = { id: 'gb' }
		const { system } = await resolveRom(new Blob([new Uint8Array(32)]), 'Game.bin', hint)
		expect(system).toBe(hint)
	})
})

describe('a game in an archive', () => {
	test('is the entry its name says is a game', async () => {
		const zip = zipSync({ 'readme.txt': new Uint8Array([1]), 'dir/Tetris.gb': new Uint8Array([2, 3]) })
		const { rom, system } = await resolveRom(new Blob([zip]), 'Tetris.zip', null)
		expect(system?.id).toBe('gb')
		expect(rom.name).toBe('Tetris.gb')
		expect(new Uint8Array(await rom.arrayBuffer())).toEqual(new Uint8Array([2, 3]))
	})

	test('is otherwise the largest entry, told by its header', async () => {
		const zip = zipSync({ 'a.txt': new Uint8Array([1]), 'Game.bin': snesDump(0x10400) })
		const { rom, system } = await resolveRom(new Blob([zip]), 'Game.zip', null)
		expect(system?.id).toBe('snes')
		expect(rom.name).toBe('Game.bin')
	})
})
