// @vitest-environment happy-dom
import { describe, expect, test, vi } from 'vitest'

vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

const { registerPlayAction } = await import('../../src/fileaction.js')

describe('the Play with Arcade entry of the file menu', () => {
	test('goes where the Files app of Nextcloud 34 and 35 reads it, and tells it so', () => {
		const heard = []
		const registry = new EventTarget()
		registry.addEventListener('register:action', (event) => heard.push(event.detail.id))
		window._nc_files_scope = { v4_0: { registry } }

		expect(registerPlayAction()).toBe(true)

		const action = window._nc_files_scope.v4_0.fileActions.get('arcade-play')
		expect(action.displayName()).toBe('Play with Arcade')
		expect(heard).toEqual(['arcade-play'])
		// And where @nextcloud/files 3 kept them, for anything reading that.
		expect(window._nc_fileactions.map((each) => each.id)).toContain('arcade-play')
	})

	test('is added once however often it is asked', () => {
		registerPlayAction()
		expect(registerPlayAction()).toBe(false)
		expect([...window._nc_files_scope.v4_0.fileActions.keys()].filter((id) => id === 'arcade-play')).toHaveLength(1)
		expect(window._nc_fileactions.filter((each) => each.id === 'arcade-play')).toHaveLength(1)
	})
})
