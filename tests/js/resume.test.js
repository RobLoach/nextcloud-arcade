// @vitest-environment happy-dom
import { describe, expect, test, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const { offerResume } = await import('../../src/panels/resume.js')

const list = (slots) => async () => ({ states: slots.map(([slot, mtime]) => ({ slot, mtime })) })

describe('offerResume', () => {
	test('loads a slot asked for by name without asking', async () => {
		const container = document.createElement('div')
		const load = vi.fn()
		await offerResume({ container, load, list: list([[0, 100], [3, 50]]), startSlot: 3 })
		expect(load).toHaveBeenCalledWith(3)
		expect(container.querySelector('.arcade-resume')).toBeNull()
	})

	test('offers the latest as before when the slot asked for is gone', async () => {
		const container = document.createElement('div')
		const load = vi.fn()
		await offerResume({ container, load, list: list([[0, 100]]), startSlot: 3 })
		expect(load).not.toHaveBeenCalled()
		expect(container.querySelector('.arcade-resume')).not.toBeNull()
	})
})
