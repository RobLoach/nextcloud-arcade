import { describe, expect, test } from 'vitest'
import { UNBOUND, inputConfig, keyLabel, retroarchKey } from '../../src/keys.js'

describe('retroarchKey', () => {
	test('translates the named keys', () => {
		expect(retroarchKey('ArrowUp')).toBe('up')
		expect(retroarchKey('NumpadEnter')).toBe('kp_enter')
		expect(retroarchKey('ShiftRight')).toBe('rshift')
	})

	test('lowercases the letter keys', () => {
		expect(retroarchKey('KeyX')).toBe('x')
	})

	test('strips digits down to the digit', () => {
		expect(retroarchKey('Digit5')).toBe('5')
	})

	test('prefixes numpad digits with num', () => {
		expect(retroarchKey('Numpad3')).toBe('num3')
	})

	test('knows the function keys up to F12', () => {
		expect(retroarchKey('F5')).toBe('f5')
		expect(retroarchKey('F12')).toBe('f12')
		expect(retroarchKey('F13')).toBeNull()
	})

	test('has no name for the rest', () => {
		expect(retroarchKey('MediaPlayPause')).toBeNull()
	})
})

describe('keyLabel', () => {
	test('shows a dash for no key at all', () => {
		expect(keyLabel('')).toBe('—')
		expect(keyLabel(undefined)).toBe('—')
	})

	test('speaks the named keys', () => {
		expect(keyLabel('ArrowUp')).toBe('↑')
		expect(keyLabel('Space')).toBe('Space')
		expect(keyLabel('Backquote')).toBe('`')
	})

	test('shows letters and digits bare', () => {
		expect(keyLabel('KeyX')).toBe('X')
		expect(keyLabel('Digit5')).toBe('5')
	})

	test('spells out the numpad', () => {
		expect(keyLabel('Numpad3')).toBe('Numpad 3')
	})

	test('passes an unknown code through', () => {
		expect(keyLabel('MetaLeft')).toBe('MetaLeft')
	})
})

describe('a binding on no key at all', () => {
	test('is shown as nothing rather than as its own name', () => {
		expect(keyLabel(UNBOUND)).toBe('—')
	})

	test('gives the emulator no name, so no line about it is written', () => {
		// inputConfig drops whatever retroarchKey cannot name, which is
		// how a cleared button stays out of the RetroArch config.
		expect(retroarchKey(UNBOUND)).toBeNull()
		expect(inputConfig({ a: 'KeyX', b: UNBOUND })).toEqual({ input_player1_a: 'x' })
	})

	test('survives the server, which keeps a binding only if it reads as a name', () => {
		// Controls::sanitize() takes /^[A-Za-z][A-Za-z0-9]{0,19}$/; an
		// empty string would be dropped and the default would come back.
		expect(UNBOUND).toMatch(/^[A-Za-z][A-Za-z0-9]{0,19}$/)
	})
})
