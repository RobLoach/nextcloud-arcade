/**
 * Gamepad browsing for the games library.
 *
 * Someone with a controller in hand should not have to reach for the mouse
 * to pick the next game. The pad moves real DOM focus between the game
 * links — the same focus the keyboard uses, drawn with the server's own
 * focus outline — so there is no second highlight system to keep in sync,
 * and A simply clicks the link the browser already considers current.
 *
 * The player is a separate page load, so nothing here ever fights the
 * emulator over the controller. Plugging a pad in is the opt-in: the poll
 * loop only runs while a pad is connected and the page is visible.
 */

const DEAD_ZONE = 0.5

// A first tap moves one step; holding waits a beat before repeating, like
// a keyboard key, so a short press never overshoots.
const FIRST_REPEAT = 400
const REPEAT = 150

// The game links of every view: grid cards, list rows, and table name
// cells. Document order matches visual order in all three.
const LINKS = '.arcade-library-game-link, .arcade-library-row, .arcade-library-table tbody a'

// The standard gamepad mapping: face buttons, shoulders, and the d-pad.
const BUTTON_A = 0
const BUTTON_B = 1
const BUTTON_L1 = 4
const BUTTON_R1 = 5
const DPAD = { up: 12, down: 13, left: 14, right: 15 }

/**
 * Merge every connected pad into one reading, so a second controller on
 * the couch works without either being "the" one.
 *
 * @return {object} pressed flags and the direction being held, if any
 */
function readPads() {
	const snap = { a: false, b: false, l1: false, r1: false, direction: null }
	for (const pad of navigator.getGamepads()) {
		if (!pad || !pad.connected) {
			continue
		}
		snap.a = snap.a || pad.buttons[BUTTON_A]?.pressed
		snap.b = snap.b || pad.buttons[BUTTON_B]?.pressed
		snap.l1 = snap.l1 || pad.buttons[BUTTON_L1]?.pressed
		snap.r1 = snap.r1 || pad.buttons[BUTTON_R1]?.pressed
		if (snap.direction === null) {
			const x = pad.axes[0] ?? 0
			const y = pad.axes[1] ?? 0
			if (pad.buttons[DPAD.up]?.pressed || y < -DEAD_ZONE) {
				snap.direction = 'up'
			} else if (pad.buttons[DPAD.down]?.pressed || y > DEAD_ZONE) {
				snap.direction = 'down'
			} else if (pad.buttons[DPAD.left]?.pressed || x < -DEAD_ZONE) {
				snap.direction = 'left'
			} else if (pad.buttons[DPAD.right]?.pressed || x > DEAD_ZONE) {
				snap.direction = 'right'
			}
		}
	}
	return snap
}

/**
 * @param {HTMLElement} link a game link
 * @return {number} how many links share its visual row's width
 */
function columnsAround(link) {
	// The grid does not say how many columns it has; the cards do, by
	// where they landed. Counting the leading links that share the first
	// one's top edge gives the column count — and comes out as 1 for the
	// list and table, where every game has a row of its own.
	const group = link.closest('.arcade-library-grid, .arcade-library-shelf-row, .arcade-library-rows, .arcade-library-table')
	if (group === null) {
		return 1
	}
	const links = [...group.querySelectorAll(LINKS)]
	if (links.length === 0) {
		return 1
	}
	const top = links[0].getBoundingClientRect().top
	let columns = 0
	for (const candidate of links) {
		if (Math.abs(candidate.getBoundingClientRect().top - top) > 4) {
			break
		}
		columns++
	}
	return Math.max(1, columns)
}

/**
 * @param {HTMLElement} link the game link to focus
 */
function focusLink(link) {
	link.focus()
	// Focus alone only scrolls when the element is fully out of view;
	// nudge partially hidden cards fully in so the outline can be seen.
	link.scrollIntoView({ block: 'nearest', inline: 'nearest' })
}

