import { api, deadline, stateUrl, UPLOAD_WAIT } from './api.js'

// How much of the save states of a game is fetched ahead, before any is
// asked for. A state is a few megabytes, a PlayStation one a few more,
// so this covers every slot the player offers many times over and stops
// a folder RetroArch filled with hundreds of slots from being pulled down
// whole. What is left out is still fetched the moment it is loaded.
const PREFETCH_BUDGET = 64 * 1024 * 1024

// How long an autosave may be on its way before leaving the page asks
// first. One arrives every few minutes, unbidden, so a reload in the
// moment it is sent should not be held up over it -- but one that has
// been stuck for longer than an upload takes is worth a word.
const QUIET_GRACE = 5 * 1000

// How long a listing is trusted before a load asks again. The panel lists
// as it opens, but a hotkey loads with no panel in sight, and a slot that
// RetroArch synced over since must not be answered with the copy held
// from before. A listing is a few hundred bytes, against megabytes for
// the state, so asking costs a load next to nothing.
const LISTING_FRESH_FOR = 30 * 1000

/**
 * The save states of one game for the length of one session, held in the
 * browser.
 *
 * Loading a state used to wait for its download, and saving one for its
 * upload, so both took as long as the line did. Now the states are
 * fetched ahead as the game starts, a load is answered from what is held,
 * and a save is held at once and sent to the server behind the player's
 * back.
 *
 * Every write to the server -- an upload or a delete -- goes through one
 * queue, in the order it was asked for, so a delete never overtakes the
 * upload it was meant to undo. A slot saved again before its last upload
 * started only sends the newer one.
 *
 * What is held is checked against the server whenever the slots are
 * listed -- as the panel opens -- and a slot written meanwhile by anybody
 * else, another tab or RetroArch syncing, is let go of, so the next load
 * fetches what is there now. Each copy is known by when the server wrote
 * it: the listing's time for one fetched, the upload's answer for one
 * saved here.
 *
 * @param {object} options options
 * @param {string} options.romPath path identifying the game
 * @param {Function} [options.onUploadFailed] told the slot and the error
 *                                             when an upload fails
 * @return {object} the cache
 */
