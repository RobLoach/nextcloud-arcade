import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { ICONS, icon } from './icons.js'
import { playGame } from './play.js'
import { isPlayable, systemForFolderPath } from './systems.js'

const ACTION_ID = 'arcade-play'

// The user's settings, as LoadViewerListener puts them on Files pages. On
// public share pages, or wherever else the listener did not run, there is
// no state to read: null then, and zips are treated as outside the library
// below -- without knowing where the library is, claiming an arbitrary
// archive would be a guess.
const settings = loadState('arcade', 'settings', null)

/**
 * Whether a path lies inside the user's games library folder.
 *
 * Zips carry no mimetype of their own that says "game", so only the ones
 * inside the library are claimed; every other archive is left to Nextcloud
 * as if this app were not installed.
 *
 * @param {string} path file path relative to the user folder
 * @return {boolean} whether the file sits under the library folder
 */
function insideLibrary(path) {
	const library = settings?.library_folder
	if (typeof library !== 'string' || library === '' || typeof path !== 'string') {
		return false
	}
	const file = path.startsWith('/') ? path : `/${path}`
	// The server stores the folder as '/Games': leading slash, no trailing
	// one. Trailing slashes are stripped anyway so a hand-fed value cannot
	// break the prefix match below.
	const folder = library.replace(/\/+$/, '')
	if (folder === '') {
		// A library of '/' means the whole user folder: everything is in.
		return true
	}
	// Prefix match with the separator included, so '/GamesBackup' does not
	// pass as inside '/Games'. Exact case on purpose: Nextcloud paths are
	// case-sensitive, and the settings hold the folder as it is named.
	return file.startsWith(`${folder}/`)
}

/**
 * A "Play with Arcade" entry in the file menu.
 *
 * The Viewer only ever matches a mimetype, so it cannot offer a game whose
 * mimetype Nextcloud has not been taught yet -- a file action is handed the
 * whole node, so it can go by the extension, and by the folder the game
 * sits in, the way the Arcade page does. Zips are the one exception: the
 * Viewer does not claim them at all, since an archive is not guaranteed to
 * be a game, so this entry offers the ones inside the games library and
 * plays them on the app page.
 *
 * It is added to `window._nc_fileactions` by hand rather than through
 * `registerFileAction` from `@nextcloud/files`: importing that package costs
 * a quarter of a megabyte on every page of the Files app, and this entry is
 * a few lines. The list is not a public API, so everything here is
 * best-effort: if Nextcloud ever stops reading plain objects from it, the
 * entry quietly does not appear, and nothing else is affected.
 */

/**
 * The nodes an action was called with, whichever way it was called.
 *
 * Nextcloud 34 and 35 pass a context object, `{ nodes, view }`. Older and
 * newer ones have passed the nodes, or a single node, directly.
 *
 * @param {object|Array} context what the Files app handed over
 * @return {object[]} the nodes
 */
function nodesOf(context) {
	if (Array.isArray(context)) {
		return context
	}
	if (Array.isArray(context?.nodes)) {
		return context.nodes
	}
	if (context !== null && typeof context === 'object' && typeof context.path === 'string') {
		return [context]
	}
	return []
}

/**
 * @param {object} node a node of the Files app
 * @return {boolean} whether the player could make something of it
 */
function playable(node) {
	if (node?.type === 'folder' || typeof node?.basename !== 'string') {
		return false
	}
	// Zips are only offered inside the games library: outside of it, an
	// archive is left to Nextcloud as if this app were not installed.
	// Every other ROM type names its system and stays ungated.
	if ((node.mime ?? '') === 'application/zip' || node.basename.toLowerCase().endsWith('.zip')) {
		return insideLibrary(node.path ?? '')
	}
	return isPlayable(node.basename, node.mime ?? '')
		|| systemForFolderPath(node.path ?? '') !== null
}

const action = {
	id: ACTION_ID,
	displayName: () => t('arcade', 'Play with Arcade'),
	iconSvgInline: () => icon(ICONS.gamepad),
	// After the actions of Nextcloud itself, and never the default one: a
	// game whose mimetype is known opens in the viewer on its own.
	order: 1000,

	enabled(context) {
		const nodes = nodesOf(context)
		return nodes.length === 1 && playable(nodes[0])
	},

	async exec(context) {
		const node = nodesOf(context)[0]
		if (node === undefined) {
			return null
		}
		// The viewer plays it in place when it knows the ROM mimetype.
		// Everything else -- zips included, which the Viewer no longer
		// claims -- goes to the app page, by file id when the node
		// carries one so the link survives renames and moves.
		playGame(node.path, node.mime, node.fileid)
		return null
	},
}

/**
 * @return {boolean} whether the entry was added
 */
export function registerPlayAction() {
	try {
		if (window._nc_fileactions === undefined) {
			window._nc_fileactions = []
		}
		if (window._nc_fileactions.some((registered) => registered?.id === ACTION_ID)) {
			return false
		}
		window._nc_fileactions.push(action)
		return true
	} catch (error) {
		console.debug('Arcade could not add its entry to the file menu', error)
		return false
	}
}
