/* eslint-disable no-console -- This command emits content-free verification results. */
/**
 * Verify that a snapshot legacy deck opens in its per-slide classic editor.
 */

import { chromium } from '@playwright/test';

const baseUrl = process.env.PRESENTER_SNAPSHOT_URL ?? 'http://localhost:8890';
const username = process.env.PRESENTER_SNAPSHOT_ADMIN_USER ?? 'presenter-local';
const password = process.env.PRESENTER_SNAPSHOT_ADMIN_PASSWORD;
const migrationAdminPageSlug = 'presenter-migration';
const postId = Number.parseInt(
	process.env.PRESENTER_SNAPSHOT_LEGACY_POST_ID ?? '67',
	10
);
const origin = new URL( baseUrl );

if (
	'http:' !== origin.protocol ||
	! [ 'localhost', '127.0.0.1' ].includes( origin.hostname )
) {
	throw new Error(
		'Legacy editor verification is restricted to local HTTP.'
	);
}

if ( ! password ) {
	throw new Error( 'PRESENTER_SNAPSHOT_ADMIN_PASSWORD is required.' );
}

if ( ! Number.isInteger( postId ) || postId <= 0 ) {
	throw new Error( 'PRESENTER_SNAPSHOT_LEGACY_POST_ID must be positive.' );
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
	await page.locator( '#slides' ).waitFor();
	const reviewLink = page.getByRole( 'link', {
		name: 'Review upgrade',
		exact: true,
	} );
	await reviewLink.waitFor();

	const editor = await page.evaluate( () => ( {
		blockEditorShellCount:
			document.querySelectorAll( '.block-editor' ).length,
		legacyEditorScriptCount: document.querySelectorAll(
			'script[src*="edit-slide-admin.js"]'
		).length,
		legacySlidesMetaBoxCount: document.querySelectorAll( '#slides' ).length,
		legacyUpgradeNoticeCount: document.querySelectorAll(
			'.presenter-legacy-upgrade-notice'
		).length,
		mismatchWarningCount: [
			...document.querySelectorAll( 'body *' ),
		].filter( ( element ) =>
			/The content of your post doesn.t match the template assigned to your post type\./.test(
				element.textContent ?? ''
			)
		).length,
		perSlideEditorCount: document.querySelectorAll(
			'#slides textarea[name^="slide-content["]'
		).length,
		postId: Number.parseInt(
			document.querySelector( '#post_ID' )?.value ?? '0',
			10
		),
	} ) );
	const reviewUrl = new URL( await reviewLink.getAttribute( 'href' ) );
	await reviewLink.click();
	await page.waitForURL( ( url ) =>
		url.pathname.endsWith( '/wp-admin/tools.php' )
	);
	await page
		.locator( `input[name="post_id"][value="${ postId }"]` )
		.waitFor( { state: 'attached' } );
	const review = await page.evaluate( () => ( {
		actionValues: [
			...document.querySelectorAll( 'tbody input[name="action"]' ),
		].map( ( input ) => input.value ),
		applyBatchCount: document.querySelectorAll(
			'[data-presenter-apply-batch]'
		).length,
		focusedPostFormCount: document.querySelectorAll(
			'tbody input[name="post_id"]'
		).length,
		prepareBatchCount: document.querySelectorAll(
			'[data-presenter-prepare-batch]'
		).length,
		rowCount: document.querySelectorAll( 'tbody tr' ).length,
		viewAllLinkCount: [ ...document.querySelectorAll( 'a' ) ].filter(
			( link ) =>
				'View all legacy slideshows' === link.textContent?.trim()
		).length,
	} ) );

	const passed =
		postId === editor.postId &&
		0 === editor.blockEditorShellCount &&
		1 === editor.legacyEditorScriptCount &&
		1 === editor.legacySlidesMetaBoxCount &&
		1 === editor.legacyUpgradeNoticeCount &&
		0 === editor.mismatchWarningCount &&
		0 < editor.perSlideEditorCount &&
		migrationAdminPageSlug === reviewUrl.searchParams.get( 'page' ) &&
		String( postId ) === reviewUrl.searchParams.get( 'presenter-post' ) &&
		0 === review.applyBatchCount &&
		review.actionValues.some( ( action ) =>
			[
				'presenter_migration_prepare',
				'presenter_migration_apply',
			].includes( action )
		) &&
		1 === review.focusedPostFormCount &&
		0 === review.prepareBatchCount &&
		1 === review.rowCount &&
		1 === review.viewAllLinkCount;

	console.log( JSON.stringify( { editor, passed, review }, null, 2 ) );

	if ( ! passed ) {
		process.exitCode = 1;
	}
} finally {
	await browser.close();
}
