import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { AUTO_SLOT, api, encodePath } from './api.js'
import { formatPlayTime } from './format.js'
import { ICONS, icon } from './icons.js'
import { playGame } from './play.js'
import { romMimes } from './systems.js'

/**
 * The Arcade tab of the Files sidebar: what the app knows about a ROM --
 * its system, what the cartridge calls itself, what it has been played
 * for, the saves waiting in its slots -- and a button that plays it.
 *
 * Registered two ways, as Nextcloud has two sidebars: through the plain-DOM
 * callbacks of OCA.Files.Sidebar up to Nextcloud 34, and as a web component
 * from Nextcloud 35 on, which replaced that sidebar. Either way no Vue is
 * carried onto the Files page for a definition list.
 */

const TAB_ID = 'arcade'

/**
 * @param {object} fileInfo what the sidebar was opened on
 * @return {string} the path of the file, relative to the user folder
 */
function pathOf(fileInfo) {
	const directory = typeof fileInfo?.path === 'string' ? fileInfo.path : '/'
	const name = typeof fileInfo?.name === 'string' ? fileInfo.name : ''
	return `${directory === '/' ? '' : directory}/${name}`
}

/**
 * @param {object} playtime what the game was played for
 * @return {string} the whole story in one line, empty when never played
 */
function playedLine(playtime) {
	const plays = playtime?.plays ?? 0
	if (plays === 0) {
		return ''
	}
	const times = n('arcade', 'Played %n time', 'Played %n times', plays)
	const duration = formatPlayTime(playtime?.seconds ?? 0)
	return duration === '' ? times : `${times}, ${duration}`
}

/**
 * @param {number} slot the save state slot
 * @return {string} what the slot is called where the player shows it
 */
function slotName(slot) {
	return slot === AUTO_SLOT ? t('arcade', 'Autosave') : t('arcade', 'Slot {slot}', { slot })
}

/**
 * @param {HTMLElement} list the definition list
 * @param {string} label what the value is
 * @param {string} value the value, nothing added when it is empty
 */
function addRow(list, label, value) {
	if (!value) {
		return
	}
	const term = document.createElement('dt')
	term.textContent = label
	const detail = document.createElement('dd')
	detail.textContent = value
	list.append(term, detail)
}

/**
 * @param {object} game what the endpoint answered
 * @param {string} file path of the game
 * @param {string} mime its mimetype
 * @param {number|string} fileId the Nextcloud file id, when known
 * @return {HTMLElement} the filled tab
 */
function render(game, file, mime, fileId) {
	const container = document.createElement('div')
	container.className = 'arcade-sidebar'

	const button = document.createElement('button')
	button.type = 'button'
	button.className = 'primary arcade-sidebar-play'
	button.innerHTML = icon(ICONS.gamepad)
	button.appendChild(document.createTextNode(t('arcade', 'Play')))
	button.addEventListener('click', () => playGame(file, mime, fileId))
	container.appendChild(button)

	const list = document.createElement('dl')
	list.className = 'arcade-sidebar-details'
	addRow(list, t('arcade', 'System'), game.system?.name ?? '')
	addRow(list, t('arcade', 'Title'), game.title ?? '')
	addRow(list, t('arcade', 'Region'), game.region ?? '')
	addRow(list, t('arcade', 'Mapper'), game.mapper ?? '')
	addRow(list, t('arcade', 'Checksum'), game.checksum ?? '')
	addRow(list, t('arcade', 'CRC32'), game.crc32 ?? '')
	addRow(list, t('arcade', 'Played'), playedLine(game.playtime))
	container.appendChild(list)

	const states = Array.isArray(game.states) ? game.states : []
	if (states.length > 0) {
		const heading = document.createElement('h3')
		heading.textContent = t('arcade', 'Save states')
		container.appendChild(heading)
		const slots = document.createElement('ul')
		slots.className = 'arcade-sidebar-slots'
		for (const state of states) {
			const item = document.createElement('li')
			const name = document.createElement('strong')
			name.textContent = slotName(state.slot)
			const when = document.createElement('span')
			when.textContent = new Date(state.mtime * 1000).toLocaleString()
			item.append(name, when)
			// A stale state was marked by colour and a tooltip alone, which
			// is nothing at all to anybody not pointing at it. Said in
			// words, the way the player's own states panel says it.
			if (state.stale === true) {
				const warning = document.createElement('span')
				warning.className = 'arcade-sidebar-stale-warning'
				warning.textContent = t('arcade', 'made from another copy of this game')
				warning.title = t('arcade', 'The ROM has changed since this state was saved, so loading it may go wrong.')
				item.appendChild(warning)
				item.classList.add('arcade-sidebar-stale')
			}
			slots.appendChild(item)
		}
		container.appendChild(slots)
	}
	return container
}

// Which request each tab is waiting on: a tab switched to another file
// before the answer came drops the answer that is no longer its own.
const generations = new WeakMap()

/**
 * Fetches what the app knows about a game and fills a tab with it.
 *
 * @param {HTMLElement} mountPoint the element of the tab
 * @param {{path: string, mime: string, id: number|string}} target the game
 */
