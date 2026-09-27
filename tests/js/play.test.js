import { beforeEach, describe, expect, test, vi } from 'vitest'

// A passthrough generateUrl that substitutes the placeholders the way the
// real one does, while recording what it was asked for.
const generateUrl = vi.fn((path, params = {}) => path.replace(/{(\w+)}/g, (match, key) => encodeURIComponent(params[key])))

vi.mock('@nextcloud/router', () => ({ generateUrl }))

// play.js pulls in systems.js, which reads the systems from the initial
// state on load; the fallback is all these tests need.
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => fallback,
}))

const { playUrl, previewUrl } = await import('../../src/play.js')

beforeEach(() => {
	generateUrl.mockClear()
})

describe('playUrl', () => {
	test('goes by file id when one is known', () => {
		expect(playUrl('/Games/NES/game.nes', 42)).toBe('/apps/arcade/?fileId=42')
		expect(generateUrl).toHaveBeenCalledWith('/apps/arcade/?fileId={fileId}', { fileId: 42 })
	})

	test('falls back to the path without one', () => {
		expect(playUrl('/Games/NES/game.nes')).toBe('/apps/arcade/?file=%2FGames%2FNES%2Fgame.nes')
		expect(generateUrl).toHaveBeenCalledWith('/apps/arcade/?file={file}', { file: '/Games/NES/game.nes' })
	})
})

describe('previewUrl', () => {
	test('asks for a square when only the width is given', () => {
		expect(previewUrl(7, 64)).toBe('/core/preview?fileId=7&x=64&y=64&a=1')
	})

	test('keeps a height of its own', () => {
		expect(previewUrl(7, 64, 32)).toBe('/core/preview?fileId=7&x=64&y=32&a=1')
	})
})
