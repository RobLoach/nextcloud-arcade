// @vitest-environment happy-dom
import { beforeEach, describe, expect, test, vi } from 'vitest'

// The server is stood in for by a list of the requests made, each held
// open until the test answers it, so the order of the queue can be seen.

const api = vi.hoisted(() => vi.fn())
vi.mock('../../src/api.js', () => ({
	// The form the upload travels in is the browser's business; the
	// tests read back the file it carries.
	asUpload: (blob) => blob,
	api,
	deadline: () => new AbortController().signal,
	stateUrl: (route, romPath, slot = null) => `${route}?slot=${slot}`,
	UPLOAD_WAIT: 1000,
}))

const { createStateCache } = await import('../../src/statecache.js')

let requests = []
beforeEach(() => {
	requests = []
	api.mockReset()
	api.mockImplementation((url, options = {}) => new Promise((resolve, reject) => {
		requests.push({ url, method: options.method ?? 'GET', body: options.body, resolve, reject })
	}))
})

const ok = (body = {}) => ({
	json: async () => body,
	blob: async () => new Blob(['from the server']),
})

/** Let every promise that can settle, settle. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

/** Answer every request that is open, and those that follow, one by one. */
const answerAll = async (answer = ok()) => {
	await settle()
	for (let open = requests.find((each) => !each.answered); open; open = requests.find((each) => !each.answered)) {
		open.answered = true
		open.resolve(answer)
		await settle()
	}
}

/** Turn the oldest open request down. */
const refuse = (error) => {
	const open = requests.find((each) => !each.answered)
	open.answered = true
	open.reject(error)
}

const listed = async (cache, states) => {
	const listing = cache.list()
	requests[0].answered = true
	requests[0].resolve(ok({ slots: 3, states, hasSram: false }))
	return await listing
}