/**
 * Attach gamepad browsing to the library page.
 *
 * @param {HTMLElement} container the element the library renders into
 */
export function attachLibraryGamepad(container) {
	let frame = null
	let heldDirection = null
	let repeatAt = 0
	// One press, one action: a button only fires again after it was seen
	// released, however many frames it stays down.
	const wasPressed = { a: false, b: false, l1: false, r1: false }

	const links = () => [...container.querySelectorAll(LINKS)]
	const search = () => container.querySelector('.arcade-library-search')

	const move = (direction) => {
		const all = links()
		if (all.length === 0) {
			return
		}
		const index = all.indexOf(document.activeElement)
		// Nothing of the library holds focus yet: the first press only
		// picks up the first game, it does not move past it.
		if (index === -1) {
			focusLink(all[0])
			return
		}
		const step = direction === 'left'
			? -1
			: direction === 'right'
				? 1
				: (direction === 'up' ? -1 : 1) * columnsAround(all[index])
		focusLink(all[Math.min(all.length - 1, Math.max(0, index + step))])
	}

	const page = (forward) => {
		// The Previous and Next buttons, when the page count called for
		// them; without pagination the shoulders do nothing.
		const buttons = container.querySelectorAll('.arcade-library-pagination button')
		const button = forward ? buttons[1] : buttons[0]
		if (button !== undefined && !button.disabled) {
			button.click()
		}
	}

	const act = (snap, now) => {
		const active = document.activeElement
		const typing = active instanceof HTMLInputElement
			|| active instanceof HTMLSelectElement
			|| active instanceof HTMLTextAreaElement

		const direction = snap.direction
		let moveNow = false
		if (direction === null) {
			heldDirection = null
		} else if (direction !== heldDirection) {
			heldDirection = direction
			repeatAt = now + FIRST_REPEAT
			moveNow = true
		} else if (now >= repeatAt) {
			repeatAt = now + REPEAT
			moveNow = true
		}

		if (typing) {
			// Text fields and selects own the pad's letters and arrows on
			// some browsers; stay out of their way. The search field alone
			// gets two exits: B backs out, down dives into the games.
			if (active === search()) {
				if (snap.b && !wasPressed.b) {
					active.blur()
				}
				if (moveNow && direction === 'down') {
					const all = links()
					if (all.length > 0) {
						focusLink(all[0])
					}
				}
			}
		} else {
			if (moveNow) {
				move(direction)
			}
			if (snap.a && !wasPressed.a) {
				const all = links()
				const index = all.indexOf(active)
				if (index !== -1) {
					active.click()
				} else if (all.length > 0) {
					// A with nothing focused is the same first touch as a
					// direction: land on the first game, launch nothing.
					focusLink(all[0])
				}
			}
			if (snap.b && !wasPressed.b) {
				// B backs out of the games and up to the search field,
				// the natural place to start over from.
				search()?.focus()
			}
			if (snap.l1 && !wasPressed.l1) {
				page(false)
			}
			if (snap.r1 && !wasPressed.r1) {
				page(true)
			}
		}

		wasPressed.a = snap.a
		wasPressed.b = snap.b
		wasPressed.l1 = snap.l1
		wasPressed.r1 = snap.r1
	}

	const poll = (now) => {
		frame = null
		// The loop dies on its own the moment the last pad goes away or
		// the tab is hidden; connect and visibility events revive it.
		if (document.hidden) {
			return
		}
		const pads = [...navigator.getGamepads()].filter((pad) => pad?.connected)
		if (pads.length === 0) {
			return
		}
		act(readPads(), now)
		frame = requestAnimationFrame(poll)
	}

	const start = () => {
		if (frame === null) {
			frame = requestAnimationFrame(poll)
		}
	}

	window.addEventListener('gamepadconnected', start)
	window.addEventListener('gamepaddisconnected', start)
	document.addEventListener('visibilitychange', start)
	// A pad plugged in before the page loaded fires no event; look once.
	start()
}
