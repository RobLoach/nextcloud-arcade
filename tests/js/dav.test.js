// @vitest-environment happy-dom
import { beforeEach, describe, expect, test, vi } from 'vitest'

const state = vi.hoisted(() => ({ public: null, token: null }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))
vi.mock('@nextcloud/router', () => ({ generateRemoteUrl: (service) => `https://cloud.example/remote.php/${service}` }))
vi.mock('@nextcloud/sharing/public', () => ({
	isPublicShare: () => state.public !== null,
	getSharingToken: () => state.token,
}))

const { davUrl } = await import('../../src/dav.js')

describe('davUrl', () => {
	beforeEach(() => {
		state.public = null
		state.token = null
	})

	test('a file of the user is under their own files', () => {
		expect(davUrl('/Games/Tom & Jerry.nes'))
			.toBe('https://cloud.example/remote.php/dav/files/alice/Games/Tom%20%26%20Jerry.nes')
	})

	test('a file of a public share is under its token, on the public endpoint', () => {
		state.public = true
		state.token = 'AbC123'
		expect(davUrl('Mario.nes')).toBe('https://cloud.example/public.php/dav/files/AbC123/Mario.nes')
	})
})
