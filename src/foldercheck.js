import { generateUrl } from '@nextcloud/router'
import { t } from './l10n.js'
import { api } from './api.js'

/**
 * What is wrong with a folder setting, in words, from how the server
 * found it. An empty message is a folder with nothing wrong with it.
 *
 * A saves folder that is not there yet is not wrong: the first save
 * makes it. It is said all the same, as a note rather than an error,
 * so a mistyped name is still caught.
 *
 * @param {string} status what the server said: ok, missing, file,
 *                        unreadable, unavailable or invalid
 * @param {string} key the setting, such as saves_folder
 * @param {boolean} admin whether this is the administration page, where
 *                        the path is read in every user's own files
 * @return {{message: string, error: boolean}} what to say, and whether
 *                                             it is an error
 */
export function folderProblem(status, key, admin = false) {
	switch (status) {
	case 'missing':
		if (key === 'saves_folder') {
			return {
				message: admin
					? t('arcade', 'You have no such folder in your files. It is made for each user the first time they save a game.')
					: t('arcade', 'There is no such folder in your files yet. It is made the first time a game is saved.'),
				error: false,
			}
		}
		return {
			message: admin
				? t('arcade', 'You have no such folder in your files. Every user needs one of their own at this path.')
				: t('arcade', 'There is no such folder in your files.'),
			error: true,
		}
	case 'file':
		return { message: t('arcade', 'This is a file, not a folder.'), error: true }
	case 'unreadable':
		return { message: t('arcade', 'This folder cannot be read.'), error: true }
	case 'unavailable':
		return { message: t('arcade', 'The storage this folder is on cannot be reached right now.'), error: true }
	case 'invalid':
		return { message: t('arcade', 'A folder path cannot contain "..", so this one is not saved.'), error: true }
	default:
		return { message: '', error: false }
	}
}

/**
 * Show what is wrong with a folder field, or that nothing is, beside it.
 *
 * @param {HTMLInputElement} input the folder field
 * @param {{message: string, error: boolean}} problem from folderProblem()
 */
export function showFolderProblem(input, { message, error }) {
	const id = `${input.id}-problem`
	let line = input.ownerDocument.getElementById(id)
	if (line === null) {
		line = input.ownerDocument.createElement('span')
		line.id = id
		line.className = 'arcade-folder-problem'
		line.setAttribute('aria-live', 'polite')
		input.parentElement.appendChild(line)
	}
	line.textContent = message
	line.classList.toggle('arcade-folder-problem--error', error)
	input.classList.toggle('error', error)
	if (error) {
		input.setAttribute('aria-invalid', 'true')
	} else {
		input.removeAttribute('aria-invalid')
	}
	if (message === '') {
		input.removeAttribute('aria-describedby')
	} else {
		input.setAttribute('aria-describedby', id)
	}
}

// A field typed into twice in quick succession asks twice, and the two
// answers can come back the other way around; only the latest is shown.
let latest = 0

/**
 * Ask the server how every folder field of the page stands, as typed,
 * and show it beside each. A check that cannot be made leaves the
 * fields as they were: saying a folder is wrong when it was only the
 * asking that failed would be wrong itself.
 *
 * @param {Element} container the settings page
 */
export async function checkFolders(container) {
	const inputs = [...container.querySelectorAll('input.arcade-setting[data-setting$="_folder"]')]
	if (inputs.length === 0) {
		return
	}
	const query = new URLSearchParams()
	for (const input of inputs) {
		query.set(input.dataset.setting, input.value)
	}
	const asked = ++latest
	let statuses
	try {
		statuses = await (await api(`${generateUrl('/apps/arcade/arcade/settings/folders')}?${query}`)).json()
	} catch (error) {
		console.error('Could not check the Arcade folders', error)
		return
	}
	if (asked !== latest) {
		return
	}
	const admin = container.dataset.scope === 'admin'
	for (const input of inputs) {
		showFolderProblem(input, folderProblem(statuses[input.dataset.setting] ?? 'ok', input.dataset.setting, admin))
	}
}
