import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { encodePath } from './paths.js'

export { encodePath }

// The slot the player writes by itself, on the clock in the settings:
// RetroArch's .state.auto, which StateService knows as AUTO_SLOT. Not 0,
// which is RetroArch's own first slot.
export const AUTO_SLOT = -1

// The core each game was started on, by its path. Saves are filed under
// the name of the core, and for a zip only the player knows which that
// is -- it looked inside -- so every request about a save says so.
const cores = new Map()

/**
 * Note the core a game runs on, for the save requests about it.
 *
 * @param {string} romPath path identifying the game
 * @param {string} core the libretro name of the core, e.g. 'fceumm'
 */
export function useCore(romPath, core) {
	cores.set(romPath, core)
}

/**
 * @param {string} route the app route, e.g. '/state'
 * @param {string} romPath path identifying the game
 * @param {number} [slot] the save state slot
 * @return {string} the endpoint URL
 */
export function stateUrl(route, romPath, slot = null) {
	const core = cores.get(romPath) ?? null
	let url = `/apps/arcade/arcade${route}?f={f}`
	if (slot !== null) {
		url += '&slot={slot}'
	}
	if (core !== null) {
		url += '&core={core}'
	}
	return generateUrl(url, { f: encodePath(romPath), slot, core })
}

// How long an ordinary request is given. These carry a listing or a
// handful of fields, so a minute is already far past a working server,
// and the point of the bound is that a stalled socket should not hold
// its caller for the several minutes the browser would otherwise allow.
const REQUEST_WAIT = 60 * 1000

/**
 * How long a request carrying a save is given instead.
 *
 * One figure for both was wrong at both ends. A save state is several
 * megabytes and the server will take up to 64, so on a slow line the
 * ordinary bound would cut off an upload that was working -- and an
 * upload cut off is a save the player asked for and did not get, which
 * is worse than waiting. Meanwhile a stalled listing was held for two
 * minutes for the sake of a size it never carries.
 *
 * Five minutes is about where the browser gives up on a stalled
 * connection anyway, so for the big ones this is no longer the shorter
 * limit of the two.
 */
export const UPLOAD_WAIT = 300 * 1000

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
 * The caller's own signal, if any, under a deadline either way.
 *
 * Every request gets one, because a stalled socket is not an error that
 * arrives late -- it is an answer that never comes, and whatever the
 * caller was holding while it waited is held for as long: a panel's
 * one-operation-at-a-time gate, or a battery save sync switched off for
 * the length of a delete.
 *
 * @param {?AbortSignal} [signal] what the caller wants to abort on
 * @return {AbortSignal} that, and the deadline
 */
function bounded(signal) {
	return signal == null ? deadline() : AbortSignal.any([signal, deadline()])
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
		signal: bounded(options.signal),
	})
	if (!response.ok) {
		// The status rides along, because a refusal is not always a fault:
		// asking for a battery save a game has never written answers 404,
		// and the caller wants to tell that apart from a server that broke.
		const refused = new Error(`${response.status} ${response.statusText}`)
		refused.status = response.status
		throw refused
	}
	return response
}

/**
 * A request to the user's own files over WebDAV, rather than to one of
 * the app's own routes.
 *
 * Hands the response back as it came rather than throwing on a refusal
 * the way api() does, because every caller reads the status itself: a
 * MKCOL answering "already there", a PUT answering "no such folder yet".
 * Those are answers, not failures. What it does share with api() is the
 * session token and the deadline, which each of these used to carry its
 * own copy of -- or go without.
 *
 * @param {string} url the WebDAV URL, from davUrl()
 * @param {object} [options] extra fetch options
 * @return {Promise<Response>} the response, whatever it says
 */
export async function dav(url, options = {}) {
	return await fetch(url, {
		...options,
		headers: {
			requesttoken: getRequestToken() ?? '',
			...(options.headers ?? {}),
		},
		credentials: 'same-origin',
		signal: bounded(options.signal),
	})
}
