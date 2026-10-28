// @vitest-environment happy-dom
import { describe, expect, test, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, params = {}) =>
		text.replace(/\{(\w+)\}/g, (whole, key) => params[key] ?? whole),
}))

const { createProgressTracker, readWithProgress, showLoading } = await import('../../src/progress.js')

describe('the bytes of a launch, added up', () => {
	test('adds the downloads together', () => {
		const onChange = vi.fn()
		const tracker = createProgressTracker(onChange)
		tracker.track('rom', 1000)
		tracker.track('core', 3000)
		tracker.advance('rom', 500)
		tracker.advance('core', 1500)

		expect(tracker.snapshot()).toEqual({ loaded: 2000, total: 4000 })
		expect(onChange).toHaveBeenLastCalledWith({ loaded: 2000, total: 4000 })
	})

	test('never runs past a length it was told', () => {
		// A compressed response delivers more than its Content-Length.
		const tracker = createProgressTracker(() => {})
		tracker.track('core', 100)
		tracker.advance('core', 250)

		expect(tracker.snapshot()).toEqual({ loaded: 100, total: 100 })
	})

	test('measures only the downloads that say how long they are', () => {
		// A core compressed on the way comes with no length at all.
		const tracker = createProgressTracker(() => {})
		tracker.track('rom', 1000)
		tracker.track('core', null)
		tracker.advance('rom', 250)
		tracker.advance('core', 70)

		expect(tracker.snapshot()).toEqual({ loaded: 250, total: 1000 })
	})

	test('counts every byte, with no total, while nothing has a length', () => {
		const tracker = createProgressTracker(() => {})
		tracker.track('core', null)
		tracker.advance('core', 70)
		tracker.advance('rom', 30)

		expect(tracker.snapshot()).toEqual({ loaded: 100, total: null })
	})

	test('reads a response through, saying what arrives', async () => {
		const response = new Response(new Blob(['abc', 'defg']))
		const pieces = []

		const blob = await readWithProgress(response, (bytes) => pieces.push(bytes))

		expect(await blob.text()).toBe('abcdefg')
		expect(pieces.reduce((a, b) => a + b, 0)).toBe(7)
	})
})

describe('the bar over a game on its way', () => {
	test('follows the download, then says the emulator is starting', async () => {
		const container = document.createElement('div')
		const loading = showLoading(container, 'Mario.nes')
		const bar = container.querySelector('progress')
		expect(container.textContent).toContain('Downloading Mario.nes')

		loading.update({ phase: 'download', loaded: 512, total: 2048 })
		await new Promise((resolve) => requestAnimationFrame(resolve))
		expect(bar.value).toBe(0.25)
		expect(container.querySelector('.arcade-loading-detail').textContent).toBe('512 B of 2.0 KB')

		// A piece drawn on the next frame is not drawn over the word that
		// the emulator is starting.
		loading.update({ phase: 'download', loaded: 1024, total: 2048 })
		loading.update({ phase: 'start' })
		loading.update({ phase: 'download', loaded: 2048, total: 2048 })
		await new Promise((resolve) => requestAnimationFrame(resolve))
		expect(container.textContent).toContain('Starting the emulator')
		expect(bar.hasAttribute('value')).toBe(false)
		expect(container.querySelector('.arcade-loading-detail').textContent).toBe('')

		loading.remove()
		expect(container.children).toHaveLength(0)
	})
})
