import { translate as t } from '@nextcloud/l10n'

/**
 * The shared shape of the play time texts: nothing under a minute, and
 * the hours and minutes computed the same way for every wording.
 *
 * @param {number} seconds a length of time
 * @param {Function} words puts the hours and minutes into words
 * @return {string} that length in words, empty under a minute
 */
function formatTime(seconds, words) {
	if (!seconds || seconds < 60) {
		return ''
	}
	const hours = Math.floor(seconds / 3600)
	const minutes = Math.round((seconds % 3600) / 60)
	return words(hours, minutes)
}

/**
 * @param {number} seconds a length of time
 * @return {string} that length in words, empty under a minute
 */
export function formatDuration(seconds) {
	return formatTime(seconds, (hours, minutes) => (hours > 0
		? t('arcade', '{hours} h {minutes} min', { hours, minutes })
		: t('arcade', '{minutes} min', { minutes })))
}

/**
 * @param {number} seconds time played
 * @return {string} that time, in words
 */
export function formatPlayTime(seconds) {
	return formatTime(seconds, (hours, minutes) => (hours > 0
		? t('arcade', '{hours}h {minutes}m played', { hours, minutes })
		: t('arcade', '{minutes}m played', { minutes })))
}

/**
 * @param {number} bytes a file size
 * @return {string} the size in human readable units
 */
export function formatSize(bytes) {
	const units = ['B', 'KB', 'MB', 'GB']
	let size = bytes
	let unit = 0
	while (size >= 1024 && unit < units.length - 1) {
		size /= 1024
		unit++
	}
	return `${size < 10 && unit > 0 ? size.toFixed(1) : Math.round(size)} ${units[unit]}`
}
