// Enough to tell two panels apart, should a page ever carry two players.
let panels = 0

/**
 * The shared scaffolding of the toolbar panels: a hidden dialog carrying
 * its title as heading and label.
 *
 * @param {string} className the class naming the panel
 * @param {string} title its title, already translated
 * @return {HTMLElement} the panel element
 */
export function createPanel(className, title) {
	const element = document.createElement('div')
	element.className = `${className} hidden`

	element.setAttribute('role', 'dialog')
	element.setAttribute('aria-modal', 'false')
	// Focusable without being a tab stop of its own, so opening the panel
	// can put the focus inside it and the reader starts at its title.
	element.tabIndex = -1

	const heading = document.createElement('h3')
	// The heading is the label, rather than a second copy of the same
	// words in an attribute beside it.
	heading.id = `${className}-title-${++panels}`
	heading.textContent = title
	element.setAttribute('aria-labelledby', heading.id)
	element.appendChild(heading)

	return element
}
