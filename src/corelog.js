// What RetroArch and the libretro core have to say for themselves.
//
// The core writes to stdout and stderr, which Emscripten hands to the
// print hooks. A launch produces hundreds of lines of [INFO] -- every
// setting read, every driver tried -- so only the two levels a player
// could act on are shown: [ERROR] and [WARN]. The rest stays in the
// console, where it always was.
//
// These words come from RetroArch, not from this app, so they are not
// translated: there is nothing to translate them from.

// A line looks like "[ERROR] [libretro ERROR] Could not load the BIOS",
// sometimes with the level repeated by the core inside its own tag.
const LEVELS = { '[ERROR]': 'error', '[WARN]': 'warning' }

// The same complaint twice in a row is one complaint. Cores repeat
// themselves once a frame when something is wrong.
const REPEAT_WINDOW = 5000

// A core stuck in a bad state would otherwise paper the screen over.
// After this many it says no more and leaves the rest to the console.
export const MESSAGE_CAP = 3

/**
 * What a line of core output says, as a player would read it.
 *
 * @param {string} line one line of the core's output
 * @return {?{message: string, type: string}} what to show, or null when
 *   the line is not worth showing
 */
export function coreMessage(line) {
	if (typeof line !== 'string') {
		return null
	}
	const text = line.trim()
	const level = Object.keys(LEVELS).find((prefix) => text.startsWith(prefix))
	if (level === undefined) {
		return null
	}
	// Drop every bracketed tag at the front -- the level, and whatever
	// subsystem the core names itself after -- so what is left reads as
	// a sentence rather than a log line.
	const message = text.slice(level.length).replace(/^(\s*\[[^\]]*\])+/, '').trim()
	return message === '' ? null : { message, type: LEVELS[level] }
}

/**
 * A sink for the core's output that passes on only what a player could
 * act on, once each, and not for ever.
 *
 * @param {Function} report called with (message, type) for what to show
 * @return {Function} hand it each line the core prints
 */
export function createCoreLog(report) {
	const said = new Map()
	let shown = 0
	return (line) => {
		const found = coreMessage(line)
		if (found === null) {
			return
		}
		const now = Date.now()
		if (now - (said.get(found.message) ?? -Infinity) < REPEAT_WINDOW) {
			return
		}
		said.set(found.message, now)
		if (shown >= MESSAGE_CAP) {
			return
		}
		shown++
		if (shown === MESSAGE_CAP) {
			console.info('arcade: further messages from the core stay in the console')
		}
		report(found.message, found.type)
	}
}
