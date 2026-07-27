/* eslint-disable no-console -- This command emits compact local verification. */
/** Verify a converted snapshot deck opens without block validation failures. */

import assert from 'node:assert/strict';

import { chromium } from '@playwright/test';

const baseUrl = process.env.PRESENTER_SNAPSHOT_URL ?? 'http://localhost:8890';
const username = process.env.PRESENTER_SNAPSHOT_ADMIN_USER ?? 'presenter-local';
const password = process.env.PRESENTER_SNAPSHOT_ADMIN_PASSWORD;
const postId = Number.parseInt(
	process.env.PRESENTER_SNAPSHOT_NATIVE_POST_ID ?? '1952',
	10
);

if ( ! password ) {
	throw new Error( 'PRESENTER_SNAPSHOT_ADMIN_PASSWORD is required.' );
}

const browser = await chromium.launch( { headless: true } );
const page = await browser.newPage( {
	viewport: { height: 1080, width: 1920 },
} );
const validationErrors = [];

page.on( 'console', ( message ) => {
	if (
		/block validation|unexpected or invalid content/iu.test(
			message.text()
		)
	) {
		validationErrors.push( message.text() );
	}
} );

try {
	await page.goto( `${ baseUrl }/wp-login.php`, {
		waitUntil: 'domcontentloaded',
	} );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /\/wp-admin\// );
	await page.goto(
		`${ baseUrl }/wp-admin/post.php?post=${ postId }&action=edit`,
		{ waitUntil: 'domcontentloaded' }
	);
	await page.waitForFunction( () => {
		const blocks = window.wp?.data
			?.select( 'core/block-editor' )
			?.getBlocks();

		return 1 === blocks?.length && 'presenter/deck' === blocks[ 0 ]?.name;
	} );
	await page.evaluate( () => {
		const deck = window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()[ 0 ];
		const chartSlide = deck.innerBlocks[ 14 ];
		window.wp.data
			.dispatch( 'core/block-editor' )
			.selectBlock( chartSlide.clientId );
	} );
	await page.waitForSelector( 'iframe[name="editor-canvas"]' );
	const editorFrame = page
		.frames()
		.find( ( frame ) => 'editor-canvas' === frame.name() );
	assert.ok( editorFrame );
	await editorFrame.waitForFunction(
		() =>
			2 ===
			document.querySelectorAll( '.presenter-chart-editor canvas' ).length
	);
	await page.waitForTimeout( 500 );
	await editorFrame
		.locator( '.presenter-chart-editor canvas' )
		.first()
		.click( { position: { x: 20, y: 20 } } );
	await page.waitForFunction(
		() =>
			'presenter/chart' ===
			window.wp.data.select( 'core/block-editor' ).getSelectedBlock()
				?.name
	);

	const blockDiagnostics = await page.evaluate( () => {
		const deck = window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()[ 0 ];
		const invalid = [];
		const counts = {};
		const visit = ( blocks, slideNumber = null ) => {
			blocks.forEach( ( block ) => {
				const currentSlide =
					'presenter/slide' === block.name
						? ( slideNumber ?? 0 ) + 1
						: slideNumber;
				counts[ block.name ] = ( counts[ block.name ] ?? 0 ) + 1;
				if ( false === block.isValid ) {
					invalid.push( {
						name: block.name,
						slide: currentSlide,
					} );
				}
				visit( block.innerBlocks, currentSlide );
			} );
		};
		visit( [ deck ] );

		return {
			counts,
			invalid,
			legacyAutoParagraphSlides: deck.innerBlocks.filter(
				( slide ) => true === slide.attributes.legacyAutoParagraph
			).length,
			legacyNotesProcessingSlides: deck.innerBlocks.filter(
				( slide ) => true === slide.attributes.legacyNotesProcessing
			).length,
			selectedBlockName: window.wp.data
				.select( 'core/block-editor' )
				.getSelectedBlock()?.name,
			slideCount: deck.innerBlocks.length,
		};
	} );
	const chartCanvases = await editorFrame.evaluate( () =>
		[
			...document.querySelectorAll( '.presenter-chart-editor canvas' ),
		].map( ( canvas ) => {
			const pixels = canvas
				.getContext( '2d' )
				?.getImageData( 0, 0, canvas.width, canvas.height ).data;

			return {
				height: canvas.height,
				painted: pixels
					? pixels.some( ( value ) => 0 !== value )
					: false,
				width: canvas.width,
			};
		} )
	);
	const alignmentSlideId = await page.evaluate( () => {
		const deck = window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()[ 0 ];
		const slide = deck.innerBlocks[ 9 ];
		window.wp.data
			.dispatch( 'core/block-editor' )
			.selectBlock( slide.clientId );

		return slide.clientId;
	} );
	const alignmentSlide = editorFrame.locator(
		`[data-block="${ alignmentSlideId }"]`
	);
	await alignmentSlide.waitFor();
	const alignmentElements = await alignmentSlide
		.locator( 'h2, p' )
		.evaluateAll( ( elements ) =>
			elements.map( ( element ) => ( {
				tagName: element.tagName,
				text: element.textContent.trim(),
				textAlign: window.getComputedStyle( element ).textAlign,
			} ) )
		);
	const navigator = page.getByRole( 'navigation', {
		name: 'Slide navigator',
		exact: true,
	} );
	if ( 0 === ( await navigator.count() ) ) {
		await page
			.getByRole( 'button', { name: 'Slides', exact: true } )
			.click();
	}
	await navigator.waitFor();
	const alignmentThumbnail = navigator
		.locator( '.presenter-slide-navigator-thumbnail' )
		.nth( 9 );
	await alignmentThumbnail.waitFor();
	const thumbnailIframe = alignmentThumbnail.locator( 'iframe' );
	await thumbnailIframe.waitFor();
	const thumbnailHandle = await thumbnailIframe.elementHandle();
	const thumbnailFrame = await thumbnailHandle?.contentFrame();
	assert.ok( thumbnailFrame );
	await thumbnailFrame.locator( 'h2' ).waitFor();
	const thumbnailAlignmentElements = await thumbnailFrame
		.locator( 'h2, p' )
		.evaluateAll( ( elements ) =>
			elements.map( ( element ) => ( {
				text: element.textContent.trim(),
				textAlign: window.getComputedStyle( element ).textAlign,
			} ) )
		);
	const diagnostics = {
		...blockDiagnostics,
		alignmentElements,
		chartCanvases,
		thumbnailAlignmentElements,
	};

	assert.equal( diagnostics.slideCount, 23 );
	assert.equal( diagnostics.legacyAutoParagraphSlides, 0 );
	assert.equal( diagnostics.legacyNotesProcessingSlides, 23 );
	assert.equal( diagnostics.selectedBlockName, 'presenter/chart' );
	assert.equal( diagnostics.alignmentElements.length, 6 );
	assert.equal(
		diagnostics.alignmentElements.every(
			( element ) => 'center' === element.textAlign
		),
		true
	);
	assert.equal( diagnostics.thumbnailAlignmentElements.length, 6 );
	assert.equal(
		diagnostics.thumbnailAlignmentElements.every(
			( element ) => 'center' === element.textAlign
		),
		true
	);
	assert.equal( diagnostics.chartCanvases.length, 2 );
	assert.equal(
		diagnostics.chartCanvases.every( ( canvas ) => canvas.painted ),
		true
	);
	assert.deepEqual( diagnostics.invalid, [] );
	assert.deepEqual( validationErrors, [] );

	console.log( JSON.stringify( { ...diagnostics, passed: true } ) );
} finally {
	await browser.close();
}
