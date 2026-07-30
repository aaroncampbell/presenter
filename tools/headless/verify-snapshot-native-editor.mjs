/* eslint-disable no-console -- This command emits content-free verification results. */
/**
 * Verify that a migrated snapshot deck opens as editable native content.
 */

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
const context = await browser.newContext( {
	viewport: { height: 1080, width: 1920 },
} );
const page = await context.newPage();

const inspectPreview = ( preview ) =>
	preview.locator( 'body' ).evaluate( () => {
		const viewport = document.querySelector( '.reveal-viewport' );
		const reveal = document.querySelector( '.reveal' );
		const heading = document.querySelector( '.slides > section h2' );
		const section = document.querySelector( '.slides > section' );
		const socialIcon = document.querySelector( 'a.social-icon-link svg' );
		const footer = document.querySelector( '.persistent-twitter-link' );
		const style = ( element ) =>
			element ? window.getComputedStyle( element ) : null;
		const sectionRect = section?.getBoundingClientRect();

		return {
			backgroundColor: style( viewport )?.backgroundColor ?? null,
			backgroundImage: style( viewport )?.backgroundImage ?? null,
			fontFamily: style( reveal )?.fontFamily ?? null,
			fontSize: style( reveal )?.fontSize ?? null,
			footerBottom: style( footer )?.bottom ?? null,
			footerLeft: style( footer )?.left ?? null,
			footerPosition: style( footer )?.position ?? null,
			footerText: footer?.textContent.trim() ?? null,
			headingColor: style( heading )?.color ?? null,
			headingFontSize: style( heading )?.fontSize ?? null,
			sectionHeight: sectionRect?.height ?? null,
			sectionTop: sectionRect?.top ?? null,
			socialIconHeight:
				socialIcon?.getBoundingClientRect().height ?? null,
			socialIconWidth: socialIcon?.getBoundingClientRect().width ?? null,
		};
	} );

