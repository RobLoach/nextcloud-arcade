import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { ICONS, icon } from './icons.js'
import { isPlayable, romMimes, systemForFolderPath } from './systems.js'

const ACTION_ID = 'arcade-play'

/**
 * A "Play with Arcade" entry in the file menu.
 *
 * The Viewer only ever matches a mimetype, so it cannot offer a game whose
 * mimetype Nextcloud has not been taught yet -- a file action is handed the
 * whole node, so it can go by the extension, and by the folder the game
 * sits in, the way the Arcade page does. Zips are the one exception: the
 * Viewer claims application/zip outright and looks inside when opened.
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
		// The viewer plays it in place when it knows the mimetype. It
		// handles zips too, working out for itself what is inside.
		if (window.OCA?.Viewer !== undefined
			&& (romMimes().includes(node.mime) || node.mime === 'application/zip')) {
			window.OCA.Viewer.open({ path: node.path })
			return null
		}
		window.location.href = generateUrl('/apps/arcade/?file={file}', { file: node.path })
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
