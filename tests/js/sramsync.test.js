// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'

const api = vi.hoisted(() => vi.fn(async () => ({ json: async () => ({}), blob: async () => new Blob([]) })))
vi.mock('nostalgist', () => ({ Nostalgist: {} }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))
vi.mock('@nextcloud/files/dav', () => ({ defaultRemoteURL: '', defaultRootPath: '' }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path, generateFilePath: (path) => path }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))
vi.mock('../../src/api.js', () => ({
	api,
	asUpload: (blob) => blob,
	dav: vi.fn(),
	deadline: () => undefined,
	encodePath: (path) => path,
	stateUrl: (route) => route,
	UPLOAD_WAIT: 1000,
	useCore: vi.fn(),
}))

const { startSramSync } = await import('../../src/player.js')

/**
 * A core whose battery save is whatever the test says it has written
 * (null: nothing), written when RetroArch is told to -- or, without the
 * command, only through Nostalgist.
 */
const core = ({ command = true } = {}) => {
	const game = { saved: null }
	const files = new Map()
	const fs = {
		unlink: (path) => files.delete(path),
		stat: (path) => {
			if (!files.has(path)) {
				throw new Error('ENOENT')
			}
			return { size: files.get(path).length }
		},
		readFile: (path) => files.get(path),
	}
	const module = command
		? { _cmd_savefiles: () => { if (game.saved !== null) files.set('/sram', game.saved) } }
		: {}
	const instance = {
		getEmscriptenFS: () => fs,
		getEmulator: () => ({ sramFilePath: '/sram' }),
		getEmscriptenModule: () => module,
		saveSRAM: vi.fn(async () => (game.saved === null ? null : new Blob([game.saved]))),
	}
	game.instance = instance
	game.exclusive = (work) => work(instance)
	return game
}

const uploads = () => api.mock.calls.filter(([, options]) => options?.method === 'POST')

beforeEach(() => {
	vi.useFakeTimers()
	api.mockClear()
	Object.defineProperty(document, 'hidden', { value: false, configurable: true })
})

afterEach(() => {
	vi.useRealTimers()
})

describe('the battery save of a game', () => {
	test('is still sent on the way out after two looks found none', async () => {
		const game = core()
		const stop = startSramSync(game, '/Games/Zelda.gb')
		// Two looks a minute apart, with nothing written yet.
		await vi.advanceTimersByTimeAsync(2 * 60 * 1000 + 5000)
		// The game writes its first save, and is closed.
		game.saved = new Uint8Array([1, 2, 3])
		const stopping = stop()
		await vi.advanceTimersByTimeAsync(5000)
		await stopping
		expect(uploads()).toHaveLength(1)
	})

	test('looks taken while the game is hidden do not count against it', async () => {
		Object.defineProperty(document, 'hidden', { value: true, configurable: true })
		const game = core()
		startSramSync(game, '/Games/Link.gb')
		await vi.advanceTimersByTimeAsync(3 * 60 * 1000 + 5000)
		Object.defineProperty(document, 'hidden', { value: false, configurable: true })
		game.saved = new Uint8Array([4, 5, 6])
		await vi.advanceTimersByTimeAsync(60 * 1000 + 5000)
		expect(uploads()).toHaveLength(1)
	})

	test('is read the slow way round when the core has no command to write it', async () => {
		const game = core({ command: false })
		game.saved = new Uint8Array([7, 8, 9])
		const stop = startSramSync(game, '/Games/Metroid.gba')
		const stopping = stop()
		await vi.advanceTimersByTimeAsync(5000)
		await stopping
		expect(game.instance.saveSRAM).toHaveBeenCalled()
		expect(uploads()).toHaveLength(1)
	})
})
