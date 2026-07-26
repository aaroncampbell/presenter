import assert from 'node:assert/strict';
import test from 'node:test';

import { chromium } from '@playwright/test';

import {
	activateFragmentState,
	captureCanonicalStructure,
	captureDeckMetadata,
	countUnstabilizedMediaAborts,
	createStaticResourceBarrier,
	fulfillAssetSubstitution,
	fulfillRewrittenTarget,
	installMediaCapturePolicy,
	isVerifiedRewrittenMediaAbort,
	prewarmSlideImages,
	resolveDeckNavigationTarget,
	rewriteDeckDocument,
	shouldCountLocalRequestFailure,
	stabilizeCurrentSlideMedia,
} from './capture-rendered-deck.mjs';

test( 'counts unrelated same-origin media aborts as local request failures', () => {
	const request = ( url, resourceType = 'media' ) => ( {
		failure: () => ( { errorText: 'net::ERR_ABORTED' } ),
		method: () => 'GET',
		postData: () => null,
		resourceType: () => resourceType,
		url: () => url,
	} );
	const resolver = {
		resolveRewrittenTarget: ( url ) =>
			url.endsWith( '/verified.mp4' )
				? {
						body: Buffer.from( 'verified' ),
						entryDigest: 'a'.repeat( 64 ),
						mimeType: 'video/mp4',
						resourceType: 'media',
				  }
				: null,
	};
	assert.equal(
		isVerifiedRewrittenMediaAbort(
			request( 'http://localhost:8890/verified.mp4' ),
			resolver
		),
		true
	);
	assert.equal(
		isVerifiedRewrittenMediaAbort(
			request( 'http://localhost:8890/unrelated.mp4' ),
			resolver
		),
		false
	);
	assert.equal(
		countUnstabilizedMediaAborts(
			[ 'http://localhost:8890/verified.mp4' ],
			new Set()
		),
		1
	);
	assert.equal(
		countUnstabilizedMediaAborts(
			[ 'http://localhost:8890/verified.mp4' ],
			new Set( [ 'http://localhost:8890/verified.mp4' ] )
		),
		0
	);
	assert.equal(
		isVerifiedRewrittenMediaAbort(
			request( 'http://localhost:8890/verified.mp4', 'image' ),
			resolver
		),
		false
	);
	assert.equal(
		shouldCountLocalRequestFailure(
			request( 'http://localhost:8890/audio.mp3' ),
			'http://localhost:8890'
		),
		true
	);
	assert.equal(
		shouldCountLocalRequestFailure(
			request( 'https://media.example.test/audio.mp3' ),
			'http://localhost:8890'
		),
		false
	);
} );

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

test( 'rewrites only the exact deck document and records logical substitutions', async () => {
	const applied = new Map();
	const digest = 'b'.repeat( 64 );
	const fetchOptions = [];
	const response = {
		headers: () => ( {} ),
		status: () => 200,
		text: async () => '<section>Original</section>',
	};
	const fulfilled = [];
	const route = {
		fetch: async ( options ) => {
			fetchOptions.push( options );
			return response;
		},
		fulfill: async ( options ) => fulfilled.push( options ),
		request: () => ( {
			method: () => 'GET',
			postData: () => null,
			resourceType: () => 'document',
			url: () => 'http://localhost:8890/?p=1322',
		} ),
	};
	const resolver = {
		rewriteDocument: ( html ) => ( {
			html: html.replace( 'Original', 'Rewritten' ),
			substitutions: [ { entryDigest: digest, count: 2 } ],
		} ),
	};

	assert.equal(
		await rewriteDeckDocument(
			route,
			resolver,
			'http://localhost:8890/?p=1322',
			applied
		),
		true
	);
	assert.deepEqual( [ ...applied ], [ [ digest, 2 ] ] );
	assert.equal( fulfilled[ 0 ].body, '<section>Rewritten</section>' );
	assert.equal( fulfilled[ 0 ].response, response );
	assert.deepEqual( fetchOptions, [ { maxRedirects: 0 } ] );

	assert.equal(
		await rewriteDeckDocument(
			{
				...route,
				request: () => ( {
					...route.request(),
					resourceType: () => 'image',
				} ),
			},
			resolver,
			'http://localhost:8890/?p=1322',
			new Map()
		),
		false
	);
} );

