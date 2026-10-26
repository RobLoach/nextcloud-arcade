import { unzipSync } from 'fflate'
import { Nostalgist } from 'nostalgist'
import { getCurrentUser } from '@nextcloud/auth'
import { defaultRemoteURL, defaultRootPath } from '@nextcloud/files/dav'
import { translate as t } from '@nextcloud/l10n'
import { generateFilePath, generateUrl } from '@nextcloud/router'
import { api, dav, deadline, stateUrl, UPLOAD_WAIT, useCore } from './api.js'
import { createCoreLog } from './corelog.js'
import { createProgressTracker, lengthOf, readWithProgress } from './progress.js'
import { inputConfig, retroarchKey } from './keys.js'
import { volumeInDecibels } from './volume.js'
import { biosForSystem, coreForSystem, systemForFile, systemFromBytes, systemLabel } from './systems.js'
import { waitAtMost } from './wait.js'

const SRAM_SYNC_INTERVAL = 60 * 1000
// How long the last upload of a closing game is given. Nostalgist polls
// the core's file system for the written save, so even a healthy one
// takes a moment; a wedged one must not keep the player here.
const SRAM_FINAL_WAIT = 5000

// How long the battery save is given to appear once RetroArch is told to
// write it, and how often to look. A game that has one writes it within a
// few frames. Nostalgist's own saveSRAM() waits a minute for the file,
// and a game without a battery save never writes one: every sync then
// held the core for that minute, and a save state asked for meanwhile
// gave up waiting behind it.
const SRAM_APPEAR_WAIT = 2000
const SRAM_POLL = 100

// How many looks in a row that find no battery save it takes to decide the
// game has none. More than one, in case a look came while the core could
// not answer -- a game with a battery save writes one every time.
const SRAM_MISSES_TO_STOP = 2

// The most a request may carry and still be allowed to outlive its page.
// The Fetch standard caps a keepalive body at 64 KiB and fails anything
// larger outright, so asking for it unconditionally was not a nicety that
// degraded: a Game Boy Advance flash save is 128 KiB, and every upload of
// one -- on the timer as much as at the end -- failed before it was sent,
// and said so only to the console. Below the cap with room for the
// headers, since the real sizes are powers of two and none of them lands
// between the two figures.
const KEEPALIVE_LIMIT = 60 * 1024

// The games whose battery save was just deleted. The emulator still holds
// the old save in memory, and the next sync would write it right back, so
// uploads stop until the game is opened anew — which starts clean, since
// there is nothing left to fetch.
const sramSyncStopped = new Set()

// What the server holds of each game's battery save, as far as this page
// knows: what was fetched at launch, then what was last sent. The save is
// read out of the core once a minute whether the game wrote to it or not,
// and one that has not changed is not sent again.
const sramOnServer = new Map()

/**
 * @param {Uint8Array} a some bytes
 * @param {?Uint8Array} b some others, if any
 * @return {boolean} whether they are the same
 */
function sameBytes(a, b) {
	if (b === null || b === undefined || a.length !== b.length) {
		return false
	}
	for (let i = 0; i < a.length; i++) {
		if (a[i] !== b[i]) {
			return false
		}
	}
	return true
}

/**
 * Stop uploading the battery save of a game for the rest of the session,
 * after its server copy was deleted. Opening the game again resumes it.
 *
 * @param {string} romPath path identifying the game
 */
export function disableSramSync(romPath) {
	sramSyncStopped.add(romPath)
	// Whatever the server held is going, so nothing is the same as it.
	sramOnServer.delete(romPath)
}

/**
 * Take that back, for a delete that did not happen after all: the server
 * still holds the save, so the game should go on keeping it up to date.
 *
 * @param {string} romPath path identifying the game
 */
