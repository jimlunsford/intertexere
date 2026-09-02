const defaults = require( '@wordpress/scripts/config/playwright.config' );

module.exports = {
	...defaults,
	testDir: './tests/e2e',
	webServer: {
		...defaults.webServer,
		command: 'npm run wp-env:start',
	},
};
