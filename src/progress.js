import { translate as t } from '@nextcloud/l10n'
import { formatSize } from './format.js'

// Enough to tell two bars apart, should a page ever carry two players.
let bars = 0

/**
 * Bytes coming in from several downloads at once, added up.
 *
 * Each download is told apart by a key, and says how big it is once its
 * headers are in -- or does not, when the server sends no length, as it
 * does not for a core it compresses on the way. Only the downloads with
 * a length are measured, then: the game, from WebDAV, always has one, and
 * is where the time goes. A bar that waited for every length would stand
 * still for as long as the core is coming in.
 *
 * A length that is known is never overshot either: a compressed response
 * that does say one says the size of what was sent, and delivers more
 * than that once unpacked.
 *
 * @param {Function} onChange told {loaded, total} after every change;
 *                            total is null while no download has a
 *                            known length, and loaded is then every byte
 * @return {{track: Function, advance: Function, snapshot: Function}} the tracker
 */
export function createProgressTracker(onChange) {
	const downloads = new Map()

	const snapshot = () => {
		let loaded = 0
		let total = 0
		let unmeasured = 0
		for (const download of downloads.values()) {
			if (download.total === null) {
				unmeasured += download.loaded
			} else {
				loaded += Math.min(download.loaded, download.total)
				total += download.total
			}
		}
		return total === 0 ? { loaded: unmeasured, total: null } : { loaded, total }
	}

	return {
		/**
		 * @param {string} key names the download
		 * @param {?number} total its length, or null when unknown
		 */
		track(key, total) {
			const known = Number.isFinite(total) && total > 0 ? total : null
			downloads.set(key, { loaded: downloads.get(key)?.loaded ?? 0, total: known })
			onChange(snapshot())
		},
		/**
		 * @param {string} key names the download
		 * @param {number} bytes how many more came in
		 */
		advance(key, bytes) {
			const download = downloads.get(key) ?? { loaded: 0, total: null }
			download.loaded += bytes
			downloads.set(key, download)
			onChange(snapshot())
		},
		snapshot,
	}
}

/**
 * The length a response says it has, or null.
 *
 * @param {Response} response the response
 * @return {?number} its Content-Length
 */
export function lengthOf(response) {
	const length = Number(response.headers?.get?.('Content-Length') ?? '')
	return Number.isFinite(length) && length > 0 ? length : null
}

/**
 * Read a whole response, saying how many bytes came in as they come.
 *
 * @param {Response} response the response, not read yet
 * @param {Function} onBytes told the size of each piece that arrives
 * @return {Promise<Blob>} everything it carried
 */
export async function readWithProgress(response, onBytes) {
	const reader = response.body?.getReader?.()
	if (reader === undefined) {
		// Nothing to watch it arrive through; it comes in one piece.
		const blob = await response.blob()
		onBytes(blob.size)
		return blob
	}
	const chunks = []
	for (;;) {
		const { done, value } = await reader.read()
		if (done) {
			break
		}
		chunks.push(value)
		onBytes(value.byteLength)
	}
	return new Blob(chunks, { type: response.headers?.get?.('Content-Type') ?? '' })
}

/**
 * What a game shows while it is on its way: a progress bar over the
 * player, the native one, which Nextcloud styles the way its own
 * progress bars look.
 *
 * Downloading is where the time goes -- a disc image is hundreds of
 * megabytes -- so the bar follows the bytes, and says how many of how
 * many. Starting the core afterwards takes a moment with nothing to
 * count, which the bar says by no longer showing how far along it is.
 *
 * @param {HTMLElement} container the player, to lay the bar over
 * @param {string} name the file name of the game
 * @return {{update: Function, remove: Function}} the bar
 */
export function showLoading(container, name) {
	const element = document.createElement('div')
	element.className = 'arcade-loading'

	// The words change twice, so they are what is read out; the figures
	// beneath change on every piece that arrives, and are not.
	const label = document.createElement('span')
	label.className = 'arcade-loading-label'
	label.setAttribute('role', 'status')
	label.id = `arcade-loading-label-${++bars}`
	label.textContent = t('arcade', 'Downloading {name} …', { name })

	const bar = document.createElement('progress')
	bar.className = 'arcade-loading-bar'
	bar.max = 1
	bar.setAttribute('aria-labelledby', label.id)

	const detail = document.createElement('span')
	detail.className = 'arcade-loading-detail'

	element.append(label, bar, detail)
	container.appendChild(element)

	let starting = false
	let latest = null
	let frame = null
	// Pieces arrive many times a frame on a fast line, so only the last
	// one before each frame is drawn.
	const draw = () => {
		frame = null
		const { loaded, total } = latest
		if (total === null) {
			bar.removeAttribute('value')
			detail.textContent = formatSize(loaded)
		} else {
			bar.value = total === 0 ? 0 : loaded / total
			detail.textContent = t('arcade', '{loaded} of {total}', {
				loaded: formatSize(loaded),
				total: formatSize(total),
			})
		}
	}

	return {
		/**
		 * @param {object} progress what the launch says
		 * @param {string} progress.phase 'download' or 'start'
		 * @param {number} [progress.loaded] bytes in so far
		 * @param {?number} [progress.total] bytes expected, null if unknown
		 */
		update(progress) {
			if (progress.phase === 'start') {
				// A download still trickling in after this -- the core,
				// warmed in the background -- has nothing to say any more.
				starting = true
				if (frame !== null) {
					cancelAnimationFrame(frame)
					frame = null
				}
				label.textContent = t('arcade', 'Starting the emulator …')
				bar.removeAttribute('value')
				detail.textContent = ''
				return
			}
			if (starting) {
				return
			}
			latest = progress
			frame ??= requestAnimationFrame(draw)
		},
		remove() {
			if (frame !== null) {
				cancelAnimationFrame(frame)
			}
			element.remove()
		},
	}
}

/**
 * The same box as the loading one, while a game is being closed: the
 * battery save is kept, the save states still on their way are sent, and
 * only then is the emulator stopped -- which can take a moment, during
 * which the player sees the game they asked to leave standing still.
 *
 * @param {HTMLElement} container the element the player is drawn in
 * @param {string} name the file name of the game
 * @return {{say: Function, remove: Function}} says which step it is on,
 *   and takes the box away
 */
export function showClosing(container, name) {
	const element = document.createElement('div')
	element.className = 'arcade-loading arcade-closing'

	const label = document.createElement('span')
	label.className = 'arcade-loading-label'
	label.setAttribute('role', 'status')
	label.id = `arcade-loading-label-${++bars}`
	label.textContent = t('arcade', 'Closing {name} …', { name })

	// Nothing here can be counted, so the bar only says that something
	// is going on.
	const bar = document.createElement('progress')
	bar.className = 'arcade-loading-bar'
	bar.setAttribute('aria-labelledby', label.id)

	const detail = document.createElement('span')
	detail.className = 'arcade-loading-detail'

	element.append(label, bar, detail)
	container.appendChild(element)

	return {
		/**
		 * @param {string} text the step under way
		 */
		say(text) {
			detail.textContent = text
		},
		remove() {
			element.remove()
		},
	}
}
