/* eslint-disable no-console -- This command emits a compact verification result. */
/**
 * Verify historical chart decks in the isolated production snapshot.
 */

import assert from 'node:assert/strict';

import { chromium } from '@playwright/test';

const baseUrl = process.env.PRESENTER_SNAPSHOT_URL ?? 'http://localhost:8890';
const username = process.env.PRESENTER_SNAPSHOT_ADMIN_USER ?? 'presenter-local';
const password = process.env.PRESENTER_SNAPSHOT_ADMIN_PASSWORD;

if ( ! password ) {
	throw new Error(
		'PRESENTER_SNAPSHOT_ADMIN_PASSWORD is required for private chart verification.'
	);
}

const externalChartHost = /^(?:www\.gstatic\.com|cdnjs\.cloudflare\.com)$/u;
const googleChartDecks = [
	'bsideslv-2018-lessons-learned-by-the-wordpress-security-team',
	'derbycon-2018-lessons-learned-by-the-wordpress-security-team',
	'cyber-security-cloud-expo-na-2018-lessons-learned-by-the-wordpress-security-team',
];

const browser = await chromium.launch( { headless: true } );
const context = await browser.newContext( {
	viewport: { height: 1080, width: 1920 },
} );
const page = await context.newPage();
const externalChartRequests = [];
const chartErrors = [];

page.on( 'request', ( request ) => {
	const host = new URL( request.url() ).hostname;
	if ( externalChartHost.test( host ) ) {
		externalChartRequests.push( request.url() );
	}
} );
page.on( 'console', ( message ) => {
	if (
		message.type() === 'error' &&
		/\b(?:Chart|google|gstatic|cdnjs)\b/iu.test( message.text() )
	) {
		chartErrors.push( message.text() );
	}
} );
page.on( 'pageerror', ( error ) => {
	if ( /\b(?:Chart|google|gstatic|cdnjs)\b/iu.test( error.message ) ) {
		chartErrors.push( error.message );
	}
} );

