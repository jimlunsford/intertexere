const defaults = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaults,
	setupFilesAfterEnv: [ '<rootDir>/tests/js/setup.js' ],
	testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],
};
