/**
 * Starting a game in a given save state, asked for from outside the
 * player -- the Load button of a slot in the Arcade tab of the sidebar.
 *
 * The game opens in the Viewer or on the app page, and neither takes a
 * slot along, so the wish is left in the session for the player to pick
 * up when it lists the slots. It is only good for that game, and only
 * for a little while: a wish left over must not jump some later game to
 * a slot nobody asked for.
 */

const KEY = 'arcade-start-slot'
const GOOD_FOR = 2 * 60 * 1000

const same = (a, b) => String(a).replace(/^\/+/, '') === String(b).replace(/^\/+/, '')

/**
 * @param {string} path the path of the game
 * @param {number} slot the slot to start in
 */
export function startInSlot(path, slot) {
	try {
		sessionStorage.setItem(KEY, JSON.stringify({ path, slot, at: Date.now() }))
	} catch (error) {
		// Without storage the game still opens, and offers to resume.
		console.warn('arcade: could not keep the slot to start in', error)
	}
}

/**
 * The slot a game was asked to start in, once: taking it clears it.
 *
 * @param {string} path the path of the game being started
 * @return {?number} the slot, or null when none was asked for
 */
export function takeStartSlot(path) {
	let wish = null
	try {
		wish = JSON.parse(sessionStorage.getItem(KEY) ?? 'null')
		sessionStorage.removeItem(KEY)
	} catch {
		return null
	}
	if (wish === null || !same(wish.path, path) || Date.now() - Number(wish.at) > GOOD_FOR) {
		return null
	}
	return Number.isInteger(wish.slot) ? wish.slot : null
}
