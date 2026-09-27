import { unzipSync } from 'fflate'
import { Nostalgist } from 'nostalgist'
import { getCurrentUser, getRequestToken } from '@nextcloud/auth'
import { defaultRemoteURL, defaultRootPath } from '@nextcloud/files/dav'
import { translate as t } from '@nextcloud/l10n'
import { generateFilePath, generateUrl } from '@nextcloud/router'
import { inputConfig, retroarchKey } from './keys.js'
import { biosForSystem, coreForSystem, systemForFile, systemFromBytes } from './systems.js'

const SRAM_SYNC_INTERVAL = 60 * 1000

/**
 * Thrown when an archive holds no game: the viewer offers the file for
 * download instead of showing an emulator error over a family photo zip.
 */
export class NotAGameError extends Error {
	constructor(message) {
		super(message)
		this.name = 'NotAGameError'
	}
}

// The games whose battery save was just deleted. The emulator still holds
// the old save in memory, and the next sync would write it right back, so
// uploads stop until the game is opened anew — which starts clean, since
// there is nothing left to fetch.
const sramSyncStopped = new Set()

/**
 * Stop uploading the battery save of a game for the rest of the session,
 * after its server copy was deleted. Opening the game again resumes it.
 *
 * @param {string} romPath path identifying the game
 */
export function disableSramSync(romPath) {
	sramSyncStopped.add(romPath)
}

/**
 * @param {string} path path of the file, relative to the user folder or,
 *                      on public share pages, the share root
 * @return {string} the WebDAV URL of the file
 */
export function davUrl(path) {
	// defaultRemoteURL and defaultRootPath resolve to the public share
	// endpoint and share token on public pages, and to the regular files
	// endpoint and user id otherwise.
	const encoded = path.replace(/^\/+/, '').split('/').map(encodeURIComponent).join('/')
	return `${defaultRemoteURL}${defaultRootPath}/${encoded}`
}

/**
 * Look up the Nextcloud file id of a file over WebDAV.
 *
 * @param {string} path path of the file, relative to the user folder
 * @return {Promise<string>} the file id
 */
export async function fileIdOf(path) {
	const response = await fetch(davUrl(path), {
		method: 'PROPFIND',
		headers: {
			'Content-Type': 'application/xml; charset=utf-8',
			Depth: '0',
			requesttoken: getRequestToken() ?? '',
		},
		credentials: 'same-origin',
		body: '<?xml version="1.0"?>'
			+ '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
			+ '<d:prop><oc:fileid/></d:prop>'
			+ '</d:propfind>',
	})
	if (!response.ok) {
		throw new Error(`${response.status} ${response.statusText}`)
	}
	const multistatus = new DOMParser().parseFromString(await response.text(), 'application/xml')
	const fileId = multistatus.getElementsByTagNameNS('http://owncloud.org/ns', 'fileid')[0]?.textContent ?? ''
	if (fileId === '') {
		throw new Error('No file id in the PROPFIND response')
	}
	return fileId
}

/**
 * Turn the fetched file into a playable ROM, extracting zip archives and
 * detecting the system from the (inner) file name.
 *
 * @param {Blob} blob the fetched file
 * @param {string} romName the file name
 * @param {?object} systemHint system detected from the folder the game is in
 * @return {Promise<{rom: File, system: object}>} the ROM and its system
 */
async function resolveRom(blob, romName, systemHint) {
	if (!romName.toLowerCase().endsWith('.zip')) {
		const bytes = new Uint8Array(await blob.arrayBuffer())
		// The name first. Failing that the cartridge itself, whose mark is
		// worth more than the folder it happens to sit in, and the folder
		// last, for a dump that carries no mark at all.
		const system = systemForFile(romName)
			?? systemFromBytes(bytes)
			?? systemHint
		return { rom: new File([bytes], romName), system }
	}
	// Listing the entries takes the whole archive: unzipSync reads from a
	// buffer, and the file was fetched in full above anyway, since playing
	// it needs all of it. So deciding that a zip is *not* a game also costs
	// a full download -- accepted rather than engineering ranged reads of
	// the central directory for a case that ends in a download link anyway.
	const entries = Object.entries(unzipSync(new Uint8Array(await blob.arrayBuffer())))
		.filter(([name]) => !name.endsWith('/'))
	for (const [name, data] of entries) {
		const system = systemForFile(name)
		if (system !== null) {
			return { rom: new File([data], name.split('/').pop()), system }
		}
	}
	// Nothing inside says what it is by name, so the largest entry is the
	// game: the folder names its system, or the bytes do.
	if (entries.length > 0) {
		const [name, data] = entries.reduce((a, b) => (a[1].length >= b[1].length ? a : b))
		const system = systemFromBytes(data) ?? systemHint
		if (system !== null) {
			return { rom: new File([data], name.split('/').pop()), system }
		}
	}
	throw new NotAGameError(t('arcade', 'No supported ROM found in the archive'))
}

