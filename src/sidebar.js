import { defaultRootPath } from '@nextcloud/files/dav'
import { t } from './l10n.js'
import { generateUrl } from '@nextcloud/router'
import { ICONS, icon } from './icons.js'
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
 * Give the sidebar the folder and the view the Files app would.
 *
 * Rendered on any page but the Files app, the sidebar of Nextcloud 35 has
 * neither: its stand-in provider leaves both empty. The Sharing tab cannot
 * do without them -- a Vue 2 component behind a web component, whose
 * wrapper throws on the definitions of the two when they come empty, so
 * the tab stayed blank. The provider keeps both in plain refs, so the
 * game's own folder and a view for this page are put in them, which is
 * what every tab and action of the sidebar is written against.
 *
 * @param {object} client the WebDAV client
 * @param {Function} resultToNode turns a WebDAV answer into a node
 * @param {Function} getDefaultPropfind what to ask WebDAV for
 * @param {string} path the path of the game, from the root of the user's files
 */
export async function giveTheSidebarAContext(client, resultToNode, getDefaultPropfind, path) {
	const provider = window.OCA?.Files?.Sidebar?._sharedState?.standaloneProvider
	if (provider?.folder === undefined || provider?.view === undefined) {
		return
	}
	try {
		const dir = path.replace(/\/[^/]*$/, '') || '/'
		const stat = await client.stat(`${defaultRootPath}${dir}`, { details: true, data: getDefaultPropfind() })
		const folder = resultToNode(stat.data)
		provider.folder.value = folder
		provider.view.value ??= {
			id: 'arcade',
			name: t('arcade', 'Arcade'),
			caption: '',
			icon: icon(ICONS.gamepad),
			order: 0,
			getContents: async () => ({ folder, contents: [] }),
		}
	} catch (error) {
		// Without them only the Sharing tab is the worse for it.
		console.warn('arcade: could not give the sidebar the folder of the game', error)
	}
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
	const client = getClient()
	const stat = await client.stat(`${defaultRootPath}${path}`, { details: true, data: getDefaultPropfind() })
	await giveTheSidebarAContext(client, resultToNode, getDefaultPropfind, path)
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