async function show(mountPoint, target) {
	const mine = (generations.get(mountPoint) ?? 0) + 1
	generations.set(mountPoint, mine)
	const current = () => generations.get(mountPoint) === mine && mountPoint.isConnected !== false
	const file = target.path
	mountPoint.textContent = ''
	try {
		const response = await api(generateUrl('/apps/arcade/arcade/game?f={f}', { f: encodePath(file) }))
		const game = await response.json()
		if (!current()) {
			return
		}
		mountPoint.textContent = ''
		mountPoint.appendChild(render(game, file, target.mime, target.id))
	} catch (error) {
		if (!current()) {
			return
		}
		mountPoint.textContent = ''
		// A 404 is the endpoint saying it has nothing filed for this file,
		// which is an answer. Anything else -- a rate limit, a server
		// having a bad day, a connection that went away -- is the asking
		// itself failing, and calling that "nothing is known" would be a
		// lie that hides a problem the user can do something about.
		if (String(error?.message ?? '').startsWith('404')) {
			console.debug('Arcade has nothing filed for this game', error)
			const message = document.createElement('p')
			message.className = 'arcade-sidebar-empty'
			message.textContent = t('arcade', 'Nothing is known about this game yet')
			mountPoint.appendChild(message)
			return
		}
		console.error('Arcade could not describe the game', error)
		const message = document.createElement('p')
		message.className = 'arcade-sidebar-error'
		message.textContent = t('arcade', 'Could not ask the server about this game.')
		const retry = document.createElement('button')
		retry.type = 'button'
		retry.textContent = t('arcade', 'Try again')
		retry.addEventListener('click', () => show(mountPoint, target))
		mountPoint.append(message, retry)
	}
}

/**
 * @param {object} fileInfo what the old sidebar was opened on
 * @return {{path: string, mime: string, id: number|string}} the game
 */
function fromFileInfo(fileInfo) {
	return { path: pathOf(fileInfo), mime: fileInfo?.mimetype ?? '', id: fileInfo?.id ?? 0 }
}

/**
 * The sidebar of Nextcloud 34 and before, which calls a tab back with an
 * element to fill.
 *
 * @return {boolean} whether the tab was registered
 */
function registerLegacy() {
	const Sidebar = window.OCA?.Files?.Sidebar
	if (Sidebar?.registerTab === undefined || Sidebar?.Tab === undefined) {
		return false
	}
	let mountPoint = null
	Sidebar.registerTab(new Sidebar.Tab({
		id: TAB_ID,
		name: t('arcade', 'Arcade'),
		iconSvg: icon(ICONS.gamepad),

		enabled(fileInfo) {
			return romMimes().includes(fileInfo?.mimetype ?? '')
		},

		async mount(el, fileInfo) {
			mountPoint = el
			await show(el, fromFileInfo(fileInfo))
		},

		async update(fileInfo) {
			if (mountPoint !== null) {
				await show(mountPoint, fromFileInfo(fileInfo))
			}
		},

		destroy() {
			if (mountPoint !== null) {
				generations.delete(mountPoint)
				mountPoint.textContent = ''
				mountPoint = null
			}
		},
	}))
	return true
}

// The web component the sidebar of Nextcloud 35 renders the tab as, given
// the file it is open on as its node.
const TAG_NAME = 'arcade-files-sidebar-tab'

/**
 * The tab as Nextcloud 35 renders it: an element it hands the node of the
 * file to, and tells when the tab is the one showing.
 */
class ArcadeSidebarTab extends HTMLElement {
	constructor() {
		super()
		this._node = null
		this._shown = null
	}

	connectedCallback() {
		// Set on the element before it was defined, a property hides the
		// setter below; taken back so it goes through it.
		for (const name of ['node', 'active']) {
			if (Object.prototype.hasOwnProperty.call(this, name)) {
				const value = this[name]
				delete this[name]
				this[name] = value
			}
		}
		this.refresh()
	}

	disconnectedCallback() {
		// Whatever was on its way for this file is dropped while the tab is
		// away, so it asks again when it is put back.
		this._shown = null
	}

	get node() {
		return this._node
	}

	set node(node) {
		this._node = node ?? null
		this.refresh()
	}

	get active() {
		return this._active === true
	}

	set active(active) {
		this._active = active === true
		this.refresh()
	}

	refresh() {
		const node = this._node
		if (!this.isConnected || node === null) {
			return
		}
		// Asked once per file, not every time the sidebar sets a property.
		if (this._shown === node.source) {
			return
		}
		this._shown = node.source
		show(this, { path: node.path, mime: node.mime ?? '', id: node.fileid ?? node.id ?? 0 })
	}
}

/**
 * The sidebar of Nextcloud 35 on, which renders a tab as a web component
 * and keeps the tabs in the global scope of @nextcloud/files 4 -- written
 * to directly here, as the library would, rather than carrying a second
 * major version of it onto every Files page for one map.
 */
function registerWebComponent() {
	if (!customElements.get(TAG_NAME)) {
		customElements.define(TAG_NAME, ArcadeSidebarTab)
	}
	window._nc_files_scope ??= {}
	window._nc_files_scope.v4_0 ??= {}
	const scope = window._nc_files_scope.v4_0
	scope.filesSidebarTabs ??= new Map()
	scope.filesSidebarTabs.set(TAB_ID, {
		id: TAB_ID,
		displayName: t('arcade', 'Arcade'),
		iconSvgInline: icon(ICONS.gamepad),
		// After the Files app's own tabs.
		order: 90,
		tagName: TAG_NAME,
		enabled: ({ node }) => romMimes().includes(node?.mime ?? ''),
		onInit: async () => {},
	})
}

// Both, where both may be: the old sidebar is only there to be found once
// the page has loaded, and the new one reads its tabs when it renders.
registerWebComponent()
if (!registerLegacy()) {
	document.addEventListener('DOMContentLoaded', registerLegacy)
}