try {
	const googleUrl = `${ baseUrl }/slideshow/${ googleChartDecks[ 0 ] }/#/wordpress-growth-by-percent`;
	const googleResponse = await page.goto( googleUrl, {
		waitUntil: 'networkidle',
	} );
	assert.equal( googleResponse?.status(), 200 );
	await page.waitForFunction(
		() =>
			document.querySelectorAll( '#chart_percent_div svg' ).length ===
				1 &&
			document.querySelectorAll( '#chart_sites_div svg' ).length === 1
	);

	const initial = await page.evaluate( () => {
		const percent = document.querySelector( '#chart_percent_div' );
		const sites = document.querySelector( '#chart_sites_div' );
		const subtitle = document.querySelector(
			'#wordpress-growth-by-percent p.fragment'
		);

		return {
			compatibilityApi:
				typeof window.google?.visualization?.LineChart === 'function',
			percentDisplay: window.getComputedStyle( percent ).display,
			percentOpacity: window.getComputedStyle( percent ).opacity,
			percentLabels: [ ...percent.querySelectorAll( 'text' ) ].map(
				( element ) => element.textContent
			),
			percentVisibility: window.getComputedStyle( percent ).visibility,
			sitesDisplay: window.getComputedStyle( sites ).display,
			sitesVisibility: window.getComputedStyle( sites ).visibility,
			subtitleVisible: subtitle.classList.contains( 'visible' ),
		};
	} );

	assert.equal( initial.compatibilityApi, true );
	assert.equal( initial.percentDisplay, 'block' );
	assert.equal( initial.percentOpacity, '1' );
	assert.equal( initial.percentVisibility, 'visible' );
	assert.ok( initial.percentLabels.includes( '40' ) );
	assert.equal( initial.sitesDisplay, 'block' );
	assert.equal( initial.sitesVisibility, 'hidden' );
	assert.equal( initial.subtitleVisible, false );

	await page.evaluate( () => window.Reveal.nextFragment() );
	await page.waitForFunction( () => {
		const sites = document.querySelector( '#chart_sites_div' );
		return (
			document
				.querySelector( '#wordpress-growth-by-percent p.fragment' )
				.classList.contains( 'visible' ) &&
			window.getComputedStyle( sites ).visibility === 'visible' &&
			window.getComputedStyle( sites ).opacity === '1'
		);
	} );

	const advanced = await page.evaluate( () => {
		const percent = document.querySelector( '#chart_percent_div' );
		const sites = document.querySelector( '#chart_sites_div' );

		return {
			hash: window.location.hash,
			percentDisplay: window.getComputedStyle( percent ).display,
			sitesDisplay: window.getComputedStyle( sites ).display,
			sitesOpacity: window.getComputedStyle( sites ).opacity,
			sitesLabels: [ ...sites.querySelectorAll( 'text' ) ].map(
				( element ) => element.textContent
			),
			sitesVisibility: window.getComputedStyle( sites ).visibility,
		};
	} );

	assert.match( advanced.hash, /\/0$/u );
	assert.equal( advanced.percentDisplay, 'none' );
	assert.equal( advanced.sitesDisplay, 'block' );
	assert.equal( advanced.sitesOpacity, '1' );
	assert.equal( advanced.sitesVisibility, 'visible' );
	assert.ok( advanced.sitesLabels.includes( '600,000,000' ) );

	for ( const slug of googleChartDecks.slice( 1 ) ) {
		const response = await page.goto(
			`${ baseUrl }/slideshow/${ slug }/#/wordpress-growth-by-percent`,
			{ waitUntil: 'networkidle' }
		);
		assert.equal( response?.status(), 200 );
		await page.waitForFunction(
			() =>
				document.querySelectorAll( '#chart_percent_div svg' ).length ===
					1 &&
				document.querySelectorAll( '#chart_sites_div svg' ).length === 1
		);
		assert.deepEqual(
			await page.evaluate( () => {
				const percent = document.querySelector( '#chart_percent_div' );
				return {
					opacity: window.getComputedStyle( percent ).opacity,
					visibility: window.getComputedStyle( percent ).visibility,
				};
			} ),
			{ opacity: '1', visibility: 'visible' }
		);
	}

	await page.goto( `${ baseUrl }/wp-login.php`, {
		waitUntil: 'networkidle',
	} );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'networkidle' } ),
		page.locator( '#wp-submit' ).click(),
	] );

	const chartJsUrl = `${ baseUrl }/?post_type=slideshow&p=2265&preview=true#/wp-marketshare-yearly`;
	const chartJsResponse = await page.goto( chartJsUrl, {
		waitUntil: 'networkidle',
	} );
	assert.equal( chartJsResponse?.status(), 200 );
	await page.waitForFunction(
		() =>
			window.Chart?.version === '3.5.1' &&
			document.querySelector( '#wp-marketshare-yearly-chart' )?.width > 0
	);

	const chartJs = await page.evaluate( () => {
		const canvas = document.querySelector( '#wp-marketshare-yearly-chart' );
		const script = [ ...document.scripts ].find( ( candidate ) =>
			candidate.src.includes( 'chart.js/3.5.1/chart.min.js' )
		);

		const canvasContext = canvas.getContext( '2d' );
		const pixels = canvasContext?.getImageData(
			0,
			0,
			canvas.width,
			canvas.height
		).data;

		return {
			canvasPainted: pixels
				? pixels.some( ( value ) => value !== 0 )
				: false,
			scriptPath: script ? new URL( script.src ).pathname : null,
			version: window.Chart.version,
		};
	} );

	assert.equal( chartJs.canvasPainted, true );
	assert.equal(
		chartJs.scriptPath,
		'/wp-content/presenter-snapshot-assets/chart.js/3.5.1/chart.min.js'
	);
	assert.equal( chartJs.version, '3.5.1' );
	assert.deepEqual( externalChartRequests, [] );
	assert.deepEqual( chartErrors, [] );

	console.log(
		JSON.stringify(
			{
				chartJs: {
					canvasPainted: true,
					version: chartJs.version,
				},
				externalChartRequests: 0,
				googleCharts: {
					advancedScaleMaximum: '600,000,000',
					decksVerified: googleChartDecks.length,
					initialScaleMaximum: '40',
					swapVerified: true,
				},
				passed: true,
			},
			null,
			2
		)
	);
} finally {
	await context.close();
	await browser.close();
}
