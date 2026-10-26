import { defaultRootPath } from '@nextcloud/files/dav'
import { generateUrl } from '@nextcloud/router'
import { fileIdOf } from './player.js'

/**
 * Whether this page can open the Files sidebar. The app page loads it for
 * the library and the player's actions menu; inside the Files app the
 * Viewer brings a sidebar button of its own.
 *
 * Only Nextcloud 35 can render the sidebar outside the Files app, and
 * _mountSidebar is what does it. Nextcloud 34 defines OCA.Files._sidebar
 * on this page too, but as a store whose open() refuses without the Files
 * navigation behind it.
 *
 * @return {boolean} whether there is a sidebar to open here
 */
export function hasFilesSidebar() {
	const files = window.OCA?.Files
	return typeof files?._mountSidebar === 'function'
		&& typeof files?._sidebar === 'function'
		&& Boolean(files._sidebar())
}

/**
 * Open the Files sidebar on a game.
 *
 * @param {string} path the path of the game, from the root of the user's files
 * @return {Promise<boolean>} whether there was a sidebar to open
 */
export async function openFilesSidebar(path) {
	if (!hasFilesSidebar()) {
		return false
	}
	// Rendered by this page, the sidebar shows whatever node it is handed
	// and fetches nothing, so it is handed a whole one -- its permissions
	// and attributes are what the other tabs go by.
	const { getClient, getDefaultPropfind, resultToNode } = await import('@nextcloud/files/dav')
	const stat = await getClient().stat(`${defaultRootPath}${path}`, { details: true, data: getDefaultPropfind() })
	window.OCA.Files._sidebar().open(resultToNode(stat.data), 'arcade')
	return true
}

/**
 * The details of a game, wherever they can be had: the sidebar of this
 * page when it carries one, or else the Files app with the file's details
 * pane open -- one page away, so whatever is running here ends.
 *
 * @param {string} path the path of the game, from the root of the user's files
 * @param {?number} [fileId] the id of the file, when it is already known
 */
export async function showDetails(path, fileId = null) {
	const absolute = path.startsWith('/') ? path : `/${path}`
	try {
		if (await openFilesSidebar(absolute)) {
			return
		}
	} catch (error) {
		console.error('arcade: could not open the Files sidebar', error)
	}
	console.warn('arcade: this page carries no Files sidebar; opening the Files app instead')
	const dir = absolute.replace(/\/[^/]*$/, '') || '/'
	let target
	try {
		const id = fileId ?? await fileIdOf(path)
		target = generateUrl('/apps/files/files/' + id) + '?dir=' + encodeURIComponent(dir) + '&opendetails=true'
	} catch (error) {
		console.warn('arcade: could not resolve the file id, opening the folder instead', error)
		target = generateUrl('/apps/files') + '?dir=' + encodeURIComponent(dir)
	}
	window.location.href = target
}