export function createStateCache({ romPath, onUploadFailed = () => {} }) {
	// What is known of each slot beyond what the server last listed:
	// a state saved here, or the bytes of one fetched from there. A slot
	// deleted here is held as deleted, so a listing taken before the
	// delete reached the server does not hand it back.
	const held = new Map()
	// Downloads under way, so a load waits for the prefetch of the same
	// slot rather than starting a second one.
	const fetching = new Map()
	let listing = { slots: 0, states: [], hasSram: false }
	let listedAt = 0
	let queue = Promise.resolve()
	// The writes not through yet, with whether anybody asked for them and
	// since when they have been waiting.
	const writing = new Set()
	let prefetched = false

	const enqueue = (work, quiet = false) => {
		const write = { quiet, since: Date.now() }
		writing.add(write)
		const run = queue.then(work).finally(() => {
			writing.delete(write)
		})
		// The queue goes on past a failure; the caller hears of it.
		queue = run.catch(() => {})
		return run
	}

	// Leaving the page while a save is still on its way would lose it, so
	// the browser is asked to say so first -- for a save the player asked
	// for, or an autosave that has been stuck past the moment it takes.
	const onBeforeUnload = (event) => {
		const now = Date.now()
		for (const write of writing) {
			if (!write.quiet || now - write.since > QUIET_GRACE) {
				event.preventDefault()
				event.returnValue = ''
				return
			}
		}
	}
	window.addEventListener('beforeunload', onBeforeUnload)

	/**
	 * Send a save to the server, unless a newer one of the slot has taken
	 * its place meanwhile, which goes instead.
	 *
	 * @param {number} slot the slot
	 * @param {object} entry what was saved
	 */
	const upload = async (slot, entry) => {
		if (held.get(slot) !== entry) {
			return
		}
		// One budget for the pair: a deadline each would let a single save
		// take twice what either of them allows.
		const budget = deadline(UPLOAD_WAIT)
		try {
			const response = await api(stateUrl('/state', romPath, slot), {
				method: 'POST',
				headers: { 'Content-Type': 'application/octet-stream' },
				body: entry.state,
				signal: budget,
			})
			const answer = await response.json().catch(() => ({}))
			// When the server wrote it, which the listing will say too as
			// long as nobody has written the slot since.
			entry.mtime = Number.isFinite(answer?.mtime) ? answer.mtime : entry.mtime
			entry.synced = true
			if (entry.thumbnail !== null) {
				// A picture is the one part worth going without.
				await api(stateUrl('/state/thumbnail', romPath, slot), {
					method: 'POST',
					headers: { 'Content-Type': 'image/png' },
					body: entry.thumbnail,
					signal: budget,
				}).catch(() => {})
			}
			entry.failed = false
		} catch (error) {
			// Still held, so it still loads for the rest of the session,
			// and flush() tries again.
			entry.failed = true
			onUploadFailed(slot, error)
		}
	}

	/**
	 * Fetch the bytes of a slot from the server, once.
	 *
	 * @param {number} slot the slot
	 * @param {?AbortSignal} signal abandons the download
	 * @return {Promise<Blob>} the state
	 */
	const download = (slot, signal = null) => {
		if (!fetching.has(slot)) {
			const signals = signal === null ? [deadline(UPLOAD_WAIT)] : [signal, deadline(UPLOAD_WAIT)]
			const request = api(stateUrl('/state', romPath, slot), { signal: AbortSignal.any(signals) })
				.then((response) => response.blob())
				.then((state) => {
					// Kept only when nothing newer came in while it was on
					// its way: a save made meanwhile is the slot now.
					if (!held.has(slot)) {
						const listed = listing.states.find((each) => each.slot === slot)
						held.set(slot, { state, thumbnail: null, thumbnailUrl: null, mtime: listed?.mtime ?? null, local: false })
					}
					return state
				})
				.finally(() => {
					fetching.delete(slot)
				})
			fetching.set(slot, request)
		}
		return fetching.get(slot)
	}

	/**
	 * Let go of every copy the server no longer agrees with: a slot written
	 * since by somebody else, or deleted. A save still on its way, or one
	 * whose upload failed, is this player's newest word on the slot, and
	 * stays; so does a delete still waiting its turn.
	 */
	const forgetChanged = () => {
		const listed = new Map(listing.states.map((state) => [state.slot, state.mtime]))
		for (const [slot, entry] of held) {
			if (entry.deleted || (entry.local && !entry.synced)) {
				continue
			}
			if (listed.get(slot) !== entry.mtime) {
				if (entry.thumbnailUrl) {
					URL.revokeObjectURL(entry.thumbnailUrl)
				}
				held.delete(slot)
			}
		}
	}

	/**
	 * What the server listed, with what was saved or deleted here since.
	 *
	 * @return {{slots: number, states: object[], hasSram: boolean}} the slots
	 */
	const merged = () => {
		const bySlot = new Map(listing.states.map((state) => [state.slot, state]))
		for (const [slot, entry] of held) {
			if (entry.deleted) {
				bySlot.delete(slot)
			} else if (entry.local) {
				bySlot.set(slot, {
					slot,
					size: entry.state.size,
					mtime: entry.mtime,
					hasThumbnail: entry.thumbnail !== null || bySlot.get(slot)?.hasThumbnail === true,
				})
			}
		}
		return {
			...listing,
			states: [...bySlot.values()].sort((a, b) => a.slot - b.slot),
		}
	}

	/**
	 * The slots of the game, as the server lists them and as they stand
	 * here.
	 *
	 * @param {?AbortSignal} [signal] abandons the request
	 * @return {Promise<object>} slots, states and hasSram
	 */
	const list = async (signal = null) => {
		const response = await api(stateUrl('/states', romPath), { signal })
		const data = await response.json()
		listing = {
			slots: Number.isFinite(Number(data?.slots)) ? Number(data.slots) : 0,
			states: Array.isArray(data?.states) ? data.states : [],
			hasSram: data?.hasSram === true,
		}
		listedAt = Date.now()
		forgetChanged()
		return merged()
	}

	return {
		list,

		/**
		 * Fetch the states of the game ahead of any load, the newest first,
		 * one at a time so the game itself is not starved of the line.
		 * Only the first call does anything.
		 *
		 * @param {?AbortSignal} [signal] stops fetching when it fires
		 */
		async prefetch(signal = null) {
			if (prefetched) {
				return
			}
			prefetched = true
			let budget = PREFETCH_BUDGET
			const newestFirst = [...listing.states].sort((a, b) => b.mtime - a.mtime)
			for (const state of newestFirst) {
				if (signal?.aborted === true) {
					return
				}
				if (held.has(state.slot) || state.size > budget) {
					continue
				}
				budget -= state.size
				try {
					await download(state.slot, signal)
				} catch {
					// Only a head start was at stake; a load asks again.
				}
			}
		},

		/**
		 * The state of a slot: at once when it is held, otherwise from the
		 * server.
		 *
		 * @param {number} slot the slot
		 * @return {Promise<Blob>} the state
		 */
		async get(slot) {
			let entry = held.get(slot)
			// A copy held from the server is only as good as the last
			// listing; one saved here and not yet through is the newest
			// there is, whatever the server says.
			const fromServer = entry !== undefined && !entry.deleted && !(entry.local && !entry.synced)
			if (fromServer && Date.now() - listedAt > LISTING_FRESH_FOR) {
				try {
					await list()
				} catch {
					// The server cannot say; the copy held is the best there is.
				}
				entry = held.get(slot)
			}
			if (entry !== undefined && !entry.deleted) {
				return entry.state
			}
			return await download(slot)
		},

		/**
		 * Hold a save, and send it to the server in the background.
		 *
		 * @param {number} slot the slot
		 * @param {Blob} state the state
		 * @param {?Blob} [thumbnail] its picture
		 * @param {object} [options] options
		 * @param {boolean} [options.quiet] nobody asked for it: an autosave
		 * @return {Promise<void>} answers once the upload is through or has
		 *                         failed; nobody needs to wait for it
		 */
		put(slot, state, thumbnail = null, { quiet = false } = {}) {
			const was = held.get(slot)
			if (was?.thumbnailUrl) {
				URL.revokeObjectURL(was.thumbnailUrl)
			}
			const entry = {
				state,
				thumbnail: thumbnail ?? null,
				thumbnailUrl: null,
				mtime: Math.floor(Date.now() / 1000),
				local: true,
				failed: false,
				quiet,
			}
			held.set(slot, entry)
			return enqueue(() => upload(slot, entry), quiet)
		},

		/**
		 * Delete a slot, here at once and on the server in its turn.
		 *
		 * @param {number} slot the slot
		 * @return {Promise<void>} answers once the server has deleted it;
		 *                         throws when it would not
		 */
		async remove(slot) {
			const was = held.get(slot)
			const tombstone = { deleted: true }
			held.set(slot, tombstone)
			try {
				await enqueue(() => api(stateUrl('/state', romPath, slot), { method: 'DELETE' })
					.catch((error) => {
						// Saved here and never uploaded: there is nothing
						// on the server to delete, which is what was asked.
						if (error?.status !== 404) {
							throw error
						}
					}))
				if (was?.thumbnailUrl) {
					URL.revokeObjectURL(was.thumbnailUrl)
				}
				// Deleted on the server too, so the next listing says so by
				// itself -- and says so if somebody saves the slot again.
				if (held.get(slot) === tombstone) {
					held.delete(slot)
					listing = { ...listing, states: listing.states.filter((state) => state.slot !== slot) }
				}
			} catch (error) {
				// Not deleted after all, so it is what it was.
				if (held.get(slot) === tombstone) {
					if (was === undefined) {
						held.delete(slot)
					} else {
						held.set(slot, was)
					}
				}
				throw error
			}
		},

		/**
		 * Where the picture of a slot saved here can be shown from, before
		 * the server has it; null for a slot whose picture is the server's.
		 *
		 * @param {number} slot the slot
		 * @return {?string} an object URL
		 */
		thumbnailUrl(slot) {
			const entry = held.get(slot)
			if (entry === undefined || entry.deleted || !entry.local || entry.thumbnail === null) {
				return null
			}
			entry.thumbnailUrl ??= URL.createObjectURL(entry.thumbnail)
			return entry.thumbnailUrl
		},

		/** @return {number} how many writes are still on their way */
		pending() {
			return writing.size
		},

		/**
		 * Wait for every write to reach the server, trying again the
		 * uploads that failed.
		 *
		 * @return {Promise<void>} answers once the queue is empty
		 */
		async flush() {
			for (const [slot, entry] of held) {
				if (entry.failed) {
					entry.failed = false
					enqueue(() => upload(slot, entry), entry.quiet)
				}
			}
			await queue
		},

		/**
		 * Let go of what is held. Writes still queued carry on, and the
		 * page goes on asking before it is left until they are through.
		 */
		dispose() {
			queue.then(() => {
				window.removeEventListener('beforeunload', onBeforeUnload)
			})
			for (const entry of held.values()) {
				if (entry.thumbnailUrl) {
					URL.revokeObjectURL(entry.thumbnailUrl)
				}
			}
		},
	}
}
