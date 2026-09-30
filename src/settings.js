import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { api } from './api.js'
import { formatSize } from './format.js'
import { keyLabel, retroarchKey } from './keys.js'

const container = document.getElementById('arcade-settings')

/**
 * Not the Nextcloud toasts the library uses: a word right beside the
 * setting it belongs to is the settings convention across Nextcloud, and
 * the .msg spans in the templates are already aria-live regions.
 *
 * @param {Element} [source] the control a change came from
 * @return {Element} the status line of its section, or the page's own
 */
function statusFor(source) {
	return (source instanceof Element && source.closest('.section')?.querySelector('.msg'))
		|| container.querySelector('.msg')
}

// One timer per status element, so a fresh message is not blanked early
// by the timer of the one before it.
const statusTimers = new WeakMap()

/**
 * Clear a status line after a moment, re-arming its timer.
 *
 * @param {Element} status the status line
 */
function flashStatus(status) {
	clearTimeout(statusTimers.get(status))
	statusTimers.set(status, setTimeout(() => {
		status.textContent = ''
	}, 3000))
}

/**
 * @param {HTMLInputElement} input the folder input to fill
 */
async function pickFolder(input) {
	// The file picker weighs more than the rest of this page put together,
	// so it is fetched when somebody actually goes looking for a folder.
	const { getFilePickerBuilder } = await import('./picker.js')
	const picker = getFilePickerBuilder(t('arcade', 'Choose a folder'))
		.setMultiSelect(false)
		.setMimeTypeFilter(['httpd/unix-directory'])
		.allowDirectories(true)
		.addButton({
			label: t('arcade', 'Choose'),
			variant: 'primary',
			// pick() below resolves with the path on its own; nothing
			// more is wanted from the button itself.
			callback: () => {},
		})
		.startAt(input.value || '/')
		.build()
	try {
		const path = await picker.pick()
		if (typeof path === 'string') {
			input.value = path === '' ? '/' : path
			save(input)
		}
	} catch (error) {
		// The picker was cancelled.
	}
}

/**
 * Save all the settings, saying so next to the section that changed.
 *
 * @param {Element} [source] the control the change came from
 * @return {Promise<boolean>} whether the settings reached the server
 */
async function save(source) {
	const status = statusFor(source)
	const settings = {}
	container.querySelectorAll('.arcade-setting').forEach((element) => {
		settings[element.dataset.setting] = element.type === 'checkbox'
			? element.checked
			: element.value
	})
	for (const kind of ['buttons', 'hotkeys']) {
		const bindings = {}
		container.querySelectorAll(`.arcade-key-binding[data-kind="${kind}"]`)
			.forEach((element) => {
				bindings[element.dataset.binding] = element.dataset.code
			})
		if (Object.keys(bindings).length > 0) {
			settings[kind] = bindings
		}
	}
	settings.thumbnail_types = {}
	container.querySelectorAll('.arcade-thumbnail-type').forEach((element) => {
		settings.thumbnail_types[element.dataset.system] = element.value
	})
	settings.core_options = {}
	container.querySelectorAll('.arcade-core-option').forEach((element) => {
		if (element.value !== '') {
			settings.core_options[element.dataset.core] ??= {}
			settings.core_options[element.dataset.core][element.dataset.option] = element.value
		}
	})

	status.textContent = t('arcade', 'Saving …')
	// Whether it worked is the caller's business too: anything that acts
	// on the settings it has just saved needs to know they were saved.
	let saved = true
	try {
		const url = container.dataset.scope === 'admin'
			? '/apps/arcade/arcade/settings/admin'
			: '/apps/arcade/arcade/settings'
		await api(generateUrl(url), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(settings),
		})
		status.textContent = t('arcade', 'Saved')
	} catch (error) {
		console.error('Could not save Arcade settings', error)
		status.textContent = t('arcade', 'Could not save the settings')
		saved = false
	}
	flashStatus(status)
	return saved
}

/**
 * Put every option of a core back to "Core default".
 *
 * @param {HTMLElement} button the reset button of the core
 */
function resetCore(button) {
	container.querySelectorAll(`.arcade-core-option[data-core="${CSS.escape(button.dataset.core)}"]`)
		.forEach((element) => {
			element.value = ''
		})
	save(button)
}

