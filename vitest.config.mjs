import { defineConfig } from 'vitest/config'

// A configuration of its own: the app build (vite.config.mjs) is about
// bundling entries into js/, none of which matters to unit tests. The
// modules under test are pure, so plain Node is enough -- no DOM.
export default defineConfig({
	test: {
		environment: 'node',
		include: ['tests/js/**/*.test.js'],
	},
})
