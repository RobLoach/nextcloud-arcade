import { describe, expect, test, vi } from 'vitest'

// The formatters only lean on translate for the wording; substituting the
// placeholders the way @nextcloud/l10n does is all the tests need.
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => text.replace(/{(\w+)}/g, (match, key) => String(vars?.[key] ?? match)),
}))

const { formatDuration, formatPlayTime, formatSize } = await import('../../src/format.js')

describe('formatDuration', () => {
	test('says nothing at zero', () => {
		expect(formatDuration(0)).toBe('')
	})

	test('says nothing under a minute', () => {
		expect(formatDuration(59)).toBe('')
	})

	test('speaks up at exactly a minute', () => {
		expect(formatDuration(60)).toBe('1 min')
	})

	test('rounds just under the hour up to 60 min', () => {
		expect(formatDuration(3599)).toBe('60 min')
	})

	test('turns into hours at exactly one', () => {
		expect(formatDuration(3600)).toBe('1 h 0 min')
	})

	test('carries hours and minutes together', () => {
		expect(formatDuration(4 * 3600 + 30 * 60)).toBe('4 h 30 min')
	})
})

describe('formatPlayTime', () => {
	test('says nothing under a minute', () => {
		expect(formatPlayTime(59)).toBe('')
	})

	test('speaks in minutes under an hour', () => {
		expect(formatPlayTime(60)).toBe('1m played')
	})

	test('speaks in hours and minutes above one', () => {
		expect(formatPlayTime(3720)).toBe('1h 2m played')
	})
})

describe('formatSize', () => {
	test('keeps bytes whole right up to the KB boundary', () => {
		expect(formatSize(1023)).toBe('1023 B')
	})

	test('crosses into KB at 1024', () => {
		expect(formatSize(1024)).toBe('1.0 KB')
	})

	test('keeps one decimal under 10 units', () => {
		expect(formatSize(5632)).toBe('5.5 KB')
	})

	test('drops the decimal from 10 units up', () => {
		expect(formatSize(10240)).toBe('10 KB')
	})

	test('rounds whole numbers above 10 units', () => {
		expect(formatSize(15872)).toBe('16 KB')
	})

	test('reaches GB', () => {
		expect(formatSize(1024 * 1024 * 1024)).toBe('1.0 GB')
	})

	test('stays in GB past the last unit', () => {
		expect(formatSize(2 * 1024 * 1024 * 1024 * 1024)).toBe('2048 GB')
	})
})
