import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import { MESSAGE_CAP, coreMessage, createCoreLog, watchCoreLog } from '../../src/corelog.js'

describe('coreMessage', () => {
	test('reads the level off the front of the line', () => {
		expect(coreMessage('[ERROR] Could not load the BIOS'))
			.toEqual({ message: 'Could not load the BIOS', type: 'error' })
		expect(coreMessage('[WARN] WebGL context lost!'))
			.toEqual({ message: 'WebGL context lost!', type: 'warning' })
	})

	test('drops the tags the core names itself after', () => {
		expect(coreMessage('[ERROR] [libretro ERROR] Could not find compatible system'))
			.toEqual({ message: 'Could not find compatible system', type: 'error' })
	})

	test('says nothing about the lines a player cannot act on', () => {
		// A launch prints hundreds of these.
		expect(coreMessage('[INFO] Loading content file')).toBeNull()
		expect(coreMessage('Version: 1.16.0')).toBeNull()
		expect(coreMessage('')).toBeNull()
		expect(coreMessage(undefined)).toBeNull()
	})

	test('says nothing about a level with no message behind it', () => {
		expect(coreMessage('[ERROR]')).toBeNull()
		expect(coreMessage('[ERROR] [libretro ERROR]  ')).toBeNull()
	})
})

describe('createCoreLog', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.spyOn(console, 'info').mockImplementation(() => {})
	})
	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	test('passes on what is worth saying', () => {
		const said = []
		const log = createCoreLog((message, type) => said.push([message, type]))
		log('[INFO] Loading content file')
		log('[ERROR] Could not load the BIOS')
		expect(said).toEqual([['Could not load the BIOS', 'error']])
	})

	test('says the same complaint once, however often the core repeats it', () => {
		const said = []
		const log = createCoreLog((message) => said.push(message))
		// Cores complain once a frame when something is wrong.
		for (let frame = 0; frame < 60; frame++) {
			log('[WARN] WebGL context lost!')
		}
		expect(said).toEqual(['WebGL context lost!'])
	})

	test('says it again when it comes back much later', () => {
		const said = []
		const log = createCoreLog((message) => said.push(message))
		log('[WARN] WebGL context lost!')
		vi.advanceTimersByTime(60000)
		log('[WARN] WebGL context lost!')
		expect(said).toHaveLength(2)
	})

	test('stops before a stuck core papers the screen over', () => {
		const said = []
		const log = createCoreLog((message) => said.push(message))
		for (let number = 0; number < MESSAGE_CAP + 5; number++) {
			log(`[ERROR] Something went wrong ${number}`)
		}
		expect(said).toHaveLength(MESSAGE_CAP)
	})
})

describe('listening in on the core', () => {
	test('hands a watcher every line, and keeps from the player what it takes', () => {
		const report = vi.fn()
		const log = createCoreLog(report)
		const heard = []
		const stop = watchCoreLog((line) => {
			heard.push(line)
			return line.includes('Failed to load state')
		})

		log('[ERROR] [State] Failed to load state from "/x.state".')
		log('[ERROR] Could not load the BIOS')
		stop()
		log('[ERROR] [State] Failed to load state from "/y.state".')

		expect(heard).toHaveLength(2)
		expect(report.mock.calls.map(([message]) => message)).toEqual([
			'Could not load the BIOS',
			'Failed to load state from "/y.state".',
		])
	})
})

describe('what the browser build says of itself', () => {
	test('is not passed on, being true of every launch', () => {
		expect(coreMessage('[ERROR] [Playlist] Cannot push NULL or empty core path into the playlist.')).toBeNull()
		expect(coreMessage('[ERROR] [EMSCRIPTEN] Could not get screen dimensions.')).toBeNull()
		expect(coreMessage('[ERROR] [State] Failed to load state from "/x.state".')).not.toBeNull()
	})
})
