import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import { MESSAGE_UP, UNTIL_DISMISSED, createFlash, showError, showInfo, showSuccess, showWarning, toastsIn } from '../../src/toast.js'

/** Just enough of an element for the class the chrome watches. */
const element = () => {
	const classes = new Set()
	return {
		classes,
		classList: {
			add: (name) => classes.add(name),
			remove: (name) => classes.delete(name),
			toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)),
			contains: (name) => classes.has(name),
		},
	}
}

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

describe('createFlash', () => {
	let toast
	let player

	/** The removal the toaster reports once a toast has gone. */
	const remove = (call) => call[1].onRemove()

	beforeEach(() => {
		vi.useFakeTimers()
		toast = toaster()
		vi.stubGlobal('window', { OCP: { Toast: toast } })
		player = element()
	})
	afterEach(() => {
		vi.useRealTimers()
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
	})

	test('takes the chrome out of the corner while a message is up', () => {
		const { flash } = createFlash(player)

		flash('Game saved', 'success')

		expect(player.classes.has(MESSAGE_UP)).toBe(true)
	})

	test('gives it back the moment the message goes', () => {
		const { flash } = createFlash(player)
		flash('Game saved', 'success')

		remove(toast.success.mock.calls[0])

		expect(player.classes.has(MESSAGE_UP)).toBe(false)
	})

	test('leaves the chrome alone for a message that waits to be dismissed', () => {
		// That message asks the player to press Close, which is in the
		// chrome: hiding it would hide the button the words name, for as
		// long as the words are up.
		const { flash } = createFlash(player)

		flash('Press Close again', 'error', { timeout: UNTIL_DISMISSED })

		expect(player.classes.has(MESSAGE_UP)).toBe(false)
	})

	test('an older message going does not uncover the newer one', () => {
		// A toast reports its removal a few hundred milliseconds late, by
		// which time the next message is already on screen.
		const { flash } = createFlash(player)
		flash('Game saved', 'success')
		flash('Could not load the state', 'error')

		remove(toast.success.mock.calls[0])

		expect(player.classes.has(MESSAGE_UP)).toBe(true)
	})

	test('waits out a message that asked to stay longer', () => {
		const { flash } = createFlash(player)
		flash('Game saved', 'success', { timeout: 30000 })

		vi.advanceTimersByTime(20000)
		expect(player.classes.has(MESSAGE_UP)).toBe(true)

		vi.advanceTimersByTime(20000)
		expect(player.classes.has(MESSAGE_UP)).toBe(false)
	})

	test('keeps the chrome where it is on a server with no toaster', () => {
		vi.stubGlobal('window', {})
		vi.spyOn(console, 'info').mockImplementation(() => {})
		const { flash } = createFlash(player)

		flash('Game saved', 'success')

		expect(player.classes.has(MESSAGE_UP)).toBe(false)
	})

	test('says one thing at a time', () => {
		const { flash } = createFlash(player)
		const first = { hideToast: vi.fn() }
		toast.success.mockReturnValueOnce(first)

		flash('Game saved', 'success')
		flash('Restarted', 'info')

		expect(first.hideToast).toHaveBeenCalled()
	})

	test('lets a confirmation go after a glance', () => {
		const { flash } = createFlash(player)

		flash('Game saved', 'success')
		flash('Restarted', 'info')

		expect(toast.success.mock.calls[0][1].timeout).toBe(3000)
		expect(toast.info.mock.calls[0][1].timeout).toBe(3000)
	})

	test('leaves anything that went wrong up for the toaster default', () => {
		const { flash } = createFlash(player)

		flash('Could not load the state', 'error')
		flash('No BIOS files found', 'warning')

		// No length asked for at all, so the toaster keeps its own.
		expect(toast.error.mock.calls[0][1]).not.toHaveProperty('timeout')
		expect(toast.warning.mock.calls[0][1]).not.toHaveProperty('timeout')
	})

	test('a length the caller asked for wins', () => {
		const { flash } = createFlash(player)

		flash('Saving the game …', 'info', { timeout: 5000 })

		expect(toast.info.mock.calls[0][1].timeout).toBe(5000)
	})

	test('gives the chrome back even if the toaster never says the toast went', () => {
		// An older toaster, or one that drops the option: a button that
		// cannot be reached is worse than a word in front of it. The
		// watchdog is the confirmation's own three seconds, plus slack.
		const { flash } = createFlash(player)
		flash('Game saved', 'success')

		vi.advanceTimersByTime(3000 + 1000)

		expect(player.classes.has(MESSAGE_UP)).toBe(false)
	})

	test('stopping puts the message away and gives the chrome back', () => {
		const { flash, stop } = createFlash(player)
		const showing = { hideToast: vi.fn() }
		toast.error.mockReturnValueOnce(showing)
		flash('Press Close again', 'error')

		stop()

		expect(showing.hideToast).toHaveBeenCalled()
		expect(player.classes.has(MESSAGE_UP)).toBe(false)
	})
})
