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
	await page.waitForFunction( () => {
		const canvases = [
			...document.querySelectorAll(
				'#wordpress-growth-by-percent .presenter-chart canvas'
			),
		];

		return (
			2 === canvases.length &&
			0 < canvases[ 0 ].width &&
			0 < canvases[ 0 ].height
		);
	} );

	const initial = await page.evaluate( () => {
		const [ percent, sites ] = document.querySelectorAll(
			'#wordpress-growth-by-percent .presenter-chart'
		);
		const subtitle = document.querySelector(
			'#wordpress-growth-by-percent p.fragment'
		);
		const painted = ( figure ) => {
			const canvas = figure.querySelector( 'canvas' );
			const pixels = canvas
				.getContext( '2d' )
				?.getImageData( 0, 0, canvas.width, canvas.height ).data;

			return pixels ? pixels.some( ( value ) => 0 !== value ) : false;
		};
		const containsColor = ( figure, color ) => {
			const canvas = figure.querySelector( 'canvas' );
			const pixels = canvas
				.getContext( '2d' )
				?.getImageData( 0, 0, canvas.width, canvas.height ).data;

			if ( ! pixels ) {
				return false;
			}
			for ( let index = 0; index < pixels.length; index += 4 ) {
				if (
					color.every(
						( channel, offset ) =>
							Math.abs( channel - pixels[ index + offset ] ) <= 2
					)
				) {
					return true;
				}
			}

			return false;
		};

		return {
			percentConfig: JSON.parse( percent.dataset.presenterChart ),
			percentDisplay: window.getComputedStyle( percent ).display,
			percentOpacity: window.getComputedStyle( percent ).opacity,
			percentPainted: painted( percent ),
			percentThemeColorPainted: containsColor(
				percent,
				[ 131, 119, 209 ]
			),
			percentVisibility: window.getComputedStyle( percent ).visibility,
			sitesConfig: JSON.parse( sites.dataset.presenterChart ),
			sitesDisplay: window.getComputedStyle( sites ).display,
			sitesPainted: painted( sites ),
			sitesVisibility: window.getComputedStyle( sites ).visibility,
			subtitleVisible: subtitle.classList.contains( 'visible' ),
		};
	} );

	assert.equal( initial.percentDisplay, 'block' );
	assert.equal( initial.percentOpacity, '1' );
	assert.equal( initial.percentPainted, true );
	assert.equal( initial.percentThemeColorPainted, true );
	assert.equal( initial.percentVisibility, 'visible' );
	assert.equal(
		Math.max( ...initial.percentConfig.rows.map( ( row ) => row[ 1 ] ) ),
		31.4
	);
	assert.equal( initial.sitesDisplay, 'none' );
	assert.equal( initial.sitesPainted, false );
	assert.equal( initial.sitesVisibility, 'hidden' );
	assert.equal(
		Math.max( ...initial.sitesConfig.rows.map( ( row ) => row[ 1 ] ) ),
		596395224.9
	);
	assert.equal( initial.subtitleVisible, false );

	await page.evaluate( () => {
		const reveal = window.presenterReveal?.getInstance?.() ?? window.Reveal;
		if ( ! reveal ) {
			throw new Error( 'Reveal instance is unavailable.' );
		}
		reveal.nextFragment();
	} );
	await page.waitForFunction( () => {
		const sites = document.querySelectorAll(
			'#wordpress-growth-by-percent .presenter-chart'
		)[ 1 ];
		return (
			document
				.querySelector( '#wordpress-growth-by-percent p.fragment' )
				.classList.contains( 'visible' ) &&
			window.getComputedStyle( sites ).visibility === 'visible' &&
			window.getComputedStyle( sites ).opacity === '1' &&
			300 < sites.querySelector( 'canvas' ).width
		);
	} );

	const advanced = await page.evaluate( () => {
		const [ percent, sites ] = document.querySelectorAll(
			'#wordpress-growth-by-percent .presenter-chart'
		);

		return {
			hash: window.location.hash,
			percentDisplay: window.getComputedStyle( percent ).display,
			sitesDisplay: window.getComputedStyle( sites ).display,
			sitesOpacity: window.getComputedStyle( sites ).opacity,
			sitesPainted: ( () => {
				const canvas = sites.querySelector( 'canvas' );
				const pixels = canvas
					.getContext( '2d' )
					?.getImageData( 0, 0, canvas.width, canvas.height ).data;

				return pixels ? pixels.some( ( value ) => 0 !== value ) : false;
			} )(),
			sitesVisibility: window.getComputedStyle( sites ).visibility,
		};
	} );

	assert.match( advanced.hash, /#\/wordpress-growth-by-percent(?:\/0)?$/u );
	assert.equal( advanced.percentDisplay, 'none' );
	assert.equal( advanced.sitesDisplay, 'block' );
	assert.equal( advanced.sitesOpacity, '1' );
	assert.equal( advanced.sitesPainted, true );
	assert.equal( advanced.sitesVisibility, 'visible' );

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
					advancedDataMaximum: 596395224.9,
					decksVerified: googleChartDecks.length,
					initialDataMaximum: 31.4,
					nativeDecksVerified: 1,
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
