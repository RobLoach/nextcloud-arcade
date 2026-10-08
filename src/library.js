import { getRequestToken } from '@nextcloud/auth'
import { loadState } from '@nextcloud/initial-state'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { api } from './api.js'
import { formatDuration, formatPlayTime, formatSize } from './format.js'
import { ICONS, icon } from './icons.js'
import { attachLibraryGamepad } from './librarypad.js'
import { davUrl } from './player.js'
import { playUrl, previewUrl } from './play.js'
import { systemLabel } from './systems.js'
import { showError, showInfo } from './toast.js'

const VIEWS = ['grid', 'list', 'table']
const PAGE_SIZES = [24, 60, 120, 240]
const VIEW_KEY = 'arcade-library-view'
const PAGE_SIZE_KEY = 'arcade-library-page-size'
const SORT_KEY = 'arcade-library-sort'

// What the server will sort by. A stored order is read back through this
// rather than trusted: it is whatever is in the browser's storage, and a
// sort the server does not know would come back as an empty library.
const SORTS = ['name', 'system', 'size', 'mtime', 'playtime']

/**
 * The sort kept from last time, as the field and the direction.
 *
 * Kept at all because the view and the page size beside it are: picking
 * "Most played" and finding it back on names at the next visit, while
 * the view button next to it remembered perfectly well, reads as the
 * page forgetting rather than as a thing it never offered.
 *
 * @return {{sort: string, order: string}} what to start out sorted by
 */
function storedSort() {
	const [sort, order] = (localStorage.getItem(SORT_KEY) ?? '').split('/')
	return SORTS.includes(sort) && (order === 'asc' || order === 'desc')
		? { sort, order }
		: { sort: 'name', order: 'asc' }
}

/** Remember it for next time. */
function rememberSort() {
	localStorage.setItem(SORT_KEY, `${state.sort}/${state.order}`)
}

// Boxarts are the cover of a game and read best big; logos are made to be
// recognized small. The rest is used when those are missing.
const THUMBNAIL_PREFERENCE = {
	large: ['boxart', 'plain', 'title', 'snap', 'logo'],
	small: ['logo', 'plain', 'boxart', 'title', 'snap'],
}

// What each system is shown with, as the administration settings have it.
const thumbnailTypes = loadState('arcade', 'settings', {}).thumbnail_types ?? {}

// The version the listing says the thumbnails are at: it changes when any
// image of the thumbnails folder does, and versioning the preview URLs
// with it keeps a replaced image from being shown stale out of the
// browser cache, previews being served immutable for a day.
let thumbnailsVersion = ''

// Pages are cached for the tab, so coming back from a game paints the
// library immediately while it is revalidated in the background.
const CACHE_PREFIX = 'arcade-library-page:'
const CACHE_TTL = 60 * 1000

const state = {
	view: localStorage.getItem(VIEW_KEY) ?? 'grid',
	pageSize: Number(localStorage.getItem(PAGE_SIZE_KEY)) || 60,
	...storedSort(),
	offset: 0,
	search: '',
	system: '',
	tag: '',
}

// Every state change redraws the whole library, which throws the focus
// out with the old DOM. Each control the page draws is named here, so the
// one that had the focus before a render can be found again after it --
// the keyboard keeps its place in the tab order, and the gamepad, which
// drives real DOM focus, keeps the game it was resting on.
const FOCUS = 'data-focus'

// Typing fires one request per pause, not one per letter. The timer lives
// out here so a render mid-typing, which builds a new search field, does
// not leave the old field's timer running as well.
let searchTimer = null

/**
 * @param {HTMLElement} element the control to name
 * @param {string} name a name for it that survives a render
 * @return {HTMLElement} the same control
 */
function focusable(element, name) {
	element.setAttribute(FOCUS, name)
	return element
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
		image.src = previewUrl(available[type], size, size, thumbnailsVersion)
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

	// Nothing to show it with. A fresh library has no box art at all, so
	// this is what every card of it is, and one grey gamepad per game
	// makes a wall in which only the titles differ. The system is known,
	// so the placeholder wears it: a colour of its own, and its name for
	// anything too small to read a label under the card.
	const placeholder = document.createElement('div')
	placeholder.className = 'arcade-library-thumbnail arcade-library-placeholder'
	placeholder.innerHTML = icon(ICONS.gamepad)
	if (game.system) {
		placeholder.dataset.system = game.system
		// Spread around the wheel by the name of the system, so each keeps
		// the same colour between visits without a table of them to keep
		// in step with the systems themselves.
		let hash = 0
		for (const character of game.system) {
			hash = (hash * 31 + character.charCodeAt(0)) % 360
		}
		placeholder.style.setProperty('--arcade-system-hue', String(hash))
	}
	return placeholder
}

