import { translate, translatePlural } from '@nextcloud/l10n'

// Every text of the app goes into the page as text -- textContent, an
// attribute, a toast -- and never as HTML. Escaped and sanitized for HTML
// on top, a game called "Tom & Jerry" would show as "Tom &amp; Jerry".
const PLAIN = { escape: false, sanitize: false }

/**
 * A translated text, its placeholders filled in as they are.
 *
 * @param {string} app the app the text belongs to
 * @param {string} text the text, in English
 * @param {object} [vars] what goes into its placeholders
 * @return {string} the text, plain
 */
export function t(app, text, vars) {
	return translate(app, text, vars, undefined, PLAIN)
}

/**
 * A translated text that has a plural, %n being the count.
 *
 * @param {string} app the app the text belongs to
 * @param {string} singular the text for one, in English
 * @param {string} plural the text for more, in English
 * @param {number} count how many
 * @param {object} [vars] what goes into its other placeholders
 * @return {string} the text, plain
 */
export function n(app, singular, plural, count, vars) {
	return translatePlural(app, singular, plural, count, vars, PLAIN)
}