export function enableSramSync(romPath) {
	sramSyncStopped.delete(romPath)
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
 * Make a folder in the user's own files, and every folder above it that
 * is missing too.
 *
 * MKCOL makes one level at a time: asked for a folder whose parent is
 * not there either, the server answers 409 and nothing is made. That is
 * the ordinary case the first time a game is played -- a screenshot goes
 * to a folder named after its system, inside a screenshots folder the
 * player has never created -- and it left the screenshot nowhere to go.
 *
 * 405 is the folder already being there, which is the outcome asked for
 * either way.
 *
 * @param {string} path the folder to make, relative to the user's files
 */
export async function makeFolder(path) {
	let walked = ''
	for (const part of path.split('/').filter(Boolean)) {
		walked += `/${part}`
		const response = await dav(davUrl(walked), { method: 'MKCOL' })
		if (!response.ok && response.status !== 405) {
			throw new Error(`${response.status} ${response.statusText}`)
		}
	}
}

/**
 * Look up the Nextcloud file id of a file over WebDAV.
 *
 * @param {string} path path of the file, relative to the user folder
 * @return {Promise<string>} the file id
 */
export async function fileIdOf(path) {
	const response = await api(davUrl(path), {
		method: 'PROPFIND',
		headers: {
			'Content-Type': 'application/xml; charset=utf-8',
			Depth: '0',
		},
		credentials: 'same-origin',
		body: '<?xml version="1.0"?>'
			+ '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
			+ '<d:prop><oc:fileid/></d:prop>'
			+ '</d:propfind>',
	})
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
	throw new Error(t('arcade', 'No supported ROM found in the archive'))
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
 * @param {?Function} [options.onMessage] told, in words for the player and
 *   with how they read, about anything the launch went without and
 *   anything the core itself has to say
 * @param {?AbortSignal} [options.signal] abandons the launch when it fires
 * @param {?Function} [options.onProgress] told how the launch is getting
 *   on: {phase: 'download', loaded, total} as the game and its core come
 *   in, with total null while a length is unknown, then {phase: 'start'}
 *   once all that is left is starting the core
 * @return {Promise<Nostalgist>} the running emulator
 */
export async function launchRom({ element, romUrl, romName, settings = {}, systemHint = null, romPath = '', onMessage = null, signal = null, onProgress = null }) {
	const canSave = (settings.saves_folder ?? '') !== ''
	const tell = typeof onProgress === 'function' ? onProgress : () => {}
	// The game and the core come in side by side, and the bar follows
	// them both: the core alone is a few megabytes, as big as most games.
	const progress = createProgressTracker((snapshot) => tell({ phase: 'download', ...snapshot }))
	// Warming the core in the browser cache now means it is there when
	// Nostalgist asks, instead of being fetched after the ROM.
	const core = coreForSystem(systemForFile(romName)?.id ?? systemHint?.id ?? '')
	const coreFetched = core === null ? Promise.resolve() : prefetchCore(core, signal, progress)

	// Fetch the ROM here so the request carries the Nextcloud session.
	// Every fetch of the launch takes the signal: a game closed while it
	// is still loading should stop downloading, not finish in the dark.
	//
	// Not through api(): this is the one request with no business having a
	// deadline. A disc image is hundreds of megabytes, and a slow line is
	// not a fault -- giving up on it two minutes in would end the game
	// that was loading. Closing the game is what stops it.
	const response = await fetch(romUrl, { credentials: 'same-origin', signal })
	if (!response.ok) {
		throw new Error(`Could not fetch the ROM: ${response.status} ${response.statusText}`)
	}
	progress.track('rom', lengthOf(response))
	const blob = await readWithProgress(response, (bytes) => progress.advance('rom', bytes))
	const { rom, system } = await resolveRom(blob, romName, systemHint)
	if (system === null) {
		throw new Error(t('arcade', 'Unsupported ROM type: {file}', { file: romName }))
	}
	if (romPath !== '') {
		// From here on every request about its saves says which core it
		// is: the one its files are filed under.
		useCore(romPath, coreForSystem(system.id))
	}
	const sram = canSave ? await fetchSram(romPath, signal) : null
	const bios = await fetchBios(system.id, signal)
	if (bios.length === 0 && biosForSystem(system.id).length > 0 && typeof onMessage === 'function') {
		// The game starts anyway, just poorer for it -- most cores run
		// without their BIOS, only worse (the PS1 much worse). Worth a
		// word, and this is the only moment that knows it.
		onMessage(t('arcade', 'No BIOS files found for {system}. Games may run worse without them.', {
			system: systemLabel(system.id),
		}), 'warning')
	}
	// The core has to be in before it can start, and on a slow line it
	// takes longer than the game: the bar keeps counting until it is,
	// rather than saying the emulator is starting while it is not.
	await coreFetched
	if (signal?.aborted === true) {
		// Closed while the files were still coming in: no core is started
		// for a game nobody is waiting for any more.
		throw new DOMException('The launch was abandoned', 'AbortError')
	}
	tell({ phase: 'start' })
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
			// RetroArch prints nothing at all unless asked to, and what it
			// prints is how the player hears of a save state the core
			// turned down, or anything else gone wrong (see corelog.js).
			// Errors only: its warnings at every launch are about the
			// graphics driver of the browser, nothing a player can act on.
			log_verbosity: true,
			frontend_log_level: 3,
			libretro_log_level: 3,
			...inputConfig(settings.buttons),
			...rewindConfig(settings),
			// Run-ahead runs the core past the shown frame to hide input
			// lag, at the cost of running it more than once per frame.
			...(runahead > 0 ? { run_ahead_enabled: true, run_ahead_frames: runahead } : {}),
			video_smooth: settings.video_smooth === true,
			video_scale_integer: settings.scale_integer === true,
			fastforward_ratio: Number(settings.fastforward_ratio ?? 3),
			audio_volume: volumeInDecibels(settings.volume),
			audio_latency: Number(settings.audio_latency ?? 64),
		},
		retroarchCoreConfig: settings.core_options?.[coreForSystem(system.id)] ?? {},
		resolveCoreJs(coreName) {
			return coreUrl(`${coreName}_libretro.js`)
		},
		resolveCoreWasm(coreName) {
			return coreUrl(`${coreName}_libretro.wasm`)
		},
		emscriptenModule: coreOutput(onMessage),
	})
}

