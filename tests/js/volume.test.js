import { describe, expect, test } from 'vitest'
import { volumeInDecibels } from '../../src/volume.js'

describe('volumeInDecibels', () => {
	test('a hundred is the game as recorded', () => {
		expect(volumeInDecibels(100)).toBe(0)
	})

	test('halving the level is about six decibels down', () => {
		expect(volumeInDecibels(50)).toBeCloseTo(-6, 0)
		expect(volumeInDecibels(25)).toBeCloseTo(-12, 0)
	})

	test('nothing is a floor RetroArch can parse, not negative infinity', () => {
		// -Infinity is what the arithmetic gives and what the config
		// cannot hold; the core would refuse the line.
		expect(Number.isFinite(volumeInDecibels(0))).toBe(true)
		expect(volumeInDecibels(0)).toBeLessThan(-60)
	})

	test('an unset level is taken as the game as recorded', () => {
		expect(volumeInDecibels(undefined)).toBe(0)
		expect(volumeInDecibels(null)).toBe(0)
	})

	test('anything outside the range is brought back into it', () => {
		expect(volumeInDecibels(500)).toBe(0)
		expect(volumeInDecibels(-20)).toBeLessThan(-60)
		expect(volumeInDecibels('nonsense')).toBe(-80)
	})
})
