/**
 * External dependencies
 */
const { defineConfig, devices } = require( '@playwright/test' );

const wordpressConfig = require( '@wordpress/scripts/config/playwright.config' );

module.exports = defineConfig( {
	...wordpressConfig,
	testDir: './tests/e2e',
	webServer: {
		...wordpressConfig.webServer,
		command: 'npm run env:start',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
		{
			name: 'firefox',
			use: { ...devices[ 'Desktop Firefox' ] },
		},
		{
			name: 'webkit',
			use: { ...devices[ 'Desktop Safari' ] },
		},
	],
} );
