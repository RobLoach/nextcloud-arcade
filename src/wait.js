/**
 * Wait for something, but not past a deadline.
 *
 * What it is mostly asked to bound is the emulator core: a core that has
 * wedged never answers at all, and the one operation allowed at a time
 * would never end. The answer says which of the two happened.
 *
 * It only stops waiting. Nothing is cancelled, so this is for promises
 * whose result can be dropped -- a request is bounded with a signal
 * instead, which actually ends it.
 *
 * @param {Promise} promise what to wait for
 * @param {number} ms how long to give it, in milliseconds
 * @param {*} fallback what to answer when the time is up
 * @return {Promise<*>} what the promise gave, or the fallback
 */
export function waitAtMost(promise, ms, fallback) {
	let timer = null
	const deadline = new Promise((resolve) => {
		timer = setTimeout(() => resolve(fallback), ms)
	})
	return Promise.race([promise, deadline]).finally(() => {
		clearTimeout(timer)
	})
}