/**
 * @param {object} game the game
 * @param {Function} reload reloads the library
 * @param {string} scope which shelf the card sits on, so the focus names
 *   of a game shown twice -- in the favorites row and in the page -- do
 *   not collide
 * @return {HTMLElement} a card for the game
 */
function renderCard(game, reload, scope) {
	const card = document.createElement('li')
	card.className = 'arcade-library-game'

	const link = focusable(document.createElement('a'), `${scope}/game/${game.path}`)
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

	const favorite = focusable(document.createElement('button'), `${scope}/star/${game.path}`)
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
			// The star does not move on its own, so without a word here the
			// only thing a refused request looks like is a dead button.
			console.error('Could not change the favorites', error)
			showError(t('arcade', 'Could not change the favorites for {game}. Try again in a moment.', {
				game: gameName(game),
			}))
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
	const grid = document.createElement('ul')
	grid.className = 'arcade-library-grid'
	for (const game of games) {
		grid.appendChild(renderCard(game, reload, 'page'))
	}
	return grid
}

/**
 * @param {string} title the heading of the row
 * @param {string} className a class for the row
 * @param {object[]} games the games in it
 * @param {Function} reload reloads the library
 * @param {string} scope the focus scope of the shelf
 * @return {HTMLElement} a scrollable row of games
 */
function renderRow(title, className, games, reload, scope) {
	const section = document.createElement('div')
	section.className = `arcade-library-row-section ${className}`

	const heading = document.createElement('h3')
	heading.textContent = title
	section.appendChild(heading)

	const row = document.createElement('ul')
	row.className = 'arcade-library-recent-row'
	for (const game of games) {
		row.appendChild(renderCard(game, reload, scope))
	}
	section.appendChild(row)
	return section
}

/**
 * @param {object[]} games the games of the current page
 * @return {HTMLElement} the list of games
 */
