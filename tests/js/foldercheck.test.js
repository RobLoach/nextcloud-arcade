// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import { checkFolders, folderProblem, showFolderProblem } from '../../src/foldercheck.js'

describe('folderProblem', () => {
	test('says nothing of a folder that is fine', () => {
		expect(folderProblem('ok', 'library_folder')).toEqual({ message: '', error: false })
	})

	test('says nothing of a status it does not know', () => {
		expect(folderProblem('something new', 'library_folder')).toEqual({ message: '', error: false })
	})

	test.each(['file', 'unreadable', 'unavailable', 'invalid'])('takes %s for an error', (status) => {
		const problem = folderProblem(status, 'system_folder')
		expect(problem.error).toBe(true)
		expect(problem.message).not.toBe('')
	})

	test('takes a missing folder for an error', () => {
		expect(folderProblem('missing', 'library_folder')).toEqual({
			message: 'There is no such folder in your files.',
			error: true,
		})
	})

	test('says on the administration page that the path is every user\'s', () => {
		const problem = folderProblem('missing', 'library_folder', true)
		expect(problem.error).toBe(true)
		expect(problem.message).toContain('Every user')
	})

	test('only notes a saves folder not made yet, since the first save makes it', () => {
		const problem = folderProblem('missing', 'saves_folder')
		expect(problem.error).toBe(false)
		expect(problem.message).toContain('first time a game is saved')
	})
})

/** A folder field as the templates write it. */
function field(setting, value = '') {
	const p = document.createElement('p')
	const input = document.createElement('input')
	input.type = 'text'
	input.id = `arcade-${setting}`
	input.className = 'arcade-setting'
	input.dataset.setting = setting
	input.value = value
	p.appendChild(input)
	return { p, input }
}

describe('showFolderProblem', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
	})

	test('highlights the field and says why beside it', () => {
		const { p, input } = field('library_folder', '/Nope')
		document.body.appendChild(p)
		showFolderProblem(input, { message: 'Gone', error: true })
		const line = document.getElementById('arcade-library_folder-problem')
		expect(line.textContent).toBe('Gone')
		expect(line.classList.contains('arcade-folder-problem--error')).toBe(true)
		expect(input.classList.contains('error')).toBe(true)
		expect(input.getAttribute('aria-invalid')).toBe('true')
		expect(input.getAttribute('aria-describedby')).toBe(line.id)
	})

	test('clears it all again once the folder is fine, keeping one line', () => {
		const { p, input } = field('library_folder', '/Games')
		document.body.appendChild(p)
		showFolderProblem(input, { message: 'Gone', error: true })
		showFolderProblem(input, { message: '', error: false })
		expect(p.querySelectorAll('.arcade-folder-problem')).toHaveLength(1)
		expect(input.classList.contains('error')).toBe(false)
		expect(input.hasAttribute('aria-invalid')).toBe(false)
		expect(input.hasAttribute('aria-describedby')).toBe(false)
	})

	test('says a note without calling the field wrong', () => {
		const { p, input } = field('saves_folder', '/Saves')
		document.body.appendChild(p)
		showFolderProblem(input, { message: 'Not yet', error: false })
		expect(document.getElementById('arcade-saves_folder-problem').textContent).toBe('Not yet')
		expect(input.classList.contains('error')).toBe(false)
	})
})

describe('checkFolders', () => {
	let container

	beforeEach(() => {
		document.body.innerHTML = ''
		container = document.createElement('div')
		for (const [setting, value] of [['library_folder', '/Games'], ['system_folder', '/Bios'], ['thumbnails_folder', '']]) {
			container.appendChild(field(setting, value).p)
		}
		document.body.appendChild(container)
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	test('asks about every folder as typed, and shows each answer', async () => {
		const fetch = vi.fn(async () => new Response(JSON.stringify({
			library_folder: 'ok',
			system_folder: 'missing',
			thumbnails_folder: 'ok',
		})))
		vi.stubGlobal('fetch', fetch)
		await checkFolders(container)
		const url = new URL(fetch.mock.calls[0][0], 'http://localhost')
		expect(url.pathname).toContain('/apps/arcade/arcade/settings/folders')
		expect(url.searchParams.get('library_folder')).toBe('/Games')
		expect(url.searchParams.get('system_folder')).toBe('/Bios')
		expect(url.searchParams.get('thumbnails_folder')).toBe('')
		expect(document.getElementById('arcade-system_folder').classList.contains('error')).toBe(true)
		expect(document.getElementById('arcade-library_folder').classList.contains('error')).toBe(false)
	})

	test('leaves the fields alone when the check itself fails', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => new Response('', { status: 500 })))
		vi.spyOn(console, 'error').mockImplementation(() => {})
		await checkFolders(container)
		expect(container.querySelector('.arcade-folder-problem')).toBeNull()
		expect(container.querySelector('.error')).toBeNull()
	})
})
