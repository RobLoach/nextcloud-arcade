import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { AUTO_SLOT, api, encodePath, stateUrl } from './api.js'
import { formatPlayTime } from './format.js'
import { ICONS, icon } from './icons.js'
import { playGame, previewUrl } from './play.js'
import { romMimes } from './systems.js'

/**
 * The Arcade tab of the Files sidebar: what the app knows about a ROM --
 * its system, what the cartridge calls itself, what it has been played
 * for, the saves waiting in its slots -- and a button that plays it.
 *
 * Registered as a web component, which is how the sidebar of Nextcloud 34
 * and 35 renders a tab, so no Vue is carried onto the Files page for a
 * definition list.
 */

const TAB_ID = 'arcade'

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
 * Whether this page is the Files app, where the file is already in view
 * and a link to it would lead nowhere new.
 *
 * @return {boolean}
 */
function inFilesApp() {
	return /\/apps\/files(\/|$)/.test(window.location.pathname)
}

/**
 * @param {string} file path of the game
 * @param {number|string} fileId the Nextcloud file id
 * @return {string} the file in the Files app, its folder open around it
 */
function filesUrl(file, fileId) {
	const dir = file.replace(/\/[^/]*$/, '') || '/'
	return generateUrl('/apps/files/files/{fileId}?dir={dir}', { fileId, dir })
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

	const actions = document.createElement('div')
	actions.className = 'arcade-sidebar-actions'
	const button = document.createElement('button')
	button.type = 'button'
	button.className = 'primary arcade-sidebar-play'
	button.innerHTML = icon(ICONS.gamepad)
	button.appendChild(document.createTextNode(t('arcade', 'Play')))
	button.addEventListener('click', () => playGame(file, mime, fileId))
	actions.appendChild(button)
	// From the library, the file is one step away in Files: its folder,
	// its shares, its versions.
	if (fileId && !inFilesApp()) {
		const link = document.createElement('a')
		link.className = 'button arcade-sidebar-files'
		link.href = filesUrl(file, fileId)
		link.innerHTML = icon(ICONS.folder)
		link.appendChild(document.createTextNode(t('arcade', 'Show in Files')))
		actions.appendChild(link)
	}
	container.appendChild(actions)

	const list = document.createElement('dl')
	list.className = 'arcade-sidebar-details'
	addRow(list, t('arcade', 'System'), game.system?.name ?? '')
	addRow(list, t('arcade', 'Emulator'), game.emulator ?? '')
	addRow(list, t('arcade', 'Title'), game.title ?? '')
	addRow(list, t('arcade', 'Region'), game.region ?? '')
	addRow(list, t('arcade', 'Mapper'), game.mapper ?? '')
	addRow(list, t('arcade', 'Checksum'), game.checksum ?? '')
	addRow(list, t('arcade', 'CRC32'), game.crc32 ?? '')
	addRow(list, t('arcade', 'Played'), playedLine(game.playtime))
	container.appendChild(list)

	const bios = Array.isArray(game.bios) ? game.bios : []
	if (bios.length > 0) {
		container.appendChild(renderBios(bios))
	}

	const states = Array.isArray(game.states) ? game.states : []
	if (states.length > 0) {
		container.appendChild(heading(t('arcade', 'Save states')))
		container.appendChild(renderStates(states, file, mime, fileId))
	}

	// Asked for after the rest is up: a folder of screenshots can take a
	// moment to look through, and nothing else waits on it.
	const screenshots = document.createElement('div')
	screenshots.className = 'arcade-sidebar-screenshots'
	container.appendChild(screenshots)
	fillScreenshots(screenshots, file)

	return container
}

/**
 * @param {string} text the heading
 * @return {HTMLElement} a heading of the tab
 */
function heading(text) {
	const element = document.createElement('h3')
	element.textContent = text
	return element
}

/**
 * The BIOS files the emulator asks for, and whether the player finds them.
 *
 * @param {Array<{name: string, present: boolean, source: ?string}>} bios the files
 * @return {HTMLElement} the section
 */
function renderBios(bios) {
	const section = document.createElement('div')
	section.appendChild(heading(t('arcade', 'BIOS')))
	const files = document.createElement('ul')
	files.className = 'arcade-sidebar-bios'
	for (const file of bios) {
		const item = document.createElement('li')
		const name = document.createElement('strong')
		name.textContent = file.name
		const where = document.createElement('span')
		where.textContent = !file.present
			? t('arcade', 'Missing')
			: (file.source === 'folder'
				? t('arcade', 'In your system folder')
				: t('arcade', 'Provided by the server'))
		item.append(name, where)
		if (!file.present) {
			item.classList.add('arcade-sidebar-bios-missing')
		}
		files.appendChild(item)
	}
	section.appendChild(files)
	if (bios.some((file) => !file.present)) {
		const hint = document.createElement('p')
		hint.className = 'arcade-sidebar-hint'
		hint.textContent = t('arcade', 'Games may run worse without them. Add them to the system folder chosen in the personal Arcade settings.')
		section.appendChild(hint)
	}
	return section
}

