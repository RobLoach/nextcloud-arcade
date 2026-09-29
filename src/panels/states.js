import { translate as t } from '@nextcloud/l10n'
import { AUTO_SLOT, api, stateUrl } from '../api.js'
import { disableSramSync, enableSramSync } from '../player.js'
import { createPanel } from './panel.js'

/**
 * Build the save states panel.
 *
 * @param {object} options options
 * @param {import('nostalgist').Nostalgist} options.instance the running emulator
 * @param {string} options.romPath path identifying the game
 * @param {Function} options.flash shows a status message
 * @param {Function} options.onDone called after a slot was saved or loaded
 * @return {{element: HTMLElement, refresh: Function, load: Function, save: Function, settled: Function}}
 *         the panel; load and save answer whether they got through, and
 *         settled waits out whichever of them is running
 */
export function createStatesPanel({ instance, romPath, flash, onDone }) {
	const element = createPanel('arcade-states', t('arcade', 'Save states'))

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

	// The buttons of each rendered slot, with the state they were rendered
	// in, so the slot being worked on can be held and let go again.
	const slotButtons = new Map()
	const setSlotBusy = (slot, working) => {
		for (const [element, disabled] of slotButtons.get(slot) ?? []) {
			element.disabled = working || disabled
		}
	}
	const addSlotButton = (slot, element) => {
		if (!slotButtons.has(slot)) {
			slotButtons.set(slot, [])
		}
		slotButtons.get(slot).push([element, element.disabled])
		return element
	}

	// One state operation at a time. A held hotkey repeats about sixty
	// times a second, and each saveState() clears the file the one before
	// it is still waiting for, so the slot ends up holding a truncated
	// state -- and the server sees a minute's worth of requests for it.
	// The extra ones are turned away, and their slot is held while the
	// one that got through runs, so it cannot be pressed either.
	let inFlight = null
	const alone = (slot, work) => {
		if (inFlight !== null) {
			return Promise.resolve(false)
		}
		setSlotBusy(slot, true)
		inFlight = work().finally(() => {
			inFlight = null
			setSlotBusy(slot, false)
		})
		return inFlight
	}
	// Lets whatever is in the air land. A caller that must not be turned
	// away -- the save on the way out of the game -- waits its turn here
	// rather than being told no by a tick of the autosave.
	const settled = async () => {
		try {
			await inFlight
		} catch {
			// How it went is the business of whoever started it.
		}
	}

	const save = (slot) => alone(slot, async () => {
		try {
			let { state, thumbnail } = await instance.saveState()
			if (thumbnail === undefined) {
				// Not every core provides a state thumbnail; fall back to a
				// plain screenshot so the slot always has one.
				thumbnail = await instance.screenshot().catch(() => undefined)
			}
			await api(stateUrl('/state', romPath, slot), {
				method: 'POST',
				headers: { 'Content-Type': 'application/octet-stream' },
				body: state,
			})
			if (thumbnail !== undefined) {
				await api(stateUrl('/state/thumbnail', romPath, slot), {
					method: 'POST',
					headers: { 'Content-Type': 'image/png' },
					body: thumbnail,
				}).catch(() => {})
			}
			flash(slot === AUTO_SLOT
				? t('arcade', 'Game saved')
				: t('arcade', 'State saved to slot {slot}', { slot }))
			onDone()
			return true
		} catch (error) {
			console.error('Could not save the state', error)
			flash(t('arcade', 'Could not save the state'))
			return false
		}
	})

	const load = (slot) => alone(slot, async () => {
		try {
			const response = await api(stateUrl('/state', romPath, slot))
			const state = await response.blob()
			if (state.size === 0) {
				// Handed an empty file, RetroArch waits for a state that
				// will never come -- a minute of nothing, and then a
				// message about loading rather than about the file.
				flash(t('arcade', 'That save state is empty, so there is nothing to load'))
				return false
			}
			await instance.loadState(state)
			flash(slot === AUTO_SLOT
				? t('arcade', 'Game restored')
				: t('arcade', 'State loaded from slot {slot}', { slot }))
			onDone()
			return true
		} catch (error) {
			console.error('Could not load the state', error)
			flash(t('arcade', 'Could not load the state'))
			return false
		}
	})

	const remove = async (slot) => {
		try {
			await api(stateUrl('/state', romPath, slot), { method: 'DELETE' })
			await refresh()
		} catch (error) {
			console.error('Could not delete the state', error)
			flash(t('arcade', 'Could not delete the state'))
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
			flash(t('arcade', 'Battery save deleted — reopen the game to start over'))
			await refresh()
		} catch (error) {
			console.error('Could not delete the battery save', error)
			enableSramSync(romPath)
			flash(t('arcade', 'Could not delete the battery save'))
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
	const renderFailure = () => {
		slotsContainer.innerHTML = ''
		sramContainer.innerHTML = ''
		slotButtons.clear()
		const row = document.createElement('div')
		row.className = 'arcade-states-slot'
		const label = document.createElement('span')
		label.className = 'arcade-states-label'
		label.textContent = t('arcade', 'Could not read the save states.')
		row.appendChild(label)
		row.appendChild(smallButton(t('arcade', 'Retry'), () => refresh()))
		slotsContainer.appendChild(row)
	}

	const render = (data) => {
		// Whatever the server sent, this is what it is taken to mean. The
		// caller catches as well, but a panel is not worth blanking over
		// a missing field.
		const states = Array.isArray(data?.states) ? data.states : []
		const slotCount = Number.isFinite(Number(data?.slots)) ? Number(data.slots) : 0
		const bySlot = new Map(states.map((state) => [state.slot, state]))
		// The slots offered now, plus anything left in slots earlier
		// versions offered, so those saves stay reachable.
		const slots = []
		if (bySlot.has(AUTO_SLOT)) {
			slots.push(AUTO_SLOT)
		}
		for (let slot = 1; slot <= slotCount; slot++) {
			slots.push(slot)
		}
		for (const state of states) {
			if (state.slot > slotCount) {
				slots.push(state.slot)
			}
		}

		slotsContainer.innerHTML = ''
		slotButtons.clear()
		for (const slot of slots) {
			const state = bySlot.get(slot)
			const row = document.createElement('div')
			row.className = 'arcade-states-slot'

			const thumbnail = document.createElement('img')
			thumbnail.className = 'arcade-states-thumbnail'
			thumbnail.alt = ''
			if (state?.hasThumbnail) {
				thumbnail.src = stateUrl('/state/thumbnail', romPath, slot) + `&mtime=${state.mtime}`
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
			} else if (slot > slotCount) {
				label.textContent = t('arcade', '{date}, from an older version', { date: when })
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

			// The automatic slot is written by the player itself, and slots
			// beyond the ones offered now are only there to be emptied.
			if (slot !== AUTO_SLOT && slot <= slotCount) {
				row.appendChild(addSlotButton(slot, smallButton(
					t('arcade', 'Save'),
					() => save(slot),
					false,
					t('arcade', 'Save to slot {slot}', { slot }),
				)))
			}
			row.appendChild(addSlotButton(slot, smallButton(
				t('arcade', 'Load'),
				() => load(slot),
				state === undefined,
				slot === AUTO_SLOT
					? t('arcade', 'Load the automatic save')
					: t('arcade', 'Load slot {slot}', { slot }),
			)))
			if (state !== undefined) {
				row.appendChild(addSlotButton(slot, smallButton(
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
	}

	const refresh = async () => {
		try {
			const response = await api(stateUrl('/states', romPath))
			render(await response.json())
		} catch (error) {
			// The rendering is in here too: a surprise in the shape of the
			// answer would otherwise escape as an unhandled rejection and
			// leave the panel blank with nothing said about it.
			console.error('Could not list the states', error)
			renderFailure()
		}
	}

	return { element, refresh, load, save, settled }
}
