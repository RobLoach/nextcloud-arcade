import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { api } from './api.js'
import { formatPlayTime } from './format.js'
import { ICONS, icon } from './icons.js'
import { playGame } from './play.js'
import { romMimes } from './systems.js'

/**
 * The Arcade tab of the Files sidebar: what the app knows about a ROM --
 * its system, what the cartridge calls itself, what it has been played
 * for, the saves waiting in its slots -- and a button that plays it.
 *
 * Registered through the plain-DOM callbacks of OCA.Files.Sidebar, so no
 * Vue is carried onto the Files page for a definition list.
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
	return slot === 0 ? t('arcade', 'Autosave') : t('arcade', 'Slot {slot}', { slot })
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
			if (state.stale === true) {
				item.title = t('arcade', 'The ROM has changed since this state was saved')
				item.classList.add('arcade-sidebar-stale')
			}
			slots.appendChild(item)
		}
		container.appendChild(slots)
	}
	return container
}

/** Where the tab renders, and which request is still the current one. */
let mountPoint = null
let generation = 0

/**
 * Fetches what the app knows and fills the tab with it.
 *
 * @param {object} fileInfo what the sidebar was opened on
 */
async function show(fileInfo) {
	if (mountPoint === null) {
		return
	}
	const mine = ++generation
	const file = pathOf(fileInfo)
	mountPoint.textContent = ''
	try {
		const response = await api(generateUrl('/apps/arcade/arcade/game?file={file}', { file }))
		const game = await response.json()
		if (mine !== generation || mountPoint === null) {
			return
		}
		mountPoint.textContent = ''
		mountPoint.appendChild(render(game, file, fileInfo?.mimetype ?? '', fileInfo?.id ?? 0))
	} catch (error) {
		console.debug('Arcade could not describe the game', error)
		if (mine !== generation || mountPoint === null) {
			return
		}
		const message = document.createElement('p')
		message.className = 'arcade-sidebar-empty'
		message.textContent = t('arcade', 'Nothing is known about this game yet')
		mountPoint.textContent = ''
		mountPoint.appendChild(message)
	}
}

/**
 * @return {boolean} whether the tab was registered
 */
function register() {
	const Sidebar = window.OCA?.Files?.Sidebar
	if (Sidebar?.registerTab === undefined || Sidebar?.Tab === undefined) {
		return false
	}
	Sidebar.registerTab(new Sidebar.Tab({
		id: TAB_ID,
		name: t('arcade', 'Arcade'),
		iconSvg: icon(ICONS.gamepad),

		enabled(fileInfo) {
			return romMimes().includes(fileInfo?.mimetype ?? '')
		},

		async mount(el, fileInfo) {
			mountPoint = el
			await show(fileInfo)
		},

		async update(fileInfo) {
			await show(fileInfo)
		},

		destroy() {
			generation++
			if (mountPoint !== null) {
				mountPoint.textContent = ''
				mountPoint = null
			}
		},
	}))
	return true
}

if (!register()) {
	document.addEventListener('DOMContentLoaded', register)
}
