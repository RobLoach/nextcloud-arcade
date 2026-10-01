// The toaster the Files app uses -- the one that slides in top right --
// wrapped so the app says things the one way.
//
// The server already carries it. Nextcloud's core bundle exposes the
// very same @nextcloud/dialogs helpers as OCP.Toast.{success, warning,
// error, info, message}, styles and all, on every page that loads core
// -- which is every page this app has. Importing them from the package
// instead would ship a second copy: @nextcloud/dialogs publishes no
// ./toast subpath, only "." and "./style.css", so the helpers can only
// be had off the package index, and in v7 a toast is a Vue component,
// so Vue and the file picker ride along with it -- measured at 660 kB
// for a line of text the page can already show.
//
// Should a server ever be without the global, nothing breaks loudly:
// the message goes to the console instead of the screen.

// A toast that stays on screen until it is dismissed, for a message the
// player has to act on. The dialogs helpers read a negative timeout that
// way -- it is their own TOAST_PERMANENT_TIMEOUT -- and the value is
// repeated here rather than imported, for the same reason the helpers
// themselves are taken off the global.
export const UNTIL_DISMISSED = -1

/**
 * @return {object|null} the toaster of the server, where there is one
 */
function toaster() {
	return window.OCP?.Toast ?? null
}

/**
 * Raise one message, however the server's toaster wants it raised.
 *
 * @param {string} kind which helper of the toaster says it: error,
 *                      warning, info or success
 * @param {string} message what to say
 * @param {string} ariaLive how insistently to announce it
 * @param {Function} log where the message goes when there is no toaster
 * @param {object} options the rest of the toast's options, passed
 *                 through: `selector`, the element to hang the toast in,
 *                 and `timeout`, how long it stays, are the two this app
 *                 uses. Given last, so a caller can overrule the
 *                 announcement too.
 * @return {object|null} the toast raised, which has a hideToast() to put
 *                       it away again, or null where there was no toaster
 */
function say(kind, message, ariaLive, log, options) {
	const toast = toaster()
	if (toast === null) {
		log(message)
		return null
	}
	try {
		return toast[kind](message, { ariaLive, ...options })
	} catch (error) {
		// A message that cannot be shown must not take down whatever was
		// trying to say it. Hanging a toast somewhere of our own is the
		// only part of this that can fail, so it is dropped and the toast
		// goes where toasts go by default -- and if even that fails, the
		// console still has it.
		console.error('Arcade could not show a toast', error)
		try {
			return toast[kind](message, { ariaLive })
		} catch {
			log(message)
			return null
		}
	}
}

/**
 * The same helpers, hung inside one element instead of on document.body.
 *
 * For the player: the browser paints only the fullscreen element's
 * subtree, and the element that goes fullscreen is the player's
 * container, so a toast on the body is invisible exactly when the player
 * is most likely to be looking at one. The toaster's own container is
 * fixed-positioned, so from in here a toast still lands where the app's
 * toasts always land, with nothing to ask about the fullscreen state.
 *
 * The element itself is passed, never a selector for it. The toaster the
 * server ships reads a string as an element **id**:
 *
 *   string ? getElementById(selector)
 *          : selector instanceof HTMLElement ? selector : document.body
 *   if (!root) throw "Root element is not defined"
 *
 * so a CSS selector finds nothing and throws, taking down not just the
 * message but whatever was saying it -- which is how a whole session's
 * worth of player messages went missing. A node is taken as it is.
 *
 * The newer @nextcloud/dialogs, which a later server will carry, resolves
 * the same option with querySelector instead and would throw on a node.
 * Neither value suits both, so the throw is caught below and the message
 * said the ordinary way: on such a server the player's toasts go back to
 * the body, seen everywhere except fullscreen, and nothing breaks.
 *
 * @param {HTMLElement} element what to hang the toasts inside
 * @return {{error: Function, warning: Function, info: Function, success: Function}}
 *         the helpers, each taking the message and returning the toast
 */
export function toastsIn(element) {
	const inside = (show) => (message, options = {}) =>
		show(message, { selector: element, ...options })
	return {
		error: inside(showError),
		warning: inside(showWarning),
		info: inside(showInfo),
		success: inside(showSuccess),
	}
}

/**
 * The class the player's container wears while a message is on screen,
 * which the stylesheet uses to take the top-right chrome out of the way:
 * the toast lands in that corner, and the chrome, at z-index 20100
 * against a toast's 9000, would paint over the words.
 */
export const MESSAGE_UP = 'arcade-message-up'

// What the toaster gives a message that does not ask for a length.
const DEFAULT_TIMEOUT = 7000

