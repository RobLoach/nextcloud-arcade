import { describe, expect, test } from 'vitest'
import { waitAtMost } from '../../src/wait.js'

const after = (ms, value) => new Promise((resolve) => setTimeout(() => resolve(value), ms))

describe('waitAtMost', () => {
	test('answers what the promise gave, when it is in time', async () => {
		await expect(waitAtMost(after(1, 'saved'), 1000, 'gave up')).resolves.toBe('saved')
	})

	test('answers the fallback when the time is up', async () => {
		await expect(waitAtMost(after(1000, 'saved'), 1, 'gave up')).resolves.toBe('gave up')
	})

	test('takes a false fallback as an answer, not as nothing', async () => {
		await expect(waitAtMost(after(1000, true), 1, false)).resolves.toBe(false)
	})

	test('keeps a rejection a rejection', async () => {
		await expect(waitAtMost(Promise.reject(new Error('no')), 1000, 'gave up')).rejects.toThrow('no')
	})

	test('does not hold the process for a deadline it no longer needs', async () => {
		// A pending timer would keep Node alive past the answer; the race
		// clears it, so this resolves and the suite ends on its own.
		await waitAtMost(Promise.resolve('now'), 60000, 'gave up')
	})
})
