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
	element.setAttribute('aria-label', title)

	const heading = document.createElement('h3')
	heading.textContent = title
	element.appendChild(heading)

	return element
}
