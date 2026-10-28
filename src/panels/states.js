import { translate as t } from '@nextcloud/l10n'
import { AUTO_SLOT, api, stateUrl, wasGivenUpOn } from '../api.js'
import { disableSramSync, enableSramSync } from '../player.js'
import { watchCoreLog } from '../corelog.js'
import { createStateCache } from '../statecache.js'
import { waitAtMost } from '../wait.js'
import { createPanel } from './panel.js'

// How long the core is given to answer. Handing over a state is the work
// of a moment, so ten seconds is already far past healthy -- and a core
// that never answers used to take the panel down with it: the one
// operation allowed at a time never ended, so every slot stayed shut for
// the rest of the session with nothing on screen to say why.
const CORE_ANSWER_WAIT = 10 * 1000

// What a wait that ran out answers with, which nothing the core hands
// back could be mistaken for.
const TIMED_OUT = Symbol('timed out')

// How long a loaded state is given to be turned down. Handing the core a
// state only asks it to load one; whether it could is said a few frames
// later, and only when it could not -- RetroArch prints a line, nothing
// more -- so the player is told it worked once this passes in silence.
const LOAD_VERDICT_WAIT = 750

// The line RetroArch prints when it cannot load a state: one made by a
// build of the core too far from this one, or for another game.
const LOAD_FAILED = 'Failed to load state'

/**
 * What to tell the player when a save made in the background did not
 * reach the server, for the cache to call.
 *
 * @param {Function} flash says something to the player
 * @return {Function} takes the slot and the error
 */
export function uploadFailed(flash) {
	return (slot, error) => {
		console.error('Could not upload the state', error)
		if (error?.reason === 'database') {
			flash(databaseBroken(), 'error')
			return
		}
		flash(wasGivenUpOn(error)
			? t('arcade', 'Sending the save to the server took too long. It is kept for now and sent again when the game closes.')
			: t('arcade', 'Could not send the save to the server ({why}). It is kept for now and sent again when the game closes.', { why: whyFailed(error) }), 'error')
	}
}

/**
 * What to tell the player when the app's tables on the server need
 * repairing -- which only an administrator can do, so it says where.
 *
 * @return {string} the message
 */
export function databaseBroken() {
	return t('arcade', 'Saves cannot be kept until the Arcade database is repaired. An administrator can repair it under Administration settings → Arcade → Maintenance.')
}

/**
 * Why a request failed, in a few words worth passing on to whoever has to
 * look into it: the status the server answered with, or that it did not.
 *
 * @param {*} error what was thrown
 * @return {string} the reason
 */
export function whyFailed(error) {
	if (error?.reason === 'moving') {
		return t('arcade', 'your saves are being moved to the new layout of this version; try again in a moment')
	}
	return Number.isFinite(error?.status)
		? t('arcade', 'the server answered {status}', { status: error.status })
		: t('arcade', 'no answer from the server')
}

/**
 * Build the save states panel.
 *
 * @param {object} options options
 * @param {import('nostalgist').Nostalgist} options.instance the running emulator
 * @param {string} options.romPath path identifying the game
 * @param {Function} options.flash says something to the player, taking
 *                                 the message and how it reads: success,
 *                                 info, warning or error
 * @param {Function} options.onDone called after a slot was saved or loaded
 * @param {?object} [options.cache] the states of the game held for the
 *                                  session, from createStateCache()
 * @return {{element: HTMLElement, refresh: Function, load: Function, save: Function}}
 *         the panel
 */
