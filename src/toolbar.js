import { getCurrentUser, getRequestToken } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { AUTO_SLOT } from './api.js'
import { ICONS, icon } from './icons.js'
import { createGalleryPanel } from './panels/gallery.js'
import { offerResume } from './panels/resume.js'
import { createStatesPanel } from './panels/states.js'
import { davUrl, fileIdOf } from './player.js'
import { shortNameForPath } from './systems.js'
import { attachTouchControls, isTouchDevice } from './touch.js'
import { waitAtMost } from './wait.js'

// How long the save on the way out is given before the player leaves
// anyway. Long enough for a healthy core, short enough to stay a door.
const SAVE_BEFORE_LEAVING_WAIT = 5000

// Everything the player puts on top of the game. Keys pressed inside it
// belong to whatever has the focus there, not to the emulator.
const CHROME_SELECTOR = '.arcade-toolbar, .arcade-topbar, .arcade-actions-menu, .arcade-states, .arcade-gallery, .arcade-resume'

/**
 * Attach a control bar for a running emulator.
 *
 * @param {object} options options
 * @param {HTMLElement} options.container element to attach the toolbar to
 * @param {import('nostalgist').Nostalgist} options.instance the running emulator
 * @param {string} options.romPath path identifying the game, for save states
 * @param {string} options.romName file name of the ROM, for screenshots
 * @param {object} options.settings the user settings
 * @param {string} [options.closeUrl] when set, adds a close button leading there
 * @param {string} [options.notice] a word from the launch, flashed once the bar is up
 * @return {Function} detaches the toolbar again
 */
