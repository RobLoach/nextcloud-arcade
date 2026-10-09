import { loadState } from '@nextcloud/initial-state'

/**
 * System definitions (extensions, mimetype, cores) provided by lib/CoreMap.php.
 */
const systems = loadState('arcade', 'systems', {})

/**
 * @return {string[]} every ROM mimetype the player can handle
 */
export function romMimes() {
	return [...new Set(Object.values(systems).map((system) => system.mime))]
}

/**
 * Find the system for a file, by mimetype first and file extension second.
 *
 * @param {string} basename the file name
 * @param {string} [mime] the file mimetype, if known
 * @return {?object} the system definition, with its id, or null
 */
export function systemForFile(basename, mime = '') {
	const entries = Object.entries(systems)
	if (mime) {
		const byMime = entries.find(([, system]) => system.mime === mime)
		if (byMime) {
			return { id: byMime[0], ...byMime[1] }
		}
	}
	const extension = (basename || '').split('.').pop().toLowerCase()
	const byExtension = entries.find(([, system]) => system.extensions.includes(extension))
	return byExtension ? { id: byExtension[0], ...byExtension[1] } : null
}

/**
 * @param {string} systemId the system id
 * @return {?string} the libretro core that runs this system
 */
export function coreForSystem(systemId) {
	return systems[systemId]?.core ?? null
}

/**
 * @param {string} systemId the system id
 * @return {string[]} the BIOS files the system may ask for
 */
export function biosForSystem(systemId) {
	return systems[systemId]?.bios ?? []
}

/**
 * @param {string} systemId the system id
 * @return {string} the short display name of the system
 */
export function systemLabel(systemId) {
	// `display` where the system has one: `short` names the folder its
	// saves are filed under, which is not always what it is called.
	return systems[systemId]?.display
		?? systems[systemId]?.short
		?? systems[systemId]?.label
		?? systemId
}

/**
 * The short name of the system of a game at a path, for the folder its
 * saves and screenshots are filed under. Empty when nothing says which
 * system it is, so those stay where they always were.
 *
 * @param {string} path path of the game
 * @return {string} the short name of the system, or an empty string
 */
export function shortNameForPath(path) {
	const basename = (path || '').split('/').pop()
	const system = systemForFile(basename) ?? systemForFolderPath(path)
	return system?.short ?? ''
}

/** Extensions that may hold a ROM without saying whose. */
const AMBIGUOUS = ['bin', 'rom', 'zip']

/**
 * @param {string} basename the file name
 * @return {boolean} whether the name says nothing about the system
 */
function isAmbiguous(basename) {
	return AMBIGUOUS.includes((basename || '').split('.').pop().toLowerCase())
}

/**
 * @param {string} basename the file name
 * @param {string} [mime] the file mimetype, if known
 * @return {boolean} whether the player can (try to) run this file
 */
export function isPlayable(basename, mime = '') {
	return systemForFile(basename, mime) !== null || isAmbiguous(basename)
}

/**
 * Which machine a file is for, going by what is written in it.
 *
 * A .bin names no system, but a cartridge dump carries a mark near its
 * front saying whose it is. Mirrors RomHeader::systemOf on the server,
 * which does the same for the library listing.
 *
 * @param {Uint8Array} bytes the front of the file
 * @return {?object} the system definition, with its id, or null
 */
export function systemFromBytes(bytes) {
	const at = (offset, text) => text.split('').every((c, i) => bytes[offset + i] === c.charCodeAt(0))
	let id = null
	if (at(0, 'NES') && bytes[3] === 0x1a) {
		id = 'nes'
	} else if (at(0, 'LYNX')) {
		id = 'lynx'
	} else if ((bytes[0] === 0xAA && bytes[1] === 0x55) || (bytes[0] === 0x55 && bytes[1] === 0xAA)) {
		id = 'coleco'
	} else if (at(0x100, 'SEGA')) {
		const console_ = String.fromCharCode(...bytes.slice(0x100, 0x110))
		id = console_.includes('32X') ? 'sega32x' : 'genesis'
	} else if (bytes[0x104] === 0xCE && bytes[0x105] === 0xED && bytes[0x106] === 0x66 && bytes[0x107] === 0x66) {
		id = [0x80, 0xC0].includes(bytes[0x143]) ? 'gbc' : 'gb'
	} else if (bytes[0x04] === 0x24 && bytes[0x05] === 0xFF && bytes[0x06] === 0xAE && bytes[0x07] === 0x51) {
		id = 'gba'
	}
	return id === null ? null : systemById(id)
}

/**
 * @param {string} systemId the system id
 * @return {?object} the system definition, with its id
 */
function systemById(systemId) {
	return systems[systemId] ? { id: systemId, ...systems[systemId] } : null
}

/**
 * Detect the system from the folders a file is stored in, e.g.
 * "/Games/SNES/NHL 96.zip" is a Super Nintendo game. No-Intro platform
 * names like "Nintendo - Super Nintendo Entertainment System" match too,
 * through their dash-separated segments.
 *
 * @param {string} path path of the file
 * @return {?object} the system definition, with its id, or null
 */
export function systemForFolderPath(path) {
	const folders = (path || '').split('/').filter(Boolean).slice(0, -1)
	for (const folder of folders.reverse()) {
		for (const candidate of folderCandidates(folder)) {
			for (const [id, system] of Object.entries(systems)) {
				if (candidate === id || (system.aliases ?? []).includes(candidate)) {
					return { id, ...system }
				}
			}
		}
	}
	return null
}

/**
 * The words that say nothing about which system a folder holds, and the
 * makers whose name in front of one says no more than the system does.
 * Both come from CoreMap, so there is one list of each.
 */
const folderWords = loadState('arcade', 'folderWords', { noise: [], vendors: [] })
const NOISE = folderWords.noise ?? []
const VENDORS = folderWords.vendors ?? []

/**
 * The normalized forms of a folder name worth looking up. The same shape
 * as CoreMap::folderCandidates, working on the same words, which come from
 * there.
 *
 * @param {string} name the folder name
 * @return {string[]} what to look for, in the order worth trying
 */
function folderCandidates(name) {
	const candidates = []
	for (const part of [name, ...name.split(/\s*[-–_+]\s*/)]) {
		const words = part.toLowerCase().split(/[^a-z0-9]+/).filter(Boolean)
		if (words.length === 0) {
			continue
		}
		// Noise is only trimmed off the ends: "Game" in the middle of
		// "Nintendo Game Boy" is the system, not padding.
		const trimmed = [...words]
		while (trimmed.length > 0 && NOISE.includes(trimmed[trimmed.length - 1])) {
			trimmed.pop()
		}
		const lead = [...trimmed]
		while (lead.length > 1 && NOISE.includes(lead[0])) {
			lead.shift()
		}
		for (const form of [words, trimmed, lead]) {
			if (form.length === 0) {
				continue
			}
			candidates.push(form.join(''))
			if (form.length > 1 && VENDORS.includes(form[0])) {
				candidates.push(form.slice(1).join(''))
			}
		}
	}
	return [...new Set(candidates)].filter(Boolean)
}
