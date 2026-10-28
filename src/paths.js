/**
 * A path, as the routes of the app take it in f: base64url of its UTF-8.
 *
 * ROMs are named like "Kirby's Adventure (USA)", and a quote with
 * brackets in a query string is what a web application firewall in front
 * of the server takes for SQL being smuggled in -- it answers itself, with
 * a 403 or a 418, and the request never reaches Nextcloud. Letters,
 * digits, - and _ give it nothing to object to. The routes still take a
 * plain file= as well, for old bookmarks.
 *
 * @param {string} path a path in the user's files
 * @return {string} the same path, in base64url without padding
 */
export function encodePath(path) {
	let binary = ''
	for (const byte of new TextEncoder().encode(path)) {
		binary += String.fromCharCode(byte)
	}
	return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}
