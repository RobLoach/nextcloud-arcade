import { loadState } from '@nextcloud/initial-state'
import { t } from './l10n.js'
import { generateUrl } from '@nextcloud/router'
import { renderLibrary } from './library.js'
import { isPlayable } from './systems.js'

const file = loadState('arcade', 'file', '')

const canvas = document.querySelector('.arcade canvas')
const message = document.querySelector('.arcade .arcade-message')
const library = document.querySelector('.arcade .arcade-library')

/**
 * @param {string} text message to show instead of the player
 */
function showMessage(text) {
	canvas.classList.add('hidden')
	library.classList.add('hidden')
	message.classList.remove('hidden')
	message.textContent = text
}

/**
 * List the games found in the user's library folder.
 */
async function showLibrary() {
	canvas.classList.add('hidden')
	library.classList.remove('hidden')
	await renderLibrary(library, showMessage)
}

async function main() {
	if (!file) {
		await showLibrary()
		return
	}
	const basename = file.split('/').pop()
	if (!isPlayable(basename)) {
		showMessage(t('arcade', 'Unsupported ROM type: {file}', { file: basename }))
		return
	}
	document.title = `${basename.replace(/\.[^.]+$/, '')} - Arcade`
	try {
		const { startSession } = await import('./session.js')
		await startSession({
			canvas,
			container: document.querySelector('.arcade'),
			filename: file,
			basename,
			closeUrl: generateUrl('/apps/arcade/'),
		})
	} catch (error) {
		console.error('Arcade failed to start', error)
		showMessage(t('arcade', 'Could not start the emulator: {error}', { error: error.message }))
	}
}

main()
