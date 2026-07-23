import assert from 'node:assert/strict';
import test from 'node:test';

import { chromium } from '@playwright/test';

import {
	captureCanonicalStructure,
	captureDeckMetadata,
	createStaticResourceBarrier,
	fulfillAssetSubstitution,
	prewarmSlideImages,
} from './capture-rendered-deck.mjs';

test( 'fulfills only exact GET substitutions and records their digest', async () => {
	const fulfilled = [];
	const digest = 'a'.repeat( 64 );
	const makeRoute = ( overrides = {} ) => ( {
		request: () => ( {
			method: () => overrides.method ?? 'GET',
			postData: () => overrides.postData ?? null,
			resourceType: () => overrides.resourceType ?? 'image',
			url: () => 'http://localhost:8890/wp-content/uploads/missing.jpg',
		} ),
		fulfill: async ( options ) => fulfilled.push( options ),
	} );
	const resolver = {
		resolve: () => ( {
			body: Buffer.from( 'image' ),
			entryDigest: digest,
			mimeType: 'image/avif',
			resourceType: 'image',
		} ),
	};
	const applied = new Map();
	assert.equal(
		await fulfillAssetSubstitution( makeRoute(), resolver, applied ),
		true
	);
	assert.deepEqual( [ ...applied ], [ [ digest, 1 ] ] );
	assert.equal(
		fulfilled[ 0 ].headers[ 'x-content-type-options' ],
		'nosniff'
	);

	for ( const overrides of [
		{ method: 'POST' },
		{ postData: 'body' },
		{ resourceType: 'stylesheet' },
	] ) {
		assert.equal(
			await fulfillAssetSubstitution(
				makeRoute( overrides ),
				resolver,
				new Map()
			),
			false
		);
	}
	assert.equal( fulfilled.length, 1 );
} );

const withPage = async ( callback ) => {
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage();
		return await callback( page );
	} finally {
		await browser.close();
	}
};

test( 'canonical DOM removes only Reveal-owned slide and fragment state', () =>
	withPage( async ( page ) => {
		await page.setContent( `
			<div class="reveal"><div class="slides">
				<section class="authored present has-dark-background" hidden aria-hidden="true" data-index-h="0" data-fragment="-1" data-authored="yes" style="color: red; display: none; top: 42px">
					<span class="visible authored-child" aria-hidden="true">Authored accessibility</span>
					<p class="fragment custom visible current-fragment" data-fragment-index="2" data-auto-animate-target="runtime-id" aria-hidden="true">Fragment</p>
				</section>
			</div></div>
		` );

		const [ slide ] = await captureCanonicalStructure( page );
		assert.deepEqual( slide.attributes, {
			class: 'authored',
			'data-authored': 'yes',
			style: 'color: red;',
		} );
		assert.match(
			slide.canonicalHtml,
			/<span aria-hidden="true" class="authored-child visible">/
		);
		assert.match(
			slide.canonicalHtml,
			/<p aria-hidden="true" class="custom fragment" data-fragment-index="2">/
		);
		assert.equal(
			slide.canonicalHtml.includes( 'has-dark-background' ),
			false
		);
		assert.equal( slide.canonicalHtml.includes( 'data-index-h' ), false );
		assert.equal( slide.canonicalHtml.includes( ' hidden' ), false );
	} ) );

test( 'metadata treats Reveal 4 and 6 theme paths and URL options semantically', () =>
	withPage( async ( page ) => {
		await page.setContent( `
			<link id="reveal-css" rel="stylesheet" href="data:text/css,core">
			<link id="reveal-theme-css" rel="stylesheet" href="http://localhost:8890/wp-content/plugins/presenter/reveal.js/dist/theme/black.css?ver=4.3.1">
			<div class="reveal"><div class="slides"><section></section></div></div>
		` );
		await page.evaluate( () => {
			window.Reveal = {
				getConfig: () => ( {
					height: 700,
					history: true,
					hash: false,
					width: 960,
				} ),
			};
		} );
		const legacy = await captureDeckMetadata( page );

		await page.evaluate( () => {
			document.querySelector( '#reveal-theme-css' ).href =
				'http://localhost:8890/wp-content/plugins/presenter/build/reveal/theme/black.css?ver=6.0.1';
			window.Reveal.getConfig = () => ( {
				height: 700,
				history: false,
				hash: true,
				width: 960,
			} );
		} );
		const native = await captureDeckMetadata( page );

		assert.deepEqual( legacy.semanticConfig, { urlNavigation: true } );
		assert.deepEqual( native.semanticConfig, legacy.semanticConfig );
		assert.deepEqual( legacy.themeStylesheets, [
			{ identity: 'builtin:black', media: 'all' },
		] );
		assert.deepEqual( native.themeStylesheets, legacy.themeStylesheets );
	} ) );

