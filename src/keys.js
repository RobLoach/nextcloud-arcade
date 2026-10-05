/**
 * Keys, as the browser reports them and as RetroArch knows them.
 *
 * A browser gives a key a code such as "KeyX" or "ArrowUp", which says
 * where the key sits rather than what is printed on it, so a binding keeps
 * working whatever the layout. RetroArch has its own names, and the two
 * have to be introduced to each other before a game starts.
 */

const NAMED = {
	ArrowUp: ['up', '↑'],
	ArrowDown: ['down', '↓'],
	ArrowLeft: ['left', '←'],
	ArrowRight: ['right', '→'],
	Enter: ['enter', 'Enter'],
	NumpadEnter: ['kp_enter', 'Numpad Enter'],
	Space: ['space', 'Space'],
	Escape: ['escape', 'Esc'],
	Tab: ['tab', 'Tab'],
	Backspace: ['backspace', 'Backspace'],
	Delete: ['del', 'Delete'],
	Insert: ['insert', 'Insert'],
	Home: ['home', 'Home'],
	End: ['end', 'End'],
	PageUp: ['pageup', 'Page up'],
	PageDown: ['pagedown', 'Page down'],
	ShiftLeft: ['shift', 'Left shift'],
	ShiftRight: ['rshift', 'Right shift'],
	ControlLeft: ['ctrl', 'Left control'],
	ControlRight: ['rctrl', 'Right control'],
	AltLeft: ['alt', 'Left alt'],
	AltRight: ['ralt', 'Right alt'],
	Minus: ['minus', '-'],
	Equal: ['equals', '='],
	Comma: ['comma', ','],
	Period: ['period', '.'],
	Slash: ['slash', '/'],
	Semicolon: ['semicolon', ';'],
	Quote: ['quote', "'"],
	Backquote: ['backquote', '`'],
	Backslash: ['backslash', '\\'],
	BracketLeft: ['leftbracket', '['],
	BracketRight: ['rightbracket', ']'],
}

/**
 * @param {string} code the code a browser reports for a key
 * @return {?string} the name RetroArch knows it by, if it knows it
 */
export function retroarchKey(code) {
	if (NAMED[code] !== undefined) {
		return NAMED[code][0]
	}
	if (/^Key[A-Z]$/.test(code)) {
		return code.slice(3).toLowerCase()
	}
	if (/^Digit[0-9]$/.test(code)) {
		return code.slice(5)
	}
	if (/^Numpad[0-9]$/.test(code)) {
		return `num${code.slice(6)}`
	}
	if (/^F([1-9]|1[0-2])$/.test(code)) {
		return code.toLowerCase()
	}
	return null
}

/**
 * A binding deliberately left on no key at all. No browser reports a key
 * by this name, so nothing ever matches it: the hotkey simply never
 * fires, and retroarchKey() has no name for it, so no line about it goes
 * into the emulator's config either. A word rather than an empty string
 * because the server keeps a binding only if it reads as a name.
 */
export const UNBOUND = 'none'

/**
 * @param {string} code the code a browser reports for a key
 * @return {string} that key, as it would be spoken of
 */
export function keyLabel(code) {
	if (!code || code === UNBOUND) {
		return '—'
	}
	if (NAMED[code] !== undefined) {
		return NAMED[code][1]
	}
	if (/^Key[A-Z]$/.test(code)) {
		return code.slice(3)
	}
	if (/^Digit[0-9]$/.test(code)) {
		return code.slice(5)
	}
	if (/^Numpad[0-9]$/.test(code)) {
		return `Numpad ${code.slice(6)}`
	}
	return code
}

/**
 * The RetroArch settings that bind the keyboard to the first controller.
 *
 * @param {object} buttons button name => key code
 * @return {object} the settings RetroArch takes
 */
export function inputConfig(buttons) {
	const config = {}
	for (const [button, code] of Object.entries(buttons ?? {})) {
		const key = retroarchKey(code)
		if (key !== null) {
			config[`input_player1_${button}`] = key
		}
	}
	return config
}
