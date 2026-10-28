import { getCurrentUser } from '@nextcloud/auth'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { api } from './api.js'
import { uploadFailed } from './panels/states.js'
import { davUrl, launchRom, recordRecent, startSramSync } from './player.js'
import { showClosing, showLoading } from './progress.js'
import { createStateCache } from './statecache.js'
import { systemForFolderPath } from './systems.js'
import { createFlash } from './toast.js'
import { oneAtATime } from './corequeue.js'
import { attachToolbar } from './toolbar.js'
import { waitAtMost } from './wait.js'

const settings = loadState('arcade', 'settings', {})

// How long a closing game waits for the save states still on their way to
// the server. They were saved, as far as the player knows, so this is far
// longer than the battery save is given -- but a line that has stopped
// altogether must not keep the player in a game they closed.
const STATES_FINAL_WAIT = 60 * 1000

// How often opening a game also puts the Cloud Sync manifest right. Saves
// copied into the folder some other way only reach RetroArch once they are
// recorded, which the weekly job does by itself; a game opened is a good
// moment not to wait for it, but every game opened would be too many.
const RECONCILE_EVERY = 60 * 60 * 1000

/**
 * Record in the Cloud Sync manifest the saves that got into the saves
 * folder some other way, at most once an hour from this browser. Only the
 * quick check: files the manifest already has and that were not touched
 * since are taken at their word.
 */
function recordCopiedSaves() {
	const key = `arcade:cloudsync:${getCurrentUser()?.uid ?? ''}`
	try {
		if (Date.now() - Number(localStorage.getItem(key) ?? 0) < RECONCILE_EVERY) {
			return
		}
		localStorage.setItem(key, String(Date.now()))
	} catch {
		// No storage to remember it in, so no telling when it last ran;
		// the weekly job still does.
		return
	}
	api(generateUrl('/apps/arcade/arcade/cloudsync'), { method: 'POST' }).catch((error) => {
		// The weekly job tries again; nothing the player is doing waits on it.
		console.error('Could not record the copied saves for RetroArch', error)
	})
}

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
 * @param {?AbortSignal} [options.signal] abandons the launch when it fires
 * @return {Promise<Function>} stops the game and puts everything away,
 *                             answering once the save data is safe
 */
export async function startSession({ canvas, container, filename, basename, source, closeUrl = '', signal = null }) {
	// Messages belong to the session, not to the toolbar: a toast hangs
	// in the player's container, which exists from the start, so the
	// launch can say what it went without -- a missing BIOS, a complaint
	// from the core -- before there is any chrome to say it on.
	const { flash, stop: stopMessages } = createFlash(container)
	// The bar lays itself over the player, which the toolbar would only
	// make the frame of everything later.
	container.classList.add('arcade-player-container')
	const loading = showLoading(container, basename)
	let instance
	try {
		instance = await launchRom({
			element: canvas,
			romUrl: source ?? davUrl(filename),
			romName: basename,
			settings,
			systemHint: systemForFolderPath(filename),
			romPath: filename,
			onMessage: flash,
			signal,
			onProgress: loading.update,
		})
	} finally {
		loading.remove()
	}
	// Everything that asks the core for a file goes through the one queue
	// from here on: the panel, the screenshot button and the battery save
	// each had a gate of their own and none could see the others.
	const core = oneAtATime(instance)
	const canSave = getCurrentUser() !== null && filename !== '' && (settings.saves_folder ?? '') !== ''
	const stopSramSync = startSramSync(core, filename, canSave)
	const stopPlayTime = recordRecent(filename)
	// The save states of the game, held for the session so a slot loads
	// and saves at once. Saving needs a logged-in user and somewhere of
	// their own to put it, so on a public share, or without a saves
	// folder, there are none to hold.
	const stateCache = canSave
		? createStateCache({ romPath: filename, onUploadFailed: uploadFailed(flash) })
		: null
	if (canSave) {
		recordCopiedSaves()
	}

	// The one way out, wherever it is asked for -- the close button, the
	// Viewer being torn down, a new game taking this one's place. The
	// battery save is waited for first: exiting takes the core away, and
	// the last upload reads the save out of it.
	let detachToolbar = null
	let stopped = false
	// leaving: the page is about to go somewhere else, and the closing
	// box stays up until it does rather than uncover a stopped game.
	const stop = async ({ leaving = false } = {}) => {
		if (stopped) {
			return
		}
		stopped = true
		const closing = showClosing(container, basename)
		stopPlayTime()
		closing.say(t('arcade', 'Keeping the in-game save …'))
		await stopSramSync()
		detachToolbar?.()
		// The states saved last went to the server in the background, and
		// a close that leaves the page would cut them off. The core is not
		// needed for that, but the page is.
		if (stateCache !== null) {
			closing.say(t('arcade', 'Sending the save states to the server …'))
			await waitAtMost(stateCache.flush(), STATES_FINAL_WAIT, undefined)
			stateCache.dispose()
		}
		closing.say(t('arcade', 'Stopping the emulator …'))
		// Nothing left to say it to, and a core on its way out is often
		// at its most talkative.
		stopMessages()
		try {
			instance.exit()
		} catch (error) {
			console.error('Arcade failed to exit', error)
		}
		if (!leaving) {
			closing.remove()
		}
	}

	detachToolbar = attachToolbar({
		container,
		instance: core,
		romPath: filename,
		romName: basename,
		settings,
		closeUrl,
		onClose: stop,
		flash,
		stateCache,
	})

	return stop
}
