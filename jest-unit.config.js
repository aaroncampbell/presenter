const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	modulePathIgnorePatterns: [ '<rootDir>/local/' ],
};
