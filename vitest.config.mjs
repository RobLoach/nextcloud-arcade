import { defineConfig } from 'vitest/config'

// A configuration of its own: the app build (vite.config.mjs) is about
// bundling entries into js/, none of which matters to unit tests.
//
// Node by default, because most of what is tested here is pure and a DOM
// costs time to stand up for every file that does not touch one. A file
// that does says so for itself, with
//
//   // @vitest-environment happy-dom
//
// at the top. happy-dom rather than jsdom: it is a fraction of the size
// and starts far quicker, and nothing here needs the parts it leaves out.
export default defineConfig({
	test: {
		environment: 'node',
		include: ['tests/js/**/*.test.js'],
	},
})