/**
 * Fetch a ROM and launch it with Nostalgist.js.
 *
 * @param {object} options launch options
 * @param {HTMLCanvasElement} options.element the canvas to render into
 * @param {string} options.romUrl URL to fetch the ROM from
 * @param {string} options.romName file name of the ROM
 * @param {object} options.settings the user settings
 * @param {?object} [options.systemHint] system detected from the game's folder
 * @param {string} [options.romPath] path identifying the game, enables SRAM restore
 * @return {Promise<Nostalgist>} the running emulator
 */
export async function launchRom({ element, romUrl, romName, settings = {}, systemHint = null, romPath = '' }) {
	const canSave = (settings.saves_folder ?? '') !== ''
	// The core is a few megabytes of its own. Warming it in the browser
	// cache now means it is there when Nostalgist asks, instead of being
	// fetched after the ROM.
	const core = coreForSystem(systemForFile(romName)?.id ?? systemHint?.id ?? '')
	if (core !== null) {
		prefetchCore(core)
	}

	// Fetch the ROM here so the request carries the Nextcloud session.
	const response = await fetch(romUrl, { credentials: 'same-origin' })
	if (!response.ok) {
		throw new Error(`Could not fetch the ROM: ${response.status} ${response.statusText}`)
	}
	const { rom, system } = await resolveRom(await response.blob(), romName, systemHint)
	if (system === null) {
		throw new Error(t('arcade', 'Unsupported ROM type: {file}', { file: romName }))
	}
	const sram = canSave ? await fetchSram(romPath) : null
	const bios = await fetchBios(system.id)
	const runahead = Number(settings.runahead_frames ?? 0)

	return await Nostalgist.launch({
		element,
		core: coreForSystem(system.id),
		rom,
		// Only what was actually found: a core asked for a file it has not
		// been given would stop rather than run without it.
		...(bios.length === 0 ? {} : { bios }),
		// Only set when there is one: an undefined value is still a present
		// key, which Nostalgist would try to resolve as a file.
		...(sram === null ? {} : { sram }),
		respondToGlobalEvents: settings.respond_to_global_events !== false,
		retroarchConfig: {
			...inputConfig(settings.buttons),
			...rewindConfig(settings),
			// Run-ahead runs the core past the shown frame to hide input
			// lag, at the cost of running it more than once per frame.
			...(runahead > 0 ? { run_ahead_enabled: true, run_ahead_frames: runahead } : {}),
			video_smooth: settings.video_smooth === true,
			video_scale_integer: settings.scale_integer === true,
			fastforward_ratio: Number(settings.fastforward_ratio ?? 3),
			audio_volume: Number(settings.audio_volume ?? 0),
			audio_latency: Number(settings.audio_latency ?? 64),
		},
		retroarchCoreConfig: settings.core_options?.[coreForSystem(system.id)] ?? {},
		resolveCoreJs(coreName) {
			return coreUrl(`${coreName}_libretro.js`)
		},
		resolveCoreWasm(coreName) {
			return coreUrl(`${coreName}_libretro.wasm`)
		},
	})
}

/**
 * The RetroArch settings that turn rewind on and bind its key.
 *
 * Rewind works while its key is held, which only RetroArch itself can
 * watch, so the binding goes into the config instead of the toolbar's
 * hotkey handling. A key that works a button of the controller is left
 * to the game, as everywhere else, and RetroArch's own stock binding is
 * cleared so it cannot rewind on a key nobody chose.
 *
 * @param {object} settings the user settings
 * @return {object} the settings RetroArch takes
 */
function rewindConfig(settings) {
	if (settings.rewind_enabled !== true) {
		return { rewind_enable: false }
	}
	const code = settings.hotkeys?.rewind
	const key = typeof code === 'string' ? retroarchKey(code) : null
	const shadowed = Object.values(settings.buttons ?? {}).includes(code)
	return {
		rewind_enable: true,
		input_rewind: key !== null && !shadowed ? key : 'nul',
	}
}

/**
 * Fetch the BIOS files of a system.
 *
 * The server looks in the user's own system folder first, whatever the
 * casing of the file there, and falls back to what the instance holds,
 * always answering under the spelling the core asks for. A core names
 * the files it wants, and most games run without them, so whatever is
 * missing is quietly left out.
 *
 * @param {string} systemId the system being played
 * @return {Promise<File[]>} the files that were there
 */