test( 'deck document rewriting fails closed without following external redirects', async () => {
	const resolver = {
		rewriteDocument: () => {
			assert.fail( 'redirect body must not be rewritten' );
		},
	};
	let fetchOptions;
	const route = {
		fetch: async ( options ) => {
			fetchOptions = options;
			return {
				headers: () => ( {
					location: 'https://external.example.test/deck',
				} ),
				status: () => 302,
				text: async () => 'external redirect body',
			};
		},
		fulfill: async () => assert.fail( 'redirect must not be fulfilled' ),
		request: () => ( {
			method: () => 'GET',
			postData: () => null,
			resourceType: () => 'document',
			url: () => 'http://localhost:8890/?p=1322',
		} ),
	};
	await assert.rejects(
		rewriteDeckDocument(
			route,
			resolver,
			'http://localhost:8890/?p=1322',
			new Map()
		),
		( error ) => error.code === 'deck-document-redirect'
	);
	assert.deepEqual( fetchOptions, { maxRedirects: 0 } );
} );

test( 'resolves one same-origin canonical navigation without collapsing browser URL semantics', async () => {
	const calls = [];
	const responses = [
		{
			headers: () => ( {
				location: 'http://localhost:8890/slideshow/deck/',
			} ),
			status: () => 301,
		},
		{ headers: () => ( {} ), status: () => 200 },
	];
	const requestContext = {
		get: async ( url, options ) => {
			calls.push( { options, url } );
			const response = responses.shift();
			return { ...response, dispose: async () => {} };
		},
	};
	assert.equal(
		await resolveDeckNavigationTarget(
			requestContext,
			'http://localhost:8890/?p=1322',
			'http://localhost:8890',
			2000
		),
		'http://localhost:8890/slideshow/deck/'
	);
	assert.deepEqual(
		calls.map( ( call ) => call.url ),
		[
			'http://localhost:8890/?p=1322',
			'http://localhost:8890/slideshow/deck/',
		]
	);
	assert.ok(
		calls.every(
			( call ) =>
				call.options.maxRedirects === 0 &&
				call.options.failOnStatusCode === false
		)
	);
} );

test( 'canonical navigation rejects external and chained redirects', async () => {
	const response = ( status, location ) => ( {
		dispose: async () => {},
		headers: () => ( { location } ),
		status: () => status,
	} );
	await assert.rejects(
		resolveDeckNavigationTarget(
			{
				get: async () =>
					response( 302, 'https://external.example.test/deck/' ),
			},
			'http://localhost:8890/?p=1322',
			'http://localhost:8890',
			2000
		),
		( error ) => error.code === 'deck-document-redirect'
	);
	let call = 0;
	await assert.rejects(
		resolveDeckNavigationTarget(
			{
				get: async () =>
					++call === 1
						? response(
								301,
								'http://localhost:8890/slideshow/deck/'
						  )
						: response(
								302,
								'http://localhost:8890/slideshow/other/'
						  ),
			},
			'http://localhost:8890/?p=1322',
			'http://localhost:8890',
			2000
		),
		( error ) => error.code === 'deck-document-redirect'
	);
} );