/**
 * Keep the value next to a slider in step with it.
 *
 * @param {HTMLInputElement} range the slider
 */
function showRangeValue(range) {
	const output = container.querySelector(`output[for="${CSS.escape(range.id)}"]`)
	if (output !== null) {
		output.textContent = range.value + (range.dataset.unit ?? '')
	}
}

// A key held with one of these never reaches the player: its own handler
// steps aside for Control, Alt and Meta so the browser's shortcuts keep
// working, which leaves a hotkey bound to one of them dead on arrival.
// Shift is not among them, so a shift key is a fair binding.
const MODIFIER_ONLY = new Set([
	'ControlLeft', 'ControlRight', 'AltLeft', 'AltRight', 'MetaLeft', 'MetaRight', 'AltGraph',
])

// A capture swallows every key of the page while it waits, so it gives up
// on its own rather than leaving the keyboard locked for good.
const CAPTURE_TIMEOUT = 15000

// Only one binding waits for a key at a time. Without this, a second click
// on a button already waiting stacked a second document listener, and
// every keypress was then taken twice -- and saved twice.
let capturing = null

/**
 * Say what is wrong with the key a binding carries, where it can be seen
 * as well as heard.
 *
 * @param {HTMLElement} element the button of the binding
 * @param {string} message what is wrong, or an empty string when nothing is
 */
function showHint(element, message) {
	element.title = message
	const hint = document.getElementById(
		`arcade-key-hint-${element.dataset.kind}-${element.dataset.binding}`,
	)
	if (hint === null) {
		return
	}
	hint.textContent = message
	if (message === '') {
		element.removeAttribute('aria-describedby')
	} else {
		element.setAttribute('aria-describedby', hint.id)
	}
}

/**
 * Wait for a key, and give it to a binding.
 *
 * @param {HTMLElement} element the button of the binding
 */
function captureKey(element) {
	if (capturing !== null) {
		// The same button again means "never mind"; another one means "that
		// one instead". Either way the capture already open ends first.
		const same = capturing.element === element
		capturing.cancel()
		if (same) {
			return
		}
	}

	const previous = element.dataset.code
	element.classList.add('capturing')
	// The way out is part of what the button is called while it waits, so
	// it is read out with it rather than only being there to be guessed.
	element.textContent = t('arcade', 'Press a key, or Escape to leave it be')
	showHint(element, '')

	let timer = null

	const done = (code, message = '') => {
		if (capturing === null || capturing.element !== element) {
			return
		}
		capturing = null
		clearTimeout(timer)
		document.removeEventListener('keydown', onKey, true)
		document.removeEventListener('pointerdown', onPointerDown, true)
		element.removeEventListener('blur', onBlur)
		element.classList.remove('capturing')
		if (code !== null) {
			element.dataset.code = code
		}
		showBinding(element)
		showShadowedHotkeys()
		if (message !== '') {
			showHint(element, message)
		}
		if (element.dataset.code !== previous) {
			save(element)
		}
	}

	const onKey = (event) => {
		event.preventDefault()
		event.stopPropagation()
		if (event.code === 'Escape') {
			done(previous)
			return
		}
		// The controller is bound through RetroArch, which has to have a
		// name for the key; the player itself can take any of them.
		if (element.dataset.kind === 'buttons' && retroarchKey(event.code) === null) {
			done(previous, t('arcade', 'The emulator has no name for that key, try another one'))
			return
		}
		if (element.dataset.kind === 'hotkeys' && MODIFIER_ONLY.has(event.code)) {
			done(previous, t('arcade', 'The player leaves Control, Alt and Meta to the browser, try another one'))
			return
		}
		done(event.code)
	}

	// Walking away from the button, by keyboard or by mouse, is as good an
	// answer as Escape: the page gets its keyboard back either way.
	const onBlur = () => done(previous)
	const onPointerDown = (event) => {
		if (!element.contains(event.target)) {
			done(previous)
		}
	}

	capturing = { element, cancel: () => done(previous) }
	timer = setTimeout(
		() => done(previous, t('arcade', 'No key was pressed, so this one was left as it was')),
		CAPTURE_TIMEOUT,
	)
	document.addEventListener('keydown', onKey, true)
	document.addEventListener('pointerdown', onPointerDown, true)
	element.addEventListener('blur', onBlur)
}

