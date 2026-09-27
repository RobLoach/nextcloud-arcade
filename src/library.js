import { loadState } from '@nextcloud/initial-state'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { api } from './api.js'
import { formatDuration, formatPlayTime, formatSize } from './format.js'
import { attachLibraryGamepad } from './librarypad.js'
import { playUrl, previewUrl } from './play.js'
import { systemLabel } from './systems.js'

const VIEWS = ['grid', 'list', 'table']
const PAGE_SIZES = [24, 60, 120, 240]
const VIEW_KEY = 'arcade-library-view'
const PAGE_SIZE_KEY = 'arcade-library-page-size'

const ICONS = {
	star: 'M12,15.39L8.24,17.66L9.23,13.38L5.91,10.5L10.29,10.13L12,6.09L13.71,10.13L18.09,10.5L14.77,13.38L15.76,17.66M22,9.24L14.81,8.63L12,2L9.19,8.63L2,9.24L7.45,13.97L5.82,21L12,17.27L18.18,21L16.54,13.97L22,9.24Z',
	grid: 'M3,11H11V3H3M3,21H11V13H3M13,21H21V13H13M13,3V11H21V3',
	list: 'M3,4H21V8H3V4M3,10H21V14H3V10M3,16H21V20H3V16Z',
	table: 'M5,4H19A2,2 0 0,1 21,6V18A2,2 0 0,1 19,20H5A2,2 0 0,1 3,18V6A2,2 0 0,1 5,4M5,8V12H11V8H5M13,8V12H19V8H13M5,14V18H11V14H5M13,14V18H19V14H13Z',
	gamepad: 'M7.97,16L5,19C4.67,19.3 4.23,19.5 3.75,19.5A1.75,1.75 0 0,1 2,17.75V17.5L3,10.12C3.21,7.81 5.14,6 7.5,6H16.5C18.86,6 20.79,7.81 21,10.12L22,17.5V17.75A1.75,1.75 0 0,1 20.25,19.5C19.77,19.5 19.33,19.3 19,19L16.03,16H7.97M7,8V10H5V11H7V13H8V11H10V10H8V8H7M16.5,8A0.75,0.75 0 0,0 15.75,8.75A0.75,0.75 0 0,0 16.5,9.5A0.75,0.75 0 0,0 17.25,8.75A0.75,0.75 0 0,0 16.5,8M14.75,9.75A0.75,0.75 0 0,0 14,10.5A0.75,0.75 0 0,0 14.75,11.25A0.75,0.75 0 0,0 15.5,10.5A0.75,0.75 0 0,0 14.75,9.75M18.25,9.75A0.75,0.75 0 0,0 17.5,10.5A0.75,0.75 0 0,0 18.25,11.25A0.75,0.75 0 0,0 19,10.5A0.75,0.75 0 0,0 18.25,9.75M16.5,11.5A0.75,0.75 0 0,0 15.75,12.25A0.75,0.75 0 0,0 16.5,13A0.75,0.75 0 0,0 17.25,12.25A0.75,0.75 0 0,0 16.5,11.5Z',
	refresh: 'M17.65,6.35C16.2,4.9 14.21,4 12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20C15.73,20 18.84,17.45 19.73,14H17.65C16.83,16.33 14.61,18 12,18A6,6 0 0,1 6,12A6,6 0 0,1 12,6C13.66,6 15.14,6.69 16.22,7.78L13,11H20V4L17.65,6.35Z',
}

// Boxarts are the cover of a game and read best big; logos are made to be
// recognized small. The rest is used when those are missing.
const THUMBNAIL_PREFERENCE = {
	large: ['boxart', 'plain', 'title', 'snap', 'logo'],
	small: ['logo', 'plain', 'boxart', 'title', 'snap'],
}

// What each system is shown with, as the administration settings have it.
const thumbnailTypes = loadState('arcade', 'settings', {}).thumbnail_types ?? {}

// Pages are cached for the tab, so coming back from a game paints the
// library immediately while it is revalidated in the background.
const CACHE_PREFIX = 'arcade-library-page:'
const CACHE_TTL = 60 * 1000