test( 'serves verified rewritten targets with bounded ranges without changing evidence', async () => {
	const verifiedBody = Buffer.from( 'verified-media-bytes' );
	const digest = 'c'.repeat( 64 );
	const fulfilled = [];
	const makeRoute = ( range ) => ( {
		fulfill: async ( options ) => fulfilled.push( options ),
		request: () => ( {
			headers: () => ( range ? { range } : {} ),
			method: () => 'GET',
			postData: () => null,
			resourceType: () => 'media',
			url: () => 'http://localhost:8890/uploads/movie.mp4',
		} ),
	} );
	const resolver = {
		resolveRewrittenTarget: () => ( {
			body: Buffer.from( verifiedBody ),
			entryDigest: digest,
			mimeType: 'video/mp4',
			resourceType: 'media',
		} ),
	};
	const logicalEvidence = new Map( [ [ digest, 1 ] ] );

	assert.equal( await fulfillRewrittenTarget( makeRoute(), resolver ), true );
	assert.equal(
		await fulfillRewrittenTarget( makeRoute( 'bytes=2-7' ), resolver ),
		true
	);
	assert.equal(
		await fulfillRewrittenTarget( makeRoute( 'bytes=-4' ), resolver ),
		true
	);
	assert.equal(
		await fulfillRewrittenTarget( makeRoute( 'bytes=999-1000' ), resolver ),
		true
	);

	assert.equal( fulfilled[ 0 ].status, 200 );
	assert.deepEqual( fulfilled[ 0 ].body, verifiedBody );
	assert.equal( fulfilled[ 1 ].status, 206 );
	assert.deepEqual( fulfilled[ 1 ].body, verifiedBody.subarray( 2, 8 ) );
	assert.equal(
		fulfilled[ 1 ].headers[ 'content-range' ],
		`bytes 2-7/${ verifiedBody.byteLength }`
	);
	assert.equal( fulfilled[ 2 ].status, 206 );
	assert.deepEqual( fulfilled[ 2 ].body, verifiedBody.subarray( -4 ) );
	assert.equal( fulfilled[ 3 ].status, 416 );
	assert.equal(
		fulfilled[ 3 ].headers[ 'content-range' ],
		`bytes */${ verifiedBody.byteLength }`
	);
	assert.equal( fulfilled[ 3 ].headers[ 'content-length' ], '0' );
	assert.equal( fulfilled[ 3 ].contentType, 'video/mp4' );
	assert.deepEqual( [ ...logicalEvidence ], [ [ digest, 1 ] ] );

	await assert.rejects(
		fulfillRewrittenTarget(
			{
				...makeRoute(),
				request: () => ( {
					...makeRoute().request(),
					resourceType: () => 'fetch',
				} ),
			},
			resolver
		),
		( error ) => error.code === 'invalid-rewritten-target-request'
	);
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

test( 'capture media policy handles play AbortErrors', () =>
	withPage( async ( page ) => {
		await page.addInitScript( () => {
			window.capturePolicyCatchCount = 0;
			window.HTMLMediaElement.prototype.play = () => {
				const result = Promise.reject(
					new DOMException( 'interrupted', 'AbortError' )
				);
				const nativeCatch = result.catch.bind( result );
				result.catch = ( ...args ) => {
					window.capturePolicyCatchCount++;
					return nativeCatch( ...args );
				};
				return result;
			};
		} );
		await installMediaCapturePolicy( page, [
			'http://localhost:8890/video.mp4',
		] );
		await page.goto(
			'data:text/html,<div class="slide-background"><video><source src="http://localhost:8890/video.mp4"></video></div>'
		);
		assert.deepEqual(
			await page.evaluate( async () => {
				const result = await document
					.querySelector( 'video' )
					.play()
					.then(
						() => 'fulfilled',
						( error ) => error.name
					);
				return {
					catchCount: window.capturePolicyCatchCount,
					result,
				};
			} ),
			{ catchCount: 1, result: 'AbortError' }
		);
		assert.deepEqual(
			await page.evaluate( async () => {
				const video = document.createElement( 'video' );
				video.innerHTML =
					'<source src="http://localhost:8890/video.mp4">';
				document.body.appendChild( video );
				const result = await video.play().then(
					() => 'fulfilled',
					( error ) => error.name
				);
				return {
					catchCount: window.capturePolicyCatchCount,
					result,
				};
			} ),
			{ catchCount: 1, result: 'AbortError' }
		);
	} ) );

test( 'capture media policy preserves non-abort play failures', () =>
	withPage( async ( page ) => {
		await page.addInitScript( () => {
			window.HTMLMediaElement.prototype.play = () =>
				Promise.reject(
					new DOMException( 'not allowed', 'NotAllowedError' )
				);
		} );
		await installMediaCapturePolicy( page, [
			'http://localhost:8890/video.mp4',
		] );
		await page.goto( 'data:text/html,<video></video>' );
		await assert.rejects(
			page.evaluate( () => document.querySelector( 'video' ).play() ),
			/NotAllowedError|not allowed/u
		);
	} ) );

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

test( 'canonical DOM treats exact WordPress emoji polyfills as their authored text', () =>
	withPage( async ( page ) => {
		await page.setContent( `
			<div class="reveal"><div class="slides">
				<section>
					<p class="fragment">Before <img alt="💪" class="emoji" draggable="false" role="img" src="https://s.w.org/images/core/emoji/17.0.2/svg/1f4aa.svg"> after</p>
					<img alt="Authored" class="emoji" src="/authored.svg">
					<img alt="Classed" class="emoji authored" draggable="false" role="img" src="https://s.w.org/images/core/emoji/17.0.2/svg/1f4aa.svg">
					<img alt="Attributed" class="emoji" data-authored="yes" draggable="false" role="img" src="https://s.w.org/images/core/emoji/17.0.2/svg/1f4aa.svg">
				</section>
			</div></div>
		` );

		const [ slide ] = await captureCanonicalStructure( page );
		assert.match(
			slide.canonicalHtml,
			/<p class="fragment">Before 💪 after<\/p>/u
		);
		assert.match(
			slide.canonicalHtml,
			/<img alt="Authored" class="emoji" src="\/authored\.svg">/u
		);
		assert.match(
			slide.canonicalHtml,
			/<img alt="Classed" class="authored emoji" draggable="false" role="img" src="https:\/\/s\.w\.org\/images\/core\/emoji\/17\.0\.2\/svg\/1f4aa\.svg">/u
		);
		assert.match(
			slide.canonicalHtml,
			/<img alt="Attributed" class="emoji" data-authored="yes" draggable="false" role="img" src="https:\/\/s\.w\.org\/images\/core\/emoji\/17\.0\.2\/svg\/1f4aa\.svg">/u
		);
		assert.match( slide.fragments[ 0 ].canonicalHtml, /Before 💪 after/u );
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
			window.prewarmSyncCalls = 0;
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
				sync: () => window.prewarmSyncCalls++,
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
			syncCalls: window.prewarmSyncCalls,
		} ) );
		const canonicalAfter = await captureCanonicalStructure( page );
		assert.deepEqual( result.indices, { h: 1, v: 0 } );
		assert.equal( result.syncCalls, 0 );
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

test( 'fragment activation preserves Reveal viewport state across initial and final frames', () =>
	withPage( async ( page ) => {
		await page.setContent( `
			<div class="reveal state-active"><div class="slides">
				<section class="present">
					<span class="fragment">One</span>
					<span class="fragment">Two</span>
				</section>
			</div></div>
		` );
		await page.evaluate( () => {
			const reveal = document.querySelector( '.reveal' );
			const slide = document.querySelector( 'section' );
			const fragments = Array.from(
				slide.querySelectorAll( '.fragment' )
			);
			const indices = { f: -1, h: 0, v: 0 };
			let layoutCalls = 0;
			let syncCalls = 0;
			window.Reveal = {
				getCurrentSlide: () => slide,
				getIndices: () => ( { ...indices } ),
				slide: ( h, v, f = indices.f ) => {
					if ( h === indices.h && v === indices.v && syncCalls > 0 ) {
						reveal.classList.remove( 'state-active' );
					}
					indices.h = h;
					indices.v = v;
					indices.f = f;
					fragments.forEach( ( fragment, index ) =>
						fragment.classList.toggle( 'visible', index <= f )
					);
				},
				layout: () => layoutCalls++,
				sync: () => syncCalls++,
			};
			window.fragmentLayoutCalls = () => layoutCalls;
			window.fragmentSyncCalls = () => syncCalls;
		} );
		const slide = {
			fragmentCount: 2,
			horizontalIndex: 0,
			verticalIndex: 0,
		};

		await activateFragmentState( page, slide, 'initial', 2000 );
		await activateFragmentState( page, slide, 'final', 2000 );

		assert.deepEqual(
			await page.evaluate( () => ( {
				layoutCalls: window.fragmentLayoutCalls(),
				stateActive: document
					.querySelector( '.reveal' )
					.classList.contains( 'state-active' ),
				syncCalls: window.fragmentSyncCalls(),
			} ) ),
			{
				layoutCalls: 0,
				stateActive: true,
				syncCalls: 0,
			}
		);
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

test( 'media stabilization waits for the current background and freezes frame zero', () =>
	withPage( async ( page ) => {
		await page.setContent( `
			<div class="reveal"><div class="slides">
				<section class="present" data-background-video="video.mp4"></section>
			</div></div>
			<div class="slide-background present"><video></video></div>
		` );
		await page.evaluate( () => {
			const slide = document.querySelector( '.slides > section' );
			const video = document.querySelector( 'video' );
			let currentTime = 1.25;
			let paused = false;
			Object.defineProperties( video, {
				currentSrc: { value: 'http://localhost:8890/video.mp4' },
				currentTime: {
					configurable: true,
					get: () => currentTime,
					set: ( value ) => {
						currentTime = value;
						setTimeout(
							() => video.dispatchEvent( new Event( 'seeked' ) ),
							0
						);
					},
				},
				error: { value: null },
				paused: {
					configurable: true,
					get: () => paused,
				},
				readyState: { value: 2 },
				videoHeight: { value: 360 },
				videoWidth: { value: 480 },
			} );
			video.pause = () => {
				paused = true;
			};
			video.play = async () => {};
			window.Reveal = {
				getCurrentSlide: () => slide,
			};
		} );

		await stabilizeCurrentSlideMedia( page, 2000 );

		assert.deepEqual(
			await page.evaluate( () => ( {
				currentTime: document.querySelector( 'video' ).currentTime,
				paused: document.querySelector( 'video' ).paused,
			} ) ),
			{ currentTime: 0, paused: true }
		);
	} ) );

test( 'media stabilization awaits an existing seek at frame zero', () =>
	withPage( async ( page ) => {
		await page.setContent( `
			<div class="reveal"><div class="slides">
				<section class="present" data-background-video="video.mp4"></section>
			</div></div>
			<div class="slide-background present"><video></video></div>
		` );
		await page.evaluate( () => {
			const slide = document.querySelector( '.slides > section' );
			const video = document.querySelector( 'video' );
			let seeking = true;
			Object.defineProperties( video, {
				currentSrc: { value: 'http://localhost:8890/video.mp4' },
				currentTime: { value: 0 },
				error: { value: null },
				paused: { value: true },
				readyState: { value: 2 },
				seeking: { get: () => seeking },
				videoHeight: { value: 360 },
				videoWidth: { value: 480 },
			} );
			video.pause = () => {};
			window.existingSeekCompleted = false;
			setTimeout( () => {
				seeking = false;
				window.existingSeekCompleted = true;
				video.dispatchEvent( new Event( 'seeked' ) );
			}, 75 );
			window.Reveal = {
				getCurrentSlide: () => slide,
			};
		} );

		await stabilizeCurrentSlideMedia( page, 2000 );

		assert.equal(
			await page.evaluate( () => window.existingSeekCompleted ),
			true
		);
	} ) );
