import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import { showError, showInfo, showSuccess, showWarning, toastsIn } from '../../src/toast.js'

/** A stand-in for the toaster the server exposes as OCP.Toast. */
const toaster = () => ({
	error: vi.fn(() => ({ hideToast: vi.fn() })),
	warning: vi.fn(() => ({ hideToast: vi.fn() })),
	info: vi.fn(() => ({ hideToast: vi.fn() })),
	success: vi.fn(() => ({ hideToast: vi.fn() })),
})

describe('the app toasts', () => {
	let toast

	beforeEach(() => {
		toast = toaster()
		vi.stubGlobal('window', { OCP: { Toast: toast } })
	})
	afterEach(() => {
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
	})

	test('say what they are given, through the server helpers', () => {
		showError('broke')
		showWarning('careful')
		showInfo('working')
		showSuccess('done')
		expect(toast.error).toHaveBeenCalledWith('broke', expect.objectContaining({ ariaLive: 'assertive' }))
		expect(toast.warning).toHaveBeenCalledWith('careful', expect.objectContaining({ ariaLive: 'polite' }))
		expect(toast.info).toHaveBeenCalledWith('working', expect.objectContaining({ ariaLive: 'polite' }))
		expect(toast.success).toHaveBeenCalledWith('done', expect.objectContaining({ ariaLive: 'polite' }))
	})

	test('fall back to the console on a server without the toaster', () => {
		// An older server, or one whose core bundle did not load.
		vi.stubGlobal('window', {})
		const logged = vi.spyOn(console, 'error').mockImplementation(() => {})

		expect(showError('broke')).toBeNull()
		expect(logged).toHaveBeenCalledWith('broke')
	})
})

describe('toastsIn', () => {
	let toast
	let container

	beforeEach(() => {
		toast = toaster()
		vi.stubGlobal('window', { OCP: { Toast: toast } })
		// Passed straight through and never touched, so what it stands for
		// matters more than what it is: the player's own container.
		container = { nodeType: 1, className: 'arcade-player-container' }
	})
	afterEach(() => {
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
	})

	test('hands the toaster the element itself, never a selector for it', () => {
		// Toastify reads a string as an element id and throws when it finds
		// none, which cost a whole session's messages. A node cannot be
		// looked up wrongly.
		toastsIn(container).success('saved')

		const [, options] = toast.success.mock.calls[0]
		expect(options.selector).toBe(container)
		expect(typeof options.selector).not.toBe('string')
	})

	test('keeps how each kind reads', () => {
		const toasts = toastsIn(container)
		toasts.error('broke')
		toasts.info('working')
		expect(toast.error.mock.calls[0][1]).toMatchObject({ ariaLive: 'assertive', selector: container })
		expect(toast.info.mock.calls[0][1]).toMatchObject({ ariaLive: 'polite', selector: container })
	})

	test('lets the caller pass the rest of the options through', () => {
		toastsIn(container).warning('no BIOS', { timeout: -1 })

		expect(toast.warning.mock.calls[0][1]).toMatchObject({ timeout: -1, selector: container })
	})

	test('still says it somewhere when the element will not hold a toast', () => {
		// Whatever goes wrong hanging a toast of our own, the message must
		// not take down the save, the load or the launch that was saying it.
		toast.success.mockImplementationOnce(() => {
			throw new Error('Root element is not defined')
		})
		vi.spyOn(console, 'error').mockImplementation(() => {})

		expect(() => toastsIn(container).success('saved')).not.toThrow()
		expect(toast.success).toHaveBeenCalledTimes(2)
		// The second try is the plain one, with nowhere of our own asked for.
		expect(toast.success.mock.calls[1][1]).not.toHaveProperty('selector')
	})

	test('falls back to the console when even that fails', () => {
		toast.error.mockImplementation(() => {
			throw new Error('no toasts today')
		})
		const logged = vi.spyOn(console, 'error').mockImplementation(() => {})

		expect(toastsIn(container).error('broke')).toBeNull()
		expect(logged).toHaveBeenCalledWith('broke')
	})
})