function renderList(games) {
	const list = document.createElement('ul')
	list.className = 'arcade-library-rows'
	for (const game of games) {
		const item = document.createElement('li')
		const row = focusable(document.createElement('a'), `page/game/${game.path}`)
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

		item.appendChild(row)
		list.appendChild(item)
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
		cell.setAttribute('scope', 'col')
		// The arrow is the glance; aria-sort is the same thing said out
		// loud, and it belongs on the header, not on the button inside it.
		cell.setAttribute('aria-sort', state.sort !== column.key
			? 'none'
			: (state.order === 'asc' ? 'ascending' : 'descending'))
		const button = focusable(document.createElement('button'), `sort/${column.key}`)
		button.type = 'button'
		button.textContent = state.sort === column.key
			? `${column.label} ${state.order === 'asc' ? '▲' : '▼'}`
			: column.label
		button.addEventListener('click', () => {
			state.order = state.sort === column.key && state.order === 'asc' ? 'desc' : 'asc'
			state.sort = column.key
			state.offset = 0
			rememberSort()
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
		const link = focusable(document.createElement('a'), `page/game/${game.path}`)
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
 * @param {?HTMLElement} status the page's live region, to say the count in,
 *   or null when the page has already said its piece there
 * @return {HTMLElement} the pagination controls
 */
function renderPagination(data, reload, status) {
	const pagination = document.createElement('div')
	pagination.className = 'arcade-library-pagination'

	const first = data.total === 0 ? 0 : data.offset + 1
	const last = Math.min(data.offset + data.limit, data.total)

	// The count is worth saying even when one page holds everything, so it
	// is always here; only the walking about needs the buttons.
	if (status !== null) {
		status.textContent = t('arcade', '{first}–{last} of {total}', {
			first,
			last,
			total: data.total,
		})
	}
	if (data.total <= data.limit) {
		if (status !== null) {
			pagination.appendChild(status)
		}
		return pagination
	}

	const pageButton = (label, name, targetOffset, disabled) => {
		const button = focusable(document.createElement('button'), name)
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
		'page/previous',
		Math.max(0, data.offset - data.limit),
		data.offset === 0,
	))

	if (status !== null) {
		pagination.appendChild(status)
	}

	pagination.appendChild(pageButton(
		t('arcade', 'Next'),
		'page/next',
		data.offset + data.limit,
		last >= data.total,
	))

	const pageSize = focusable(document.createElement('select'), 'page/size')
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

	const search = focusable(document.createElement('input'), 'filters/search')
	search.type = 'search'
	search.className = 'arcade-library-search'
	search.placeholder = t('arcade', 'Search games …')
	search.setAttribute('aria-label', t('arcade', 'Search games'))
	search.value = state.search
	search.addEventListener('input', () => {
		// The state takes every letter at once, so a response landing
		// mid-typing rebuilds the field with what is in it rather than with
		// the text of three hundred milliseconds ago. Only the request
		// waits for the typing to stop.
		state.search = search.value
		clearTimeout(searchTimer)
		searchTimer = setTimeout(() => {
			state.offset = 0
			reload()
		}, 300)
	})
	filters.appendChild(search)

	const systemFilter = focusable(document.createElement('select'), 'filters/system')
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

	// The table sorts by its own column headings, which is how a table is
	// sorted. The other two views had no way to say it at all: the server
	// has always taken a sort and an order, and two views out of three
	// could only ever ask for name, ascending.
	//
	// One control for both halves of the answer. Asking for the field and
	// the direction separately would mean two selects to say "most
	// played", and "played, ascending" is not a thing anybody wants.
	if (state.view !== 'table') {
		const orders = [
			['name/asc', t('arcade', 'Name, A to Z')],
			['name/desc', t('arcade', 'Name, Z to A')],
			['system/asc', t('arcade', 'System')],
			['mtime/desc', t('arcade', 'Newest first')],
			['mtime/asc', t('arcade', 'Oldest first')],
			['playtime/desc', t('arcade', 'Most played')],
			['size/desc', t('arcade', 'Largest first')],
		]
		const sortBy = focusable(document.createElement('select'), 'filters/sort')
		sortBy.className = 'arcade-library-sort'
		sortBy.setAttribute('aria-label', t('arcade', 'Sort games'))
		for (const [value, label] of orders) {
			const option = document.createElement('option')
			option.value = value
			option.textContent = label
			option.selected = value === `${state.sort}/${state.order}`
			sortBy.appendChild(option)
		}
		sortBy.addEventListener('change', () => {
			const [sort, order] = sortBy.value.split('/')
			state.sort = sort
			state.order = order
			state.offset = 0
			rememberSort()
			reload()
		})
		filters.appendChild(sortBy)
	}

	// A tag the filter is set to stays offered even when the last game
	// carrying it was filtered away, so it can be unset again.
	const tagOptions = tags.includes(state.tag) || state.tag === ''
		? tags
		: [...tags, state.tag].sort((a, b) => a.localeCompare(b))
	if (tagOptions.length > 0) {
		const tagFilter = focusable(document.createElement('select'), 'filters/tag')
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

	return filters
}

/**
 * @param {Function} reload reloads the library with new parameters
 * @param {Function} setView switches the view without reloading
 * @param {boolean} rescanning whether a rescan of the library is already
 *   going, in which case asking for another one is no use
 * @return {HTMLElement} the header, with the view switcher
 */
function renderHeader(reload, setView, rescanning) {
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
		const button = focusable(document.createElement('button'), `view/${view}`)
		button.type = 'button'
		button.className = view === state.view ? 'active' : ''
		button.title = labels[view]
		button.setAttribute('aria-label', labels[view])
		// Which view is on shows as a filled button; aria-pressed is the
		// same fact for anything that cannot see the fill.
		button.setAttribute('aria-pressed', view === state.view ? 'true' : 'false')
		button.innerHTML = icon(ICONS[view])
		button.addEventListener('click', () => setView(view))
		controls.appendChild(button)
	}

	const refresh = focusable(document.createElement('button'), 'view/refresh')
	refresh.type = 'button'
	refresh.innerHTML = icon(ICONS.refresh)
	// aria-disabled rather than disabled: the button keeps its place in
	// the tab order, so somebody on the keyboard can land on it and be
	// told why it is not to be pressed, instead of finding it missing.
	const setRescanning = (running) => {
		refresh.setAttribute('aria-disabled', String(running))
		refresh.classList.toggle('arcade-library-refreshing', running)
		refresh.title = running
			? t('arcade', 'The games library is being refreshed.')
			: t('arcade', 'Rescan the library folder')
		refresh.setAttribute('aria-label', refresh.title)
	}
	setRescanning(rescanning)
	refresh.addEventListener('click', () => {
		// Asking again while one is going only queues nothing and confuses
		// the asker, so the button turns itself off at the press and stays
		// off; the next load of the page hears from the server whether the
		// rescan is done and decides afresh.
		if (refresh.getAttribute('aria-disabled') === 'true') {
			return
		}
		setRescanning(true)
		// A rescan walks the whole library folder and leaves a metadata job
		// behind it, so nothing moves on screen for a while. Saying so is
		// what keeps the button from looking dead.
		showInfo(t('arcade', 'Refreshing the games library in the background. This can take a while.'))
		reload(true)
	})
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

	const use = focusable(document.createElement('button'), `suggestion/${suggestion.path}`)
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
 * Make a folder in the user's own files.
 *
 * MKCOL answers 405 when the folder is already there, which is the
 * outcome being asked for either way, so only a real refusal is raised.
 *
 * @param {string} path the folder to make, relative to the user's files
 */
async function createFolder(path) {
	const response = await fetch(davUrl(path), {
		method: 'MKCOL',
		headers: { requesttoken: getRequestToken() ?? '' },
		credentials: 'same-origin',
	})
	if (!response.ok && response.status !== 405) {
		throw new Error(`${response.status} ${response.statusText}`)
	}
}

/**
 * The first-run panel, shown when the library folder is missing or holds
 * no games: what the app looks for, the folders that already hold ROMs,
 * and the way to pick one by hand.
 *
 * @param {object} data the library response
 * @param {Function} reload reloads the library
 * @param {HTMLElement} status the page's live region, used for the looking
 * @return {HTMLElement} the onboarding panel
 */
function renderOnboarding(data, reload, status) {
	const panel = document.createElement('div')
	panel.className = 'arcade-library-onboarding'

	const where = document.createElement('p')
	where.className = 'arcade-library-hint'
	where.textContent = data.exists
		? t('arcade', 'The games library folder {folder} exists, but no games were found in it.', { folder: data.folder })
		: t('arcade', 'The games library folder {folder} does not exist yet.', { folder: data.folder })
	panel.appendChild(where)


	const explain = document.createElement('p')
	explain.textContent = t(
		'arcade',
		'Arcade lists the ROM files of retro consoles — like .nes, .sfc, .gba, .md or zipped games — and plays them right in the browser.',
	)
	panel.appendChild(explain)

	// The page has just said which folder is missing, so it offers to make
	// it. Without this the only way forward was to leave for the Files
	// app, make the folder by hand, come back and rescan -- four steps to
	// answer a question the page had already asked and answered.
	if (!data.exists && data.folder) {
		const make = focusable(document.createElement('button'), 'onboarding/create')
		make.type = 'button'
		make.className = 'primary'
		make.textContent = t('arcade', 'Create {folder}', { folder: data.folder })
		make.addEventListener('click', async () => {
			make.disabled = true
			try {
				await createFolder(data.folder)
				showInfo(t('arcade', 'Created {folder}. Put some games in it and rescan.', { folder: data.folder }))
				reload(true)
			} catch (error) {
				console.error('Could not create the games library folder', error)
				showError(t('arcade', 'Could not create {folder}', { folder: data.folder }))
				make.disabled = false
			}
		})
		panel.appendChild(make)
	}

	// The looking goes on in the background and rewrites this line when it
	// is done, so it is the page's live region that carries it.
	status.textContent = t('arcade', 'Looking for ROMs in your files …')
	panel.appendChild(status)
	loadSuggestions(status, reload)

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
		// Typing in the search field fires several loads; only the last one
		// is of interest. The one before it is dropped before anything is
		// painted, so a page cached under the old filter cannot land on
		// screen after the new filter has already been asked for.
		pending?.abort()
		pending = new AbortController()

		const key = cacheKey()
		if (!refresh) {
			const cached = readCache(key)
			if (cached !== null) {
				render(cached)
			}
		}

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
				// Nothing on screen yet for a toast to sit beside: the page
				// itself has to carry the failure.
				onError(t('arcade', 'Could not load the games library.'))
			} else if (refresh) {
				// A reload that was asked for -- the refresh button, or a
				// favorite that just moved. The background revalidation stays
				// quiet: it fails over a page that is still good.
				showError(t('arcade', 'Could not load the games library.'))
			}
			return
		}
		// A page past the end of the list -- the page size grew, or games
		// went away under a filter -- would draw an empty grid under a line
		// reading "121–150 of 40". Step back to the last page that has
		// games on it and ask again.
		const limit = data.limit > 0 ? data.limit : state.pageSize
		const lastOffset = data.total > 0
			? Math.floor((data.total - 1) / limit) * limit
			: 0
		if (state.offset > lastOffset) {
			state.offset = lastOffset
			await load(refresh)
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
		thumbnailsVersion = data.thumbnailsVersion ?? ''
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

		// The whole page is thrown away and drawn again, so whatever held
		// the focus has to be found again by name afterwards -- and a text
		// field also wants the caret where it was left.
		const active = document.activeElement
		const focused = container.contains(active) ? active.getAttribute(FOCUS) : null
		const caret = active instanceof HTMLInputElement ? active.selectionStart : null

		container.innerHTML = ''
		const status = paint(data)

		// A live region only announces what arrives after it is in the
		// document, never what it was inserted already holding -- and the
		// whole page is inserted at once here. So the line is emptied and
		// filled again a frame later, which is a change the region can see.
		const said = status.textContent
		status.textContent = ''
		requestAnimationFrame(() => {
			if (status.isConnected && status.textContent === '') {
				status.textContent = said
			}
		})

		if (focused !== null) {
			const again = container.querySelector(`[${FOCUS}="${CSS.escape(focused)}"]`)
			if (again !== null) {
				again.focus()
				if (caret !== null && again instanceof HTMLInputElement) {
					again.setSelectionRange(caret, caret)
				}
			}
		}
	}

	/**
	 * Draw the page into the emptied container. The focus is put back by
	 * render() around this, so nothing here has to think about it.
	 *
	 * @param {object} data the library response
	 * @return {HTMLElement} the page's live region, wherever it ended up
	 */
	const paint = (data) => {
		container.appendChild(renderHeader(load, setView, data.rescanning === true))

		// One live region for the page: the count of what matched, the
		// reason there was nothing to count, or what the looking for ROM
		// folders turned up. It is put wherever the path at hand needs it.
		const status = document.createElement('p')
		status.className = 'arcade-library-hint arcade-library-count'
		status.setAttribute('role', 'status')

		if (!data.exists || data.libraryTotal === 0) {
			container.appendChild(renderOnboarding(data, load, status))
			return status
		}

		// Only on the plain first page: these are shortcuts, not results.
		const plainPage = state.search === '' && state.system === '' && state.tag === '' && state.offset === 0
		if (plainPage && (data.favorites ?? []).length > 0) {
			container.appendChild(renderRow(
				t('arcade', 'Favorites'),
				'arcade-library-favorites',
				data.favorites,
				load,
				'favorites',
			))
		}
		if (plainPage && (data.recent ?? []).length > 0) {
			container.appendChild(renderRow(
				t('arcade', 'Recently played'),
				'arcade-library-recent',
				data.recent,
				load,
				'recent',
			))
		}

		container.appendChild(renderFilters(data.systems, data.tags ?? [], load))

		if ((data.games ?? []).length === 0) {
			status.textContent = data.total === 0
				? t('arcade', 'No games match the filters.')
				: t('arcade', 'No games on this page. Go back to see the rest.')
			container.appendChild(status)
			if (data.total > 0) {
				// Somewhere to go back to, rather than a dead end. The count
				// is already spoken for by the line above it.
				container.appendChild(renderPagination(data, () => load(), null))
			}
			return status
		}

		const views = {
			grid: () => renderGrid(data.games, load),
			list: () => renderList(data.games),
			table: () => renderTable(data.games, () => load()),
		}
		container.appendChild((views[state.view] ?? views.grid)())

		container.appendChild(renderPagination(data, () => load(), status))
		if (data.truncated) {
			const truncated = document.createElement('p')
			truncated.className = 'arcade-library-hint'
			truncated.textContent = t('arcade', 'Only the first {count} games of the folder are listed.', {
				count: data.libraryTotal,
			})
			container.appendChild(truncated)
		}
		return status
	}

	await load()
}
