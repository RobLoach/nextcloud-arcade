import { describe, expect, test, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token' }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => url.replace(/\{(\w+)\}/g, (whole, key) => encodeURIComponent(params[key])),
}))

const { stateUrl, useCore } = await import('../../src/api.js')

describe('the address of a save', () => {
	test('names the game, and the slot when there is one', () => {
		expect(stateUrl('/states', '/Games/Mario.nes')).toBe('/apps/arcade/arcade/states?file=%2FGames%2FMario.nes')
		expect(stateUrl('/state', '/Games/Mario.nes', -1)).toBe('/apps/arcade/arcade/state?file=%2FGames%2FMario.nes&slot=-1')
	})

	test('says which core the game runs on, once the player knows', () => {
		useCore('/Games/Mystery.zip', 'snes9x')

		expect(stateUrl('/sram', '/Games/Mystery.zip')).toBe('/apps/arcade/arcade/sram?file=%2FGames%2FMystery.zip&core=snes9x')
		expect(stateUrl('/state', '/Games/Mystery.zip', 2)).toBe('/apps/arcade/arcade/state?file=%2FGames%2FMystery.zip&slot=2&core=snes9x')
		expect(stateUrl('/sram', '/Games/Other.zip')).not.toContain('core=')
	})
})
