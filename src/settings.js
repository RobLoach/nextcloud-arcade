import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { api } from './api.js'
import { keyLabel, retroarchKey } from './keys.js'

const container = document.getElementById('arcade-settings')

/**
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
	const { FilePickerType, getFilePickerBuilder } = await import('./picker.js')
	const picker = getFilePickerBuilder(t('arcade', 'Choose a folder'))
		.setMultiSelect(false)
		.setMimeTypeFilter(['httpd/unix-directory'])
		.allowDirectories(true)
		.setType(FilePickerType.Choose)
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
	}
	flashStatus(status)
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

/**
 * Wait for a key, and give it to a binding.
 *
 * @param {HTMLElement} element the button of the binding
 */
function captureKey(element) {
	const previous = element.dataset.code
	element.classList.add('capturing')
	element.textContent = t('arcade', 'Press a key …')

	const done = (code) => {
		document.removeEventListener('keydown', onKey, true)
		element.classList.remove('capturing')
		if (code !== null) {
			element.dataset.code = code
		}
		showBinding(element)
		showShadowedHotkeys()
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
			done(previous)
			element.title = t('arcade', 'The emulator has no name for that key, try another one')
			return
		}
		element.title = ''
		done(event.code)
	}
	document.addEventListener('keydown', onKey, true)
}

/**
 * @param {HTMLElement} element the button of a binding
 */
function showBinding(element) {
	element.textContent = keyLabel(element.dataset.code)
}

/**
 * Say so where a key of the player is also a key of the controller: the
 * game gets it, and the player is left waiting for a key that never comes.
 */
function showShadowedHotkeys() {
	const taken = new Set(
		[...container.querySelectorAll('.arcade-key-binding[data-kind="buttons"]')]
			.map((element) => element.dataset.code),
	)
	container.querySelectorAll('.arcade-key-binding[data-kind="hotkeys"]').forEach((element) => {
		const shadowed = taken.has(element.dataset.code)
		element.classList.toggle('shadowed', shadowed)
		element.title = shadowed
			? t('arcade', 'This key works a button of the controller, so the game gets it instead')
			: ''
	})
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
	try {
		// Saving first, so a folder just typed in is the one used.
		await save(button)
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
 * @param {number} bytes a file size
 * @return {string} the size as people write it
 */
function formatBiosSize(bytes) {
	if (bytes < 1024) {
		return `${bytes} B`
	}
	if (bytes < 1024 * 1024) {
		return `${(bytes / 1024).toFixed(1)} KB`
	}
	return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

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
				{ size: formatBiosSize(file.size) })
		} else if (file.present) {
			state = t('arcade', 'Present ({size})', { size: formatBiosSize(file.size) })
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
