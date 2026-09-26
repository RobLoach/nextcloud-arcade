import { getCurrentUser, getRequestToken } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { AUTO_SLOT } from './api.js'
import { ICONS, icon } from './icons.js'
import { createGalleryPanel } from './panels/gallery.js'
import { offerResume } from './panels/resume.js'
import { createStatesPanel } from './panels/states.js'
import { davUrl } from './player.js'
import { shortNameForPath } from './systems.js'
import { attachTouchControls, isTouchDevice } from './touch.js'

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
 * @return {Function} detaches the toolbar again
 */
export function attachToolbar({ container, instance, romPath, romName, settings = {}, closeUrl = '', onClose = null }) {
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

	let paused = false
	const pauseButton = button(ICONS.pause, t('arcade', 'Pause'), (element) => {
		paused = !paused
		if (paused) {
			instance.pause()
		} else {
			instance.resume()
		}
		element.innerHTML = icon(paused ? ICONS.play : ICONS.pause)
		element.title = paused ? t('arcade', 'Resume') : t('arcade', 'Pause')
		element.setAttribute('aria-label', element.title)
		element.classList.toggle('active', paused)
	})

	// A game left in a background tab keeps the processor busy for nothing.
	let pausedByTab = false
	const onVisibilityChange = () => {
		if (settings.pause_when_hidden === false) {
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

	button(ICONS.restart, t('arcade', 'Restart'), () => {
		instance.restart()
		flash(t('arcade', 'Restarted'))
	})

	// Saving needs a logged-in user and somewhere of their own to put it,
	// so on a public share, or without a saves folder, there is none.
	const canSave = getCurrentUser() !== null && romPath && (settings.saves_folder ?? '') !== ''
	let autosaveTimer = null
	let statesPanel = null
	let statesButton = null
	const hideStates = () => {
		statesPanel?.element.classList.add('hidden')
		statesButton?.classList.remove('active')
	}
	if (canSave) {
		statesPanel = createStatesPanel({
			instance,
			romPath,
			flash,
			// Saving or loading a slot is the end of the interaction, so get
			// the panel out of the way and back to the game.
			onDone: () => {
				statesPanel.element.classList.add('hidden')
				statesButton?.classList.remove('active')
			},
		})
		statesButton = button(ICONS.save, t('arcade', 'Save states'), (element) => {
			const visible = !statesPanel.element.classList.contains('hidden')
			statesPanel.element.classList.toggle('hidden', visible)
			element.classList.toggle('active', !visible)
			if (!visible) {
				// Both panels cover the game, so only one shows at a time.
				galleryPanel?.element.classList.add('hidden')
				galleryButton?.classList.remove('active')
				closeActionsMenu()
				statesPanel.refresh()
			}
		})
		offerResume({
			container,
			romPath,
			load: statesPanel.load,
			automatic: settings.autoload_on_start === true,
		})

		// And keep saving it while it is played, when asked to.
		const interval = Number(settings.autosave_interval ?? 0)
		if (interval > 0) {
			autosaveTimer = setInterval(() => {
				if (!paused && !document.hidden) {
					statesPanel.save(AUTO_SLOT)
				}
			}, interval * 1000)
		}
	}

	// Virtual gamepad for touch play.
	let touchControls = null
	if (isTouchDevice()) {
		touchControls = attachTouchControls({ container, instance })
		button(ICONS.gamepad, t('arcade', 'Touch controls'), (element) => {
			const hidden = touchControls.element.classList.toggle('hidden')
			element.classList.toggle('active', !hidden)
		}).classList.add('active')
	}

	button(ICONS.mute, t('arcade', 'Mute'), (element) => {
		instance.sendCommand('MUTE')
		element.classList.toggle('active')
	})

	const fastForwardButton = button(ICONS.fastForward, t('arcade', 'Fast-forward'), (element) => {
		instance.sendCommand('FAST_FORWARD')
		element.classList.toggle('active')
	})

	// RetroArch's built-in menu, with core options, control remapping, etc.
	button(ICONS.menu, t('arcade', 'RetroArch menu'), (element) => {
		instance.sendCommand('MENU_TOGGLE')
		element.classList.toggle('active')
	})

	const screenshotButton = button(ICONS.screenshot, t('arcade', 'Screenshot'), async () => {
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
		}
	})

	// The screenshots of this game, which also live in the user's files.
	let galleryPanel = null
	let galleryButton = null
	if (getCurrentUser() !== null && romPath && (settings.screenshots_folder ?? '') !== '') {
		galleryPanel = createGalleryPanel({ romPath, flash })
		galleryButton = button(ICONS.gallery, t('arcade', 'Screenshots'), (element) => {
			const visible = !galleryPanel.element.classList.contains('hidden')
			galleryPanel.element.classList.toggle('hidden', visible)
			element.classList.toggle('active', !visible)
			if (!visible) {
				hideStates()
				closeActionsMenu()
				galleryPanel.refresh()
			}
		})
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
	const closeActionsMenu = () => {
		actionsMenu?.classList.add('hidden')
		actionsButton?.classList.remove('active')
		actionsButton?.setAttribute('aria-expanded', 'false')
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

		item(ICONS.fullscreen, t('arcade', 'Full screen'), () => fullscreenButton.click())
		// The full Files sidebar, when the page carries it.
		if (romPath && window.OCA?.Files?.Sidebar !== undefined) {
			item(ICONS.sidebar, t('arcade', 'Open sidebar'), () => {
				window.OCA.Files.Sidebar.open(romPath.startsWith('/') ? romPath : `/${romPath}`)
			})
		}
		if (romPath) {
			const link = document.createElement('a')
			link.className = 'arcade-actions-item'
			link.href = davUrl(romPath)
			link.setAttribute('download', romName || '')
			link.innerHTML = icon(ICONS.download)
			link.appendChild(document.createTextNode(t('arcade', 'Download')))
			link.addEventListener('click', (event) => {
				event.stopPropagation()
				closeActionsMenu()
			})
			actionsMenu.appendChild(link)
		}

		actionsButton = button(ICONS.dots, t('arcade', 'Actions'), (element) => {
			const visible = !actionsMenu.classList.contains('hidden')
			actionsMenu.classList.toggle('hidden', visible)
			element.classList.toggle('active', !visible)
			element.setAttribute('aria-expanded', String(!visible))
			if (!visible) {
				// The menu and the panels cover the same spot.
				hideStates()
				galleryPanel?.element.classList.add('hidden')
				galleryButton?.classList.remove('active')
			}
		}, topbar)
		actionsButton.setAttribute('aria-haspopup', 'true')
		actionsButton.setAttribute('aria-expanded', 'false')
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
			// Leave the game where it was, so it can be picked up again.
			if (settings.autosave_on_close !== false && statesPanel !== null) {
				flash(t('arcade', 'Saving the game …'))
				await statesPanel.save(AUTO_SLOT)
			}
			onClose?.()
			try {
				instance.exit()
			} catch (error) {
				console.error('Arcade failed to exit', error)
			}
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
		closeGame: () => closeButton?.click(),
	}
	const bound = {}
	for (const [action, code] of Object.entries(hotkeys)) {
		if (actions[action] !== undefined && !controlKeys.has(code)) {
			bound[code] = actions[action]
		}
	}
	// Closing whatever is open still comes first: while a panel shows,
	// Escape puts it away, and only then does what it was bound to.
	const escapeAction = bound.Escape
	bound.Escape = () => {
		// The actions menu first: while it shows, Escape means only "put
		// the menu away", never whatever the key is bound to, such as
		// closing the game.
		if (actionsMenu !== null && !actionsMenu.classList.contains('hidden')) {
			closeActionsMenu()
			return
		}
		const panelOpen = (statesPanel !== null && !statesPanel.element.classList.contains('hidden'))
			|| (galleryPanel !== null && !galleryPanel.element.classList.contains('hidden'))
		if (panelOpen) {
			hideStates()
			galleryPanel?.element.classList.add('hidden')
			galleryButton?.classList.remove('active')
			return
		}
		escapeAction?.()
	}

	const onKeyDown = (event) => {
		if (event.ctrlKey || event.altKey || event.metaKey) {
			return
		}
		const target = event.target
		if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
			return
		}
		const action = bound[event.code]
		if (action !== undefined) {
			event.preventDefault()
			event.stopPropagation()
			// A hotkey is activity too: bring the chrome back for it.
			wakeChrome()
			action()
		}
	}
	// Ahead of the emulator, which listens on the window for its own keys.
	document.addEventListener('keydown', onKeyDown, true)

	toolbar.appendChild(status)
	container.appendChild(toolbar)
	if (statesPanel !== null) {
		container.appendChild(statesPanel.element)
	}
	if (galleryPanel !== null) {
		container.appendChild(galleryPanel.element)
	}
	if (topbar !== null) {
		// The actions menu hangs from the topbar, so it opens downward and
		// stays aligned to it however the container is sized.
		if (actionsMenu !== null) {
			topbar.appendChild(actionsMenu)
		}
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
		|| (statesPanel !== null && !statesPanel.element.classList.contains('hidden'))
		|| (galleryPanel !== null && !galleryPanel.element.classList.contains('hidden'))
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
	container.addEventListener('pointermove', onPointerMove)
	container.addEventListener('pointerdown', onPointerDown)
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
