import { t } from '../l10n.js'
import { AUTO_SLOT } from '../api.js'

// How long the offer to resume stays up when left alone.
export const OFFER_SHOWN = 7 * 1000

/**
 * Pick up a game where it was left: either by offering to, or by doing it
 * as soon as the game starts.
 *
 * @param {object} options options
 * @param {HTMLElement} options.container element to attach the prompt to
 * @param {string} options.romPath path identifying the game
 * @param {Function} options.load loads a state slot
 * @param {Function} options.list lists the slots, taking an abort signal
 * @param {boolean} [options.automatic] load without asking
 * @param {?number} [options.startSlot] a slot asked for by name, loaded
 *                                      without asking when it is there
 * @param {?AbortSignal} [options.signal] the game is over; offer nothing
 * @param {?Function} [options.onFailure] told the error when the slots
 *                                         cannot be listed at all
 * @return {Promise<void>} answers once the offer is made, or not
 */
export async function offerResume({ container, load, list, automatic = false, startSlot = null, signal = null, onFailure = null }) {
	let latest = null
	try {
		const data = await list(signal)
		// A slot asked for by name -- the Load button of the sidebar -- is
		// loaded straight away, no question asked.
		if (startSlot !== null && signal?.aborted !== true && data.states.some(({ slot }) => slot === startSlot)) {
			load(startSlot)
			return
		}
		latest = data.states.reduce((a, b) => (a === null || b.mtime > a.mtime ? b : a), null)
	} catch (error) {
		// A game closed mid-request is not a fault worth reporting.
		if (signal?.aborted !== true) {
			console.error('Could not list the states', error)
			// Said, not only logged: without the list the game starts with
			// none of its saves, and nothing else would say why.
			onFailure?.(error)
		}
	}
	// The list takes a moment, and the game can be closed inside it: a
	// prompt put up now would outlive the player it belongs to.
	if (latest === null || signal?.aborted === true) {
		return
	}
	if (automatic) {
		load(latest.slot)
		return
	}

	const prompt = document.createElement('div')
	prompt.className = 'arcade-resume'
	// It arrives on its own, after the game has already started, so it is
	// spoken as it appears rather than only drawn.
	prompt.setAttribute('role', 'status')
	const text = document.createElement('span')
	const date = new Date(latest.mtime * 1000).toLocaleString()
	text.textContent = latest.slot === AUTO_SLOT
		? t('arcade', 'Continue from the automatic save ({date})?', { date })
		: t('arcade', 'Continue from slot {slot} ({date})?', { slot: latest.slot, date })
	prompt.appendChild(text)

	// It goes by itself after a few seconds, the way a notice does: the
	// slot is still a press away in the Save states panel. While the
	// pointer or the focus is on it, it waits, so it is never taken away
	// from somebody in the middle of reading it or reaching for a button.
	let timer = null
	const dismiss = () => {
		clearTimeout(timer)
		prompt.remove()
	}
	const wait = () => {
		clearTimeout(timer)
		timer = null
	}
	const resume = () => {
		if (timer === null && !prompt.matches(':hover, :focus-within')) {
			timer = setTimeout(dismiss, OFFER_SHOWN)
		}
	}
	prompt.addEventListener('pointerenter', wait)
	prompt.addEventListener('focusin', wait)
	prompt.addEventListener('pointerleave', resume)
	prompt.addEventListener('focusout', () => setTimeout(resume))
	signal?.addEventListener('abort', dismiss, { once: true })

	const resumeButton = document.createElement('button')
	resumeButton.type = 'button'
	resumeButton.className = 'primary-action'
	resumeButton.textContent = t('arcade', 'Continue')
	resumeButton.addEventListener('click', () => {
		load(latest.slot)
		dismiss()
	})
	prompt.appendChild(resumeButton)

	const dismissButton = document.createElement('button')
	dismissButton.type = 'button'
	dismissButton.textContent = t('arcade', 'Dismiss')
	dismissButton.addEventListener('click', dismiss)
	prompt.appendChild(dismissButton)

	container.appendChild(prompt)
	resume()
}
