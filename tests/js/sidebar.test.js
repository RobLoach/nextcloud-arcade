// @vitest-environment happy-dom
import { afterEach, describe, expect, test, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))

const { giveTheSidebarAContext } = await import('../../src/sidebar.js')

const client = (answer) => ({ stat: vi.fn(async (path) => answer(path)) })
const resultToNode = (data) => ({ node: data })
const propfind = () => '<propfind/>'

afterEach(() => {
	delete window.OCA
})

describe('giveTheSidebarAContext', () => {
	test('gives the stand-in sidebar the folder of the game and a view', async () => {
		const provider = { folder: { value: undefined }, view: { value: undefined } }
		window.OCA = { Files: { Sidebar: { _sharedState: { standaloneProvider: provider } } } }
		const dav = client((path) => ({ data: path }))

		await giveTheSidebarAContext(dav, resultToNode, propfind, '/Games/SNES/Chrono.sfc')

		expect(dav.stat).toHaveBeenCalledWith('/files/alice/Games/SNES', expect.anything())
		expect(provider.folder.value).toEqual({ node: '/files/alice/Games/SNES' })
		expect(provider.view.value?.id).toBe('arcade')
	})

	test('leaves a sidebar alone that has no stand-in provider', async () => {
		window.OCA = { Files: { Sidebar: {} } }
		const dav = client(() => ({ data: '' }))
		await giveTheSidebarAContext(dav, resultToNode, propfind, '/Games/Chrono.sfc')
		expect(dav.stat).not.toHaveBeenCalled()
	})

	test('opens without them when the folder cannot be read', async () => {
		const provider = { folder: { value: undefined }, view: { value: undefined } }
		window.OCA = { Files: { Sidebar: { _sharedState: { standaloneProvider: provider } } } }
		const dav = client(() => {
			throw new Error('404')
		})
		await expect(giveTheSidebarAContext(dav, resultToNode, propfind, '/Games/Chrono.sfc')).resolves.toBeUndefined()
		expect(provider.folder.value).toBeUndefined()
	})
})
