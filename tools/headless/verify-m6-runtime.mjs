/* eslint-disable no-console -- This command emits content-free verification results. */
/**
 * Verify Milestone 6 behavior through the real local WordPress route.
 */

import assert from 'node:assert/strict';

import { chromium } from '@playwright/test';

const targetUrl =
	process.env.PRESENTER_M6_URL ??
	'http://localhost:8888/?slideshow=presenter-m6-runtime-smoke';
const printUrl = new URL( targetUrl );
printUrl.searchParams.set( 'print-pdf', '' );

const browser = await chromium.launch( { headless: true } );

try {
	const context = await browser.newContext();
	const page = await context.newPage();
	const pageErrors = [];
	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );

	const response = await page.goto( targetUrl, { waitUntil: 'networkidle' } );
	assert.equal( response?.status(), 200 );
	await page.waitForFunction(
		() => window.presenterReveal?.getInstance()?.isReady() === true
	);

	const rendered = await page.evaluate( () => {
		const fragmentSlide = document.querySelector( '#fragment-runtime' );
		const advancedSlide = document.querySelector( '#advanced-runtime' );
		const fragments = [
			...fragmentSlide.querySelectorAll( ':scope > .fragment' ),
		];

		return {
			advanced: {
				autoAnimate: advancedSlide.hasAttribute( 'data-auto-animate' ),
				autoAnimateId: advancedSlide.getAttribute(
					'data-auto-animate-id'
				),
				autoAnimateRestart: advancedSlide.hasAttribute(
					'data-auto-animate-restart'
				),
				backgroundOpacity: advancedSlide.getAttribute(
					'data-background-opacity'
				),
				backgroundPosition: advancedSlide.getAttribute(
					'data-background-position'
				),
				backgroundRepeat: advancedSlide.getAttribute(
					'data-background-repeat'
				),
				backgroundSize: advancedSlide.getAttribute(
					'data-background-size'
				),
				backgroundTransition: advancedSlide.getAttribute(
					'data-background-transition'
				),
			},
			dynamicFragmentIsList: fragments.some(
				( fragment ) => fragment.tagName === 'UL'
			),
			fragmentClasses: fragments.map( ( fragment ) => [
				...fragment.classList,
			] ),
			fragmentCount: fragments.length,
			fragmentIndices: fragments.map( ( fragment ) =>
				fragment.getAttribute( 'data-fragment-index' )
			),
			notesMarkdown: Boolean(
				fragmentSlide.querySelector(
					':scope > aside.notes[data-markdown]'
				)
			),
		};
	} );

	assert.equal( rendered.fragmentCount, 3 );
	assert.deepEqual( rendered.fragmentIndices, [ '0', '0', '1' ] );
	assert.equal( rendered.dynamicFragmentIsList, true );
	assert(
		rendered.fragmentClasses.some( ( classes ) =>
			classes.includes( 'fade-up' )
		)
	);
	assert(
		rendered.fragmentClasses.some( ( classes ) =>
			classes.includes( 'grow' )
		)
	);
	assert(
		rendered.fragmentClasses.some( ( classes ) =>
			classes.includes( 'aaron-pop' )
		)
	);
	assert.equal( rendered.notesMarkdown, true );
	assert.deepEqual( rendered.advanced, {
		autoAnimate: true,
		autoAnimateId: 'm6-sequence',
		autoAnimateRestart: true,
		backgroundOpacity: '0.45',
		backgroundPosition: 'top right',
		backgroundRepeat: 'repeat-x',
		backgroundSize: 'contain',
		backgroundTransition: 'zoom',
	} );

	await page.keyboard.press( 'ArrowRight' );
	await page.waitForFunction(
		() =>
			document.querySelectorAll( '#fragment-runtime > .fragment.visible' )
				.length === 2
	);
	assert.equal(
		await page
			.locator( '#fragment-runtime > .fragment.current-fragment' )
			.count(),
		2,
		'Equal fragment indices must reveal as one shared step.'
	);

	await page.keyboard.press( 'ArrowRight' );
	await page.waitForFunction(
		() =>
			document.querySelectorAll( '#fragment-runtime > .fragment.visible' )
				.length === 3
	);
	assert.equal(
		await page
			.locator( '#fragment-runtime > .fragment.current-fragment' )
			.count(),
		1
	);

	await page.keyboard.press( 'ArrowLeft' );
	await page.waitForFunction(
		() =>
			document.querySelectorAll( '#fragment-runtime > .fragment.visible' )
				.length === 2
	);
	assert.equal(
		await page
			.locator( '#fragment-runtime > .fragment.current-fragment' )
			.count(),
		2,
		'Backward navigation must restore the shared fragment step.'
	);

	await page.evaluate( () => {
		const instance = window.presenterReveal.getInstance();
		window.__presenterM6AutoAnimateEvents = 0;
		instance.on( 'autoanimate', () => {
			window.__presenterM6AutoAnimateEvents += 1;
		} );
		instance.slide( 1 );
		instance.slide( 2 );
	} );
	await page.waitForFunction(
		() => window.__presenterM6AutoAnimateEvents === 1
	);

	await page.evaluate( () =>
		window.presenterReveal.getInstance().slide( 0 )
	);
	const popupPromise = context.waitForEvent( 'page' );
	await page.keyboard.press( 's' );
	const speakerPage = await popupPromise;
	const speakerErrors = [];
	speakerPage.on( 'pageerror', ( error ) =>
		speakerErrors.push( error.message )
	);
	await speakerPage.waitForSelector( '.speaker-controls-notes .value' );
	await speakerPage.waitForFunction( () => {
		const notes = document.querySelector(
			'.speaker-controls-notes .value'
		);
		return (
			document.querySelectorAll(
				'#current-slide iframe, #upcoming-slide iframe'
			).length === 2 &&
			notes?.textContent.includes( 'Milestone 6 speaker notes' )
		);
	} );
	assert.equal(
		await speakerPage.evaluate( () => Boolean( window.opener ) ),
		true
	);
	assert.deepEqual( speakerErrors, [] );
	await speakerPage.close();
	assert.deepEqual( pageErrors, [] );
	await context.close();

	const printContext = await browser.newContext();
	const printPage = await printContext.newPage();
	const printErrors = [];
	printPage.on( 'pageerror', ( error ) => printErrors.push( error.message ) );
	const printResponse = await printPage.goto( printUrl.href, {
		waitUntil: 'networkidle',
	} );
	assert.equal( printResponse?.status(), 200 );
	await printPage.waitForFunction(
		() =>
			window.presenterReveal?.getInstance()?.isReady() === true &&
			document.documentElement.classList.contains( 'print-pdf' )
	);
	const printState = await printPage.evaluate( () => ( {
		allFragmentsInPdfPages: [
			...document.querySelectorAll( '.fragment' ),
		].every( ( fragment ) => fragment.closest( '.pdf-page' ) ),
		authoredSlideCount: new Set(
			[ ...document.querySelectorAll( '.slides > section[id]' ) ].map(
				( slide ) => slide.id
			)
		).size,
		pdfPages: document.querySelectorAll( '.pdf-page' ).length,
		printing:
			document.documentElement.classList.contains( 'print-pdf' ) &&
			new URLSearchParams( window.location.search ).has( 'print-pdf' ),
		visibleFragments:
			document.querySelectorAll( '.fragment.visible' ).length,
	} ) );
	assert.equal( printState.printing, true );
	assert.equal( printState.allFragmentsInPdfPages, true );
	assert( printState.visibleFragments > 0 );
	assert( printState.pdfPages > printState.authoredSlideCount );
	assert.deepEqual( printErrors, [] );
	await printContext.close();

	console.log(
		JSON.stringify(
			{
				autoAnimateEvents: 1,
				fragmentCount: rendered.fragmentCount,
				fragmentSteps: 2,
				passed: true,
				printPages: printState.pdfPages,
				speakerViewConnected: true,
				staticAndDynamicFragments: true,
				targetUrl,
			},
			null,
			2
		)
	);
} finally {
	await browser.close();
}
