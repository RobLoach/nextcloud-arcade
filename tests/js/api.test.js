import { afterEach, describe, expect, test, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token', getCurrentUser: () => ({ uid: 'alice' }) }))
vi.mock('@nextcloud/router', () => ({
	generateRemoteUrl: (service) => `/remote.php/${service}`,
	generateUrl: (url, params = {}) => url.replace(/\{(\w+)\}/g, (whole, key) => encodeURIComponent(params[key])),
}))

const { asUpload, encodePath, isFolder, stateUrl, useCore } = await import('../../src/api.js')

describe('the address of a save', () => {
	test('names the game, and the slot when there is one', () => {
		expect(stateUrl('/states', '/Games/Mario.nes')).toBe('/apps/arcade/arcade/states?f=L0dhbWVzL01hcmlvLm5lcw')
		expect(stateUrl('/state', '/Games/Mario.nes', -1)).toBe('/apps/arcade/arcade/state?f=L0dhbWVzL01hcmlvLm5lcw&slot=-1')
	})

	test('says which core the game runs on, once the player knows', () => {
		useCore('/Games/Mystery.zip', 'snes9x')

		expect(stateUrl('/sram', '/Games/Mystery.zip')).toBe('/apps/arcade/arcade/sram?f=L0dhbWVzL015c3Rlcnkuemlw&core=snes9x')
		expect(stateUrl('/state', '/Games/Mystery.zip', 2)).toBe('/apps/arcade/arcade/state?f=L0dhbWVzL015c3Rlcnkuemlw&slot=2&core=snes9x')
		expect(stateUrl('/sram', '/Games/Other.zip')).not.toContain('core=')
	})

	test('carries a name a firewall would take for SQL as nothing but letters', () => {
		const path = "/ROMs/NES/Kirby's Adventure (USA) (Rev 1) — 日本.nes"
		const encoded = encodePath(path)

		expect(encoded).toMatch(/^[A-Za-z0-9_-]+$/)
		const bytes = Uint8Array.from(atob(encoded.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0))
		expect(new TextDecoder().decode(bytes)).toBe(path)
	})

	test('carries an upload as the file of a form, which no firewall turns away', async () => {
		const form = asUpload(new Blob(['a state']), 'state')

		expect(form).toBeInstanceOf(FormData)
		expect(await form.get('data').text()).toBe('a state')
	})

	test('says the name of the game inside a zip, encoded like the path', () => {
		useCore('/Games/SSF2.zip', 'snes9x', 'Super Street Fighter II (USA)')

		expect(stateUrl('/sram', '/Games/SSF2.zip')).toBe(
			'/apps/arcade/arcade/sram?f=' + encodePath('/Games/SSF2.zip') + '&core=snes9x&n=' + encodePath('Super Street Fighter II (USA)'))
	})
})

describe('isFolder', () => {
	afterEach(() => vi.unstubAllGlobals())
	const answer = (status, body = '') => {
		const fetch = vi.fn(async () => new Response(body, { status }))
		vi.stubGlobal('fetch', fetch)
		return fetch
	}
	const resourcetype = (type) => `<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:prop><d:resourcetype>${type}</d:resourcetype></d:prop></d:propstat></d:response></d:multistatus>`

	test('asks the user\'s own files, and a folder is a folder', async () => {
		const fetch = answer(207, resourcetype('<d:collection/>'))
		expect(await isFolder('/Games/Tom & Jerry/')).toBe(true)
		expect(fetch.mock.calls[0][0]).toBe('/remote.php/dav/files/alice/Games/Tom%20%26%20Jerry')
		expect(fetch.mock.calls[0][1].method).toBe('PROPFIND')
	})

	test('a file is not', async () => {
		answer(207, resourcetype(''))
		expect(await isFolder('/notes.txt')).toBe(false)
	})

	test('nor is nothing at all', async () => {
		answer(404)
		expect(await isFolder('/Nope')).toBe(false)
	})

	test('a server that cannot say throws rather than guess', async () => {
		answer(503)
		await expect(isFolder('/Games')).rejects.toThrow('503')
	})
})