async function fetchBios(systemId) {
	const names = biosForSystem(systemId)
	if (names.length === 0 || getCurrentUser() === null) {
		return []
	}
	const files = await Promise.all(names.map(async (name) => {
		try {
			const url = generateUrl('/apps/arcade/arcade/bios?name={name}', { name })
			const response = await fetch(url, { credentials: 'same-origin' })
			if (response.ok) {
				return new File([await response.blob()], name)
			}
		} catch (error) {
			console.error(`Could not read the BIOS file ${name}`, error)
		}
		return null
	}))
	return files.filter((file) => file !== null)
}

/**
 * Ask for the files of a core without waiting for them.
 *
 * @param {string} core name of the libretro core
 */
function prefetchCore(core) {
	for (const file of [`${core}_libretro.js`, `${core}_libretro.wasm`]) {
		fetch(coreUrl(file), { credentials: 'same-origin', priority: 'high' })
			.catch(() => {
				// Only a warm cache was at stake.
			})
	}
}

/**
 * The core files are loaded from blob: URLs, where relative paths have no
 * meaningful base, so they are resolved to absolute URLs here.
 *
 * @param {string} file name of the core file
 * @return {string} the absolute URL of the core file
 */
function coreUrl(file) {
	return new URL(
		generateFilePath('arcade', 'img', `cores/${file}`),
		window.location.origin,
	).href
}

/**
 * Remember a game as played, for the library's recently played row.
 *
 * @param {string} romPath path of the game
 */
export function recordRecent(romPath) {
	if (!romPath || getCurrentUser() === null) {
		return () => {}
	}
	report(romPath, 0)

	// And how long it was played for, once it is over.
	const started = Date.now()
	let reported = false
	const reportPlayTime = () => {
		if (reported) {
			return
		}
		reported = true
		report(romPath, Math.round((Date.now() - started) / 1000))
	}
	window.addEventListener('pagehide', reportPlayTime)
	return () => {
		window.removeEventListener('pagehide', reportPlayTime)
		reportPlayTime()
	}
}

/**
 * @param {string} romPath path of the game
 * @param {number} seconds how long it was played, 0 when starting
 */
function report(romPath, seconds) {
	fetch(generateUrl('/apps/arcade/arcade/recent?file={file}&seconds={seconds}', {
		file: romPath,
		seconds,
	}), {
		method: 'POST',
		headers: { requesttoken: getRequestToken() ?? '' },
		keepalive: true,
	}).catch((error) => {
		console.error('Could not record the game as played', error)
	})
}

/**
 * @param {string} romPath path identifying the game
 * @return {string} the SRAM endpoint URL
 */
function sramUrl(romPath) {
	return generateUrl('/apps/arcade/arcade/sram?file={file}', { file: romPath })
}

/**
 * Fetch the stored in-game battery save, if any.
 *
 * @param {string} romPath path identifying the game
 * @return {Promise<?Blob>} the SRAM, or null
 */
async function fetchSram(romPath) {
	if (!romPath || getCurrentUser() === null) {
		return null
	}
	try {
		const response = await fetch(sramUrl(romPath), {
			headers: { requesttoken: getRequestToken() ?? '' },
		})
		if (!response.ok) {
			return null
		}
		const blob = await response.blob()
		return blob.size > 0 ? blob : null
	} catch (error) {
		console.error('Could not fetch the SRAM', error)
		return null
	}
}

/**
 * Periodically upload the in-game battery save, and once more when the
 * page is hidden, so in-game saves survive closing the tab.
 *
 * @param {Nostalgist} instance the running emulator
 * @param {string} romPath path identifying the game
 * @return {Function} stops the synchronization
 */
export function startSramSync(instance, romPath, canSave = true) {
	if (!romPath || !canSave || getCurrentUser() === null) {
		return () => {}
	}
	// A fresh launch starts from what the server holds, so it syncs again
	// even when the battery save was deleted in an earlier session.
	sramSyncStopped.delete(romPath)
	const upload = async () => {
		if (sramSyncStopped.has(romPath)) {
			return
		}
		try {
			const sram = await instance.saveSRAM()
			if (sram === undefined || sram.size === 0) {
				return
			}
			await fetch(sramUrl(romPath), {
				method: 'POST',
				headers: {
					'Content-Type': 'application/octet-stream',
					requesttoken: getRequestToken() ?? '',
				},
				body: sram,
				// So the final upload survives the page closing.
				keepalive: true,
			})
		} catch (error) {
			console.error('Could not save the SRAM', error)
		}
	}
	const timer = setInterval(upload, SRAM_SYNC_INTERVAL)
	const onPageHide = () => {
		upload()
	}
	window.addEventListener('pagehide', onPageHide)
	return () => {
		clearInterval(timer)
		window.removeEventListener('pagehide', onPageHide)
		upload()
	}
}
