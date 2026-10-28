import { describe, expect, it } from 'vitest'
import { n, t } from '../../src/l10n.js'

describe('l10n', () => {
	it('fills in a name as it is, not escaped for HTML', () => {
		expect(t('arcade', 'Unsupported ROM type: {file}', { file: 'Tom & Jerry <b>.zip' }))
			.toBe('Unsupported ROM type: Tom & Jerry <b>.zip')
	})

	it('does the same for a plural', () => {
		expect(n('arcade', '%n game in {path}', '%n games in {path}', 2, { path: '/R&D "Games"' }))
			.toBe('2 games in /R&D "Games"')
		expect(n('arcade', '%n game in {path}', '%n games in {path}', 1, { path: '/A&B' }))
			.toBe('1 game in /A&B')
	})

	it('leaves a text without placeholders alone', () => {
		expect(t('arcade', 'Save states')).toBe('Save states')
	})
})