const inspectEditorSlide = async ( editorCanvas, index ) => {
	const slide = editorCanvas
		.locator( '.presenter-slide-editor' )
		.nth( index );
	const iframe = slide.locator( 'iframe[title="Slide preview"]' );
	const shell = await slide.evaluate( ( element ) => {
		const frame = element.querySelector( 'iframe[title="Slide preview"]' );
		const preview = element.querySelector(
			'.presenter-legacy-slide-preview'
		);
		const button = element.querySelector( '.presenter-edit-legacy-html' );
		const rect = ( candidate ) => {
			const bounds = candidate?.getBoundingClientRect();

			return bounds
				? {
						height: bounds.height,
						left: bounds.left,
						top: bounds.top,
						width: bounds.width,
				  }
				: null;
		};

		return {
			button: rect( button ),
			computedSlideWidth: window.getComputedStyle( element ).width,
			computedIframeHeight: frame
				? window.getComputedStyle( frame ).height
				: null,
			computedIframeWidth: frame
				? window.getComputedStyle( frame ).width
				: null,
			iframe: rect( frame ),
			iframeTabIndex: frame?.tabIndex ?? null,
			iframeTransform: frame?.style.transform ?? null,
			preview: rect( preview ),
			slide: rect( element ),
		};
	} );
	const inner =
		1 === ( await iframe.count() )
			? await slide
					.frameLocator( 'iframe[title="Slide preview"]' )
					.locator( 'body' )
					.evaluate( () => {
						const reveal = document.querySelector( '.reveal' );
						const heading = document.querySelector(
							'.slides > section h1, .slides > section h2, .slides > section h3'
						);
						const image = document.querySelector(
							'.slides > section img, .slides > section svg'
						);
						const footer = document.querySelector(
							'.persistent-twitter-link'
						);
						const footerIcon = footer?.querySelector( 'svg' );

						return {
							footerBottom:
								footer?.getBoundingClientRect().bottom,
							footerIconHeight:
								footerIcon?.getBoundingClientRect().height,
							footerIconWidth:
								footerIcon?.getBoundingClientRect().width,
							fontFamily: reveal
								? window.getComputedStyle( reveal ).fontFamily
								: null,
							headingColor: heading
								? window.getComputedStyle( heading ).color
								: null,
							imageHeight: image?.getBoundingClientRect().height,
							imageWidth: image?.getBoundingClientRect().width,
							styleLengths: [
								...document.querySelectorAll( 'style' ),
							].map( ( style ) => style.textContent.length ),
							viewportHeight: window.innerHeight,
							viewportWidth: window.innerWidth,
						};
					} )
			: null;

	return { index, inner, shell };
};

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
	await dismissEditorWelcome( page );
	await page.waitForFunction( () => {
		const editorDocument =
			document.querySelector( 'iframe[name="editor-canvas"]' )
				?.contentDocument ?? document;

		return (
			1 <
			editorDocument.querySelectorAll( '.presenter-slide-editor' ).length
		);
	} );

	const result = await page.evaluate( () => {
		const blockEditor = window.wp.data.select( 'core/block-editor' );
		const blocks = blockEditor.getBlocks();
		const deck = blocks[ 0 ];
		const editorDocument =
			document.querySelector( 'iframe[name="editor-canvas"]' )
				?.contentDocument ?? document;
		const slideElements = [
			...editorDocument.querySelectorAll( '.presenter-slide-editor' ),
		];
		const slideRects = slideElements.map( ( slide ) => {
			const rect = slide.getBoundingClientRect();

			return { height: rect.height, width: rect.width };
		} );
		const slideGaps = slideElements.slice( 1 ).map( ( slide, index ) => {
			const previous = slideElements[ index ].getBoundingClientRect();
			const current = slide.getBoundingClientRect();

			return current.top - previous.bottom;
		} );
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
			deckAspectRatio: deck.attributes.aspectRatio,
			deckHeight: deck.attributes.height,
			deckInnerTemplateLock: blockEditor.getTemplateLock( deck.clientId ),
			deckWidth: deck.attributes.width,
			invalidBlocks,
			legacyMetaBoxCount: document.querySelectorAll( '#slides' ).length,
			missingBlocks,
			postId: window.wp.data.select( 'core/editor' ).getCurrentPostId(),
			rootNames: blocks.map( ( block ) => block.name ),
			rootTemplateLock: blockEditor.getTemplateLock(),
			slideCount: deck.innerBlocks.length,
			slideGaps,
			slideRects,
			templateMismatchWarning: document.body.innerText.includes(
				'The content of your post doesn’t match the template assigned to your post type.'
			),
		};
	} );

	const editorCanvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	const editorSlideDiagnostics = await Promise.all(
		[ 0, 6 ].map( ( index ) => inspectEditorSlide( editorCanvas, index ) )
	);
	const mainPreview = editorCanvas
		.frameLocator( 'iframe[title="Slide preview"]' )
		.first();
	await mainPreview.locator( '.reveal h2' ).waitFor();
	await page.waitForTimeout( 250 );
	const mainPreviewFidelity = await inspectPreview( mainPreview );
	const migratedBackgrounds = await editorCanvas
		.locator( '.presenter-slide-editor' )
		.evaluateAll( ( slides ) =>
			[ 2, 3 ].map(
				( index ) =>
					window.getComputedStyle( slides[ index ] ).backgroundImage
			)
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
	const firstThumbnail = navigator
		.locator( '.presenter-slide-navigator-thumbnail' )
		.first();
	const thumbnailPreview = firstThumbnail
		.frameLocator( 'iframe[title="Slide preview"]' )
		.first();
	await thumbnailPreview.locator( '.reveal h2' ).waitFor();
	await page.waitForTimeout( 250 );
	const thumbnailPreviewFidelity = await inspectPreview( thumbnailPreview );
	const thumbnailIframeCount = await firstThumbnail
		.locator( 'iframe' )
		.count();
	await page.evaluate( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];

		window.wp.data
			.dispatch( 'core/block-editor' )
			.selectBlock( deck.innerBlocks[ 6 ].clientId );
	} );
	const legacyEditToolbarVisible = await page
		.getByRole( 'button', { name: 'Edit legacy HTML', exact: true } )
		.isVisible();
	await page.evaluate( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];

		window.wp.data
			.dispatch( 'core/block-editor' )
			.selectBlock( deck.innerBlocks[ 2 ].clientId );
	} );
	const backgroundPanel = page.getByRole( 'button', {
		name: 'Background',
		exact: true,
	} );
	if ( ! ( await backgroundPanel.isVisible() ) ) {
		const settingsToggle = page.locator( 'button[aria-label="Settings"]' );
		if ( await settingsToggle.isVisible() ) {
			await settingsToggle.click();
		}
	}
	const blockTab = page.getByRole( 'tab', { name: 'Block', exact: true } );
	if ( await blockTab.isVisible() ) {
		await blockTab.click();
	}
	await backgroundPanel.click();
	const legacyBackgroundInspectorValue = await page
		.getByRole( 'textbox', { name: 'Image URL', exact: true } )
		.inputValue();
	const previewSandbox = await editorCanvas
		.locator( 'iframe[title="Slide preview"]' )
		.first()
		.getAttribute( 'sandbox' );
	const previewReferrerPolicy = await editorCanvas
		.locator( 'iframe[title="Slide preview"]' )
		.first()
		.getAttribute( 'referrerpolicy' );
	const previewFidelity = {
		editorSlideDiagnostics,
		legacyEditToolbarVisible,
		main: mainPreviewFidelity,
		legacyBackgroundInspectorValue,
		migratedBackgrounds,
		referrerPolicy: previewReferrerPolicy,
		sandbox: previewSandbox,
		thumbnail: thumbnailPreviewFidelity,
		thumbnailIframeCount,
	};
	const previewMatchesTheme = ( preview ) =>
		'rgb(214, 209, 247)' === preview.backgroundColor &&
		preview.backgroundImage?.includes( 'asanoha-400px.png' ) &&
		preview.fontFamily?.includes( 'Open Sans' ) &&
		'36px' === preview.fontSize &&
		'rgb(131, 119, 209)' === preview.headingColor &&
		Math.abs( Number.parseFloat( preview.headingFontSize ) - 75.96 ) <
			0.1 &&
		Math.abs( preview.socialIconWidth - 36 ) < 0.1 &&
		Math.abs( preview.socialIconHeight - 36 ) < 0.1 &&
		'fixed' === preview.footerPosition &&
		'0px' === preview.footerLeft &&
		'0px' === preview.footerBottom &&
		preview.footerText?.includes( '@AaronCampbell' ) &&
		0 < preview.sectionTop &&
		preview.sectionTop + preview.sectionHeight < 700;

	const passed =
		postId === result.postId &&
		'custom' === result.deckAspectRatio &&
		960 === result.deckWidth &&
		700 === result.deckHeight &&
		1 === result.rootNames.length &&
		'presenter/deck' === result.rootNames[ 0 ] &&
		1 < result.slideCount &&
		'all' === result.rootTemplateLock &&
		false === result.deckInnerTemplateLock &&
		0 === result.invalidBlocks.length &&
		0 === result.missingBlocks.length &&
		0 === result.legacyMetaBoxCount &&
		result.slideRects.length === result.slideCount &&
		result.slideRects.every(
			( rect ) =>
				0 < rect.width &&
				Math.abs( rect.width / rect.height - 960 / 700 ) < 0.01
		) &&
		result.slideGaps.every( ( gap ) => 20 <= gap ) &&
		'' === previewFidelity.sandbox &&
		'no-referrer' === previewFidelity.referrerPolicy &&
		1 === previewFidelity.thumbnailIframeCount &&
		previewFidelity.legacyEditToolbarVisible &&
		previewFidelity.editorSlideDiagnostics.every(
			( diagnostic ) =>
				null === diagnostic.shell.button &&
				'700px' === diagnostic.shell.computedIframeHeight &&
				'960px' === diagnostic.shell.computedIframeWidth &&
				-1 === diagnostic.shell.iframeTabIndex &&
				Math.abs(
					diagnostic.shell.iframe.width -
						diagnostic.shell.preview.width
				) < 1 &&
				Math.abs(
					diagnostic.shell.iframe.height -
						diagnostic.shell.preview.height
				) < 1 &&
				700 === diagnostic.inner.viewportHeight &&
				960 === diagnostic.inner.viewportWidth &&
				diagnostic.inner.fontFamily?.includes( 'Open Sans' ) &&
				'rgb(131, 119, 209)' === diagnostic.inner.headingColor &&
				650 < diagnostic.inner.footerBottom &&
				Math.abs( diagnostic.inner.footerIconHeight - 27 ) < 0.1 &&
				Math.abs( diagnostic.inner.footerIconWidth - 27 ) < 0.1 &&
				1000 < diagnostic.inner.styleLengths[ 1 ]
		) &&
		'//aarondcampbell.mystagingwebsite.com/wp-content/uploads/2016/09/Gorillas_screenshot.png' ===
			previewFidelity.legacyBackgroundInspectorValue &&
		previewFidelity.migratedBackgrounds[ 0 ]?.includes(
			'Gorillas_screenshot.png'
		) &&
		previewFidelity.migratedBackgrounds[ 1 ]?.includes(
			'qbasic-gorillas.png'
		) &&
		previewMatchesTheme( previewFidelity.main ) &&
		previewMatchesTheme( previewFidelity.thumbnail ) &&
		! result.templateMismatchWarning;

	console.log(
		JSON.stringify( { ...result, passed, previewFidelity }, null, 2 )
	);

	if ( ! passed ) {
		process.exitCode = 1;
	}
} finally {
	await browser.close();
}
