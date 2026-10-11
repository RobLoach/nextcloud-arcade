// The calls that reach into the core's own file system: each writes a
// file and then waits for it to appear, so two of them at once are two
// readers and writers of the same place. The rest -- pausing, resuming,
// a command sent to RetroArch -- return at once and are left alone.
const QUEUED = ['saveState', 'loadState', 'screenshot', 'saveSRAM']

/**
 * One emulator, answering one of these at a time.
 *
 * The player asks the core for things from three places that cannot see
 * each other: the save states panel, the screenshot button, and the
 * battery save on its timer. Each had a gate of its own, so each was
 * orderly by itself and none of them knew about the others -- a state
 * being written while the battery save was being read is two operations
 * in the one core, and the core has one file system to do it in.
 *
 * So the gate goes where the thing being protected is. Everything else
 * is handed through untouched, and a caller cannot tell the difference
 * beyond the waiting.
 *
 * The queue does not break on a failure: a call that throws is still a
 * call that finished, and the ones behind it have no reason to be
 * dropped along with it.
 *
 * Even the first call waits a microtask, which is a tick against a
 * megabyte written and polled for, and it keeps the shape of this one
 * line rather than a second path for the idle case.
 *
 * @param {import('nostalgist').Nostalgist} instance the running emulator
 * @return {import('nostalgist').Nostalgist} it, taking one of those at a time
 */
export function oneAtATime(instance) {
	let tail = Promise.resolve()
	// Each call is wrapped once and kept: the virtual gamepad reaches for
	// pressDown and pressUp on every touch that moves, and a fresh
	// function each time would be litter made sixty times a second.
	const wrapped = new Map()
	return new Proxy(instance, {
		get(target, property, receiver) {
			const value = Reflect.get(target, property, receiver)
			if (typeof value !== 'function') {
				return value
			}
			if (!wrapped.has(property)) {
				wrapped.set(property, QUEUED.includes(property)
					? (...args) => {
						const mine = tail.then(() => value.apply(target, args))
						tail = mine.then(() => {}, () => {})
						return mine
					}
					: value.bind(target))
			}
			return wrapped.get(property)
		},
	})
}
