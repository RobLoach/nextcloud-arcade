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
			// A ROM takes a while to arrive, and the Viewer can be closed
			// the whole time it does. Nothing exists to stop yet then, so
			// the wish to stop is remembered instead.
			destroyed: false,
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
		this.destroyed = true
		// Whatever is still on its way can stop coming.
		this.launch?.abort()
		this.stopSession?.()
	},

	methods: {
		beforeUnmount() {
			this.beforeDestroy()
		},

		async start() {
			this.started = true
			// Not in data(): a controller is not state to render, and the
			// Viewer's Vue would only walk it for reactivity it never uses.
			this.launch = new AbortController()
			try {
				if (!isPlayable(this.basename, this.mime)) {
					throw new Error(t('arcade', 'Unsupported ROM type: {file}', { file: this.basename }))
				}
				const { startSession } = await import('./session.js')
				const stop = await startSession({
					canvas: this.$refs.canvas,
					container: this.$el,
					filename: this.filename,
					basename: this.basename,
					source: this.source,
					signal: this.launch.signal,
				})
				this.stopSession = stop
				if (this.destroyed) {
					// Closed while it was loading: beforeDestroy had nothing
					// to stop then, so a whole emulator -- sound, timers,
					// listeners and all -- would be left running for good.
					stop()
					return
				}
			} catch (error) {
				if (this.destroyed) {
					return
				}
				console.error('Arcade failed to start', error)
				this.errorMessage = t('arcade', 'Could not start the emulator: {error}', { error: error.message })
			}
			this.$emit('update:loaded', true)
		},
	},

	render(h) {
		let child
		if (this.errorMessage !== null) {
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
		// Only the ROM mimetypes, which name their system. Zips are not
		// claimed: a zip is not guaranteed to be a game, and the Viewer
		// matches by mimetype alone, so it would swallow every archive on
		// the instance. Zips inside the games library are played through
		// the file menu entry instead, on the app page.
		mimes: romMimes(),
		component: ArcadeViewer,
	})
	return true
}

if (!register()) {
	document.addEventListener('DOMContentLoaded', register)
}
