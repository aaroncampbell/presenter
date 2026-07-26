/* eslint-disable no-console -- This command emits content-free verification results. */
/**
 * Verify that a migrated snapshot deck opens as editable native content.
 */

import { chromium } from '@playwright/test';

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
	throw new Error(
		'Snapshot native-editor verification is restricted to local HTTP.'
	);
}

if ( ! password ) {
	throw new Error( 'PRESENTER_SNAPSHOT_ADMIN_PASSWORD is required.' );
}

if ( ! Number.isInteger( postId ) || postId <= 0 ) {
	throw new Error( 'PRESENTER_SNAPSHOT_NATIVE_POST_ID must be positive.' );
}

const browser = await chromium.launch( { headless: true } );
const context = await browser.newContext();
const page = await context.newPage();

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

		return Array.isArray( blocks ) && blocks.length > 0;
	} );

	const result = await page.evaluate( () => {
		const blockEditor = window.wp.data.select( 'core/block-editor' );
		const blocks = blockEditor.getBlocks();
		const deck = blocks[ 0 ];
		const invalidBlocks = [];
		const missingBlocks = [];
		const inspect = ( block ) => {
			if ( false === block.isValid ) {
				invalidBlocks.push( block.name );
			}
			if ( 'core/missing' === block.name ) {
				missingBlocks.push( block.name );
			}
			block.innerBlocks.forEach( inspect );
		};
		blocks.forEach( inspect );

		return {
			deckInnerTemplateLock: blockEditor.getTemplateLock( deck.clientId ),
			invalidBlocks,
			legacyMetaBoxCount: document.querySelectorAll( '#slides' ).length,
			missingBlocks,
			postId: window.wp.data.select( 'core/editor' ).getCurrentPostId(),
			rootNames: blocks.map( ( block ) => block.name ),
			rootTemplateLock: blockEditor.getTemplateLock(),
			slideCount: deck.innerBlocks.length,
			templateMismatchWarning: document.body.innerText.includes(
				'The content of your post doesn’t match the template assigned to your post type.'
			),
		};
	} );

	const passed =
		postId === result.postId &&
		1 === result.rootNames.length &&
		'presenter/deck' === result.rootNames[ 0 ] &&
		1 < result.slideCount &&
		'all' === result.rootTemplateLock &&
		false === result.deckInnerTemplateLock &&
		0 === result.invalidBlocks.length &&
		0 === result.missingBlocks.length &&
		0 === result.legacyMetaBoxCount &&
		! result.templateMismatchWarning;

	console.log( JSON.stringify( { ...result, passed }, null, 2 ) );

	if ( ! passed ) {
		process.exitCode = 1;
	}
} finally {
	await browser.close();
}
