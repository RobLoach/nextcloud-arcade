import { describe, expect, test, vi } from 'vitest'
import { oneAtATime } from '../../src/corequeue.js'

// A core that answers when it is told to, so a test can hold one call
// open and see what the next one does.
const held = () => {
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
			saveSRAM: open('saveSRAM'),
			pause: vi.fn(() => 'paused'),
			sendCommand: vi.fn((command) => command),
			slots: 3,
		},
	}
}

describe('oneAtATime', () => {
	test('lets the first call through with nothing to wait for', async () => {
		const { calls, instance } = held()

		oneAtATime(instance).saveState()
		await Promise.resolve()

		expect(calls).toHaveLength(1)
	})

	test('holds the second until the first has answered', async () => {
		const { calls, instance } = held()
		const core = oneAtATime(instance)

		core.saveState()
		core.screenshot()
		await Promise.resolve()

		// The screenshot belongs to a different part of the player, and
		// used to reach the core while the state was still being written.
		expect(calls).toHaveLength(1)
		calls[0].resolve('a state')
		await vi.waitFor(() => expect(calls).toHaveLength(2))
		expect(calls[1].name).toBe('screenshot')
	})

	test('queues across every call that touches the core file system', async () => {
		const { calls, instance } = held()
		const core = oneAtATime(instance)

		core.saveSRAM()
		core.loadState('a state')
		await Promise.resolve()

		expect(calls.map((call) => call.name)).toEqual(['saveSRAM'])
		calls[0].resolve()
		await vi.waitFor(() => expect(calls).toHaveLength(2))
		expect(calls[1].name).toBe('loadState')
	})

	test('a call that failed does not take the queue with it', async () => {
		const { calls, instance } = held()
		const core = oneAtATime(instance)

		const failing = core.saveState()
		const after = core.screenshot()
		await Promise.resolve()

		calls[0].reject(new Error('the core would not'))
		await expect(failing).rejects.toThrow('the core would not')
		await vi.waitFor(() => expect(calls).toHaveLength(2))
		calls[1].resolve('a picture')
		await expect(after).resolves.toBe('a picture')
	})

	test('hands back what the call answered', async () => {
		const { calls, instance } = held()

		const saving = oneAtATime(instance).saveState()
		await vi.waitFor(() => expect(calls).toHaveLength(1))
		calls[0].resolve({ state: 'bytes' })

		await expect(saving).resolves.toEqual({ state: 'bytes' })
	})

	test('passes the arguments along', async () => {
		const { calls, instance } = held()

		oneAtATime(instance).loadState('the state')
		await vi.waitFor(() => expect(calls).toHaveLength(1))

		expect(calls[0].args).toEqual(['the state'])
	})

	test('leaves the calls that answer at once alone', () => {
		const { instance } = held()
		const core = oneAtATime(instance)

		// Pausing is not a file: it returns there and then, and queueing it
		// behind a save would be a pause that waited on a megabyte upload.
		expect(core.pause()).toBe('paused')
		expect(core.sendCommand('MUTE')).toBe('MUTE')
	})

	test('hands the same function back each time it is asked', () => {
		// The gamepad reaches for these on every touch that moves.
		const { instance } = held()
		const core = oneAtATime(instance)

		expect(core.pause).toBe(core.pause)
		expect(core.saveState).toBe(core.saveState)
	})

	test('hands through anything that is not a call', () => {
		const { instance } = held()

		expect(oneAtATime(instance).slots).toBe(3)
	})

	test('runs work of the caller\'s own in its turn, handed the emulator itself', async () => {
		const { calls, instance } = held()
		const core = oneAtATime(instance)
		const work = vi.fn(async (emulator) => emulator.slots)

		core.saveState()
		const answer = core.exclusive(work)
		await Promise.resolve()
		expect(work).not.toHaveBeenCalled()

		calls[0].resolve()
		expect(await answer).toBe(3)
		expect(work).toHaveBeenCalledWith(instance)
	})
})
