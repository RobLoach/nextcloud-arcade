// @vitest-environment happy-dom
import { afterEach, describe, expect, test, vi } from 'vitest'
import { startInSlot, takeStartSlot } from '../../src/startslot.js'

afterEach(() => {
	sessionStorage.clear()
	vi.useRealTimers()
})

describe('the slot a game is asked to start in', () => {
	test('reaches the player of that game, once', () => {
		startInSlot('/Games/Chrono.sfc', 3)
		// The Viewer and the app page spell the path with or without the
		// leading slash; both are the same game.
		expect(takeStartSlot('Games/Chrono.sfc')).toBe(3)
		expect(takeStartSlot('/Games/Chrono.sfc')).toBeNull()
	})

	test('is not taken by another game', () => {
		startInSlot('/Games/Chrono.sfc', 3)
		expect(takeStartSlot('/Games/Zelda.sfc')).toBeNull()
		// Nor left lying about for the first one to find later.
		expect(takeStartSlot('/Games/Chrono.sfc')).toBeNull()
	})

	test('goes stale', () => {
		vi.useFakeTimers()
		startInSlot('/Games/Chrono.sfc', 0)
		vi.advanceTimersByTime(3 * 60 * 1000)
		expect(takeStartSlot('/Games/Chrono.sfc')).toBeNull()
	})

	test('is nothing when nobody asked', () => {
		expect(takeStartSlot('/Games/Chrono.sfc')).toBeNull()
	})
})