export function attachToolbar({ container, instance, romPath, romName, settings = {}, closeUrl = '', onClose = null, notice = '' }) {
	container.classList.add('arcade-player-container')

	const toolbar = document.createElement('div')
	toolbar.className = 'arcade-toolbar'

	// The top-right cluster carries the chrome for leaving the game. Like
	// the actions and close buttons that live in it, it only exists where
	// a close URL is passed -- the app page.
	let topbar = null
	if (closeUrl !== '') {
		topbar = document.createElement('div')
		topbar.className = 'arcade-topbar'
	}

	const status = document.createElement('span')
	status.className = 'arcade-toolbar-status'
	// Everything the player is ever told happens here and nowhere else --
	// a deleted battery save, a state that would not save, a missing BIOS
	// -- so it is read out as it arrives rather than only seen.
	status.setAttribute('role', 'status')
	let statusTimer = null
	const flash = (text) => {
		status.textContent = text
		clearTimeout(statusTimer)
		statusTimer = setTimeout(() => {
			status.textContent = ''
		}, 3000)
	}

	const button = (iconPath, label, onClick, parent = toolbar) => {
		const element = document.createElement('button')
		element.type = 'button'
		element.title = label
		element.setAttribute('aria-label', label)
		element.innerHTML = icon(iconPath)
		element.addEventListener('click', (event) => {
			event.stopPropagation()
			onClick(element)
		})
		parent.appendChild(element)
		return element
	}

	// A button that is either on or off says so, rather than only looking
	// it: the class is a colour, aria-pressed is the state itself.
	const setPressed = (element, on) => {
		element.classList.toggle('active', on)
		element.setAttribute('aria-pressed', String(on))
	}
	// One that opens something says that instead.
	const setExpanded = (element, open) => {
		element?.classList.toggle('active', open)
		element?.setAttribute('aria-expanded', String(open))
	}

	let paused = false
	const pauseButton = button(ICONS.pause, t('arcade', 'Pause'), () => {
		if (paused) {
			instance.resume()
		} else {
			instance.pause()
		}
		setPaused(!paused)
	})
	// The one place that decides what "paused" looks like, so the flag and
	// the button cannot drift apart -- as they did when restarting resumed
	// the core behind the button's back.
	const setPaused = (value) => {
		paused = value
		pauseButton.innerHTML = icon(paused ? ICONS.play : ICONS.pause)
		pauseButton.title = paused ? t('arcade', 'Resume') : t('arcade', 'Pause')
		pauseButton.setAttribute('aria-label', pauseButton.title)
		setPressed(pauseButton, paused)
	}
	setPressed(pauseButton, false)

	// A game left in a background tab keeps the processor busy for nothing,
	// but a game that goes quiet on its own is a surprise, so it is asked
	// for. The server merges its defaults into every settings payload, so
	// the flag is only ever absent when there is no payload at all -- and
	// off is what the default says then too.
	let pausedByTab = false
	const onVisibilityChange = () => {
		if (settings.pause_when_hidden !== true) {
			return
		}
		if (document.hidden && !paused) {
			instance.pause()
			pausedByTab = true
		} else if (!document.hidden && pausedByTab) {
			instance.resume()
			pausedByTab = false
		}
	}
	document.addEventListener('visibilitychange', onVisibilityChange)

	// Restarting lives in the actions menu, but keeps a handler of its
	// own so a hotkey could reach it without a button to click.
	const restartGame = () => {
		instance.restart()
		// restart() brings the core back running, so a button still saying
		// "Resume" would be a lie -- and one the idle chrome and the
		// autosave both go on believing for the rest of the session.
		setPaused(false)
		flash(t('arcade', 'Restarted'))
	}

	// Saving needs a logged-in user and somewhere of their own to put it,
	// so on a public share, or without a saves folder, there is none.
	const canSave = getCurrentUser() !== null && romPath && (settings.saves_folder ?? '') !== ''
	let autosaveTimer = null
	let statesPanel = null
	let statesButton = null
	let galleryPanel = null
	let galleryButton = null
	// Hiding a panel and letting go of its button always travel together.
	// So does the focus: a panel that goes away while it holds the focus
	// would drop it on the body, leaving the keyboard nowhere.
	const hidePanel = (panel, element) => {
		if (panel === null) {
			return
		}
		const held = panel.element.contains(document.activeElement)
		panel.element.classList.add('hidden')
		setExpanded(element, false)
		if (held) {
			element?.focus()
		}
	}
	const hideStates = () => hidePanel(statesPanel, statesButton)
	const hideGallery = () => hidePanel(galleryPanel, galleryButton)
	const statesOpen = () => statesPanel !== null && !statesPanel.element.classList.contains('hidden')
	const galleryOpen = () => galleryPanel !== null && !galleryPanel.element.classList.contains('hidden')
	const togglePanel = (panel, element, onOpen) => {
		const visible = !panel.element.classList.contains('hidden')
		if (visible) {
			hidePanel(panel, element)
			return
		}
		panel.element.classList.remove('hidden')
		setExpanded(element, true)
		onOpen()
		// Into the panel, so it is read out on opening and its buttons are
		// the next thing the Tab key reaches.
		panel.element.focus()
	}
	if (canSave) {
		statesPanel = createStatesPanel({
			instance,
			romPath,
			flash,
			// Saving or loading a slot is the end of the interaction, so get
			// the panel out of the way and back to the game.
			onDone: hideStates,
		})
		statesButton = button(ICONS.save, t('arcade', 'Save states'), (element) => {
			togglePanel(statesPanel, element, () => {
				// Both panels cover the game, so only one shows at a time.
				hideGallery()
				closeActionsMenu()
				statesPanel.refresh()
			})
		})
		statesButton.setAttribute('aria-haspopup', 'dialog')
		setExpanded(statesButton, false)
		offerResume({
			container,
			romPath,
			load: statesPanel.load,
			automatic: settings.autoload_on_start === true,
		})

		// And keep saving it while it is played, when asked to. Two tabs
		// on the same game write over each other's automatic slot here,
		// last tick winning; coordinating them is not attempted.
		const interval = Number(settings.autosave_interval ?? 0)
		if (interval > 0) {
			autosaveTimer = setInterval(() => {
				if (!paused && !document.hidden) {
					statesPanel.save(AUTO_SLOT)
				}
			}, interval * 1000)
		}
	}

	// Every way out of the game goes through here first, so the game is
	// left where it was and can be picked up again. Nothing to save
	// without a slot to save into, or when it was not asked for.
	const saveBeforeLeaving = async () => {
		if (settings.autosave_on_close !== true || statesPanel === null) {
			return
		}
		flash(t('arcade', 'Saving the game …'))
		await statesPanel.save(AUTO_SLOT)
	}

	// Virtual gamepad for touch play.
	let touchControls = null
	if (isTouchDevice()) {
		touchControls = attachTouchControls({ container, instance })
		const touchButton = button(ICONS.gamepad, t('arcade', 'Touch controls'), (element) => {
			const hidden = touchControls.element.classList.toggle('hidden')
			setPressed(element, !hidden)
		})
		setPressed(touchButton, true)
	}

	const muteButton = button(ICONS.mute, t('arcade', 'Mute'), (element) => {
		instance.sendCommand('MUTE')
		setPressed(element, !element.classList.contains('active'))
	})
	setPressed(muteButton, false)

	const fastForwardButton = button(ICONS.fastForward, t('arcade', 'Fast-forward'), (element) => {
		instance.sendCommand('FAST_FORWARD')
		setPressed(element, !element.classList.contains('active'))
	})
	setPressed(fastForwardButton, false)

	// RetroArch's built-in menu, with core options, control remapping, etc.
	// It sits in the actions menu; the handler stays separate so a hotkey
	// could reach it too.
	const toggleRetroArchMenu = () => {
		instance.sendCommand('MENU_TOGGLE')
	}

	// Inside the Files Viewer there is no actions menu of ours -- the
	// Viewer's own knows nothing of the emulator -- so the two commands
	// keep their old place in the pill there.
	if (closeUrl === '') {
		button(ICONS.menu, t('arcade', 'RetroArch menu'), toggleRetroArchMenu)
		button(ICONS.restart, t('arcade', 'Restart'), restartGame)
	}

	// One shot at a time, for the same reason the state panel takes one
	// operation at a time: a held hotkey would otherwise fire a burst of
	// them, each one a file of its own.
	let shooting = false
	const screenshotButton = button(ICONS.screenshot, t('arcade', 'Screenshot'), async () => {
		if (shooting) {
			return
		}
		shooting = true
		try {
			const blob = await instance.screenshot()
			const stem = (romName || 'nostalgist').replace(/\.[^.]+$/, '')
			const folder = settings.screenshots_folder
			if (folder && getCurrentUser() !== null) {
				// Under the system, so two games of the same name keep apart.
				const system = shortNameForPath(romPath)
				await saveScreenshot(system === '' ? folder : `${folder}/${system}`, stem, blob)
				flash(t('arcade', 'Screenshot saved to {folder}', { folder }))
				galleryPanel?.refresh()
			} else {
				const url = URL.createObjectURL(blob)
				const link = document.createElement('a')
				link.href = url
				link.download = `${stem}.png`
				link.click()
				URL.revokeObjectURL(url)
			}
		} catch (error) {
			console.error('Could not take a screenshot', error)
			flash(t('arcade', 'Could not take a screenshot'))
		} finally {
			shooting = false
		}
	})

	// The screenshots of this game, which also live in the user's files.
	if (getCurrentUser() !== null && romPath && (settings.screenshots_folder ?? '') !== '') {
		galleryPanel = createGalleryPanel({ romPath, flash })
		galleryButton = button(ICONS.gallery, t('arcade', 'Screenshots'), (element) => {
			togglePanel(galleryPanel, element, () => {
				hideStates()
				closeActionsMenu()
				galleryPanel.refresh()
			})
		})
		galleryButton.setAttribute('aria-haspopup', 'dialog')
		setExpanded(galleryButton, false)
		// Nothing to show until there is a screenshot of this game.
		galleryButton.classList.add('hidden')
		galleryPanel.count().then((count) => {
			galleryButton.classList.toggle('hidden', count === 0)
		})
	}

	const fullscreenButton = button(ICONS.fullscreen, t('arcade', 'Fullscreen'), () => {
		if (document.fullscreenElement !== null) {
			document.exitFullscreen()
		} else {
			container.requestFullscreen?.()
		}
	})

	// The three-dots actions menu, with what the Files Viewer offers in its
	// own chrome. Inside the Viewer the player would only double it, so it
	// is kept to the app page -- the one place a close URL is passed.
	let actionsMenu = null
	let actionsButton = null
	let refreshSidebarItem = null
	const closeActionsMenu = () => {
		if (actionsMenu === null) {
			return
		}
		// Only when the menu had the focus: an outside click closing it is
		// on its way somewhere else, and should not be pulled back here.
		const held = actionsMenu.contains(document.activeElement)
		actionsMenu.classList.add('hidden')
		setExpanded(actionsButton, false)
		if (held) {
			actionsButton?.focus()
		}
	}
	if (closeUrl !== '') {
		actionsMenu = document.createElement('div')
		actionsMenu.className = 'arcade-actions-menu hidden'

		const item = (iconPath, label, onClick) => {
			const element = document.createElement('button')
			element.type = 'button'
			element.className = 'arcade-actions-item'
			element.innerHTML = icon(iconPath)
			element.appendChild(document.createTextNode(label))
			element.addEventListener('click', (event) => {
				event.stopPropagation()
				closeActionsMenu()
				onClick()
			})
			actionsMenu.appendChild(element)
			return element
		}

		// The same entry for what leads somewhere rather than doing
		// something: an anchor, so it can be opened in a tab or copied the
		// way any other link can. Closing the menu is all the click does;
		// the browser follows the link itself.
		const linkItem = (iconPath, label, href, attributes = {}) => {
			const element = document.createElement('a')
			element.className = 'arcade-actions-item'
			element.href = href
			for (const [name, value] of Object.entries(attributes)) {
				element.setAttribute(name, value)
			}
			element.innerHTML = icon(iconPath)
			element.appendChild(document.createTextNode(label))
			element.addEventListener('click', (event) => {
				event.stopPropagation()
				closeActionsMenu()
			})
			actionsMenu.appendChild(element)
			return element
		}

		item(ICONS.fullscreen, t('arcade', 'Full screen'), () => fullscreenButton.click())
		item(ICONS.menu, t('arcade', 'RetroArch menu'), toggleRetroArchMenu)
		item(ICONS.restart, t('arcade', 'Restart'), restartGame)
		// Without the sidebar, the details live one page away: in the Files
		// app, with the file's details pane open. Leaving the game is like
		// closing it, so the game is left where it was first.
		const openFilesDetails = async () => {
			const missing = window.OCA === undefined
				? 'OCA'
				: window.OCA.Files === undefined ? 'OCA.Files' : 'OCA.Files.Sidebar'
			console.warn(`arcade: cannot open the Files sidebar, window.${missing} is undefined; opening the Files app instead`)
			await waitAtMost(saveBeforeLeaving(), SAVE_BEFORE_LEAVING_WAIT, undefined)
			const absolute = romPath.startsWith('/') ? romPath : `/${romPath}`
			const dir = absolute.replace(/\/[^/]*$/, '') || '/'
			let target
			try {
				const fileId = await fileIdOf(romPath)
				target = generateUrl('/apps/files/files/' + fileId) + '?dir=' + encodeURIComponent(dir) + '&opendetails=true'
			} catch (error) {
				console.warn('arcade: could not resolve the file id, opening the folder instead', error)
				target = generateUrl('/apps/files') + '?dir=' + encodeURIComponent(dir)
			}
			window.location.href = target
		}
		// The full Files sidebar. Whether the page carries it is only known
		// for sure when it is asked for, so the item always shows and the
		// click looks for it.
		const sidebarItem = item(ICONS.sidebar, t('arcade', 'Open sidebar'), () => {
			if (window.OCA?.Files?.Sidebar === undefined) {
				openFilesDetails()
				return
			}
			window.OCA.Files.Sidebar.open(romPath.startsWith('/') ? romPath : `/${romPath}`)
		})
		// What the item does depends on the page, so its face follows suit
		// each time the menu opens.
		refreshSidebarItem = () => {
			const available = window.OCA?.Files?.Sidebar !== undefined
			sidebarItem.innerHTML = icon(available ? ICONS.sidebar : ICONS.information)
			sidebarItem.appendChild(document.createTextNode(
				available ? t('arcade', 'Open sidebar') : t('arcade', 'Details'),
			))
		}
		// The personal Arcade settings, in a tab of their own: leaving this
		// page would tear the running game down, so the game stays put and
		// no autosave dance is needed.
		linkItem(ICONS.cog, t('arcade', 'Settings'), generateUrl('/settings/user/arcade'), {
			target: '_blank',
			rel: 'noopener noreferrer',
		})
		if (romPath) {
			linkItem(ICONS.download, t('arcade', 'Download'), davUrl(romPath), { download: romName || '' })
		}

		actionsButton = button(ICONS.dots, t('arcade', 'Actions'), (element) => {
			const visible = !actionsMenu.classList.contains('hidden')
			actionsMenu.classList.toggle('hidden', visible)
			setExpanded(element, !visible)
			if (!visible) {
				// The menu and the panels cover the same spot.
				hideStates()
				hideGallery()
				refreshSidebarItem?.()
				actionsMenu.querySelector('.arcade-actions-item')?.focus()
			}
		}, topbar)
		actionsButton.setAttribute('aria-haspopup', 'true')
		setExpanded(actionsButton, false)
		// Before the close button, not after it: the menu belongs to the
		// button that opens it, and Tab should walk into it rather than
		// past it to Close.
		topbar.appendChild(actionsMenu)
	}

	// A click anywhere else puts the menu away, the way core menus behave.
	// The toolbar's own buttons stop propagation, so they are not "anywhere
	// else" and keep their meaning.
	const onDocumentClick = (event) => {
		if (actionsMenu === null || actionsMenu.classList.contains('hidden')) {
			return
		}
		if (!actionsMenu.contains(event.target)) {
			closeActionsMenu()
		}
	}
	document.addEventListener('click', onDocumentClick)

	let closeButton = null
	if (closeUrl !== '') {
		closeButton = button(ICONS.close, t('arcade', 'Close'), async (element) => {
			element.disabled = true
			// Bounded: the save polls the core's file system for the file
			// it wrote, and a core that never writes it would otherwise
			// hold the page here for a minute with nothing to press.
			await waitAtMost(saveBeforeLeaving(), SAVE_BEFORE_LEAVING_WAIT, undefined)
			// Closing is the session's to do, not the toolbar's: it waits
			// for the battery save before it takes the core away.
			await onClose?.()
			window.location.href = closeUrl
		}, topbar)
	}

	// The keys the player itself listens for, as they were set. A key that
	// a game uses is left to the game: the emulator needs it more.
	const controlKeys = new Set(Object.values(settings.buttons ?? {}))
	const hotkeys = settings.hotkeys ?? {}
	const actions = {
		pause: () => pauseButton.click(),
		fastForward: () => fastForwardButton.click(),
		fullscreen: () => fullscreenButton.click(),
		saveState: () => statesPanel?.save(1),
		loadState: () => statesPanel?.load(1),
		screenshot: () => screenshotButton.click(),
		// Inside the Files Viewer there is nowhere to close to, so the key
		// is not the player's to take: saying so lets it through to the
		// Viewer, whose own Escape closes the modal.
		closeGame: () => (closeButton === null ? false : closeButton.click()),
	}
	const bound = {}
	for (const [action, code] of Object.entries(hotkeys)) {
		if (actions[action] !== undefined && !controlKeys.has(code)) {
			bound[code] = actions[action]
		}
	}
	// Escape unwinds the player one layer at a time, and an action answers
	// false when there was no layer of ours left for it to take.
	const escapeAction = bound.Escape
	bound.Escape = () => {
		// The actions menu first: while it shows, Escape means only "put
		// the menu away", never whatever the key is bound to, such as
		// closing the game.
		if (actionsMenu !== null && !actionsMenu.classList.contains('hidden')) {
			closeActionsMenu()
			return true
		}
		const panelOpen = statesOpen() || galleryOpen()
		if (panelOpen) {
			hideStates()
			hideGallery()
			return true
		}
		// Then the full screen, and only the one this game is filling:
		// leaving it is what Escape means everywhere else on the web, and
		// closing the game underneath it would be a layer too many.
		if (document.fullscreenElement === container) {
			document.exitFullscreen().catch((error) => {
				console.error('Could not leave fullscreen', error)
			})
			return true
		}
		if (escapeAction === undefined) {
			return false
		}
		return escapeAction()
	}

	const onKeyDown = (event) => {
		if (event.ctrlKey || event.altKey || event.metaKey) {
			return
		}
		// Held keys repeat, and none of what the player binds means
		// anything more the second time: a held save key would be sixty
		// saves a second of the same slot.
		if (event.repeat) {
			return
		}
		const target = event.target
		if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
			return
		}
		// Nor from our own chrome: a button that has the focus owns its
		// keys -- Space and Enter press it -- and taking Space there would
		// pause the game instead of working the button under the finger.
		if (target instanceof Element && target.closest(CHROME_SELECTOR) !== null) {
			return
		}
		const action = bound[event.code]
		if (action === undefined) {
			return
		}
		// A hotkey is activity too: bring the chrome back for it.
		wakeChrome()
		if (action() === false) {
			// Nothing of ours came of it, so the key is not ours to keep:
			// the Viewer's own Escape still has a modal to close.
			return
		}
		event.preventDefault()
		event.stopPropagation()
	}
	// Ahead of the emulator, which listens on the window for its own keys.
	document.addEventListener('keydown', onKeyDown, true)

	toolbar.appendChild(status)
	container.appendChild(toolbar)
	if (notice !== '') {
		// Known since before the toolbar existed -- the launch found out,
		// but had no status line yet to say it on. Said now, once.
		flash(notice)
	}
	if (statesPanel !== null) {
		container.appendChild(statesPanel.element)
	}
	if (galleryPanel !== null) {
		container.appendChild(galleryPanel.element)
	}
	if (topbar !== null) {
		// The actions menu already hangs from the topbar, so it opens
		// downward and stays aligned to it however the container is sized.
		container.appendChild(topbar)
	}

	// The chrome fades away over an idle game, so it does not sit on the
	// picture -- and a stray click cannot hit an invisible close button,
	// because the hidden clusters take no pointer events. Any movement
	// brings it back.
	const IDLE_HIDE_DELAY = 3000
	let idleTimer = null
	const chromeBusy = () => paused
		|| (actionsMenu !== null && !actionsMenu.classList.contains('hidden'))
		|| statesOpen() || galleryOpen()
		|| container.querySelector('.arcade-resume') !== null
		|| toolbar.contains(document.activeElement) || toolbar.matches(':hover')
		|| (topbar !== null && (topbar.contains(document.activeElement) || topbar.matches(':hover')))
	const scheduleHide = () => {
		clearTimeout(idleTimer)
		idleTimer = setTimeout(() => {
			if (chromeBusy()) {
				// Try again later: a menu, panel or pause holds it open.
				scheduleHide()
				return
			}
			container.classList.add('arcade-chrome-hidden')
		}, IDLE_HIDE_DELAY)
	}
	const wakeChrome = () => {
		container.classList.remove('arcade-chrome-hidden')
		scheduleHide()
	}
	const onPointerMove = () => wakeChrome()
	const onPointerDown = (event) => {
		// A touch tap on hidden chrome is left to onTouchReveal below,
		// which can still swallow it: pointerdown comes first, and waking
		// here would make the tap look like a plain one there.
		if (event.pointerType === 'touch' && container.classList.contains('arcade-chrome-hidden')) {
			return
		}
		wakeChrome()
	}
	const onTouchReveal = (event) => {
		if (!container.classList.contains('arcade-chrome-hidden')) {
			return
		}
		// The first tap only brings the chrome back, so it cannot also
		// press whatever sits beneath. The virtual gamepad is the game's
		// own input, though: dropping a press mid-play would be worse, so
		// taps on it go through and merely reveal.
		if (!(event.target instanceof Element) || event.target.closest('.arcade-touch') === null) {
			event.preventDefault()
			event.stopPropagation()
		}
		wakeChrome()
	}
	// Focus is the keyboard's version of moving the pointer: it arriving
	// anywhere in the player brings the chrome back, so a player who never
	// touches a mouse can still get at it.
	const onFocusIn = () => wakeChrome()
	container.addEventListener('pointermove', onPointerMove)
	container.addEventListener('pointerdown', onPointerDown)
	container.addEventListener('focusin', onFocusIn)
	container.addEventListener('touchstart', onTouchReveal, { capture: true, passive: false })
	scheduleHide()

	return () => {
		clearTimeout(statusTimer)
		clearInterval(autosaveTimer)
		clearTimeout(idleTimer)
		document.removeEventListener('visibilitychange', onVisibilityChange)
		document.removeEventListener('keydown', onKeyDown, true)
		document.removeEventListener('click', onDocumentClick)
		container.removeEventListener('pointermove', onPointerMove)
		container.removeEventListener('pointerdown', onPointerDown)
		container.removeEventListener('focusin', onFocusIn)
		container.removeEventListener('touchstart', onTouchReveal, true)
		container.classList.remove('arcade-chrome-hidden')
		touchControls?.detach()
		statesPanel?.element.remove()
		galleryPanel?.element.remove()
		actionsMenu?.remove()
		container.querySelector('.arcade-resume')?.remove()
		topbar?.remove()
		toolbar.remove()
	}
}

/**
 * Upload a screenshot to the user's screenshots folder over WebDAV,
 * creating the folder if needed.
 *
 * @param {string} folder the screenshots folder
 * @param {string} stem the ROM file name without extension
 * @param {Blob} blob the screenshot
 */
async function saveScreenshot(folder, stem, blob) {
	const timestamp = new Date().toISOString().replace(/[:T]/g, '-').slice(0, 19)
	const url = davUrl(`${folder}/${stem} ${timestamp}.png`)
	const put = () => fetch(url, {
		method: 'PUT',
		headers: {
			'Content-Type': 'image/png',
			requesttoken: getRequestToken() ?? '',
		},
		body: blob,
		credentials: 'same-origin',
	})
	let response = await put()
	if (response.status === 404 || response.status === 409) {
		// The folder does not exist yet; create it and retry.
		await fetch(davUrl(folder), {
			method: 'MKCOL',
			headers: { requesttoken: getRequestToken() ?? '' },
			credentials: 'same-origin',
		})
		response = await put()
	}
	if (!response.ok) {
		throw new Error(`${response.status} ${response.statusText}`)
	}
}
