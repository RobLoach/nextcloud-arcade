import { generateUrl } from '@nextcloud/router'
import { romMimes } from './systems.js'

/**
 * The URL that plays a game on the app page: by file id where there is
 * one, so the link survives renames and moves; the path form stays as
 * the fallback.
 *
 * @param {string} path path of the game
 * @param {number|string} [fileId] the Nextcloud file id, when known
 * @return {string} the URL that plays the game
 */
export function playUrl(path, fileId) {
	return fileId
		? generateUrl('/apps/arcade/?fileId={fileId}', { fileId })
		: generateUrl('/apps/arcade/?file={file}', { file: path })
}

/**
 * Start a game: in the Viewer when it knows the mimetype, on the app
 * page otherwise -- by file id where one is known, so the link survives
 * renames and moves.
 *
 * @param {string} path path of the game
 * @param {string} mime its mimetype
 * @param {number|string} [fileId] the Nextcloud file id, when known
 */
export function playGame(path, mime, fileId) {
	if (window.OCA?.Viewer !== undefined && romMimes().includes(mime)) {
		window.OCA.Viewer.open({ path })
		return
	}
	window.location.href = playUrl(path, fileId)
}