describe('the save states held for a session', () => {
	test('answers a load at once with a state saved here, without asking the server', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		cache.put(1, new Blob(['mine']))

		const state = await cache.get(1)

		expect(await state.text()).toBe('mine')
		expect(requests.filter((request) => request.method === 'GET')).toHaveLength(0)
	})

	test('sends a save to the server in the background, the state then its picture', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		cache.put(2, new Blob(['state']), new Blob(['png']))
		await settle()

		expect(requests.map((request) => request.url)).toEqual(['/state?slot=2'])
		expect(cache.pending()).toBe(1)
		await answerAll()
		expect(requests.map((request) => request.url)).toEqual(['/state?slot=2', '/state/thumbnail?slot=2'])
		expect(cache.pending()).toBe(0)
	})

	test('sends only the newest of saves to a slot that pile up behind an upload', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		cache.put(1, new Blob(['first']))
		await settle()
		cache.put(1, new Blob(['second']))
		cache.put(1, new Blob(['third']))
		await answerAll()

		const bodies = await Promise.all(requests.map((request) => request.body.text()))
		expect(bodies).toEqual(['first', 'third'])
	})

	test('never lets a delete overtake the upload it undoes', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		cache.put(1, new Blob(['state']))
		await settle()
		const removed = cache.remove(1)
		await settle()
		expect(requests.map((request) => request.method)).toEqual(['POST'])

		await answerAll()
		await removed
		expect(requests.map((request) => `${request.method} ${request.url}`))
			.toEqual(['POST /state?slot=1', 'DELETE /state?slot=1'])
	})

	test('sends nothing for a save deleted before its upload started', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		cache.put(1, new Blob(['state']))
		const removed = cache.remove(1)
		await answerAll()
		await removed

		expect(requests.map((request) => request.method)).toEqual(['DELETE'])
	})

	test('lists what was saved and deleted here on top of what the server said', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		cache.put(3, new Blob(['new']), new Blob(['png']))
		// An upload still on its way is shown, whatever the server lists.
		const pending = await listed(cache, [{ slot: -1, size: 5, mtime: 10, hasThumbnail: false }])
		expect(pending.states.map((state) => state.slot)).toEqual([-1, 3])
		expect(pending.states[1].hasThumbnail).toBe(true)
		expect(cache.thumbnailUrl(3)).toMatch(/^blob:/)
		expect(cache.thumbnailUrl(-1)).toBeNull()

		// The upload answers when it was written, and the server lists the
		// slot at that time from then on: still the copy held here.
		await answerAll(ok({ mtime: 50 }))
		requests = []
		const { states } = await listed(cache, [
			{ slot: -1, size: 5, mtime: 10, hasThumbnail: false },
			{ slot: 3, size: 3, mtime: 50, hasThumbnail: true },
		])
		expect(states.map((state) => state.slot)).toEqual([-1, 3])
		expect(await (await cache.get(3)).text()).toBe('new')
		expect(requests).toHaveLength(1)
	})

	test('hides a slot being deleted, and lists it again once somebody saves it anew', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		await listed(cache, [{ slot: 2, size: 5, mtime: 10, hasThumbnail: false }])
		requests = []
		const removed = cache.remove(2)
		const during = cache.list()
		await settle()
		requests.find((request) => request.method === 'GET').answered = true
		requests.find((request) => request.method === 'GET').resolve(ok({ slots: 3, states: [{ slot: 2, size: 5, mtime: 10 }] }))
		expect((await during).states).toEqual([])
		await answerAll()
		await removed

		requests = []
		const { states } = await listed(cache, [{ slot: 2, size: 5, mtime: 99, hasThumbnail: false }])
		expect(states.map((state) => state.slot)).toEqual([2])
	})

	test('lets go of a state somebody else wrote since, and fetches it anew', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		await listed(cache, [{ slot: 1, size: 5, mtime: 10, hasThumbnail: false }])
		requests = []
		cache.prefetch()
		await answerAll()
		await cache.get(1)
		expect(requests).toHaveLength(1)

		// RetroArch synced a newer one meanwhile.
		requests = []
		await listed(cache, [{ slot: 1, size: 5, mtime: 20, hasThumbnail: false }])
		requests = []
		const loading = cache.get(1)
		await answerAll()
		await loading
		expect(requests.map((request) => request.url)).toEqual(['/state?slot=1'])
	})

	test('keeps a save whose upload failed, whatever the server lists', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes', onUploadFailed: () => {} })
		cache.put(1, new Blob(['mine']))
		await settle()
		refuse(new Error('500'))
		await settle()
		requests = []

		await listed(cache, [{ slot: 1, size: 5, mtime: 10, hasThumbnail: false }])

		expect(await (await cache.get(1)).text()).toBe('mine')
	})

	test('fetches the newest states ahead, one at a time, and loads them from then on', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		await listed(cache, [
			{ slot: 1, size: 5, mtime: 10, hasThumbnail: false },
			{ slot: -1, size: 5, mtime: 30, hasThumbnail: false },
		])
		requests = []

		const prefetching = cache.prefetch()
		await settle()
		expect(requests.map((request) => request.url)).toEqual(['/state?slot=-1'])
		await answerAll()
		await prefetching
		expect(requests.map((request) => request.url)).toEqual(['/state?slot=-1', '/state?slot=1'])

		await cache.get(1)
		expect(requests).toHaveLength(2)
	})

	test('waits for a state already being fetched rather than asking twice', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		await listed(cache, [{ slot: 1, size: 5, mtime: 10, hasThumbnail: false }])
		requests = []

		cache.prefetch()
		await settle()
		const loading = cache.get(1)
		await answerAll()

		expect(await (await loading).text()).toBe('from the server')
		expect(requests).toHaveLength(1)
	})

	test('says so when an upload fails, keeps the state, and tries again on flush', async () => {
		const onUploadFailed = vi.fn()
		const cache = createStateCache({ romPath: '/Games/Mario.nes', onUploadFailed })
		cache.put(1, new Blob(['state']))
		await settle()
		refuse(new Error('500'))
		await settle()

		expect(onUploadFailed).toHaveBeenCalledWith(1, expect.any(Error))
		expect(await (await cache.get(1)).text()).toBe('state')

		const flushed = cache.flush()
		await settle()
		expect(requests).toHaveLength(2)
		await answerAll()
		await flushed
	})

	test('takes a delete back when the server would not do it', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		cache.put(1, new Blob(['state']))
		await answerAll(ok({ mtime: 7 }))
		const removed = cache.remove(1)
		await settle()
		refuse(Object.assign(new Error('500'), { status: 500 }))

		await expect(removed).rejects.toThrow('500')
		// The listing taken before the load still has the slot, as written.
		const loading = cache.get(1)
		await settle()
		requests.at(-1).answered = true
		requests.at(-1).resolve(ok({ slots: 3, states: [{ slot: 1, size: 5, mtime: 7 }] }))
		expect(await (await loading).text()).toBe('state')
	})

	test('sends a save whose upload the delete stood in front of, when the delete fails', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		cache.put(1, new Blob(['state']))
		const removed = cache.remove(1)
		await settle()
		// The upload saw the slot deleted and sent nothing; the delete is
		// the one request made, and it is turned down.
		expect(requests.map((request) => request.method)).toEqual(['DELETE'])
		refuse(Object.assign(new Error('500'), { status: 500 }))
		await expect(removed).rejects.toThrow('500')

		await answerAll()
		expect(requests.map((request) => `${request.method} ${request.url}`))
			.toEqual(['DELETE /state?slot=1', 'POST /state?slot=1'])
		expect(cache.pending()).toBe(0)
	})

	test('stops fetching ahead once the session is over, though nobody gave it a signal', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		await listed(cache, [{ slot: 1, size: 5, mtime: 9 }, { slot: 2, size: 5, mtime: 8 }])
		const fetching = cache.prefetch()
		await settle()
		expect(requests.filter((request) => request.method === 'GET')).toHaveLength(2)

		cache.dispose()
		requests.at(-1).answered = true
		requests.at(-1).resolve(ok())
		await fetching
		// The first was on its way; the second is never asked for.
		expect(requests.filter((request) => request.method === 'GET')).toHaveLength(2)
	})

	test('asks the server again before loading from an old listing', async () => {
		vi.useFakeTimers({ toFake: ['Date'] })
		try {
			const cache = createStateCache({ romPath: '/Games/Mario.nes' })
			await listed(cache, [{ slot: 1, size: 5, mtime: 10, hasThumbnail: false }])
			requests = []
			cache.prefetch()
			await answerAll()

			// Fresh enough: answered at once.
			requests = []
			await cache.get(1)
			expect(requests).toHaveLength(0)

			// Half a minute on, RetroArch has synced a newer one.
			vi.setSystemTime(Date.now() + 31 * 1000)
			const loading = cache.get(1)
			await settle()
			expect(requests.map((request) => request.url)).toEqual(['/states?slot=null'])
			requests[0].answered = true
			requests[0].resolve(ok({ slots: 3, states: [{ slot: 1, size: 5, mtime: 20 }] }))
			await answerAll()
			await loading
			expect(requests.map((request) => request.url)).toEqual(['/states?slot=null', '/state?slot=1'])
		} finally {
			vi.useRealTimers()
		}
	})

	test('asks before the page is left for a save the player made, not a fresh autosave', async () => {
		vi.useFakeTimers({ toFake: ['Date'] })
		try {
			const listening = vi.spyOn(window, 'addEventListener')
			const cache = createStateCache({ romPath: '/Games/Mario.nes' })
			const [, onBeforeUnload] = listening.mock.calls.find(([type]) => type === 'beforeunload')
			listening.mockRestore()
			const leave = () => {
				const event = new Event('beforeunload', { cancelable: true })
				onBeforeUnload(event)
				return event.defaultPrevented
			}

			cache.put(-1, new Blob(['auto']), null, { quiet: true })
			expect(leave()).toBe(false)
			vi.setSystemTime(Date.now() + 6 * 1000)
			expect(leave()).toBe(true)
			await answerAll()
			expect(leave()).toBe(false)
			cache.dispose()
		} finally {
			vi.useRealTimers()
		}
	})

	test('asks before the page is left while a save is on its way', async () => {
		// The caches of the other tests are still listening, some with
		// requests that are never answered, so this one's own handler is
		// asked rather than the window.
		const listening = vi.spyOn(window, 'addEventListener')
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		const [, onBeforeUnload] = listening.mock.calls.find(([type]) => type === 'beforeunload')
		listening.mockRestore()
		const leave = () => {
			const event = new Event('beforeunload', { cancelable: true })
			onBeforeUnload(event)
			return event.defaultPrevented
		}

		cache.put(1, new Blob(['state']))
		expect(leave()).toBe(true)

		await answerAll()
		expect(leave()).toBe(false)
		cache.dispose()
	})

	test('fetches nothing ahead until a listing has said what there is, and then does', async () => {
		const cache = createStateCache({ romPath: '/Games/Mario.nes' })
		await cache.prefetch()
		expect(requests).toHaveLength(0)

		await listed(cache, [{ slot: 0, size: 5, mtime: 10, hasThumbnail: false }])
		requests = []
		cache.prefetch()
		await settle()
		expect(requests.map((request) => request.url)).toEqual(['/state?slot=0'])
		await answerAll()
	})
})
