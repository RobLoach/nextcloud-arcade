import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import { generateRemoteUrl } from '@nextcloud/router'
import { insideLibrary, registerPlayAction } from './fileaction.js'
import { isPlayable, romMimes } from './systems.js'

/**
 * The WebDAV URL of a file of the logged-in user.
 *
 * A twin of davUrl() in player.js, kept here so that showing the
 * outside-the-library panel does not pull in the emulator chunk that
 * player.js weighs. On public share pages there is no user, but there the
 * Viewer hands the component a `source` URL, which wins anyway.
 *
 * @param {string} filename path of the file, relative to the user folder
 * @return {string} the URL, or '' when there is no one logged in
 */
function davDownloadUrl(filename) {
	const uid = getCurrentUser()?.uid
	if (!uid) {
		return ''
	}
	const encoded = filename.replace(/^\/+/, '').split('/').map(encodeURIComponent).join('/')
	return `${generateRemoteUrl(`dav/files/${encodeURIComponent(uid)}`)}/${encoded}`
}

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
			// A zip outside the games library: never opened at all.
			outsideLibrary: false,
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
			// The Viewer matches handlers by mimetype alone -- as of Viewer
			// 35 a handler has no per-file say before it is opened -- so a
			// zip from outside the games library lands here too. It is
			// turned away before anything is fetched: no zip download, no
			// emulation, just a pointer back to the library. ROM mimetypes
			// name their system and stay ungated. On public share pages
			// there are no user settings, so insideLibrary() is
			// conservative there and treats zips as outside.
			if (this.mime === 'application/zip' && !insideLibrary(this.filename)) {
				this.outsideLibrary = true
				this.downloadUrl = this.source ?? davDownloadUrl(this.filename)
				this.$emit('update:loaded', true)
				return
			}
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
		 * A panel with the file name and a way to download it, shown when
		 * the archive is not played: because it holds no game, or because
		 * it sits outside the games library.
		 *
		 * @param {Function} h the render function of the Viewer's Vue
		 * @param {string} message what is the matter with the file
		 * @param {?string} hint what to do about it, if anything
		 * @return {object} the panel
		 */
		renderDownloadPanel(h, message, hint = null) {
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
				h('p', {}, message),
				h('p', { style: { fontWeight: 'bold' } }, this.basename),
				// No user and no source URL leaves nothing to link to.
				this.downloadUrl === '' ? null : h('a', {
					attrs: link,
					...link,
					style: { color: '#fff', textDecoration: 'underline' },
				}, t('arcade', 'Download')),
				hint === null ? null : h('p', {}, hint),
			])
		},
	},

	render(h) {
		let child
		if (this.outsideLibrary) {
			child = this.renderDownloadPanel(h,
				t('arcade', 'This archive is outside your games library'),
				t('arcade', 'Move it into your games folder to play it.'))
		} else if (this.notAGame) {
			child = this.renderDownloadPanel(h,
				t('arcade', 'This archive does not look like a game'))
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
		// The registration cannot be narrower than a mimetype, so the
		// component itself turns away zips outside the games library.
		mimes: [...romMimes(), 'application/zip'],
		component: ArcadeViewer,
	})
	return true
}

if (!register()) {
	document.addEventListener('DOMContentLoaded', register)
}
