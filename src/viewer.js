import { translate as t } from '@nextcloud/l10n'
import { registerPlayAction } from './fileaction.js'
import { isPlayable, romMimes } from './systems.js'

/**
 * The player, as the Viewer app shows it.
 *
 * This script is loaded on every page of the Files app, so it carries no
 * more than the shell: the emulator, a few hundred kilobytes of it, is
 * fetched the first time a game is opened.
 *
 * Written as a plain options object with a render function so it works with
 * the Viewer's own Vue instance without needing a template compiler, and so
 * the Viewer can apply its own mixin to it.
 */
const ArcadeViewer = {
	name: 'ArcadeViewer',

	props: {
		active: {
			type: Boolean,
			default: false,
		},
		basename: {
			type: String,
			required: true,
		},
		// file path relative to the user folder
		filename: {
			type: String,
			required: true,
		},
		// alternative file source URL, used on public share pages
		source: {
			type: String,
			default: undefined,
		},
		mime: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			started: false,
			errorMessage: null,
			stopSession: null,
			// A zip that holds no game: shown as a download offer instead.
			notAGame: false,
			downloadUrl: '',
		}
	},

	watch: {
		active(isActive) {
			if (isActive && !this.started) {
				this.start()
			}
		},
	},

	mounted() {
		// The emulator needs the arrow keys, so no swiping to other files.
		this.$emit('update:canSwipe', false)
		if (this.active) {
			this.start()
		}
	},

	// Vue 2 calls this beforeDestroy, Vue 3 beforeUnmount; both are here so
	// the handler keeps working if the Viewer ever moves on.
	beforeDestroy() {
		this.stopSession?.()
	},

	methods: {
		beforeUnmount() {
			this.beforeDestroy()
		},

		async start() {
			this.started = true
			try {
				if (!isPlayable(this.basename, this.mime)) {
					throw new Error(t('arcade', 'Unsupported ROM type: {file}', { file: this.basename }))
				}
				// session.js pulls in player.js, so asking for both costs
				// one download; the emulator's weight still only lands here.
				const [{ startSession }, { NotAGameError, davUrl }] = await Promise.all([
					import('./session.js'),
					import('./player.js'),
				])
				try {
					this.stopSession = await startSession({
						canvas: this.$refs.canvas,
						container: this.$el,
						filename: this.filename,
						basename: this.basename,
						source: this.source,
					})
				} catch (error) {
					if (!(error instanceof NotAGameError)) {
						throw error
					}
					// An archive of something else entirely. Offering it
					// for download beats both an emulator error and core's
					// "no plugin available" page this handler replaced.
					this.notAGame = true
					this.downloadUrl = this.source ?? davUrl(this.filename)
				}
			} catch (error) {
				console.error('Arcade failed to start', error)
				this.errorMessage = t('arcade', 'Could not start the emulator: {error}', { error: error.message })
			}
			this.$emit('update:loaded', true)
		},

		/**
		 * The panel shown for an archive that holds no game: the file
		 * name and a way to download it, in place of core's "no plugin
		 * available" error.
		 *
		 * @param {Function} h the render function of the Viewer's Vue
		 * @return {object} the panel
		 */
		renderNotAGame(h) {
			// href and download are given both flat and under attrs, the
			// same hedge as beforeDestroy/beforeUnmount above: Vue 2 reads
			// attrs, Vue 3 reads the flat keys, each ignores the rest.
			const link = {
				href: this.downloadUrl,
				download: this.basename,
			}
			return h('div', {
				style: { color: '#fff', textAlign: 'center' },
			}, [
				h('p', {}, t('arcade', 'This archive does not look like a game')),
				h('p', { style: { fontWeight: 'bold' } }, this.basename),
				h('a', {
					attrs: link,
					...link,
					style: { color: '#fff', textDecoration: 'underline' },
				}, t('arcade', 'Download')),
			])
		},
	},

	render(h) {
		let child
		if (this.notAGame) {
			child = this.renderNotAGame(h)
		} else if (this.errorMessage !== null) {
			child = h('p', { style: { color: '#fff' } }, this.errorMessage)
		} else {
			child = h('canvas', {
				ref: 'canvas',
				style: {
					width: '100%',
					height: '100%',
					objectFit: 'contain',
					backgroundColor: '#000',
				},
			})
		}
		return h('div', {
			class: 'arcade-viewer',
			style: {
				width: '100%',
				height: '100%',
				display: 'flex',
				alignItems: 'center',
				justifyContent: 'center',
			},
		}, [child])
	},
}

function register() {
	// The file menu entry does not depend on the Viewer being there.
	registerPlayAction()
	if (window.OCA?.Viewer?.registerHandler === undefined) {
		return false
	}
	window.OCA.Viewer.registerHandler({
		id: 'arcade',
		group: null,
		// Zips keep their server mimetype on purpose, so they are claimed
		// here as well: one may hold a game, and one that holds anything
		// else gets a download offer instead of "no plugin available".
		mimes: [...romMimes(), 'application/zip'],
		component: ArcadeViewer,
	})
	return true
}

if (!register()) {
	document.addEventListener('DOMContentLoaded', register)
}