export function createStatesPanel({ instance, romPath, flash, onDone, cache = null }) {
	const element = createPanel('arcade-states', t('arcade', 'Save states'))
	// The player hands over the one the session holds; standing alone,
	// the panel keeps one of its own.
	const states = cache ?? createStateCache({ romPath, onUploadFailed: uploadFailed(flash) })

	const slotsContainer = document.createElement('div')
	element.appendChild(slotsContainer)

	// The in-game battery save, apart from the slots: it is the game's own
	// save file, so there is nothing to load or overwrite, only to throw away.
	const sramContainer = document.createElement('div')
	sramContainer.className = 'arcade-states-sram'
	element.appendChild(sramContainer)

	// The visible word stays short, because the row it sits in says which
	// slot it belongs to. Read out of that row it would not, so the label
	// carries the slot along with it.
	const smallButton = (label, onClick, disabled = false, ariaLabel = '') => {
		const buttonElement = document.createElement('button')
		buttonElement.type = 'button'
		buttonElement.textContent = label
		buttonElement.disabled = disabled
		if (ariaLabel !== '') {
			buttonElement.setAttribute('aria-label', ariaLabel)
		}
		buttonElement.addEventListener('click', (event) => {
			event.stopPropagation()
			onClick()
		})
		return buttonElement
	}

	// The live buttons of every slot, held together while an operation
	// runs. They were once held one slot at a time, which is what let a
	// press on any other slot reach a door already shut: it was turned
	// away with nothing on screen to say why, and the click did nothing.
	const slotButtons = []
	const setSlotsBusy = (working) => {
		for (const element of slotButtons) {
			element.disabled = working
		}
	}
	const addSlotButton = (element) => {
		// One drawn disabled -- Load on an empty slot -- is not held and
		// not let go: only the next render has anything to say about it.
		if (!element.disabled) {
			slotButtons.push(element)
		}
		return element
	}

	// One state operation at a time. A held hotkey repeats about sixty
	// times a second, and each saveState() clears the file the one before
	// it is still waiting for, so the slot ends up holding a truncated
	// state -- and the server sees a minute's worth of requests for it.
	// The extra ones are turned away, and their slot is held while the
	// one that got through runs, so it cannot be pressed either.
	let inFlight = null
	const alone = (work) => {
		if (inFlight !== null) {
			return Promise.resolve()
		}
		setSlotsBusy(true)
		inFlight = work().finally(() => {
			inFlight = null
			setSlotsBusy(false)
		})
		return inFlight
	}
	// Ask the core for something, and give up on it rather than wait out
	// a core that has stopped answering. Nothing is cancelled -- there is
	// nothing in the emulator to cancel it with -- but the caller stops
	// holding the panel, and says what happened.
	const askCore = async (promise, what, message) => {
		const answer = await waitAtMost(promise, CORE_ANSWER_WAIT, TIMED_OUT)
		if (answer === TIMED_OUT) {
			console.error(`The core did not answer ${what}() within`, CORE_ANSWER_WAIT, 'ms')
			flash(message, 'error')
		}
		return answer
	}

	// What went wrong, in the two kinds worth telling apart: the game or
	// the line was too slow, or the server said no.
	const report = (error, log, gaveUp, failed) => {
		console.error(log, error)
		flash(wasGivenUpOn(error) ? gaveUp : failed, 'error')
	}

	// A save the player asked for says so. The one the clock asks for
	// does not: it arrives in the middle of the game, unbidden, and takes
	// the top-right chrome with it while it shows. Going wrong is still
	// worth saying either way.
	//
	// The panel is only held for as long as the core takes to hand the
	// state over. Getting it to the server is the cache's business, in
	// the background, and the cache says so if that fails.
	const save = (slot, { quiet = false } = {}) => alone(async () => {
		// Nothing is held until the core has answered, so a core that
		// never does costs the slot nothing: what it already held is
		// still there afterwards.
		const written = await askCore(instance.saveState(), 'saveState',
			t('arcade', 'The game did not hand over a save state, so the slot is unchanged'))
		if (written === TIMED_OUT) {
			return
		}
		let { state, thumbnail } = written
		if (thumbnail === undefined) {
			// Not every core provides a state thumbnail; fall back to a
			// plain screenshot so the slot always has one. A picture is
			// the one part worth going without, so a core that will not
			// answer for it holds nothing up.
			thumbnail = await waitAtMost(
				instance.screenshot().catch(() => undefined), CORE_ANSWER_WAIT, undefined)
		}
		states.put(slot, state, thumbnail ?? null, { quiet })
		if (!quiet) {
			flash(slot === AUTO_SLOT
				? t('arcade', 'Game saved')
				: t('arcade', 'State saved to slot {slot}', { slot }), 'success')
		}
		onDone()
	}).catch((error) => {
		console.error('Could not save the state', error)
		flash(t('arcade', 'Could not save the state'), 'error')
	})

	const load = (slot) => alone(async () => {
		try {
			// At once when the state is held, which it is once the session
			// has fetched it ahead or it was saved here.
			const state = await states.get(slot)
			if (state.size === 0) {
				// Handed an empty file, RetroArch waits for a state that
				// will never come -- a minute of nothing, and then a
				// message about loading rather than about the file.
				flash(t('arcade', 'That save state is empty, so there is nothing to load'), 'warning')
				return
			}
			// The core only says when it could not load a state, and in
			// a log line naming a file the player never sees, so the line
			// is listened for, and said in words of the player's instead.
			let refused = false
			let heard = () => {}
			const verdict = new Promise((resolve) => {
				heard = resolve
			})
			const stopWatching = watchCoreLog((line) => {
				if (typeof line !== 'string' || !line.includes(LOAD_FAILED)) {
					return false
				}
				refused = true
				heard()
				return true
			})
			try {
				// A core can swallow a state the same way it can withhold
				// one, and the game is left running either way.
				const taken = await askCore(instance.loadState(state), 'loadState',
					t('arcade', 'The game did not take the save state'))
				if (taken === TIMED_OUT) {
					return
				}
				await waitAtMost(verdict, LOAD_VERDICT_WAIT, undefined)
			} finally {
				stopWatching()
			}
			if (refused) {
				flash(t('arcade', 'That save state would not load. It may have been made by another version of the emulator, as RetroArch on another device can be, or for another copy of the game.'), 'error')
				return
			}
			flash(slot === AUTO_SLOT
				? t('arcade', 'Game restored')
				: t('arcade', 'State loaded from slot {slot}', { slot }), 'success')
			onDone()
		} catch (error) {
			report(error, 'Could not load the state',
				t('arcade', 'Loading took too long and was stopped'),
				t('arcade', 'Could not load the state'))
		}
	})

	const remove = async (slot) => {
		try {
			await states.remove(slot)
			await refresh()
		} catch (error) {
			console.error('Could not delete the state', error)
			flash(t('arcade', 'Could not delete the state'), 'error')
		}
	}

	const removeSram = async () => {
		try {
			// Before the delete, not after: an upload started a moment ago
			// is already past its own check and still reading the save out
			// of the core, and would land on top of the deleted file.
			//
			// The emulator still holds the old save in memory, and the next
			// automatic upload would write it right back, so uploads stop
			// until the game is opened anew — which then starts clean.
			disableSramSync(romPath)
			await api(stateUrl('/sram', romPath), { method: 'DELETE' })
			flash(t('arcade', 'Battery save deleted — reopen the game to start over'), 'success')
			await refresh()
		} catch (error) {
			console.error('Could not delete the battery save', error)
			enableSramSync(romPath)
			flash(t('arcade', 'Could not delete the battery save'), 'error')
		}
	}

	const renderSram = (hasSram) => {
		sramContainer.innerHTML = ''
		const row = document.createElement('div')
		row.className = 'arcade-states-slot'
		const label = document.createElement('span')
		label.className = 'arcade-states-label'
		row.appendChild(label)
		sramContainer.appendChild(row)
		if (!hasSram) {
			label.textContent = t('arcade', 'No battery save')
			return
		}
		label.textContent = t('arcade', 'Battery save')
		// Deleting throws away the game's own save file, so the button asks
		// to be pressed twice, and forgets being pressed once soon enough.
		const resting = t('arcade', 'Delete battery save')
		const armed = t('arcade', 'Really delete?')
		let disarmTimer = null
		const deleteButton = smallButton(resting, () => {
			if (!deleteButton.classList.contains('arcade-states-confirm')) {
				deleteButton.classList.add('arcade-states-confirm')
				deleteButton.textContent = armed
				disarmTimer = setTimeout(() => {
					deleteButton.classList.remove('arcade-states-confirm')
					deleteButton.textContent = resting
				}, 4000)
				return
			}
			clearTimeout(disarmTimer)
			removeSram()
		})
		row.appendChild(deleteButton)
	}

	// A panel that cannot say what is in the slots says that, rather than
	// standing there empty with no way to ask again.
	const renderFailure = (error) => {
		slotsContainer.innerHTML = ''
		sramContainer.innerHTML = ''
		slotButtons.length = 0
		const row = document.createElement('div')
		row.className = 'arcade-states-slot'
		const label = document.createElement('span')
		label.className = 'arcade-states-label'
		label.textContent = error?.reason === 'database'
			? databaseBroken()
			: t('arcade', 'Could not read the save states ({why}).', { why: whyFailed(error) })
		row.appendChild(label)
		row.appendChild(smallButton(t('arcade', 'Retry'), () => refresh()))
		slotsContainer.appendChild(row)
	}

	const render = (data) => {
		// Whatever the server sent, this is what it is taken to mean. The
		// caller catches as well, but a panel is not worth blanking over
		// a missing field.
		const listed = Array.isArray(data?.states) ? data.states : []
		const slotCount = Number.isFinite(Number(data?.slots)) ? Number(data.slots) : 0
		const bySlot = new Map(listed.map((state) => [state.slot, state]))
		// Numbered from 0, as RetroArch numbers them.
		const offered = (slot) => slot >= 0 && slot < slotCount
		// The slots offered, plus any other RetroArch saved into, so those
		// saves are reachable here too.
		const slots = []
		if (bySlot.has(AUTO_SLOT)) {
			slots.push(AUTO_SLOT)
		}
		for (let slot = 0; slot < slotCount; slot++) {
			slots.push(slot)
		}
		for (const state of listed) {
			if (state.slot !== AUTO_SLOT && !offered(state.slot)) {
				slots.push(state.slot)
			}
		}

		slotsContainer.innerHTML = ''
		slotButtons.length = 0
		for (const slot of slots) {
			const state = bySlot.get(slot)
			const row = document.createElement('div')
			row.className = 'arcade-states-slot'

			const thumbnail = document.createElement('img')
			thumbnail.className = 'arcade-states-thumbnail'
			thumbnail.alt = ''
			if (state?.hasThumbnail) {
				// A slot saved this session shows the picture it was saved
				// with, which the server may not have yet.
				thumbnail.src = states.thumbnailUrl(slot)
					?? stateUrl('/state/thumbnail', romPath, slot) + `&mtime=${state.mtime}`
			}
			row.appendChild(thumbnail)

			const label = document.createElement('span')
			label.className = 'arcade-states-label'
			// A filled slot is its date: the thumbnail already tells the
			// slots apart, and the slot number only earns its room where
			// there is nothing else to say.
			const when = state === undefined ? '' : new Date(state.mtime * 1000).toLocaleString()
			if (slot === AUTO_SLOT) {
				label.textContent = t('arcade', 'Auto — {date}', { date: when })
			} else if (!offered(slot)) {
				label.textContent = t('arcade', 'Slot {slot} — {date}', { slot, date: when })
			} else {
				label.textContent = state === undefined
					? t('arcade', 'Slot {slot} — empty', { slot })
					: when
			}
			row.appendChild(label)

			// A state belongs to the exact dump it was made from.
			if (state?.stale) {
				const warning = document.createElement('span')
				warning.className = 'arcade-states-stale'
				warning.textContent = t('arcade', 'made from another copy of this game')
				warning.title = t('arcade', 'The ROM has changed since this state was saved, so loading it may go wrong.')
				row.appendChild(warning)
			}

			// The automatic slot is written by the player itself, and the
			// slots it does not offer are only there to be loaded or emptied.
			if (offered(slot)) {
				row.appendChild(addSlotButton(smallButton(
					t('arcade', 'Save'),
					() => save(slot),
					false,
					t('arcade', 'Save to slot {slot}', { slot }),
				)))
			}
			row.appendChild(addSlotButton(smallButton(
				t('arcade', 'Load'),
				() => load(slot),
				state === undefined,
				slot === AUTO_SLOT
					? t('arcade', 'Load the automatic save')
					: t('arcade', 'Load slot {slot}', { slot }),
			)))
			if (state !== undefined) {
				row.appendChild(addSlotButton(smallButton(
					t('arcade', 'Delete'),
					() => remove(slot),
					false,
					slot === AUTO_SLOT
						? t('arcade', 'Delete the automatic save')
						: t('arcade', 'Delete slot {slot}', { slot }),
				)))
			}
			slotsContainer.appendChild(row)
		}
		renderSram(data?.hasSram === true)
		// These buttons are new, and know nothing of an operation that was
		// already running when they were drawn.
		if (inFlight !== null) {
			setSlotsBusy(true)
		}
	}

	const refresh = async () => {
		try {
			render(await states.list())
			// Should the listing as the game started have failed, the states
			// are fetched ahead now; it does nothing if they already were.
			states.prefetch()
		} catch (error) {
			// The rendering is in here too: a surprise in the shape of the
			// answer would otherwise escape as an unhandled rejection and
			// leave the panel blank with nothing said about it.
			console.error('Could not list the states', error)
			renderFailure(error)
		}
	}

	return { element, refresh, load, save }
}