// What a confirmation gets instead. "Game saved" and "Restarted" are
// glances: the player already knows what they pressed, and the message
// holds the corner -- and with it the chrome -- for as long as it is up.
// Anything that went wrong keeps the longer default; those are worth
// reading twice.
const CONFIRMATION_TIMEOUT = 3000
const CONFIRMATIONS = ['success', 'info']

/**
 * The one way the player says things: a toast hung inside the player,
 * with the chrome stepping aside while it is up.
 *
 * @param {HTMLElement} element the player's container
 * @return {{flash: Function, stop: Function}} flash takes the message,
 *         how it reads (success, info, warning or error) and the rest of
 *         the toast's options; stop puts away whatever is showing
 */
export function createFlash(element) {
	const toasts = toastsIn(element)
	// One message at a time: the newest is the one that matters. Each is
	// numbered, so that the one going away cannot bring the chrome back
	// over the top of the one that replaced it -- the old toast's removal
	// arrives a few hundred milliseconds late, by which time the new one
	// is already up.
	let current = null
	let latest = 0
	let expected = null

	const done = (id) => {
		if (id === latest) {
			element.classList.remove(MESSAGE_UP)
		}
	}

	const flash = (text, type = 'info', options = {}) => {
		current?.hideToast?.()
		clearTimeout(expected)
		const id = ++latest
		// A message that waits to be dismissed leaves the chrome alone.
		// The one the player has that never expires asks them to press
		// Close, and Close is in the chrome -- hiding it would hide the
		// button the words name, for as long as the words are up.
		const stays = options.timeout === UNTIL_DISMISSED
		element.classList.toggle(MESSAGE_UP, !stays)
		// Only when the caller did not say: an explicit length wins, and
		// a key set to undefined would overwrite the toaster's default
		// rather than leave it alone.
		const asked = options.timeout === undefined && CONFIRMATIONS.includes(type)
			? { timeout: CONFIRMATION_TIMEOUT }
			: {}
		current = toasts[type](text, {
			...options,
			...asked,
			onRemove: () => {
				done(id)
				options.onRemove?.()
			},
		})
		if (current === null) {
			// No toaster: the message went to the console, and nothing
			// is ever going to call onRemove.
			done(id)
		} else if (!stays) {
			// The chrome comes back even if that call never arrives --
			// an older toaster, a helper that drops the option. A button
			// that cannot be reached is worse than a word in front of it.
			const timeout = options.timeout ?? asked.timeout ?? DEFAULT_TIMEOUT
			expected = setTimeout(() => done(id), timeout + 1000)
		}
		return current
	}

	const stop = () => {
		clearTimeout(expected)
		current?.hideToast?.()
		current = null
		element.classList.remove(MESSAGE_UP)
	}

	return { flash, stop }
}

/**
 * Say that something went wrong.
 *
 * Assertively, which is what the in-page line this replaced had with its
 * role="alert": the dialogs helpers give an assertive toast role="alert"
 * and announce it aria-live="assertive". That is already their default
 * for errors; saying it keeps it a decision of ours rather than a
 * default we happen to be sitting on.
 *
 * @param {string} message what went wrong
 * @param {object} [options] the rest of the toast's options
 * @return {object|null} the toast raised, where there was a toaster
 */
export function showError(message, options = {}) {
	return say('error', message, 'assertive', console.error, options)
}

/**
 * Say that something did not go as asked, without anything having
 * broken: a game launched without its BIOS, a save state with nothing
 * in it.
 *
 * Politely, like the news below it: it is worth reading, but it is not
 * worth cutting into whatever a screen reader is in the middle of.
 *
 * @param {string} message what to look out for
 * @param {object} [options] the rest of the toast's options
 * @return {object|null} the toast raised, where there was a toaster
 */
export function showWarning(message, options = {}) {
	return say('warning', message, 'polite', console.warn, options)
}

/**
 * Say a word about something under way.
 *
 * Politely -- role="status", aria-live="polite": it is news, not a
 * failure, and it has no business cutting into whatever a screen reader
 * is in the middle of saying.
 *
 * @param {string} message what is happening
 * @param {object} [options] the rest of the toast's options
 * @return {object|null} the toast raised, where there was a toaster
 */
export function showInfo(message, options = {}) {
	return say('info', message, 'polite', console.info, options)
}

/**
 * Say that something asked for is done: a state saved, a screenshot
 * kept. Politely, for the same reason as the rest of the good news.
 *
 * @param {string} message what came off
 * @param {object} [options] the rest of the toast's options
 * @return {object|null} the toast raised, where there was a toaster
 */
export function showSuccess(message, options = {}) {
	return say('success', message, 'polite', console.info, options)
}
