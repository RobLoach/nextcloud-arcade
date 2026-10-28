import { getCurrentUser } from '@nextcloud/auth'
import { generateRemoteUrl } from '@nextcloud/router'
import { getSharingToken, isPublicShare } from '@nextcloud/sharing/public'
import { api, dav } from './api.js'

// The files over WebDAV, worked out here rather than taken from
// @nextcloud/files/dav: importing the two strings from there brings the
// whole WebDAV client along, on every page, for a library that never uses
// it. The same answers: on a public share page, its share token under the
// public endpoint; anywhere else, the user's own files.

/**
 * @return {string} the address of the DAV endpoint of this page
 */
export function davEndpoint() {
	const url = generateRemoteUrl('dav')
	return isPublicShare() ? url.replace('remote.php', 'public.php') : url
}

/**
 * @return {string} the root of the files of this page under the endpoint,
 *                  such as /files/alice
 */
export function davRoot() {
	return isPublicShare() ? `/files/${getSharingToken()}` : `/files/${getCurrentUser()?.uid}`
}

/**
 * @param {string} path path of the file, relative to the user folder or,
 *                      on public share pages, the share root
 * @return {string} the WebDAV URL of the file
 */
export function davUrl(path) {
	const encoded = path.replace(/^\/+/, '').split('/').map(encodeURIComponent).join('/')
	return `${davEndpoint()}${davRoot()}/${encoded}`
}

/**
 * Make a folder in the user's own files, and every folder above it that
 * is missing too.
 *
 * MKCOL makes one level at a time: asked for a folder whose parent is
 * not there either, the server answers 409 and nothing is made. That is
 * the ordinary case the first time a game is played -- a screenshot goes
 * to a folder named after its system, inside a screenshots folder the
 * player has never created -- and it left the screenshot nowhere to go.
 *
 * 405 is the folder already being there, which is the outcome asked for
 * either way.
 *
 * @param {string} path the folder to make, relative to the user's files
 */
export async function makeFolder(path) {
	let walked = ''
	for (const part of path.split('/').filter(Boolean)) {
		walked += `/${part}`
		const response = await dav(davUrl(walked), { method: 'MKCOL' })
		if (!response.ok && response.status !== 405) {
			throw new Error(`${response.status} ${response.statusText}`)
		}
	}
}

/**
 * Look up the Nextcloud file id of a file over WebDAV.
 *
 * @param {string} path path of the file, relative to the user folder
 * @return {Promise<string>} the file id
 */
export async function fileIdOf(path) {
	const response = await api(davUrl(path), {
		method: 'PROPFIND',
		headers: {
			'Content-Type': 'application/xml; charset=utf-8',
			Depth: '0',
		},
		credentials: 'same-origin',
		body: '<?xml version="1.0"?>'
			+ '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
			+ '<d:prop><oc:fileid/></d:prop>'
			+ '</d:propfind>',
	})
	const multistatus = new DOMParser().parseFromString(await response.text(), 'application/xml')
	const fileId = multistatus.getElementsByTagNameNS('http://owncloud.org/ns', 'fileid')[0]?.textContent ?? ''
	if (fileId === '') {
		throw new Error('No file id in the PROPFIND response')
	}
	return fileId
}
