// @vitest-environment happy-dom
import { describe, expect, test, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, one) => one }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (match, key) => params[key]),
}))
vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))

const { showPictureSample } = await import('../../src/settings.js')

/**
 * @param {object} samples type => file id
 * @param {string} value the type selected
 * @return {HTMLSelectElement} the select of a picture row
 */
function pictureRow(samples, value) {
	const row = document.createElement('p')
	row.innerHTML = `
		<select class="arcade-thumbnail-type">
			<option value="boxart">Box art</option>
			<option value="title">Title screen</option>
		</select>
		<span class="arcade-thumbnail-sample" data-version="v1">
			<img alt="" hidden>
			<em>None</em>
		</span>`
	row.querySelector('.arcade-thumbnail-sample').dataset.samples = JSON.stringify(samples)
	const select = row.querySelector('select')
	select.value = value
	return select
}

describe('showPictureSample', () => {
	test('shows the sample of the kind selected, and follows the selection', () => {
		const select = pictureRow({ boxart: 12, title: 34 }, 'boxart')
		const image = select.parentElement.querySelector('img')

		showPictureSample(select)
		expect(image.hidden).toBe(false)
		expect(image.getAttribute('src')).toContain('fileId=12')
		expect(image.getAttribute('src')).toContain('&v=v1')

		select.value = 'title'
		showPictureSample(select)
		expect(image.getAttribute('src')).toContain('fileId=34')
	})

	test('says so when there is no picture of the kind selected', () => {
		const select = pictureRow({ boxart: 12 }, 'title')
		const sample = select.parentElement

		showPictureSample(select)
		expect(sample.querySelector('img').hidden).toBe(true)
		expect(sample.querySelector('img').hasAttribute('src')).toBe(false)
		expect(sample.querySelector('em').hidden).toBe(false)
	})

	test('falls back on a picture not sorted by kind', () => {
		const select = pictureRow({ plain: 56 }, 'title')
		showPictureSample(select)
		expect(select.parentElement.querySelector('img').getAttribute('src')).toContain('fileId=56')
	})

	test('leaves a row without samples alone', () => {
		const select = document.createElement('select')
		document.createElement('p').appendChild(select)
		expect(() => showPictureSample(select)).not.toThrow()
	})
})
