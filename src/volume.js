/**
 * A volume out of a hundred, as the gain in decibels RetroArch takes.
 *
 * The setting a player sets is a percentage, because a gain of -6 dB is
 * a thing a mixing desk asks for and not a thing somebody about to play
 * a game knows the answer to. RetroArch only takes the decibels, so the
 * two meet here.
 *
 * A hundred is the game exactly as recorded, and every halving of the
 * level is about six decibels down. Nothing is a floor rather than
 * negative infinity, which RetroArch would not parse.
 *
 * @param {number} percent how loud, from 0 to 100
 * @return {number} the gain in decibels
 */
export function volumeInDecibels(percent) {
	const level = Math.max(0, Math.min(100, Number(percent ?? 100)))
	if (!Number.isFinite(level) || level === 0) {
		return -80
	}
	return Math.round(20 * Math.log10(level / 100) * 10) / 10
}
