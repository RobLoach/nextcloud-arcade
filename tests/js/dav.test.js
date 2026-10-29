// @vitest-environment happy-dom
import { describe, expect, test, vi } from 'vitest'

vi.mock('@nextcloud/files/dav', () => ({
	defaultRemoteURL: 'https://cloud.example/remote.php/dav',
	defaultRootPath: '/files/alice',
}))

const { davUrl } = await import('../../src/dav.js')

describe('davUrl', () => {
	test('each part of the path is encoded on its own', () => {
		expect(davUrl('/Games/Tom & Jerry.nes'))
			.toBe('https://cloud.example/remote.php/dav/files/alice/Games/Tom%20%26%20Jerry.nes')
	})
})
