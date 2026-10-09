import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'

// The slot the player writes by itself, on the clock in the settings;
// StateService knows it as AUTO_SLOT.
export const AUTO_SLOT = 0

/**
 * @param {string} route the app route, e.g. '/state'
 * @param {string} romPath path identifying the game
 * @param {number} [slot] the save state slot
 * @return {string} the endpoint URL
 */
export function stateUrl(route, romPath, slot = null) {
	return slot === null
		? generateUrl(`/apps/arcade/arcade${route}?file={file}`, { file: romPath })
		: generateUrl(`/apps/arcade/arcade${route}?file={file}&slot={slot}`, { file: romPath, slot })
}

// How long a request is given. Generous on purpose: a save state runs to
// several megabytes, a slow line is not a fault, and an upload cut off
// here is a save the player asked for and did not get. It is bounded at
// all only so a stalled socket cannot hold its caller for the several
// minutes the browser would otherwise allow.
const REQUEST_WAIT = 120 * 1000

/**
 * A signal that gives up after a while.
 *
 * Pass one to cover a run of requests with a single budget -- a save
 * uploads a state and then its picture, and two deadlines of their own
 * would let one save hold its caller for twice as long as either says.
 *
 * @param {number} [ms] how long to allow
 * @return {AbortSignal} a signal that aborts when the time is up
 */
export function deadline(ms = REQUEST_WAIT) {
	return AbortSignal.timeout(ms)
}

/**
 * Whether a request was given up on, rather than answered badly.
 *
 * Worth telling apart: one means the game or the line was too slow and
 * nothing was written, the other that the server said no.
 *
 * @param {*} error what was thrown
 * @return {boolean} whether it was abandoned
 */
export function wasGivenUpOn(error) {
	// deadline() aborts with a TimeoutError; a caller's own signal, and
	// the browser on its way off the page, abort with an AbortError.
	return error?.name === 'TimeoutError' || error?.name === 'AbortError'
}

/**
 * @param {string} url endpoint to call
 * @param {object} [options] extra fetch options
 * @return {Promise<Response>} the response, always ok
 */
export async function api(url, options = {}) {
	const response = await fetch(url, {
		...options,
		headers: {
			requesttoken: getRequestToken() ?? '',
			...(options.headers ?? {}),
		},
		// Every request gets a deadline, because a stalled socket is not
		// an error that arrives late -- it is an answer that never comes,
		// and whatever the caller was holding while it waited is held for
		// as long: a panel's one-operation-at-a-time gate, or a battery
		// save sync switched off for the length of a delete.
		signal: options.signal == null
			? deadline()
			: AbortSignal.any([options.signal, deadline()]),
	})
	if (!response.ok) {
		throw new Error(`${response.status} ${response.statusText}`)
	}
	return response
}
