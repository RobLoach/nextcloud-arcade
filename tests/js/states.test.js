// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'

// The panel builds real elements and reads them back, so it needs a DOM.
// Everything it talks to beyond that is stood in for: the server, the
// battery save sync, and the emulator itself.

const api = vi.hoisted(() => vi.fn())
vi.mock('../../src/api.js', () => ({
	AUTO_SLOT: 0,
	api,
	deadline: () => undefined,
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

const { createStatesPanel } = await import('../../src/panels/states.js')

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
})