/**
 * @param {HTMLElement} element the button of a binding
 */
function showBinding(element) {
	element.textContent = keyLabel(element.dataset.code)
}

/**
 * Say so where a key is bound more than once. A key of the player that is
 * also a key of the controller goes to the game, and the player is left
 * waiting for a key that never comes; two bindings of one kind on one key
 * are worse, with one hotkey of the pair silently dead, or two buttons of
 * the controller pressed at once.
 */
function showShadowedHotkeys() {
	const taken = new Set(
		[...container.querySelectorAll('.arcade-key-binding[data-kind="buttons"]')]
			.map((element) => element.dataset.code),
	)
	for (const kind of ['buttons', 'hotkeys']) {
		const elements = [...container.querySelectorAll(`.arcade-key-binding[data-kind="${kind}"]`)]
		const counts = new Map()
		for (const element of elements) {
			const code = element.dataset.code
			counts.set(code, (counts.get(code) ?? 0) + 1)
		}
		for (const element of elements) {
			const code = element.dataset.code
			let message = ''
			if (code && counts.get(code) > 1) {
				message = kind === 'buttons'
					? t('arcade', 'Another button of the controller has this key too, so both are pressed at once')
					: t('arcade', 'Another hotkey has this key too, so only one of the two ever runs')
			} else if (kind === 'hotkeys' && taken.has(code)) {
				message = t('arcade', 'This key works a button of the controller, so the game gets it instead')
			}
			element.classList.toggle('shadowed', message !== '')
			showHint(element, message)
		}
	}
}

/**
 * Ask for the box art of the games that have none. The looking itself runs
 * as a background job, so this only starts it and reports what it says.
 */
async function fetchThumbnails() {
	const button = document.getElementById('arcade-fetch-thumbnails')
	const status = document.getElementById('arcade-fetch-status')
	button.disabled = true
	status.textContent = t('arcade', 'Starting …')
	// Saving first, so a folder just typed in is the one used. A save that
	// did not land would leave the job looking in the folder from before
	// while this page reported that all was well, so it stops here.
	if (!await save(button)) {
		status.textContent = t('arcade', 'The settings could not be saved, so nothing was started.')
		button.disabled = false
		return
	}
	try {
		await api(generateUrl('/apps/arcade/arcade/thumbnails/fetch'), { method: 'POST' })
		status.textContent = t('arcade', 'Looking for box art in the background. It carries on without this page.')
	} catch (error) {
		console.error('Could not look for box art', error)
		status.textContent = t('arcade', 'Could not start looking. A thumbnails folder has to be set first.')
	}
	button.disabled = false
}

/**
 * Show what the background job last had to say for itself.
 */
async function showFetchStatus() {
	const status = document.getElementById('arcade-fetch-status')
	if (status === null) {
		return
	}
	try {
		const result = await (await api(generateUrl('/apps/arcade/arcade/thumbnails/fetch'))).json()
		if (result.message) {
			status.textContent = result.queued
				? t('arcade', '{message}, still going', result)
				: result.message
		}
	} catch (error) {
		// Only the last word on an old run was at stake.
	}
}

// The upload endpoint refuses anything bigger; a real BIOS is far smaller.
const BIOS_MAX_SIZE = 16 * 1024 * 1024

/**
 * Ask the server what the system folder and the instance store hold, and
 * show every row accordingly. A file from the store cannot be removed
 * here -- that store belongs to occ arcade:bios -- so its row says where
 * it comes from instead of offering a remove.
 */
async function refreshBios() {
	const section = document.getElementById('arcade-bios-section')
	if (section === null) {
		return
	}
	let status
	try {
		status = await (await api(generateUrl('/apps/arcade/arcade/bios/status'))).json()
	} catch (error) {
		console.error('Could not read the BIOS status', error)
		return
	}
	const files = new Map()
	for (const entry of status.systems ?? []) {
		for (const file of entry.files ?? []) {
			files.set(file.name, file)
		}
	}
	section.querySelectorAll('.arcade-bios-file').forEach((row) => {
		const file = files.get(row.dataset.name)
		if (file === undefined) {
			return
		}
		const fromStore = file.present && file.source === 'store'
		let state = t('arcade', 'Missing')
		if (fromStore) {
			state = t('arcade', 'Present ({size}, instance store, managed with occ arcade:bios)',
				{ size: formatSize(file.size) })
		} else if (file.present) {
			state = t('arcade', 'Present ({size})', { size: formatSize(file.size) })
		}
		row.querySelector('.arcade-bios-state').textContent = state
		row.querySelector('.arcade-bios-input').classList.toggle('hidden', file.present)
		row.querySelector('.arcade-bios-remove').classList.toggle('hidden', !file.present || fromStore)
	})
}

