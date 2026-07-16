/* eslint-disable no-console -- This command emits content-free verification results. */
/**
 * Exercise the native Deck/Slide save and reload path in the real block editor.
 */

import { chromium } from '@playwright/test';

const baseUrl = process.env.WP_BASE_URL ?? 'http://localhost:8888';
const username = process.env.WP_ADMIN_USER ?? 'admin';
const password = process.env.WP_ADMIN_PASSWORD ?? 'password';
const browser = await chromium.launch( { headless: true } );
const context = await browser.newContext();
const page = await context.newPage();
const pageErrors = [];
const consoleErrors = [];
const consoleWarnings = [];

page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
page.on( 'console', ( message ) => {
	if ( message.type() === 'error' ) {
		consoleErrors.push( message.text() );
	}
	if ( message.type() === 'warning' ) {
		consoleWarnings.push( message.text() );
	}
} );

const waitForEditor = () =>
	page.waitForFunction( () => {
		const editor = window.wp?.data?.select( 'core/block-editor' );

		return editor?.getBlocks().length > 0;
	} );

try {
	await page.goto( `${ baseUrl }/wp-login.php`, {
		waitUntil: 'domcontentloaded',
	} );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/ );

	await page.goto( `${ baseUrl }/wp-admin/post-new.php?post_type=slideshow`, {
		waitUntil: 'domcontentloaded',
	} );
	await waitForEditor();
	await page.waitForFunction( () => {
		return Boolean(
			window.wp.data.select( 'core/block-editor' ).getBlocks()[ 0 ]
				?.innerBlocks[ 0 ]?.attributes.anchor
		);
	} );

	const created = await page.evaluate( async () => {
		const blockEditor = window.wp.data.dispatch( 'core/block-editor' );
		const editor = window.wp.data.dispatch( 'core/editor' );
		const blocks = window.wp.data.select( 'core/block-editor' ).getBlocks();
		const deck = blocks[ 0 ];
		const firstSlide = deck.innerBlocks[ 0 ];
		const firstHeading = firstSlide.innerBlocks[ 0 ];
		const secondSlide = window.wp.blocks.createBlock(
			'presenter/slide',
			{
				anchor: 'editor-e2e-second',
				notes: 'Markdown speaker note',
				notesFormat: 'markdown',
			},
			[
				window.wp.blocks.createBlock( 'core/paragraph', {
					content: 'Second editor slide',
				} ),
			]
		);

		blockEditor.replaceInnerBlocks(
			deck.clientId,
			[ ...deck.innerBlocks, secondSlide ],
			false
		);
		blockEditor.updateBlockAttributes( deck.clientId, {
			height: 768,
			width: 1366,
		} );
		blockEditor.updateBlockAttributes( firstSlide.clientId, {
			anchor: 'editor-e2e-first',
			notes: 'Plain speaker note',
		} );
		blockEditor.updateBlockAttributes( firstHeading.clientId, {
			content: 'First editor slide',
		} );
		editor.editPost( { title: 'Presenter native editor smoke' } );
		await editor.savePost();

		return {
			generatedAnchor: firstSlide.attributes.anchor,
			postId: window.wp.data.select( 'core/editor' ).getCurrentPostId(),
			rootNames: window.wp.data
				.select( 'core/block-editor' )
				.getBlocks()
				.map( ( block ) => block.name ),
		};
	} );

	await page.reload( { waitUntil: 'domcontentloaded' } );
	await waitForEditor();

	const reloaded = await page.evaluate( () => {
		const blockEditor = window.wp.data.select( 'core/block-editor' );
		const blocks = blockEditor.getBlocks();
		const deck = blocks[ 0 ];
		const invalidBlocks = [];
		const inspect = ( block ) => {
			if ( false === block.isValid ) {
				invalidBlocks.push( block.name );
			}
			block.innerBlocks.forEach( inspect );
		};
		blocks.forEach( inspect );

		return {
			deckHeight: deck.attributes.height,
			deckWidth: deck.attributes.width,
			firstAnchor: deck.innerBlocks[ 0 ]?.attributes.anchor ?? null,
			firstHeading:
				deck.innerBlocks[ 0 ]?.innerBlocks[ 0 ]?.attributes.content ??
				null,
			deckInserterDisabled:
				false ===
				window.wp.blocks.getBlockType( 'presenter/deck' )?.supports
					.inserter,
			editorScriptTags: document.querySelectorAll(
				'script[src*="/build/index.js"]'
			).length,
			invalidBlocks,
			legacyMetaBoxes: document.querySelectorAll( '#slides' ).length,
			legacyScriptTags: document.querySelectorAll(
				'script[src*="edit-slide-admin.js"]'
			).length,
			paragraphAllowedAtRoot:
				blockEditor.canInsertBlockType( 'core/paragraph' ),
			paragraphAllowedInSlide: blockEditor.canInsertBlockType(
				'core/paragraph',
				deck.innerBlocks[ 0 ].clientId
			),
			rootNames: blocks.map( ( block ) => block.name ),
			secondAnchor: deck.innerBlocks[ 1 ]?.attributes.anchor ?? null,
			secondNotesFormat:
				deck.innerBlocks[ 1 ]?.attributes.notesFormat ?? null,
			slideCount: deck.innerBlocks.length,
		};
	} );

	const cleanupDeleted = await page.evaluate( async ( postId ) => {
		const result = await window.wp.data
			.dispatch( 'core' )
			.deleteEntityRecord( 'postType', 'slideshow', postId, {
				force: true,
			} );

		return true === result?.deleted;
	}, created.postId );
	const duplicateRegistrationWarnings = consoleWarnings.filter( ( warning ) =>
		/already registered/i.test( warning )
	);

	const passed =
		created.postId > 0 &&
		/^slide-[a-f0-9-]+$/.test( created.generatedAnchor ) &&
		cleanupDeleted &&
		1 === created.rootNames.length &&
		'presenter/deck' === created.rootNames[ 0 ] &&
		1 === reloaded.rootNames.length &&
		'presenter/deck' === reloaded.rootNames[ 0 ] &&
		2 === reloaded.slideCount &&
		1366 === reloaded.deckWidth &&
		768 === reloaded.deckHeight &&
		'editor-e2e-first' === reloaded.firstAnchor &&
		'First editor slide' === reloaded.firstHeading &&
		'editor-e2e-second' === reloaded.secondAnchor &&
		'markdown' === reloaded.secondNotesFormat &&
		reloaded.deckInserterDisabled &&
		1 === reloaded.editorScriptTags &&
		0 === reloaded.invalidBlocks.length &&
		0 === reloaded.legacyMetaBoxes &&
		0 === reloaded.legacyScriptTags &&
		! reloaded.paragraphAllowedAtRoot &&
		reloaded.paragraphAllowedInSlide &&
		0 === pageErrors.length &&
		0 === consoleErrors.length &&
		0 === duplicateRegistrationWarnings.length;

	console.log(
		JSON.stringify(
			{
				...reloaded,
				cleanupDeleted,
				consoleErrors,
				consoleWarningCount: consoleWarnings.length,
				duplicateRegistrationWarnings,
				pageErrors,
				passed,
				postId: created.postId,
			},
			null,
			2
		)
	);

	if ( ! passed ) {
		process.exitCode = 1;
	}
} finally {
	await browser.close();
}
