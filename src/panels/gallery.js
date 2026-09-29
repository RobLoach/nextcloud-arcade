import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { api } from '../api.js'
import { ICONS, icon } from '../icons.js'
import { previewUrl } from '../play.js'
import { createPanel } from './panel.js'

/**
 * Build the panel listing the screenshots taken of a game.
 *
 * @param {object} options options
 * @param {string} options.romPath path identifying the game
 * @param {Function} options.flash shows a status message
 * @param {?Function} [options.onCount] told how many there are, each time
 *                                      the list comes back
 * @return {{element: HTMLElement, refresh: Function, count: Function}} the panel
 */
export function createGalleryPanel({ romPath, flash, onCount = null }) {
	const element = createPanel('arcade-gallery', t('arcade', 'Screenshots'))

	const grid = document.createElement('div')
	grid.className = 'arcade-gallery-grid'
	element.appendChild(grid)

	const empty = document.createElement('p')
	empty.className = 'arcade-gallery-empty hidden'
	element.appendChild(empty)

	// A listing that did not come back says so and offers another go,
	// rather than leaving an empty dialog with nothing to press.
	const failure = document.createElement('p')
	failure.className = 'arcade-gallery-empty hidden'
	failure.appendChild(document.createTextNode(t('arcade', 'Could not read the screenshots.')))
	const retryButton = document.createElement('button')
	retryButton.type = 'button'
	retryButton.textContent = t('arcade', 'Retry')
	retryButton.addEventListener('click', (event) => {
		event.stopPropagation()
		refresh()
	})
	failure.appendChild(retryButton)
	element.appendChild(failure)

	const remove = async (fileId) => {
		try {
			await api(generateUrl('/apps/arcade/arcade/screenshots?fileId={fileId}', { fileId }), {
				method: 'DELETE',
			})
			await refresh()
		} catch (error) {
			console.error('Could not delete the screenshot', error)
			flash(t('arcade', 'Could not delete the screenshot'))
		}
	}

	const list = async () => {
		const response = await api(generateUrl(
			'/apps/arcade/arcade/screenshots?file={file}',
			{ file: romPath },
		))
		return await response.json()
	}

	const refresh = async () => {
		let data
		try {
			// Always asked afresh: the launch's answer is stale the moment
			// the first screenshot of the game is taken, and it was that
			// stale answer the panel used to open with.
			data = await list()
		} catch (error) {
			console.error('Could not list the screenshots', error)
			grid.innerHTML = ''
			empty.classList.add('hidden')
			failure.classList.remove('hidden')
			return
		}
		failure.classList.add('hidden')

		grid.innerHTML = ''
		if (data.folder === '') {
			empty.textContent = t('arcade', 'Set a screenshots folder in the Arcade settings to keep your screenshots.')
		} else if (data.screenshots.length === 0) {
			empty.textContent = t('arcade', 'No screenshots of this game yet.')
		} else {
			empty.textContent = ''
		}
		empty.classList.toggle('hidden', empty.textContent === '')

		for (const screenshot of data.screenshots) {
			const item = document.createElement('figure')
			item.className = 'arcade-gallery-item'

			const link = document.createElement('a')
			link.href = generateUrl('/f/{fileId}', { fileId: screenshot.fileId })
			link.target = '_blank'
			link.rel = 'noreferrer noopener'
			link.title = screenshot.basename

			const image = document.createElement('img')
			image.src = previewUrl(screenshot.fileId, 256, 192)
			image.alt = screenshot.basename
			image.loading = 'lazy'
			link.appendChild(image)
			item.appendChild(link)

			const caption = document.createElement('figcaption')
			caption.textContent = new Date(screenshot.mtime * 1000).toLocaleString()
			item.appendChild(caption)

			const deleteButton = document.createElement('button')
			deleteButton.type = 'button'
			deleteButton.title = t('arcade', 'Delete')
			// A grid of buttons all called "Delete" is a grid of one
			// button, read out; the file name is what tells them apart.
			deleteButton.setAttribute('aria-label', t('arcade', 'Delete {name}', { name: screenshot.basename }))
			deleteButton.innerHTML = icon(ICONS.trash)
			deleteButton.addEventListener('click', (event) => {
				event.stopPropagation()
				remove(screenshot.fileId)
			})
			item.appendChild(deleteButton)

			grid.appendChild(item)
		}
		onCount?.(data.screenshots.length)
	}

	/**
	 * @return {Promise<number>} how many screenshots this game has
	 */
	const count = async () => {
		try {
			return (await list()).screenshots.length
		} catch (error) {
			return 0
		}
	}

	return { element, refresh, count }
}
