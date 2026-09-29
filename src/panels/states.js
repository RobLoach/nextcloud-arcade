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
 * @return {{element: HTMLElement, refresh: Function, load: Function, save: Function}}
 *         the panel; load and save answer whether they got through
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

	// One state operation at a time. A held hotkey repeats about sixty
	// times a second, and each saveState() clears the file the one before
	// it is still waiting for, so the slot ends up holding a truncated
	// state -- and the server sees a minute's worth of requests for it.
	let busy = false

	const save = async (slot) => {
		if (busy) {
			return false
		}
		busy = true
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
		} finally {
			busy = false
		}
	}

	const load = async (slot) => {
		if (busy) {
			return false
		}
		busy = true
		try {
			const response = await api(stateUrl('/state', romPath, slot))
			await instance.loadState(await response.blob())
			flash(slot === AUTO_SLOT
				? t('arcade', 'Game restored')
				: t('arcade', 'State loaded from slot {slot}', { slot }))
			onDone()
			return true
		} catch (error) {
			console.error('Could not load the state', error)
			flash(t('arcade', 'Could not load the state'))
			return false
		} finally {
			busy = false
		}
	}

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

	const refresh = async () => {
		let data
		try {
			const response = await api(stateUrl('/states', romPath))
			data = await response.json()
		} catch (error) {
			console.error('Could not list the states', error)
			return
		}
		const bySlot = new Map(data.states.map((state) => [state.slot, state]))
		// The slots offered now, plus anything left in slots earlier
		// versions offered, so those saves stay reachable.
		const slots = []
		if (bySlot.has(AUTO_SLOT)) {
			slots.push(AUTO_SLOT)
		}
		for (let slot = 1; slot <= data.slots; slot++) {
			slots.push(slot)
		}
		for (const state of data.states) {
			if (state.slot > data.slots) {
				slots.push(state.slot)
			}
		}

		slotsContainer.innerHTML = ''
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
			} else if (slot > data.slots) {
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
			if (slot !== AUTO_SLOT && slot <= data.slots) {
				row.appendChild(smallButton(
					t('arcade', 'Save'),
					() => save(slot),
					false,
					t('arcade', 'Save to slot {slot}', { slot }),
				))
			}
			row.appendChild(smallButton(
				t('arcade', 'Load'),
				() => load(slot),
				state === undefined,
				slot === AUTO_SLOT
					? t('arcade', 'Load the automatic save')
					: t('arcade', 'Load slot {slot}', { slot }),
			))
			if (state !== undefined) {
				row.appendChild(smallButton(
					t('arcade', 'Delete'),
					() => remove(slot),
					false,
					slot === AUTO_SLOT
						? t('arcade', 'Delete the automatic save')
						: t('arcade', 'Delete slot {slot}', { slot }),
				))
			}
			slotsContainer.appendChild(row)
		}
		renderSram(data.hasSram === true)
	}

	return { element, refresh, load, save }
}