/**
 * The save states of the game, each with its picture and a button that
 * starts the game in it.
 *
 * @param {object[]} states the slots
 * @param {string} file path of the game
 * @param {string} mime its mimetype
 * @param {number|string} fileId the Nextcloud file id
 * @return {HTMLElement} the list
 */
function renderStates(states, file, mime, fileId) {
	const slots = document.createElement('ul')
	slots.className = 'arcade-sidebar-slots'
	for (const state of [...states].sort((a, b) => b.mtime - a.mtime)) {
		const item = document.createElement('li')

		if (state.hasThumbnail) {
			const picture = document.createElement('img')
			picture.className = 'arcade-sidebar-slot-picture'
			picture.alt = ''
			picture.loading = 'lazy'
			// The mtime keeps a rewritten slot from showing its old picture.
			picture.src = stateUrl('/state/thumbnail', file, state.slot) + '&v=' + state.mtime
			item.appendChild(picture)
		} else {
			const blank = document.createElement('span')
			blank.className = 'arcade-sidebar-slot-picture'
			item.appendChild(blank)
		}

		const text = document.createElement('div')
		text.className = 'arcade-sidebar-slot-text'
		const name = document.createElement('strong')
		name.textContent = slotName(state.slot)
		const when = document.createElement('span')
		when.textContent = new Date(state.mtime * 1000).toLocaleString()
		text.append(name, when)
		// A stale state was marked by colour and a tooltip alone, which
		// is nothing at all to anybody not pointing at it. Said in
		// words, the way the player's own states panel says it.
		if (state.stale === true) {
			const warning = document.createElement('span')
			warning.className = 'arcade-sidebar-stale-warning'
			warning.textContent = t('arcade', 'made from another copy of this game')
			warning.title = t('arcade', 'The ROM has changed since this state was saved, so loading it may go wrong.')
			text.appendChild(warning)
			item.classList.add('arcade-sidebar-stale')
		}
		item.appendChild(text)

		const load = document.createElement('button')
		load.type = 'button'
		load.className = 'arcade-sidebar-load'
		load.textContent = t('arcade', 'Load')
		load.setAttribute('aria-label', t('arcade', 'Play from {slot}', { slot: slotName(state.slot) }))
		load.addEventListener('click', () => playGame(file, mime, fileId, state.slot))
		item.appendChild(load)

		slots.appendChild(item)
	}
	return slots
}

// How many screenshots the tab shows; the player's gallery has the rest.
const SCREENSHOTS_SHOWN = 6

/**
 * The latest screenshots of the game, from the user's screenshots folder.
 * Nothing at all when there are none, or no folder to keep them in.
 *
 * @param {HTMLElement} section where they go
 * @param {string} file path of the game
 */
async function fillScreenshots(section, file) {
	let data
	try {
		const response = await api(generateUrl('/apps/arcade/arcade/screenshots?f={f}', { f: encodePath(file) }))
		data = await response.json()
	} catch (error) {
		console.debug('arcade: could not list the screenshots of a game', error)
		return
	}
	const shots = Array.isArray(data?.screenshots) ? data.screenshots.slice(0, SCREENSHOTS_SHOWN) : []
	if (shots.length === 0 || !section.isConnected) {
		return
	}
	section.appendChild(heading(t('arcade', 'Screenshots')))
	const strip = document.createElement('ul')
	strip.className = 'arcade-sidebar-screenshot-strip'
	const folder = String(data.folder ?? '').replace(/\/+$/, '')
	for (const shot of shots) {
		const item = document.createElement('li')
		const link = document.createElement('a')
		link.href = generateUrl('/f/{fileId}', { fileId: shot.fileId })
		link.title = shot.basename
		// In the Viewer where there is one, so the picture opens over the
		// page rather than taking it away.
		link.addEventListener('click', (event) => {
			if (window.OCA?.Viewer?.open === undefined || folder === '') {
				return
			}
			event.preventDefault()
			window.OCA.Viewer.open({ path: `${folder}/${shot.basename}` })
		})
		const picture = document.createElement('img')
		picture.alt = shot.basename
		picture.loading = 'lazy'
		picture.src = previewUrl(shot.fileId, 160, 120, String(shot.mtime))
		link.appendChild(picture)
		item.appendChild(link)
		strip.appendChild(item)
	}
	section.appendChild(strip)
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
	// A game not read yet is read for this answer, which can take a
	// moment for a big one: said, rather than an empty tab.
	const waiting = document.createElement('p')
	waiting.className = 'arcade-sidebar-empty'
	waiting.textContent = t('arcade', 'Reading the game …')
	mountPoint.appendChild(waiting)
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

// The web component the sidebar renders the tab as, given
// the file it is open on as its node.
const TAG_NAME = 'arcade-files-sidebar-tab'

/**
 * The tab as the sidebar renders it: an element it hands the node of the
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
 * The sidebar renders a tab as a web component and keeps the tabs in the global scope of @nextcloud/files 4 -- written
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

registerWebComponent()
