import { describe, expect, test, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token' }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => url.replace(/\{(\w+)\}/g, (whole, key) => encodeURIComponent(params[key])),
}))

const { asUpload, encodePath, stateUrl, useCore } = await import('../../src/api.js')

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
})