/**
 * Send the file just picked to the system folder, under the name of its row.
 *
 * @param {HTMLInputElement} input the file input of a BIOS row
 */
async function uploadBios(input) {
	const row = input.closest('.arcade-bios-file')
	const status = statusFor(input)
	const file = input.files[0]
	if (file === undefined) {
		return
	}
	if (file.size > BIOS_MAX_SIZE) {
		status.textContent = t('arcade', 'That file is too big to be a BIOS')
		input.value = ''
		return
	}
	status.textContent = t('arcade', 'Uploading …')
	try {
		await api(generateUrl('/apps/arcade/arcade/bios?name={name}', { name: row.dataset.name }), {
			method: 'POST',
			headers: { 'Content-Type': 'application/octet-stream' },
			body: file,
		})
		status.textContent = t('arcade', 'Saved')
	} catch (error) {
		console.error('Could not upload the BIOS file', error)
		status.textContent = t('arcade', 'Could not upload the file')
	}
	input.value = ''
	await refreshBios()
	flashStatus(status)
}

/**
 * Take the file of a row back out of the system folder.
 *
 * @param {HTMLElement} button the remove button of a BIOS row
 */
async function removeBios(button) {
	const row = button.closest('.arcade-bios-file')
	const status = statusFor(button)
	status.textContent = t('arcade', 'Removing …')
	try {
		await api(generateUrl('/apps/arcade/arcade/bios?name={name}', { name: row.dataset.name }), {
			method: 'DELETE',
		})
		status.textContent = t('arcade', 'Removed')
	} catch (error) {
		// 409 says the file only lives in the instance store, which is not
		// this page's to delete; anything else really did go wrong.
		if (String(error?.message ?? '').startsWith('409')) {
			status.textContent = t('arcade', 'This file comes from the instance store; manage it with occ arcade:bios')
		} else {
			console.error('Could not remove the BIOS file', error)
			status.textContent = t('arcade', 'Could not remove the file')
		}
	}
	await refreshBios()
	flashStatus(status)
}

if (container !== null) {
	// No save button: any change is saved right away.
	container.addEventListener('change', (event) => {
		if (event.target.matches('.arcade-setting, .arcade-thumbnail-type, .arcade-core-option')) {
			save(event.target)
		}
	})
	document.getElementById('arcade-fetch-thumbnails')?.addEventListener('click', fetchThumbnails)
	container.querySelectorAll('.arcade-key-binding').forEach((element) => {
		showBinding(element)
		element.addEventListener('click', () => captureKey(element))
	})
	showShadowedHotkeys()
	document.getElementById('arcade-keys-reset')?.addEventListener('click', (event) => {
		container.querySelectorAll('.arcade-key-binding').forEach((element) => {
			element.dataset.code = element.dataset.default ?? element.dataset.code
			showBinding(element)
		})
		showShadowedHotkeys()
		save(event.target)
	})
	showFetchStatus()
	container.querySelectorAll('.arcade-range').forEach((range) => {
		range.addEventListener('input', () => showRangeValue(range))
	})
	container.querySelectorAll('.arcade-core-reset').forEach((button) => {
		button.addEventListener('click', () => resetCore(button))
	})
	container.querySelectorAll('.arcade-folder-picker').forEach((button) => {
		button.addEventListener('click', () => {
			pickFolder(document.getElementById(button.dataset.target))
		})
	})
	container.querySelectorAll('.arcade-bios-input').forEach((input) => {
		input.addEventListener('change', () => uploadBios(input))
	})
	container.querySelectorAll('.arcade-bios-remove').forEach((button) => {
		button.addEventListener('click', () => removeBios(button))
	})
	refreshBios()
}
