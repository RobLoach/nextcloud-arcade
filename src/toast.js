// The toaster the Files app uses -- the one that slides in top right --
// wrapped so the app says things the one way.
//
// This is meant to be reached by a dynamic import, and it is why the
// wrapper exists at all: @nextcloud/dialogs publishes no ./toast
// subpath, only "." and "./style.css", so the helpers can only be had
// off the package index, and the Vue file picker rides along with them.
// Imported statically the picker lands in the page's first load, 660 kB
// of it; behind a chunk of its own it stays out of the way.
//
// The stylesheet travels with the helpers, the way src/picker.js has it
// travel with the picker. It is folded into the shared @nextcloud/dialogs
// chunk -- the same one the picker's copy lands in -- and injected the
// moment that chunk loads, so nothing is emitted into css/, which holds
// handwritten stylesheets only.
import '@nextcloud/dialogs/style.css'
import { showError as toastError, showInfo as toastInfo, ToastAriaLive } from '@nextcloud/dialogs'

/**
 * Say that something went wrong.
 *
 * The in-page line this replaced carried role="alert", and assertive is
 * what gets that back: @nextcloud/dialogs gives an assertive toast
 * role="alert" and announces it aria-live="assertive". It is already the
 * default for errors; passing it keeps it a decision of ours rather than
 * a default we happen to be sitting on.
 *
 * @param {string} message what went wrong
 */
export function showError(message) {
	toastError(message, { ariaLive: ToastAriaLive.ASSERTIVE })
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
	toastInfo(message, { ariaLive: ToastAriaLive.POLITE })
}
