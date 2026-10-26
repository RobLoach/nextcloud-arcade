// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'

// The panel builds real elements and reads them back, so it needs a DOM.
// Everything it talks to beyond that is stood in for: the server, the
// battery save sync, and the emulator itself.

const api = vi.hoisted(() => vi.fn())
vi.mock('../../src/api.js', () => ({
	AUTO_SLOT: -1,
	api,
	deadline: () => new AbortController().signal,
	stateUrl: (route, romPath, slot = null) => `${route}?file=${romPath}&slot=${slot}`,
	UPLOAD_WAIT: 1000,
	wasGivenUpOn: (error) => error?.name === 'TimeoutError',
}))
vi.mock('../../src/player.js', () => ({
	disableSramSync: vi.fn(),
	enableSramSync: vi.fn(),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, params = {}) =>
		text.replace(/\{(\w+)\}/g, (whole, key) => params[key] ?? whole),
}))

const { createStatesPanel, uploadFailed } = await import('../../src/panels/states.js')
const { createCoreLog } = await import('../../src/corelog.js')

// A core that answers when the test says so, so one call can be held open
// while another is attempted.
const heldCore = () => {
	const calls = []
	const open = (name) => (...args) => new Promise((resolve, reject) => {
		calls.push({ name, args, resolve, reject })
	})
	return {
		calls,
		instance: {
			saveState: open('saveState'),
			loadState: open('loadState'),
			screenshot: open('screenshot'),
		},
	}
}

const panelWith = (instance, flash = vi.fn()) => ({
	panel: createStatesPanel({ instance, romPath: '/Games/Mario.nes', flash, onDone: vi.fn() }),
	flash,
})

/** The listing the panel asks for before it draws any slot. */
const listing = (states = []) => ({
	json: async () => ({ slots: 3, states, hasSram: false }),
	blob: async () => new Blob(['a state']),
})

beforeEach(() => {
	vi.useFakeTimers()
	api.mockReset()
	api.mockResolvedValue(listing())
})

afterEach(() => {
	vi.useRealTimers()
})