test( 'prewarming visits hidden slides, awaits their images, and restores Reveal state', () =>
	withPage( async ( page ) => {
		await page.setContent( `
			<div class="reveal"><div class="slides">
				<section><img src="about:blank" alt="First"></section>
				<section><img src="about:blank" alt="Second"></section>
				<section><img data-src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=" alt="Third"></section>
			</div></div>
		` );
		await page.evaluate( () => {
			const slides = Array.from(
				document.querySelectorAll( '.reveal > .slides > section' )
			);
			const state = { h: 1, v: 0 };
			window.prewarmVisits = [];
			window.Reveal = {
				getCurrentSlide: () => slides[ state.h ],
				getIndices: () => ( { ...state } ),
				slide: ( h, v ) => {
					state.h = h;
					state.v = v ?? 0;
					window.prewarmVisits.push( [ state.h, state.v ] );
					const image = slides[ state.h ].querySelector( 'img' );
					if ( image.dataset.loadStarted ) {
						return;
					}
					image.dataset.loadStarted = 'true';
					setTimeout( () => {
						image.src =
							image.dataset.src ??
							'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="2" height="2"/>';
						image.removeAttribute( 'data-src' );
					}, 40 );
				},
				sync: () => {},
			};
		} );

		const canonicalBefore = await captureCanonicalStructure( page );
		await prewarmSlideImages(
			page,
			[ 0, 1, 2 ].map( ( horizontalIndex ) => ( {
				fragmentCount: 0,
				horizontalIndex,
				verticalIndex: 0,
			} ) ),
			2000
		);

		const result = await page.evaluate( () => ( {
			indices: window.Reveal.getIndices(),
			loaded: Array.from( document.images ).map(
				( image ) => image.complete && image.naturalWidth > 0
			),
			visits: window.prewarmVisits,
		} ) );
		const canonicalAfter = await captureCanonicalStructure( page );
		assert.deepEqual( result.indices, { h: 1, v: 0 } );
		assert.deepEqual( result.loaded, [ true, true, true ] );
		assert.match( canonicalBefore[ 2 ].canonicalHtml, /data-src=/ );
		assert.doesNotMatch( canonicalAfter[ 2 ].canonicalHtml, /data-src=/ );
		assert.deepEqual( result.visits, [
			[ 0, 0 ],
			[ 1, 0 ],
			[ 2, 0 ],
			[ 1, 0 ],
		] );
	} ) );

test( 'prewarming waits for delayed CSS background images on activated slides', () =>
	withPage( async ( page ) => {
		const fulfilled = [];
		await page.route( 'https://capture.test/**', async ( route ) => {
			await new Promise( ( resolve ) => setTimeout( resolve, 75 ) );
			fulfilled.push( route.request().url() );
			await route.fulfill( {
				body: Buffer.from(
					'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
					'base64'
				),
				contentType: 'image/png',
			} );
		} );
		const staticResourceBarrier = createStaticResourceBarrier( page );
		await page.setContent( `
			<style>
				.slides > section { display: none; width: 20px; height: 20px; }
				.slides > section.present { display: block; }
				#first { background-image: url("https://capture.test/first.png"); }
				#second { background-image: url("https://capture.test/second.png"); }
			</style>
			<div class="reveal"><div class="slides">
				<section class="present" id="first"></section>
				<section id="second"></section>
			</div></div>
		` );
		await page.evaluate( () => {
			const slides = Array.from(
				document.querySelectorAll( '.reveal > .slides > section' )
			);
			const state = { h: 0, v: 0 };
			window.Reveal = {
				getCurrentSlide: () => slides[ state.h ],
				getIndices: () => ( { ...state } ),
				slide: ( h, v ) => {
					state.h = h;
					state.v = v ?? 0;
					slides.forEach( ( slide, index ) =>
						slide.classList.toggle( 'present', index === h )
					);
				},
				sync: () => {},
			};
		} );

		await prewarmSlideImages(
			page,
			[ 0, 1 ].map( ( horizontalIndex ) => ( {
				fragmentCount: 0,
				horizontalIndex,
				verticalIndex: 0,
			} ) ),
			2000,
			staticResourceBarrier
		);

		assert.deepEqual( fulfilled.sort(), [
			'https://capture.test/first.png',
			'https://capture.test/second.png',
		] );
		assert.deepEqual(
			await page.evaluate( () => window.Reveal.getIndices() ),
			{
				h: 0,
				v: 0,
			}
		);
	} ) );
