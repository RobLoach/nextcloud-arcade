const STYLE_ID = 'arcade-touch-style'
const STYLE = `
.arcade-touch {
	position: absolute;
	inset: 0;
	pointer-events: none;
	z-index: 20090;
	user-select: none;
	-webkit-user-select: none;
}
.arcade-touch.hidden { display: none; }
.arcade-touch * { touch-action: none; }
.arcade-touch-dpad {
	position: absolute;
	bottom: 70px;
	left: 20px;
	width: 140px;
	height: 140px;
	border-radius: 50%;
	background-color: rgba(255, 255, 255, 0.08);
	border: 2px solid rgba(255, 255, 255, 0.25);
	pointer-events: auto;
}
.arcade-touch-dpad::before {
	content: '';
	position: absolute;
	top: 50%;
	left: 50%;
	width: 46px;
	height: 46px;
	transform: translate(-50%, -50%);
	border-radius: 50%;
	background-color: rgba(255, 255, 255, 0.2);
}
.arcade-touch-buttons {
	position: absolute;
	bottom: 70px;
	right: 20px;
	width: 150px;
	height: 150px;
	pointer-events: none;
}
.arcade-touch-button {
	position: absolute;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
	background-color: rgba(255, 255, 255, 0.15);
	border: 2px solid rgba(255, 255, 255, 0.3);
	color: rgba(255, 255, 255, 0.85);
	font-weight: bold;
	font-size: 16px;
	pointer-events: auto;
}
.arcade-touch-button.pressed { background-color: rgba(255, 255, 255, 0.45); }
.arcade-touch-button.face {
	width: 52px;
	height: 52px;
}
.arcade-touch-buttons .face-a { right: 0; top: 50%; transform: translateY(-50%); }
.arcade-touch-buttons .face-b { bottom: 0; left: 50%; transform: translateX(-50%); }
.arcade-touch-buttons .face-x { top: 0; left: 50%; transform: translateX(-50%); }
.arcade-touch-buttons .face-y { left: 0; top: 50%; transform: translateY(-50%); }
.arcade-touch-button.pill {
	border-radius: 16px;
	font-size: 11px;
	width: 64px;
	height: 28px;
}
.arcade-touch-select { position: absolute; bottom: 24px; left: 50%; transform: translateX(-108%); }
.arcade-touch-start { position: absolute; bottom: 24px; left: 50%; transform: translateX(8%); }
/* Below the top-right chrome cluster, so the R shoulder stays tappable. */
.arcade-touch-l { position: absolute; top: 60px; left: 20px; }
.arcade-touch-r { position: absolute; top: 60px; right: 20px; }
`

/**
 * @return {boolean} whether this looks like a touch device
 */
export function isTouchDevice() {
	return window.matchMedia?.('(pointer: coarse)').matches
		|| navigator.maxTouchPoints > 0
}

function ensureStyle() {
	if (document.getElementById(STYLE_ID) !== null) {
		return
	}
	const style = document.createElement('style')
	style.id = STYLE_ID
	style.textContent = STYLE
	document.head.appendChild(style)
}

/**
 * Attach a virtual gamepad overlay for touch play.
 *
 * @param {object} options options
 * @param {HTMLElement} options.container element to attach the overlay to
 * @param {import('nostalgist').Nostalgist} options.instance the running emulator
 * @return {{element: HTMLElement, detach: Function}} the overlay
 */
export function attachTouchControls({ container, instance }) {
	ensureStyle()

	const overlay = document.createElement('div')
	overlay.className = 'arcade-touch'

	// The D-pad is a single zone: the touch position relative to the center
	// decides the pressed directions, so diagonals work with one thumb.
	const dpad = document.createElement('div')
	dpad.className = 'arcade-touch-dpad'
	let pressedDirections = new Set()
	const releaseDirections = () => {
		for (const direction of pressedDirections) {
			instance.pressUp(direction)
		}
		pressedDirections = new Set()
	}
	const updateDirections = (touch) => {
		const rect = dpad.getBoundingClientRect()
		const dx = touch.clientX - (rect.left + rect.width / 2)
		const dy = touch.clientY - (rect.top + rect.height / 2)
		const distance = Math.hypot(dx, dy)
		const directions = new Set()
		if (distance > rect.width / 8) {
			// 0.38 ≈ sin(22.5°): eight-way sectors, diagonals included.
			if (Math.abs(dx) / distance > 0.38) {
				directions.add(dx > 0 ? 'right' : 'left')
			}
			if (Math.abs(dy) / distance > 0.38) {
				directions.add(dy > 0 ? 'down' : 'up')
			}
		}
		for (const direction of directions) {
			if (!pressedDirections.has(direction)) {
				instance.pressDown(direction)
			}
		}
		for (const direction of pressedDirections) {
			if (!directions.has(direction)) {
				instance.pressUp(direction)
			}
		}
		pressedDirections = directions
	}
	dpad.addEventListener('touchstart', (event) => {
		event.preventDefault()
		updateDirections(event.targetTouches[0])
	})
	dpad.addEventListener('touchmove', (event) => {
		event.preventDefault()
		updateDirections(event.targetTouches[0])
	})
	dpad.addEventListener('touchend', (event) => {
		event.preventDefault()
		if (event.targetTouches.length === 0) {
			releaseDirections()
		} else {
			updateDirections(event.targetTouches[0])
		}
	})
	dpad.addEventListener('touchcancel', releaseDirections)
	overlay.appendChild(dpad)

	const button = (label, name, className) => {
		const element = document.createElement('div')
		element.className = `arcade-touch-button ${className}`
		element.textContent = label
		element.addEventListener('touchstart', (event) => {
			event.preventDefault()
			element.classList.add('pressed')
			instance.pressDown(name)
		})
		const release = (event) => {
			event.preventDefault()
			element.classList.remove('pressed')
			instance.pressUp(name)
		}
		element.addEventListener('touchend', release)
		element.addEventListener('touchcancel', release)
		return element
	}

	const faceButtons = document.createElement('div')
	faceButtons.className = 'arcade-touch-buttons'
	faceButtons.appendChild(button('A', 'a', 'face face-a'))
	faceButtons.appendChild(button('B', 'b', 'face face-b'))
	faceButtons.appendChild(button('X', 'x', 'face face-x'))
	faceButtons.appendChild(button('Y', 'y', 'face face-y'))
	overlay.appendChild(faceButtons)

	overlay.appendChild(button('SELECT', 'select', 'pill arcade-touch-select'))
	overlay.appendChild(button('START', 'start', 'pill arcade-touch-start'))
	overlay.appendChild(button('L', 'l', 'pill arcade-touch-l'))
	overlay.appendChild(button('R', 'r', 'pill arcade-touch-r'))

	container.appendChild(overlay)

	return {
		element: overlay,
		detach() {
			releaseDirections()
			overlay.remove()
		},
	}
}