const state = {
	view: localStorage.getItem(VIEW_KEY) ?? 'grid',
	pageSize: Number(localStorage.getItem(PAGE_SIZE_KEY)) || 60,
	sort: 'name',
	order: 'asc',
	offset: 0,
	search: '',
	system: '',
	tag: '',
}

/**
 * @param {string} path the MDI icon path
 * @return {string} an inline SVG
 */
function icon(path) {
	return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path d="${path}"/></svg>`
}

/**
 * @param {string} url the endpoint
 * @param {object} params the query to send
 * @return {Promise<Response>} the response, always ok
 */
async function post(url, params) {
	return await api(generateUrl(url + '?' + new URLSearchParams(params)), { method: 'POST' })
}

/**
 * @return {string} the cache key of the page being shown
 */
function cacheKey() {
	return CACHE_PREFIX + JSON.stringify([
		state.offset, state.pageSize, state.sort, state.order, state.search, state.system, state.tag,
	])
}

/**
 * @param {string} key the cache key
 * @return {?object} the cached page, when still fresh
 */
function readCache(key) {
	try {
		const cached = JSON.parse(sessionStorage.getItem(key) ?? 'null')
		return cached !== null && Date.now() - cached.time < CACHE_TTL ? cached.data : null
	} catch (error) {
		return null
	}
}

/**
 * @param {string} key the cache key
 * @param {object} data the page to cache
 */
function writeCache(key, data) {
	try {
		sessionStorage.setItem(key, JSON.stringify({ time: Date.now(), data }))
	} catch (error) {
		// A full or unavailable session storage only costs us the cache.
	}
}

/**
 * @param {object} game the game
 * @return {string} the game name, without its file extension
 */
function gameName(game) {
	return game.basename.replace(/\.[^.]+$/, '')
}

/**
 * @param {object} game the game
 * @return {string} the name of the game's system
 */
function gameSystem(game) {
	if (game.system === 'zip' || !game.system) {
		return t('arcade', 'ZIP archive')
	}
	return systemLabel(game.system)
}

/**
 * @param {object} game the game
 * @param {number} size the requested thumbnail size in pixels
 * @return {HTMLElement} the thumbnail image, or a placeholder
 */
function thumbnailFor(game, size) {
	const available = game.thumbnails ?? {}
	// The kind chosen for the system comes first, then whatever suits the
	// size it is drawn at.
	const chosen = thumbnailTypes[game.system]
	const preference = [
		...(chosen === undefined ? [] : [chosen]),
		...THUMBNAIL_PREFERENCE[size > 96 ? 'large' : 'small'],
	]
	const type = preference.find((candidate) => available[candidate] !== undefined)

	const image = document.createElement('img')
	image.alt = ''
	image.loading = 'lazy'
	image.decoding = 'async'

	if (type !== undefined) {
		image.className = `arcade-library-thumbnail arcade-library-thumbnail-${type}`
		image.src = previewUrl(available[type], size)
		return image
	}

	// No image of its own: show the game as it was last seen.
	if (game.fallback?.type === 'screenshot') {
		image.className = 'arcade-library-thumbnail arcade-library-thumbnail-snap'
		image.src = previewUrl(game.fallback.fileId, size)
		return image
	}
	if (game.fallback?.type === 'state') {
		image.className = 'arcade-library-thumbnail arcade-library-thumbnail-snap'
		image.src = generateUrl('/apps/arcade/arcade/state/thumbnail?file={file}&slot={slot}', {
			file: game.path,
			slot: game.fallback.slot,
		})
		return image
	}

	const placeholder = document.createElement('div')
	placeholder.className = 'arcade-library-thumbnail arcade-library-placeholder'
	placeholder.innerHTML = icon(ICONS.gamepad)
	return placeholder
}

/**
 * @param {object} game the game
 * @param {Function} reload reloads the library
 * @return {HTMLElement} a card for the game
 */
function renderCard(game, reload) {
	const card = document.createElement('div')
	card.className = 'arcade-library-game'

	const link = document.createElement('a')
	link.className = 'arcade-library-game-link'
	link.href = playUrl(game.path, game.id)
	link.appendChild(thumbnailFor(game, 256))

	const name = document.createElement('span')
	name.className = 'arcade-library-game-name'
	name.textContent = gameName(game)
	name.title = game.basename
	link.appendChild(name)

	const system = document.createElement('span')
	system.className = 'arcade-library-game-system'
	const played = formatPlayTime(game.seconds)
	system.textContent = played === '' ? gameSystem(game) : `${gameSystem(game)} · ${played}`
	link.appendChild(system)
	card.appendChild(link)

	const favorite = document.createElement('button')
	favorite.type = 'button'
	favorite.className = game.favorite ? 'arcade-library-favorite active' : 'arcade-library-favorite'
	favorite.title = game.favorite
		? t('arcade', 'Remove from favorites')
		: t('arcade', 'Add to favorites')
	favorite.setAttribute('aria-label', favorite.title)
	favorite.setAttribute('aria-pressed', game.favorite ? 'true' : 'false')
	favorite.innerHTML = icon(ICONS.star)
	favorite.addEventListener('click', async (event) => {
		event.preventDefault()
		try {
			await post('/apps/arcade/arcade/favorite', { file: game.path })
			reload(true)
		} catch (error) {
			console.error('Could not change the favorites', error)
		}
	})
	card.appendChild(favorite)

	return card
}

/**
 * @param {object[]} games the games of the current page
 * @param {Function} reload reloads the library
 * @return {HTMLElement} the grid of game cards
 */
function renderGrid(games, reload) {
	const grid = document.createElement('div')
	grid.className = 'arcade-library-grid'
	for (const game of games) {
		grid.appendChild(renderCard(game, reload))
	}
	return grid
}

/**
 * @param {string} title the heading of the row
 * @param {string} className a class for the row
 * @param {object[]} games the games in it
 * @param {Function} reload reloads the library
 * @return {HTMLElement} a scrollable row of games
 */
function renderRow(title, className, games, reload) {
	const section = document.createElement('div')
	section.className = `arcade-library-row-section ${className}`

	const heading = document.createElement('h3')
	heading.textContent = title
	section.appendChild(heading)

	const row = document.createElement('div')
	row.className = 'arcade-library-recent-row'
	for (const game of games) {
		row.appendChild(renderCard(game, reload))
	}
	section.appendChild(row)
	return section
}

/**
 * @param {object[]} games the games of the current page
 * @return {HTMLElement} the list of games
 */
function renderList(games) {
	const list = document.createElement('div')
	list.className = 'arcade-library-rows'
	for (const game of games) {
		const row = document.createElement('a')
		row.className = 'arcade-library-row'
		row.href = playUrl(game.path, game.id)
		row.appendChild(thumbnailFor(game, 64))

		const name = document.createElement('span')
		name.className = 'arcade-library-row-name'
		name.textContent = gameName(game)
		name.title = game.basename
		row.appendChild(name)

		const system = document.createElement('span')
		system.className = 'arcade-library-row-system'
		const played = formatPlayTime(game.seconds)
		system.textContent = played === '' ? gameSystem(game) : `${gameSystem(game)} · ${played}`
		row.appendChild(system)

		list.appendChild(row)
	}
	return list
}

/**
 * @param {object[]} games the games of the current page
 * @param {Function} reload reloads the library with new parameters
 * @return {HTMLElement} the table of games
 */
function renderTable(games, reload) {
	const table = document.createElement('table')
	table.className = 'arcade-library-table'

	const head = document.createElement('thead')
	const headRow = document.createElement('tr')
	const columns = [
		{ key: 'name', label: t('arcade', 'Name') },
		{ key: 'system', label: t('arcade', 'System') },
		{ key: 'size', label: t('arcade', 'Size') },
		{ key: 'mtime', label: t('arcade', 'Modified') },
		{ key: 'playtime', label: t('arcade', 'Played') },
	]
	for (const column of columns) {
		const cell = document.createElement('th')
		const button = document.createElement('button')
		button.type = 'button'
		button.textContent = state.sort === column.key
			? `${column.label} ${state.order === 'asc' ? '▲' : '▼'}`
			: column.label
		button.addEventListener('click', () => {
			state.order = state.sort === column.key && state.order === 'asc' ? 'desc' : 'asc'
			state.sort = column.key
			state.offset = 0
			reload()
		})
		cell.appendChild(button)
		headRow.appendChild(cell)
	}
	head.appendChild(headRow)
	table.appendChild(head)

	const body = document.createElement('tbody')
	for (const game of games) {
		const row = document.createElement('tr')

		const nameCell = document.createElement('td')
		const link = document.createElement('a')
		link.href = playUrl(game.path, game.id)
		link.textContent = gameName(game)
		link.title = game.basename
		nameCell.appendChild(link)
		row.appendChild(nameCell)

		const systemCell = document.createElement('td')
		systemCell.textContent = gameSystem(game)
		row.appendChild(systemCell)

		const sizeCell = document.createElement('td')
		sizeCell.textContent = formatSize(game.size ?? 0)
		row.appendChild(sizeCell)

		const modifiedCell = document.createElement('td')
		modifiedCell.textContent = game.mtime
			? new Date(game.mtime * 1000).toLocaleDateString()
			: ''
		row.appendChild(modifiedCell)

		const playedCell = document.createElement('td')
		playedCell.textContent = formatDuration(game.seconds ?? 0)
		if (game.plays > 0) {
			playedCell.title = n('arcade', 'Played %n time', 'Played %n times', game.plays)
		}
		row.appendChild(playedCell)

		body.appendChild(row)
	}
	table.appendChild(body)
	return table
}

/**
 * @param {object} data the library response
 * @param {Function} reload reloads the library with new parameters
 * @return {HTMLElement} the pagination controls
 */
function renderPagination(data, reload) {
	const pagination = document.createElement('div')
	pagination.className = 'arcade-library-pagination'

	const first = data.total === 0 ? 0 : data.offset + 1
	const last = Math.min(data.offset + data.limit, data.total)

	const pageButton = (label, targetOffset, disabled) => {
		const button = document.createElement('button')
		button.type = 'button'
		button.textContent = label
		button.disabled = disabled
		button.addEventListener('click', () => {
			state.offset = targetOffset
			reload()
		})
		return button
	}

	pagination.appendChild(pageButton(
		t('arcade', 'Previous'),
		Math.max(0, data.offset - data.limit),
		data.offset === 0,
	))

	const count = document.createElement('span')
	count.className = 'arcade-library-count'
	count.textContent = t('arcade', '{first}–{last} of {total}', {
		first,
		last,
		total: data.total,
	})
	pagination.appendChild(count)

	pagination.appendChild(pageButton(
		t('arcade', 'Next'),
		data.offset + data.limit,
		last >= data.total,
	))

	const pageSize = document.createElement('select')
	pageSize.className = 'arcade-library-page-size'
	pageSize.setAttribute('aria-label', t('arcade', 'Games per page'))
	for (const size of PAGE_SIZES) {
		const option = document.createElement('option')
		option.value = String(size)
		option.textContent = t('arcade', '{count} per page', { count: size })
		option.selected = size === state.pageSize
		pageSize.appendChild(option)
	}
	pageSize.addEventListener('change', () => {
		state.pageSize = Number(pageSize.value)
		state.offset = 0
		localStorage.setItem(PAGE_SIZE_KEY, String(state.pageSize))
		reload()
	})
	pagination.appendChild(pageSize)

	return pagination
}

/**
 * @param {string[]} systems the systems present in the library
 * @param {string[]} tags the system tags carried by games in the library
 * @param {Function} reload reloads the library with new parameters
 * @return {HTMLElement} the filters
 */
function renderFilters(systems, tags, reload) {
	const filters = document.createElement('div')
	filters.className = 'arcade-library-filters'

	const search = document.createElement('input')
	search.type = 'search'
	search.className = 'arcade-library-search'
	search.placeholder = t('arcade', 'Search games …')
	search.setAttribute('aria-label', t('arcade', 'Search games'))
	search.value = state.search
	let searchTimer = null
	search.addEventListener('input', () => {
		clearTimeout(searchTimer)
		searchTimer = setTimeout(() => {
			state.search = search.value
			state.offset = 0
			reload()
		}, 300)
	})
	filters.appendChild(search)

	const systemFilter = document.createElement('select')
	systemFilter.className = 'arcade-library-system-filter'
	systemFilter.setAttribute('aria-label', t('arcade', 'Filter by system'))
	const all = document.createElement('option')
	all.value = ''
	all.textContent = t('arcade', 'All systems')
	systemFilter.appendChild(all)
	for (const system of systems) {
		const option = document.createElement('option')
		option.value = system
		option.textContent = gameSystem({ system })
		option.selected = system === state.system
		systemFilter.appendChild(option)
	}
	systemFilter.addEventListener('change', () => {
		state.system = systemFilter.value
		state.offset = 0
		reload()
	})
	filters.appendChild(systemFilter)

	// A tag the filter is set to stays offered even when the last game
	// carrying it was filtered away, so it can be unset again.
	const tagOptions = tags.includes(state.tag) || state.tag === ''
		? tags
		: [...tags, state.tag].sort((a, b) => a.localeCompare(b))
	if (tagOptions.length > 0) {
		const tagFilter = document.createElement('select')
		tagFilter.className = 'arcade-library-tag-filter'
		tagFilter.setAttribute('aria-label', t('arcade', 'Filter by tag'))
		const allTags = document.createElement('option')
		allTags.value = ''
		allTags.textContent = t('arcade', 'All tags')
		tagFilter.appendChild(allTags)
		for (const tag of tagOptions) {
			const option = document.createElement('option')
			option.value = tag
			option.textContent = tag
			option.selected = tag === state.tag
			tagFilter.appendChild(option)
		}
		tagFilter.addEventListener('change', () => {
			state.tag = tagFilter.value
			state.offset = 0
			reload()
		})
		filters.appendChild(tagFilter)
	}

	return { element: filters, search }
}

/**
 * @param {Function} reload reloads the library with new parameters
 * @param {Function} setView switches the view without reloading
 * @return {HTMLElement} the header, with the view switcher
 */
function renderHeader(reload, setView) {
	const header = document.createElement('div')
	header.className = 'arcade-library-header'

	const heading = document.createElement('h2')
	heading.textContent = t('arcade', 'Games library')
	header.appendChild(heading)

	const controls = document.createElement('div')
	controls.className = 'arcade-library-controls'

	const labels = {
		grid: t('arcade', 'Grid view'),
		list: t('arcade', 'List view'),
		table: t('arcade', 'Table view'),
	}
	for (const view of VIEWS) {
		const button = document.createElement('button')
		button.type = 'button'
		button.className = view === state.view ? 'active' : ''
		button.title = labels[view]
		button.setAttribute('aria-label', labels[view])
		button.innerHTML = icon(ICONS[view])
		button.addEventListener('click', () => setView(view))
		controls.appendChild(button)
	}

	const refresh = document.createElement('button')
	refresh.type = 'button'
	refresh.title = t('arcade', 'Rescan the library folder')
	refresh.setAttribute('aria-label', refresh.title)
	refresh.innerHTML = icon(ICONS.refresh)
	refresh.addEventListener('click', () => reload(true))
	controls.appendChild(refresh)

	header.appendChild(controls)
	return header
}

/**
 * @param {object} suggestion a suggested folder: path, games, systems
 * @param {Function} reload reloads the library
 * @return {HTMLElement} one suggested folder, with its button
 */
function renderSuggestion(suggestion, reload) {
	const item = document.createElement('li')
	item.className = 'arcade-library-suggestion'

	const text = document.createElement('span')
	text.className = 'arcade-library-suggestion-text'
	const games = n('arcade', '%n game in {path}', '%n games in {path}', suggestion.games, {
		path: suggestion.path,
	})
	const systems = (suggestion.systems ?? []).map((system) => systemLabel(system)).join(', ')
	text.textContent = systems === '' ? games : `${games} (${systems})`
	item.appendChild(text)

	const use = document.createElement('button')
	use.type = 'button'
	use.className = 'primary'
	use.textContent = t('arcade', 'Use this folder')
	use.addEventListener('click', async () => {
		use.disabled = true
		try {
			// The personal settings endpoint takes a partial body, so only
			// the library folder changes.
			await post('/apps/arcade/arcade/settings', { library_folder: suggestion.path })
			reload(true)
		} catch (error) {
			console.error('Could not save the library folder', error)
			use.disabled = false
		}
	})
	item.appendChild(use)

	return item
}

/**
 * Ask the server where ROMs already are, and offer those folders.
 *
 * @param {HTMLElement} status the "looking …" line, replaced by what was found
 * @param {Function} reload reloads the library
 */
async function loadSuggestions(status, reload) {
	let suggestions = []
	try {
		const response = await api(generateUrl('/apps/arcade/arcade/suggest'))
		suggestions = (await response.json()).suggestions ?? []
	} catch (error) {
		console.error('Could not look for ROM folders', error)
		status.remove()
		return
	}

	if (suggestions.length === 0) {
		status.textContent = t('arcade', 'No ROMs were found in your files yet. Upload some games, then rescan.')
		return
	}

	status.textContent = t('arcade', 'ROMs were already found in these folders:')
	const list = document.createElement('ul')
	list.className = 'arcade-library-suggestions'
	for (const suggestion of suggestions) {
		list.appendChild(renderSuggestion(suggestion, reload))
	}
	status.after(list)
}

/**
 * The first-run panel, shown when the library folder is missing or holds
 * no games: what the app looks for, the folders that already hold ROMs,
 * and the way to pick one by hand.
 *
 * @param {object} data the library response
 * @param {Function} reload reloads the library
 * @return {HTMLElement} the onboarding panel
 */
function renderOnboarding(data, reload) {
	const panel = document.createElement('div')
	panel.className = 'arcade-library-onboarding'

	const status = document.createElement('p')
	status.className = 'arcade-library-hint'
	status.textContent = data.exists
		? t('arcade', 'The games library folder {folder} exists, but no games were found in it.', { folder: data.folder })
		: t('arcade', 'The games library folder {folder} does not exist yet.', { folder: data.folder })
	panel.appendChild(status)

	const explain = document.createElement('p')
	explain.textContent = t(
		'arcade',
		'Arcade lists the ROM files of retro consoles — like .nes, .sfc, .gba, .md or zipped games — and plays them right in the browser.',
	)
	panel.appendChild(explain)

	const looking = document.createElement('p')
	looking.className = 'arcade-library-hint'
	looking.textContent = t('arcade', 'Looking for ROMs in your files …')
	panel.appendChild(looking)
	loadSuggestions(looking, reload)

	const manual = document.createElement('p')
	manual.className = 'arcade-library-hint'
	const link = document.createElement('a')
	link.href = generateUrl('/settings/user/arcade')
	link.textContent = t('arcade', 'Or pick a folder yourself in the Arcade personal settings.')
	manual.appendChild(link)
	panel.appendChild(manual)

	return panel
}

/**
 * Render the games library, replacing the contents of the container.
 *
 * @param {HTMLElement} container the element to render into
 * @param {Function} onError called with a message when the library fails to load
 */
export async function renderLibrary(container, onError) {
	let shown = null
	let pending = null

	// A connected controller can browse and launch games; without one
	// this costs nothing, the poll loop only runs while a pad is there.
	attachLibraryGamepad(container)

	const load = async (refresh = false) => {
		const key = cacheKey()
		if (!refresh) {
			const cached = readCache(key)
			if (cached !== null) {
				render(cached)
			}
		}

		// Typing in the search field fires several loads; only the last one
		// is of interest.
		pending?.abort()
		pending = new AbortController()

		let data
		try {
			const response = await api(generateUrl(
				'/apps/arcade/arcade/library?offset={offset}&limit={limit}&sort={sort}&order={order}'
					+ '&search={search}&system={system}&tag={tag}&refresh={refresh}',
				{
					offset: state.offset,
					limit: state.pageSize,
					sort: state.sort,
					order: state.order,
					search: state.search,
					system: state.system,
					tag: state.tag,
					refresh: refresh ? 1 : 0,
				},
			), { signal: pending.signal })
			data = await response.json()
		} catch (error) {
			if (error.name === 'AbortError') {
				return
			}
			console.error('Could not load the games library', error)
			if (shown === null) {
				onError(t('arcade', 'Could not load the games library.'))
			}
			return
		}
		writeCache(key, data)
		render(data)
	}

	const setView = (view) => {
		state.view = view
		localStorage.setItem(VIEW_KEY, view)
		if (shown !== null) {
			// The page is already here, no need to ask for it again.
			render(shown, true)
		} else {
			load()
		}
	}

	const render = (data, force = false) => {
		// The favorites are known for the whole library, so the flag is put
		// on whatever is being shown. Likewise the play stats: the recently
		// played and the favorites carry theirs already, the page looks
		// them up here.
		const favorites = new Set((data.favorites ?? []).map((game) => game.path))
		const stats = data.stats ?? {}
		for (const game of [...(data.games ?? []), ...(data.recent ?? []), ...(data.favorites ?? [])]) {
			game.favorite = favorites.has(game.path)
			if (game.seconds === undefined && stats[game.id] !== undefined) {
				game.seconds = stats[game.id].seconds
				game.plays = stats[game.id].plays
			}
		}

		// Revalidating usually returns what is already on screen; redrawing
		// it would only throw away the scroll position.
		if (!force && shown !== null && JSON.stringify(shown) === JSON.stringify(data)) {
			return
		}
		shown = data

		// Typing in the search field re-renders, so put the caret back.
		const searchWasFocused = container.querySelector('.arcade-library-search') === document.activeElement

		container.innerHTML = ''
		container.appendChild(renderHeader(load, setView))

		if (!data.exists || data.libraryTotal === 0) {
			container.appendChild(renderOnboarding(data, load))
			return
		}

		// Only on the plain first page: these are shortcuts, not results.
		const plainPage = state.search === '' && state.system === '' && state.tag === '' && state.offset === 0
		if (plainPage && (data.favorites ?? []).length > 0) {
			container.appendChild(renderRow(
				t('arcade', 'Favorites'),
				'arcade-library-favorites',
				data.favorites,
				load,
			))
		}
		if (plainPage && (data.recent ?? []).length > 0) {
			container.appendChild(renderRow(
				t('arcade', 'Recently played'),
				'arcade-library-recent',
				data.recent,
				load,
			))
		}

		const filters = renderFilters(data.systems, data.tags ?? [], load)
		container.appendChild(filters.element)
		if (searchWasFocused) {
			filters.search.focus()
			const end = filters.search.value.length
			filters.search.setSelectionRange(end, end)
		}

		if (data.total === 0) {
			const hint = document.createElement('p')
			hint.className = 'arcade-library-hint'
			hint.textContent = t('arcade', 'No games match the filters.')
			container.appendChild(hint)
			return
		}

		const views = {
			grid: () => renderGrid(data.games, load),
			list: () => renderList(data.games),
			table: () => renderTable(data.games, () => load()),
		}
		container.appendChild((views[state.view] ?? views.grid)())

		if (data.total > data.limit) {
			container.appendChild(renderPagination(data, () => load()))
		}
		if (data.truncated) {
			const truncated = document.createElement('p')
			truncated.className = 'arcade-library-hint'
			truncated.textContent = t('arcade', 'Only the first {count} games of the folder are listed.', {
				count: data.libraryTotal,
			})
			container.appendChild(truncated)
		}
	}

	await load()
}
