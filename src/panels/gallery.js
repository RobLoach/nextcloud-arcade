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
 * @return {{element: HTMLElement, refresh: Function}} the panel
 */
export function createGalleryPanel({ romPath, flash }) {
	const element = createPanel('arcade-gallery', t('arcade', 'Screenshots'))

	const grid = document.createElement('div')
	grid.className = 'arcade-gallery-grid'
	element.appendChild(grid)

	const empty = document.createElement('p')
	empty.className = 'arcade-gallery-empty hidden'
	element.appendChild(empty)

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

	// What count() fetched for the button, kept for the first refresh, so
	// the launch does not ask for the same list twice.
	let counted = null

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
			data = counted ?? await list()
			counted = null
		} catch (error) {
			console.error('Could not list the screenshots', error)
			return
		}

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
	}

	/**
	 * @return {Promise<number>} how many screenshots this game has
	 */
	const count = async () => {
		try {
			counted = await list()
			return counted.screenshots.length
		} catch (error) {
			return 0
		}
	}

	return { element, refresh, count }
}
