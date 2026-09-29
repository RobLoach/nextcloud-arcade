/**
 * Wait for something, but not past a deadline.
 *
 * Leaving a game waits for the save that goes with it, and a core that
 * has wedged would otherwise hold the player in a game they asked to
 * leave -- for a minute, with nothing to press. So the wait is bounded,
 * and the answer says which of the two it was.
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