/**
 * Listen in on what the core prints.
 *
 * RetroArch writes its log through Emscripten's print hooks, and
 * Nostalgist's own send it to the console. That stays: everything
 * printed still lands there exactly as before, and only the lines a
 * player could act on are passed on to be said out loud as well.
 *
 * @param {?Function} onMessage told what is worth saying, or null to
 *                               leave the output to the console alone
 * @return {object} the Emscripten module options for the launch
 */
function coreOutput(onMessage) {
	if (typeof onMessage !== 'function') {
		return {}
	}
	const log = createCoreLog(onMessage)
	// A hook that threw would take the core's logging down with it, so
	// whatever happens in here stops in here.
	const watch = (write) => (...args) => {
		write(...args)
		try {
			// Emscripten calls these with the one line it has to print;
			// the join is for the shape of the signature, not for what
			// actually arrives.
			log(args.length === 1 ? args[0] : args.join(' '))
		} catch (error) {
			console.error('Arcade could not read the core output', error)
		}
	}
	return {
		print: watch(console.info.bind(console)),
		printErr: watch(console.error.bind(console)),
	}
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
 * @param {?AbortSignal} [signal] abandons the requests when it fires
 * @return {Promise<File[]>} the files that were there
 */
async function fetchBios(systemId, signal = null) {
	const names = biosForSystem(systemId)
	if (names.length === 0 || getCurrentUser() === null) {
		return []
	}
	const files = await Promise.all(names.map(async (name) => {
		try {
			const url = generateUrl('/apps/arcade/arcade/bios?name={name}', { name })
			// Not through api(): a BIOS that is not there answers 404, and
			// that is the ordinary answer for most games rather than a
			// failure to report. api() would raise it, and the catch below
			// would write a console error on every launch without one.
			const response = await fetch(url, { credentials: 'same-origin', signal })
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
 * They are read through to the end, which is what puts them in the
 * cache anyway, so the progress bar can count them on the way.
 *
 * @param {string} core name of the libretro core
 * @param {?AbortSignal} [signal] abandons the requests when it fires
 * @param {?object} [progress] the tracker to count the bytes into
 * @return {Promise<void>} answers once both are in, or have failed
 */
function prefetchCore(core, signal = null, progress = null) {
	return Promise.all([`${core}_libretro.js`, `${core}_libretro.wasm`].map((file) =>
		// Not through api(): a static file under the app's own img/, not a
		// route, and nothing depends on it arriving -- only a warm cache is
		// at stake, so there is no caller for a deadline to release.
		fetch(coreUrl(file), { credentials: 'same-origin', priority: 'high', signal })
			.then(async (response) => {
				if (!response.ok || progress === null) {
					return
				}
				progress.track(file, lengthOf(response))
				await readWithProgress(response, (bytes) => progress.advance(file, bytes))
			})
			.catch(() => {
				// Only a warm cache was at stake.
			})))
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
	api(generateUrl('/apps/arcade/arcade/recent?file={file}&seconds={seconds}', {
		file: romPath,
		seconds,
	}), {
		method: 'POST',
		// It carries no body, so it is nowhere near the size a request is
		// allowed to outlive its page at.
		keepalive: true,
	}).catch((error) => {
		console.error('Could not record the game as played', error)
	})
}

/**
 * Fetch the stored in-game battery save, if any.
 *
 * @param {string} romPath path identifying the game
 * @param {?AbortSignal} [signal] abandons the request when it fires
 * @return {Promise<?Blob>} the SRAM, or null
 */
async function fetchSram(romPath, signal = null) {
	if (!romPath || getCurrentUser() === null) {
		return null
	}
	try {
		// A game with no battery save yet answers 404, which api() throws
		// on and the catch below reads as the same "nothing stored".
		const response = await api(stateUrl('/sram', romPath), { signal })
		const blob = await response.blob()
		if (blob.size === 0) {
			return null
		}
		sramOnServer.set(romPath, new Uint8Array(await blob.arrayBuffer()))
		return blob
	} catch (error) {
		// A game that has never written one answers 404, which is an
		// answer rather than a fault: it starts with a fresh cartridge.
		if (error?.status !== 404) {
			console.error('Could not fetch the SRAM', error)
		}
		return null
	}
}

/**
 * Read the battery save out of the core, or null when it writes none --
 * which a game without one never does.
 *
 * Done here rather than through Nostalgist's saveSRAM(), which waits a
 * minute for a file that may never come. It reaches into Nostalgist's
 * emulator for the path it reads from, which the pinned version keeps;
 * should that ever give way, saveSRAM() is still there to fall back on.
 *
 * @param {import('nostalgist').Nostalgist} instance the running emulator,
 *                                                   from the core queue
 * @return {Promise<?Blob>} the battery save, or null
 */
async function readSram(instance) {
	let FS
	let path
	let module
	try {
		FS = instance.getEmscriptenFS()
		path = instance.getEmulator().sramFilePath
		module = instance.getEmscriptenModule()
	} catch {
		return await instance.saveSRAM()
	}
	try {
		FS.unlink(path)
	} catch {
		// Not written yet, which is what is wanted.
	}
	module._cmd_savefiles?.()
	let lastSize = -1
	for (let waited = 0; waited < SRAM_APPEAR_WAIT; waited += SRAM_POLL) {
		await new Promise((resolve) => setTimeout(resolve, SRAM_POLL))
		let size = -1
		try {
			size = FS.stat(path).size
		} catch {
			// Not there yet.
		}
		// Written in full once two looks in a row find it the same size.
		if (size > 0 && size === lastSize) {
			return new Blob([FS.readFile(path)], { type: 'application/octet-stream' })
		}
		lastSize = size
	}
	return null
}

/**
 * Periodically upload the in-game battery save, and once more when the
 * page is hidden, so in-game saves survive closing the tab.
 *
 * @param {Nostalgist} instance the running emulator
 * @param {string} romPath path identifying the game
 * @return {Function} stops the synchronization, answering once the last
 *                    upload is through or has been given up on
 */
export function startSramSync(instance, romPath, canSave = true) {
	if (!romPath || !canSave || getCurrentUser() === null) {
		return async () => {}
	}
	// A fresh launch starts from what the server holds, so it syncs again
	// even when the battery save was deleted in an earlier session.
	sramSyncStopped.delete(romPath)
	// Whether the game keeps a battery save at all: it does if the server
	// had one for it, and otherwise until it is seen not to.
	let misses = 0
	const upload = async () => {
		if (sramSyncStopped.has(romPath) || misses >= SRAM_MISSES_TO_STOP) {
			return
		}
		try {
			const sram = await instance.exclusive(readSram)
			if (sram === null || sram === undefined || sram.size === 0) {
				if (!sramOnServer.has(romPath)) {
					misses++
				}
				return
			}
			misses = 0
			const bytes = new Uint8Array(await sram.arrayBuffer())
			if (sameBytes(bytes, sramOnServer.get(romPath))) {
				return
			}
			// Asked again, because reading the save out of the core takes
			// long enough for it to have been deleted meanwhile: the check
			// above the read is not the one that decides.
			if (sramSyncStopped.has(romPath)) {
				return
			}
			await api(stateUrl('/sram', romPath), {
				method: 'POST',
				headers: { 'Content-Type': 'application/octet-stream' },
				body: sram,
				signal: deadline(UPLOAD_WAIT),
				// So the final upload survives the page closing -- but only
				// where it is allowed to, and a save too big to ask it for
				// goes as an ordinary request instead. Closing the game
				// waits for that one; a tab closed outright is the single
				// case it cannot outlive, which beats never arriving.
				keepalive: sram.size <= KEEPALIVE_LIMIT,
			})
			sramOnServer.set(romPath, bytes)
		} catch (error) {
			console.error('Could not save the SRAM', error)
		}
	}
	// Two tabs on the same game still write over one another: each holds
	// its own copy of the save and uploads it on this timer, so the last
	// tick wins. Coordinating them is not attempted here.
	const timer = setInterval(upload, SRAM_SYNC_INTERVAL)
	const onPageHide = () => {
		upload()
	}
	window.addEventListener('pagehide', onPageHide)
	return async () => {
		clearInterval(timer)
		window.removeEventListener('pagehide', onPageHide)
		// Everything played since the last tick lives only in this upload,
		// so the caller waits for it before tearing the emulator down --
		// an exit takes the core, and with it the save, away mid-read.
		await waitAtMost(upload(), SRAM_FINAL_WAIT, undefined)
	}
}
