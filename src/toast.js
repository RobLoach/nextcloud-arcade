// The toaster the Files app uses -- the one that slides in top right --
// wrapped so the app says things the one way.
//
// The server already carries it. Nextcloud's core bundle exposes the
// very same @nextcloud/dialogs helpers as OCP.Toast.{success, warning,
// error, info, message}, styles and all, on every page that loads core
// -- which is every page this app has. Importing them from the package
// instead would ship a second copy: @nextcloud/dialogs publishes no
// ./toast subpath, only "." and "./style.css", so the helpers can only
// be had off the package index, and in v7 a toast is a Vue component,
// so Vue and the file picker ride along with it -- measured at 660 kB
// for a line of text the page can already show.
//
// Should a server ever be without the global, nothing breaks loudly:
// the message goes to the console instead of the screen.

/**
 * @return {object|null} the toaster of the server, where there is one
 */
function toaster() {
	return window.OCP?.Toast ?? null
}

/**
 * Say that something went wrong.
 *
 * Assertively, which is what the in-page line this replaced had with its
 * role="alert": the dialogs helpers give an assertive toast role="alert"
 * and announce it aria-live="assertive". That is already their default
 * for errors; saying it keeps it a decision of ours rather than a
 * default we happen to be sitting on.
 *
 * @param {string} message what went wrong
 */
export function showError(message) {
	const toast = toaster()
	if (toast === null) {
		console.error(message)
		return
	}
	toast.error(message, { ariaLive: 'assertive' })
}

/**
 * Say a word about something under way.
 *
 * Politely -- role="status", aria-live="polite": it is news, not a
 * failure, and it has no business cutting into whatever a screen reader
 * is in the middle of saying.
 *
 * @param {string} message what is happening
 */
export function showInfo(message) {
	const toast = toaster()
	if (toast === null) {
		console.info(message)
		return
	}
	toast.info(message, { ariaLive: 'polite' })
}
