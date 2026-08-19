/* eslint-disable no-console -- This local command reports conversion diagnostics. */
/** Convert one local snapshot deck's legacy HTML islands through the editor. */

import { chromium } from '@playwright/test';

import { dismissEditorWelcome } from './dismiss-editor-welcome.mjs';

const baseUrl = process.env.PRESENTER_SNAPSHOT_URL ?? 'http://localhost:8890';
const username = process.env.PRESENTER_SNAPSHOT_ADMIN_USER ?? 'presenter-local';
const password = process.env.PRESENTER_SNAPSHOT_ADMIN_PASSWORD;
const postId = Number.parseInt(
	process.env.PRESENTER_SNAPSHOT_NATIVE_POST_ID ?? '1952',
	10
);
const origin = new URL( baseUrl );

if (
	'http:' !== origin.protocol ||
	! [ 'localhost', '127.0.0.1' ].includes( origin.hostname )
) {
	throw new Error( 'Native conversion is restricted to local HTTP.' );
}
if ( ! password ) {
	throw new Error( 'PRESENTER_SNAPSHOT_ADMIN_PASSWORD is required.' );
}
if ( ! Number.isInteger( postId ) || postId < 1 ) {
	throw new Error( 'PRESENTER_SNAPSHOT_NATIVE_POST_ID must be positive.' );
}

const browser = await chromium.launch( { headless: true } );
const page = await browser.newPage();

try {
	await page.goto( new URL( '/wp-login.php', origin ).href, {
		waitUntil: 'domcontentloaded',
	} );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /\/wp-admin\// );

	await page.goto(
		new URL( `/wp-admin/post.php?post=${ postId }&action=edit`, origin )
			.href,
		{ waitUntil: 'domcontentloaded' }
	);
	await page.waitForFunction( () => {
		const blocks = window.wp?.data
			?.select( 'core/block-editor' )
			?.getBlocks();

		return (
			typeof window.wp?.blocks?.getBlockContent === 'function' &&
			1 === blocks?.length &&
			'presenter/deck' === blocks[ 0 ]?.name
		);
	} );
	await dismissEditorWelcome( page );

	const before = await page.evaluate( () => {
		const store = window.wp.data.select( 'core/block-editor' );
		const deck = store.getBlocks()[ 0 ];
		window.wp.data
			.dispatch( 'core/block-editor' )
			.selectBlock( deck.clientId );
		const legacyHtmlSlides = deck.innerBlocks.filter(
			( slide ) =>
				1 === slide.innerBlocks.length &&
				'core/html' === slide.innerBlocks[ 0 ].name
		);

		return {
			legacyHtmlSlides: legacyHtmlSlides.length,
			nonEmptyLegacyHtmlSlides: legacyHtmlSlides.filter( ( slide ) =>
				window.wp.blocks
					.getBlockContent( slide.innerBlocks[ 0 ] )
					.trim()
			).length,
		};
	} );

	const convert = page.getByRole( 'button', {
		name: /Convert \d+ legacy slides to blocks/,
	} );
	await convert.waitFor();
	await convert.click();
	await page.waitForTimeout( 1000 );

	const getDiagnostics = () =>
		page.evaluate( () => {
			const deck = window.wp.data
				.select( 'core/block-editor' )
				.getBlocks()[ 0 ];
			const countNames = ( blocks, counts = {} ) => {
				blocks.forEach( ( block ) => {
					counts[ block.name ] = ( counts[ block.name ] ?? 0 ) + 1;
					countNames( block.innerBlocks, counts );
				} );

				return counts;
			};

			return {
				blockCounts: countNames( deck.innerBlocks ),
				contentBlockCount: Object.entries(
					countNames( deck.innerBlocks )
				)
					.filter(
						( [ name ] ) =>
							! [
								'presenter/deck',
								'presenter/slide',
								'presenter/stack',
							].includes( name )
					)
					.reduce( ( total, [ , count ] ) => total + count, 0 ),
				legacyAutoParagraphSlides: deck.innerBlocks.filter(
					( slide ) => true === slide.attributes.legacyAutoParagraph
				).length,
				legacyNotesProcessingSlides: deck.innerBlocks.filter(
					( slide ) => true === slide.attributes.legacyNotesProcessing
				).length,
				slideCount: deck.innerBlocks.length,
				remainingLegacySlides: deck.innerBlocks
					.map( ( slide, index ) => ( {
						index: index + 1,
						legacyAutoParagraph:
							slide.attributes.legacyAutoParagraph,
						name: slide.name,
					} ) )
					.filter( ( slide ) => true === slide.legacyAutoParagraph ),
			};
		} );
	const diagnostics = await getDiagnostics();
	if ( 0 < diagnostics.remainingLegacySlides.length ) {
		throw new Error(
			`Legacy slides remain after conversion: ${ JSON.stringify(
				diagnostics.remainingLegacySlides
			) }`
		);
	}
	if ( diagnostics.contentBlockCount < before.nonEmptyLegacyHtmlSlides ) {
		throw new Error(
			`Conversion discarded authored content: ${ diagnostics.contentBlockCount } content blocks remain for ${ before.nonEmptyLegacyHtmlSlides } non-empty legacy Slides.`
		);
	}

	await page.evaluate( async () => {
		await window.wp.data.dispatch( 'core/editor' ).savePost();
	} );
	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => {
		const blocks = window.wp?.data
			?.select( 'core/block-editor' )
			?.getBlocks();

		return 1 === blocks?.length && 'presenter/deck' === blocks[ 0 ]?.name;
	} );
	const persistedDiagnostics = await getDiagnostics();
	if (
		persistedDiagnostics.contentBlockCount !==
			diagnostics.contentBlockCount ||
		0 < persistedDiagnostics.remainingLegacySlides.length
	) {
		throw new Error(
			`Converted content did not survive save and reload: ${ JSON.stringify(
				persistedDiagnostics
			) }`
		);
	}

	console.log(
		JSON.stringify( {
			beforeLegacyHtmlSlides: before.legacyHtmlSlides,
			nonEmptyLegacyHtmlSlides: before.nonEmptyLegacyHtmlSlides,
			postId,
			...diagnostics,
			persistedContentBlockCount: persistedDiagnostics.contentBlockCount,
		} )
	);
} finally {
	await browser.close();
}
