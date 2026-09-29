import { loadState } from '@nextcloud/initial-state'
import { davUrl, launchRom, recordRecent, startSramSync } from './player.js'
import { systemForFolderPath } from './systems.js'
import { attachToolbar } from './toolbar.js'

const settings = loadState('arcade', 'settings', {})

/**
 * Start a game and everything that goes with it: the save data, the
 * control bar, and what the library remembers of it.
 *
 * Everything the emulator needs is imported here rather than where the
 * player is registered, so the weight only lands when a game is opened.
 *
 * @param {object} options options
 * @param {HTMLCanvasElement} options.canvas the canvas to render into
 * @param {HTMLElement} options.container the element holding the canvas
 * @param {string} options.filename path of the game in the user folder
 * @param {string} options.basename file name of the game
 * @param {string} [options.source] URL to read the game from instead
 * @param {string} [options.closeUrl] where the close button leads
 * @return {Promise<Function>} stops the game and puts everything away,
 *                             answering once the save data is safe
 */
export async function startSession({ canvas, container, filename, basename, source, closeUrl = '' }) {
	// The launch learns of anything it went without -- a missing BIOS --
	// before the toolbar and its status line exist, so the word is held
	// here and handed over below, for the toolbar to flash once it is on
	// screen.
	let notice = ''
	const instance = await launchRom({
		element: canvas,
		romUrl: source ?? davUrl(filename),
		romName: basename,
		settings,
		systemHint: systemForFolderPath(filename),
		romPath: filename,
		onWarning: (text) => {
			notice = text
		},
	})
	const stopSramSync = startSramSync(instance, filename, (settings.saves_folder ?? '') !== '')
	const stopPlayTime = recordRecent(filename)

	// The one way out, wherever it is asked for -- the close button, the
	// Viewer being torn down, a new game taking this one's place. The
	// battery save is waited for first: exiting takes the core away, and
	// the last upload reads the save out of it.
	let detachToolbar = null
	let stopped = false
	const stop = async () => {
		if (stopped) {
			return
		}
		stopped = true
		stopPlayTime()
		await stopSramSync()
		detachToolbar?.()
		try {
			instance.exit()
		} catch (error) {
			console.error('Arcade failed to exit', error)
		}
	}

	detachToolbar = attachToolbar({
		container,
		instance,
		romPath: filename,
		romName: basename,
		settings,
		closeUrl,
		onClose: stop,
		notice,
	})

	return stop
}
