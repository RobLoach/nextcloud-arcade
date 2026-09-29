import { afterEach, describe, expect, test, vi } from 'vitest'
import { isTouchDevice, isTouchPrimary } from '../../src/touch.js'

// The two predicates read nothing but the media query and the touch point
// count, so a window of exactly those two is the whole world they need.
const pretend = ({ coarse = false, touchPoints = 0, matchMedia = true } = {}) => {
	vi.stubGlobal('window', matchMedia
		? { matchMedia: (query) => ({ matches: query === '(pointer: coarse)' && coarse }) }
		: {})
	vi.stubGlobal('navigator', { maxTouchPoints: touchPoints })
}

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('isTouchDevice', () => {
	test('says yes to a phone', () => {
		pretend({ coarse: true, touchPoints: 5 })
		expect(isTouchDevice()).toBe(true)
	})

	test('says yes to a laptop with a touchscreen', () => {
		pretend({ coarse: false, touchPoints: 10 })
		expect(isTouchDevice()).toBe(true)
	})

	test('says no to a plain desktop', () => {
		pretend({ coarse: false, touchPoints: 0 })
		expect(isTouchDevice()).toBe(false)
	})

	test('says no rather than undefined without matchMedia', () => {
		pretend({ matchMedia: false, touchPoints: 0 })
		expect(isTouchDevice()).toBe(false)
	})
})

describe('isTouchPrimary', () => {
	test('says yes to a phone', () => {
		pretend({ coarse: true, touchPoints: 5 })
		expect(isTouchPrimary()).toBe(true)
	})

	test('says no to a laptop with a touchscreen, which is pointed at with a mouse', () => {
		pretend({ coarse: false, touchPoints: 10 })
		expect(isTouchPrimary()).toBe(false)
	})

	test('says no without matchMedia', () => {
		pretend({ matchMedia: false, touchPoints: 10 })
		expect(isTouchPrimary()).toBe(false)
	})
})
