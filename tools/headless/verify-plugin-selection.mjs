/* eslint-disable no-console -- This command emits content-free verification results. */
/**
 * Verify feature-aware Reveal plugin selection and optional chunk loading.
 */

import { chromium } from '@playwright/test';

const baseUrl =
	process.env.PRESENTER_PLUGIN_SELECTION_BASE_URL ?? 'http://localhost:8888';
const baseOrigin = new URL( baseUrl ).origin;
const fixtures = [
	{
		expectedPlugins: [ 'search', 'notes', 'zoom' ],
		expectsChartBridge: false,
		expectsHighlightPayload: false,
		maxPresenterScriptBytes: 430_000,
		slug: 'presenter-plugin-selection-plain',
	},
	{
		expectedPlugins: [ 'markdown', 'search', 'notes', 'zoom', 'highlight' ],
		expectsChartBridge: false,
		expectsHighlightPayload: true,
		maxPresenterScriptBytes: 1_400_000,
		slug: 'presenter-plugin-selection-markdown',
	},
	{
		expectedPlugins: [ 'search', 'notes', 'zoom', 'highlight' ],
		expectsChartBridge: false,
		expectsHighlightPayload: true,
		maxPresenterScriptBytes: 1_350_000,
		slug: 'presenter-plugin-selection-code',
	},
	{
		expectedPlugins: [ 'search', 'notes', 'zoom', 'chartjs' ],
		expectsChartBridge: true,
		expectsHighlightPayload: false,
		maxPresenterScriptBytes: 430_000,
		slug: 'presenter-plugin-selection-chart',
	},
];
const browser = await chromium.launch( { headless: true } );
const results = [];

try {
	for ( const fixture of fixtures ) {
		const context = await browser.newContext();
		const page = await context.newPage();
		const externalRequests = [];
		const failedResponses = [];
		const requestFailures = [];
		const scriptResponses = [];
		const scriptResponseErrors = [];
		const scriptResponseTasks = [];
		const pageErrors = [];

		page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
		page.on( 'request', ( request ) => {
			const requestUrl = request.url();

			if (
				/^https?:/.test( requestUrl ) &&
				new URL( requestUrl ).origin !== baseOrigin
			) {
				externalRequests.push( requestUrl );
			}
		} );
		page.on( 'requestfailed', ( request ) => {
			requestFailures.push( {
				error:
					request.failure()?.errorText ?? 'Unknown request failure',
				url: request.url(),
			} );
		} );
		page.on( 'response', ( response ) => {
			const responseUrl = response.url();

			if ( response.status() >= 400 ) {
				failedResponses.push( {
					status: response.status(),
					url: responseUrl,
				} );
			}

			if (
				response.request().resourceType() !== 'script' ||
				! /^https?:/.test( responseUrl )
			) {
				return;
			}

			scriptResponseTasks.push(
				response
					.body()
					.then( ( body ) => {
						scriptResponses.push( {
							bytes: body.byteLength,
							url: responseUrl,
						} );
					} )
					.catch( ( error ) => {
						scriptResponseErrors.push( {
							error: error.message,
							url: responseUrl,
						} );
					} )
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
		const chartBridgeLoaded = scriptResponses.some( ( scriptResponse ) => {
			const scriptPath = new URL( scriptResponse.url ).pathname;

			return (
				scriptPath.includes( '/wp-content/plugins/' ) &&
				scriptPath.endsWith( '/js/chartjs-plugin.js' )
			);
		} );
		const presenterScriptResponses = scriptResponses.filter(
			( scriptResponse ) =>
				scriptResponse.url.includes( '/plugins/presenter/build/' )
		);
		const presenterScriptBytes = presenterScriptResponses.reduce(
			( total, scriptResponse ) => total + scriptResponse.bytes,
			0
		);
		const largeOptionalPayloadLoaded = presenterScriptResponses.some(
			( scriptResponse ) => scriptResponse.bytes > 500_000
		);
		const pluginsMatch =
			JSON.stringify( configuredPlugins ) ===
			JSON.stringify( fixture.expectedPlugins );
		const passed =
			pluginsMatch &&
			chartBridgeLoaded === fixture.expectsChartBridge &&
			largeOptionalPayloadLoaded === fixture.expectsHighlightPayload &&
			presenterScriptBytes <= fixture.maxPresenterScriptBytes &&
			externalRequests.length === 0 &&
			failedResponses.length === 0 &&
			requestFailures.length === 0 &&
			scriptResponseErrors.length === 0 &&
			pageErrors.length === 0;

		results.push( {
			chartBridgeLoaded,
			configuredPlugins,
			expectedPlugins: fixture.expectedPlugins,
			externalRequests,
			failedResponses,
			largeOptionalPayloadLoaded,
			maxPresenterScriptBytes: fixture.maxPresenterScriptBytes,
			pageErrors,
			passed,
			presenterScriptBytes,
			requestFailures,
			scriptResponseErrors,
			scriptResponses: presenterScriptResponses,
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
