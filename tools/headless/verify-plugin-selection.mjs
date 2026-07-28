/* eslint-disable no-console -- This command emits content-free verification results. */
/**
 * Verify feature-aware Reveal plugin selection and optional chunk loading.
 */

import { chromium } from '@playwright/test';

const baseUrl =
	process.env.PRESENTER_PLUGIN_SELECTION_BASE_URL ?? 'http://localhost:8888';
const builtInPluginIds = new Set( [
	'highlight',
	'markdown',
	'math',
	'notes',
	'search',
	'zoom',
] );
const fixtures = [
	{
		expectedPlugins: [ 'search', 'notes', 'zoom' ],
		expectsHighlightPayload: false,
		slug: 'presenter-plugin-selection-plain',
	},
	{
		expectedPlugins: [ 'markdown', 'search', 'notes', 'zoom', 'highlight' ],
		expectsHighlightPayload: true,
		slug: 'presenter-plugin-selection-markdown',
	},
	{
		expectedPlugins: [ 'search', 'notes', 'zoom', 'highlight' ],
		expectsHighlightPayload: true,
		slug: 'presenter-plugin-selection-code',
	},
];
const browser = await chromium.launch( { headless: true } );
const results = [];

try {
	for ( const fixture of fixtures ) {
		const context = await browser.newContext();
		const page = await context.newPage();
		const scriptResponses = [];
		const scriptResponseTasks = [];
		const pageErrors = [];

		page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
		page.on( 'response', ( response ) => {
			if (
				response.request().resourceType() !== 'script' ||
				! response.url().includes( '/plugins/presenter/build/' )
			) {
				return;
			}

			scriptResponseTasks.push(
				response
					.body()
					.then( ( body ) => {
						scriptResponses.push( {
							bytes: body.byteLength,
							url: response.url(),
						} );
					} )
					.catch( () => {} )
			);
		} );

		const targetUrl = `${ baseUrl }/?slideshow=${ fixture.slug }`;
		const response = await page.goto( targetUrl, {
			waitUntil: 'networkidle',
		} );

		if ( ! response?.ok() ) {
			throw new Error(
				`${ fixture.slug } returned HTTP ${ response?.status() }.`
			);
		}

		await page.waitForFunction(
			() => window.presenterReveal?.getInstance()?.isReady() === true
		);
		await Promise.all( scriptResponseTasks );

		const configuredPlugins = await page.evaluate( () => {
			const configElement = document.querySelector(
				'script[data-presenter-reveal-config]'
			);

			return JSON.parse( configElement?.textContent || '{}' ).plugins;
		} );
		const configuredBuiltInPlugins = configuredPlugins.filter(
			( pluginId ) => builtInPluginIds.has( pluginId )
		);
		const largeOptionalPayloadLoaded = scriptResponses.some(
			( scriptResponse ) => scriptResponse.bytes > 500_000
		);
		const pluginsMatch =
			JSON.stringify( configuredBuiltInPlugins ) ===
			JSON.stringify( fixture.expectedPlugins );
		const passed =
			pluginsMatch &&
			largeOptionalPayloadLoaded === fixture.expectsHighlightPayload &&
			pageErrors.length === 0;

		results.push( {
			configuredBuiltInPlugins,
			configuredPlugins,
			expectedPlugins: fixture.expectedPlugins,
			largeOptionalPayloadLoaded,
			pageErrors,
			passed,
			scriptResponses,
			slug: fixture.slug,
		} );
		await context.close();
	}
} finally {
	await browser.close();
}

console.log( JSON.stringify( results, null, 2 ) );

if ( results.some( ( result ) => ! result.passed ) ) {
	process.exitCode = 1;
}