describe('the save states panel', () => {
	test('draws a slot for each the server offers', async () => {
		const { panel } = panelWith(heldCore().instance)

		await panel.refresh()

		const labels = [...panel.element.querySelectorAll('.arcade-states-label')]
			.map((node) => node.textContent)
		// Three slots and the battery save beneath them.
		expect(labels.length).toBe(4)
		expect(labels[0]).toContain('Slot 1')
	})

	test('holds every slot while one is being written, not just its own', async () => {
		// Pressing Save on another slot used to reach a gate already shut
		// and be answered with nothing at all: the click did nothing and
		// said nothing.
		const { calls, instance } = heldCore()
		const { panel } = panelWith(instance)
		await panel.refresh()

		const saves = [...panel.element.querySelectorAll('button')]
			.filter((button) => button.textContent === 'Save')
		expect(saves.every((button) => !button.disabled)).toBe(true)

		saves[0].click()
		await vi.advanceTimersByTimeAsync(0)

		expect(calls).toHaveLength(1)
		expect(saves.every((button) => button.disabled)).toBe(true)
	})

	test('lets go of the slots once the save is through', async () => {
		const { calls, instance } = heldCore()
		const { panel } = panelWith(instance)
		await panel.refresh()
		const saves = () => [...panel.element.querySelectorAll('button')]
			.filter((button) => button.textContent === 'Save')

		saves()[0].click()
		await vi.advanceTimersByTimeAsync(0)
		calls[0].resolve({ state: new Blob(['bytes']), thumbnail: undefined })
		await vi.advanceTimersByTimeAsync(0)
		// The screenshot the panel falls back to for a picture.
		calls[1]?.resolve(new Blob(['png']))
		await vi.advanceTimersByTimeAsync(0)

		expect(saves().some((button) => button.disabled)).toBe(false)
	})

	test('gives up on a core that never answers, and says which it was', async () => {
		// The gate used to be held by the unfinished call for the rest of
		// the session, with every slot shut and nothing on screen saying so.
		const { instance } = heldCore()
		const { panel, flash } = panelWith(instance)
		await panel.refresh()

		const saves = () => [...panel.element.querySelectorAll('button')]
			.filter((button) => button.textContent === 'Save')
		saves()[0].click()
		await vi.advanceTimersByTimeAsync(0)
		expect(saves().every((button) => button.disabled)).toBe(true)

		await vi.advanceTimersByTimeAsync(10 * 1000)

		expect(flash).toHaveBeenCalledWith(
			expect.stringContaining('did not hand over a save state'), 'error')
		expect(saves().some((button) => button.disabled)).toBe(false)
	})

	test('refuses to load a slot holding nothing', async () => {
		// Handed an empty file, the core waits for a state that never comes.
		const { instance } = heldCore()
		const { panel, flash } = panelWith(instance)
		api.mockResolvedValue({
			json: async () => ({ slots: 3, states: [{ slot: 1, mtime: 1, size: 0 }], hasSram: false }),
			blob: async () => new Blob([]),
		})
		await panel.refresh()

		const load = [...panel.element.querySelectorAll('button')]
			.find((button) => button.textContent === 'Load' && !button.disabled)
		load.click()
		await vi.advanceTimersByTimeAsync(0)

		expect(flash).toHaveBeenCalledWith(expect.stringContaining('empty'), 'warning')
	})

	test('says so when the listing cannot be read, and offers to try again', async () => {
		const { panel } = panelWith(heldCore().instance)
		api.mockRejectedValue(new Error('500 Server Error'))
		vi.spyOn(console, 'error').mockImplementation(() => {})

		await panel.refresh()

		expect(panel.element.textContent).toContain('Could not read the save states')
		expect([...panel.element.querySelectorAll('button')]
			.some((button) => button.textContent === 'Retry')).toBe(true)
	})

	test('says a save is made as soon as the core hands it over, not once it is uploaded', async () => {
		const { calls, instance } = heldCore()
		const { panel, flash } = panelWith(instance)
		await panel.refresh()
		// An upload that never finishes.
		api.mockImplementation((url, options = {}) => (options.method === 'POST'
			? new Promise(() => {})
			: Promise.resolve(listing())))

		panel.save(2)
		await vi.advanceTimersByTimeAsync(0)
		calls[0].resolve({ state: new Blob(['bytes']), thumbnail: new Blob(['png']) })
		await vi.advanceTimersByTimeAsync(0)

		expect(flash).toHaveBeenCalledWith('State saved to slot 2', 'success')
		// And it loads from what is held, without asking the server.
		const asked = api.mock.calls.length
		panel.load(2)
		await vi.advanceTimersByTimeAsync(0)
		expect(calls[1].name).toBe('loadState')
		expect(api.mock.calls.length).toBe(asked)
		// Seen through, so it stops listening to the core.
		calls[1].resolve()
		await vi.advanceTimersByTimeAsync(1000)
	})

	test('lists the slots RetroArch saved into beyond the ones offered', async () => {
		const { panel } = panelWith(heldCore().instance)
		api.mockResolvedValue({
			json: async () => ({
				slots: 3,
				states: [{ slot: 0, mtime: 1, size: 5 }, { slot: 990, mtime: 1, size: 5 }],
				hasSram: false,
			}),
		})

		await panel.refresh()

		const labels = [...panel.element.querySelectorAll('.arcade-states-label')]
			.map((node) => node.textContent)
		expect(labels[0]).toContain('Slot 1')
		expect(labels[3]).toContain('Slot 0')
		expect(labels[4]).toContain('Slot 990')
	})

	test('says in words of its own when the core turns a state down', async () => {
		const { calls, instance } = heldCore()
		const { panel, flash } = panelWith(instance)
		api.mockResolvedValue({
			json: async () => ({ slots: 3, states: [{ slot: 990, mtime: 1, size: 5 }], hasSram: false }),
			blob: async () => new Blob(['from another build']),
		})
		await panel.refresh()
		// What the player would otherwise be shown, raw.
		const shown = vi.fn()
		const coreSays = createCoreLog(shown)

		panel.load(990)
		await vi.advanceTimersByTimeAsync(0)
		calls[0].resolve()
		await vi.advanceTimersByTimeAsync(0)
		coreSays('[ERROR] [State] Failed to load state from "/home/web_user/retroarch/userdata/states/Mario.state".')
		// Well short of the wait: the refusal is what ends it.
		await vi.advanceTimersByTimeAsync(10)

		expect(flash).toHaveBeenCalledWith(expect.stringContaining('would not load'), 'error')
		expect(flash).not.toHaveBeenCalledWith(expect.stringContaining('loaded'), 'success')
		expect(shown).not.toHaveBeenCalled()
	})

	test('says a state loaded once the core has had a moment to turn it down', async () => {
		const { calls, instance } = heldCore()
		const { panel, flash } = panelWith(instance)
		api.mockResolvedValue({
			json: async () => ({ slots: 3, states: [{ slot: 1, mtime: 1, size: 5 }], hasSram: false }),
			blob: async () => new Blob(['a state']),
		})
		await panel.refresh()

		panel.load(1)
		await vi.advanceTimersByTimeAsync(0)
		calls[0].resolve()
		await vi.advanceTimersByTimeAsync(0)
		expect(flash).not.toHaveBeenCalled()

		await vi.advanceTimersByTimeAsync(750)
		expect(flash).toHaveBeenCalledWith('State loaded from slot 1', 'success')
	})

	test('says why a save could not be sent, and where to repair a broken database', () => {
		const flash = vi.fn()
		vi.spyOn(console, 'error').mockImplementation(() => {})
		const told = uploadFailed(flash)

		told(1, Object.assign(new Error('500'), { status: 500, reason: null }))
		told(1, new TypeError('Failed to fetch'))
		told(1, Object.assign(new Error('503'), { status: 503, reason: 'database' }))

		expect(flash.mock.calls[0][0]).toContain('the server answered 500')
		expect(flash.mock.calls[1][0]).toContain('no answer from the server')
		expect(flash.mock.calls[2][0]).toContain('Administration settings → Arcade → Maintenance')
	})

	test('says the database needs repairing when the slots cannot be listed for it', async () => {
		const { panel } = panelWith(heldCore().instance)
		api.mockRejectedValue(Object.assign(new Error('503'), { status: 503, reason: 'database' }))
		vi.spyOn(console, 'error').mockImplementation(() => {})

		await panel.refresh()

		expect(panel.element.textContent).toContain('Arcade database is repaired')
	})
})
