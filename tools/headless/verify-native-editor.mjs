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

const openSettingsSidebar = async ( controlLabel ) => {
	if (
		0 === ( await page.getByLabel( controlLabel, { exact: true } ).count() )
	) {
		await page
			.getByRole( 'button', { name: 'Settings', exact: true } )
			.click();
	}

	await page.getByLabel( controlLabel, { exact: true } ).waitFor();
};

const dismissVisibleEditorModal = async () => {
	const visibleModal = page.locator(
		'.components-modal__screen-overlay:visible'
	);

	if ( 0 < ( await visibleModal.count() ) ) {
		await visibleModal.getByRole( 'button', { name: /close/i } ).click();
	}
};

const readThemePreview = () =>
	page.evaluate( () => {
		const editorDocument =
			document.querySelector( 'iframe[name="editor-canvas"]' )
				?.contentDocument ?? document;
		const preview = editorDocument.querySelector(
			'.presenter-theme-preview'
		);
		const viewport = preview?.querySelector( '.reveal-viewport' );
		const reveal = preview?.querySelector( '.reveal' );
		const firstSlide = preview?.querySelector( '.presenter-slide-editor' );

		return {
			backgroundColor: viewport
				? viewport.ownerDocument.defaultView.getComputedStyle(
						viewport
				  ).backgroundColor
				: null,
			backgroundImage: viewport
				? viewport.ownerDocument.defaultView.getComputedStyle(
						viewport
				  ).backgroundImage
				: null,
			bodyFontSize: editorDocument.defaultView.getComputedStyle(
				editorDocument.body
			).fontSize,
			color: reveal
				? reveal.ownerDocument.defaultView.getComputedStyle( reveal )
						.color
				: null,
			firstSlideBackgroundColor: firstSlide
				? firstSlide.ownerDocument.defaultView.getComputedStyle(
						firstSlide
				  ).backgroundColor
				: null,
			firstSlideBackgroundImage: firstSlide
				? firstSlide.ownerDocument.defaultView.getComputedStyle(
						firstSlide
				  ).backgroundImage
				: null,
			hasRevealHierarchy: Boolean(
				preview?.querySelector( '.reveal-viewport > .reveal > .slides' )
			),
			styleCount: preview?.querySelectorAll(
				'style[data-presenter-theme-preview]'
			).length,
		};
	} );

const readCanvasLayout = () =>
	page.evaluate( () => {
		const editorDocument =
			document.querySelector( 'iframe[name="editor-canvas"]' )
				?.contentDocument ?? document;
		const slides = [
			...editorDocument.querySelectorAll( '.presenter-slide-editor' ),
		];
		const rects = slides.map( ( slide ) => {
			const rect = slide.getBoundingClientRect();

			return { height: rect.height, width: rect.width };
		} );

		return {
			gaps: slides.slice( 1 ).map( ( slide, index ) => {
				const previous = slides[ index ].getBoundingClientRect();
				const current = slide.getBoundingClientRect();

				return current.top - previous.bottom;
			} ),
			rects,
		};
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
	await dismissVisibleEditorModal();
	await page.waitForFunction( () => {
		return Boolean(
			window.wp.data.select( 'core/block-editor' ).getBlocks()[ 0 ]
				?.innerBlocks[ 0 ]?.attributes.anchor
		);
	} );

	const editorIds = await page.evaluate( () => {
		const deck = window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()[ 0 ];

		window.wp.data
			.dispatch( 'core/block-editor' )
			.selectBlock( deck.clientId );

		return {
			deck: deck.clientId,
			firstSlide: deck.innerBlocks[ 0 ].clientId,
			generatedAnchor: deck.innerBlocks[ 0 ].attributes.anchor,
		};
	} );
	await openSettingsSidebar( 'Aspect ratio' );

	// Drive a preset through the visible control, then prove editor undo restores
	// the atomic ratio/dimension change before choosing custom dimensions.
	await page
		.getByLabel( 'Aspect ratio', { exact: true } )
		.selectOption( '4:3' );
	await page.waitForFunction( () => {
		const deck = window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()[ 0 ];

		return 960 === deck.attributes.width && 720 === deck.attributes.height;
	} );
	await page.evaluate( () =>
		window.wp.data.dispatch( 'core/editor' ).undo()
	);
	await page.waitForFunction( () => {
		const deck = window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()[ 0 ];

		return (
			'16:9' === deck.attributes.aspectRatio &&
			1280 === deck.attributes.width &&
			720 === deck.attributes.height
		);
	} );

	await page
		.getByLabel( 'Aspect ratio', { exact: true } )
		.selectOption( 'custom' );
	await page.getByLabel( 'Width', { exact: true } ).fill( '1366' );
	await page.getByLabel( 'Height', { exact: true } ).fill( '768' );
	await page
		.locator( 'input[type="number"][aria-label="Margin"]' )
		.fill( '0.12' );
	const hasAaronPurpleOption =
		1 ===
		( await page
			.getByLabel( 'Theme', { exact: true } )
			.locator( 'option[value="aaron-purple"]' )
			.count() );
	await page.waitForFunction( () => {
		const editorDocument =
			document.querySelector( 'iframe[name="editor-canvas"]' )
				?.contentDocument ?? document;

		return Boolean(
			editorDocument.querySelector(
				'.presenter-theme-preview style[data-presenter-theme-preview]'
			)
		);
	} );
	const defaultThemePreview = await readThemePreview();
	await page.getByLabel( 'Theme', { exact: true } ).selectOption( 'white' );
	await page.waitForFunction( () => {
		const editorDocument =
			document.querySelector( 'iframe[name="editor-canvas"]' )
				?.contentDocument ?? document;
		const viewport = editorDocument.querySelector(
			'.presenter-theme-preview .reveal-viewport'
		);

		return (
			viewport &&
			'rgb(255, 255, 255)' ===
				viewport.ownerDocument.defaultView.getComputedStyle( viewport )
					.backgroundColor
		);
	} );
	const whiteThemePreview = await readThemePreview();
	await page
		.getByLabel( 'Transition', { exact: true } )
		.selectOption( 'convex' );
	await page
		.getByLabel( 'Background transition', { exact: true } )
		.selectOption( 'zoom' );
	await page.getByText( 'Navigation', { exact: true } ).click();
	await page.getByLabel( 'Show controls', { exact: true } ).uncheck();
	await page.getByLabel( 'Show progress', { exact: true } ).uncheck();
	await page
		.getByLabel( 'Center slides vertically', { exact: true } )
		.uncheck();

	await page.evaluate(
		( clientId ) =>
			window.wp.data
				.dispatch( 'core/block-editor' )
				.selectBlock( clientId ),
		editorIds.firstSlide
	);
	await page.getByLabel( 'Label', { exact: true } ).fill( 'Opening slide' );
	await page
		.getByLabel( 'Anchor', { exact: true } )
		.fill( 'editor-e2e-first' );
	await page
		.getByLabel( 'Transition', { exact: true } )
		.selectOption( 'fade' );
	await page
		.getByRole( 'button', { name: 'Background', exact: true } )
		.click();
	await page.getByLabel( 'Color', { exact: true } ).fill( '#123456' );
	await page.getByLabel( 'Color', { exact: true } ).blur();
	await page
		.getByLabel( 'Image URL', { exact: true } )
		.fill( `${ baseUrl }/wp-includes/images/w-logo-blue-white-bg.png` );
	await page.getByLabel( 'Image URL', { exact: true } ).blur();
	await page
		.getByLabel( 'Image size', { exact: true } )
		.selectOption( 'contain' );
	await page
		.getByLabel( 'Image position', { exact: true } )
		.selectOption( 'bottom right' );
	await page
		.getByLabel( 'Image repeat', { exact: true } )
		.selectOption( 'repeat-x' );
	await page
		.getByLabel( 'Background opacity', { exact: true } )
		.fill( '0.45' );
	await page.getByLabel( 'Background opacity', { exact: true } ).blur();
	await page
		.getByLabel( 'Background transition', { exact: true } )
		.selectOption( 'fade' );
	await page
		.getByRole( 'button', { name: 'Auto-animate', exact: true } )
		.click();
	await page
		.getByLabel( 'Animate from the previous slide', { exact: true } )
		.check();
	await page
		.getByLabel( 'Group identifier', { exact: true } )
		.fill( 'intro' );
	await page.getByLabel( 'Group identifier', { exact: true } ).blur();

	await page.evaluate( () => {
		const heading = window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()[ 0 ].innerBlocks[ 0 ].innerBlocks[ 0 ];

		window.wp.data
			.dispatch( 'core/block-editor' )
			.selectBlock( heading.clientId );
	} );
	await page
		.getByLabel( 'Reveal this block incrementally', { exact: true } )
		.check();
	await page
		.getByLabel( 'Effect', { exact: true } )
		.selectOption( 'fade-up' );
	await page.getByLabel( 'Order', { exact: true } ).fill( '2' );
	await page.getByLabel( 'Order', { exact: true } ).blur();
	const slideBackgroundPreview = await readThemePreview();

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
				backgroundColor: '#654321',
				label: 'Second slide',
				notes: 'Markdown speaker note',
				notesFormat: 'markdown',
				transition: 'zoom',
			},
			[
				window.wp.blocks.createBlock( 'core/paragraph', {
					content: 'Second editor slide',
				} ),
			]
		);
		const representativeSlide = window.wp.blocks.createBlock(
			'presenter/slide',
			{
				anchor: 'editor-e2e-core-blocks',
				label: 'Representative core blocks',
			},
			[
				window.wp.blocks.createBlock( 'core/heading', {
					content: 'Core compatibility sentinel',
				} ),
				window.wp.blocks.createBlock( 'core/paragraph', {
					content:
						'Read the <a href="https://wordpress.org/">WordPress project</a>.',
				} ),
				window.wp.blocks.createBlock( 'core/group', {}, [
					window.wp.blocks.createBlock( 'core/columns', {}, [
						window.wp.blocks.createBlock( 'core/column', {}, [
							window.wp.blocks.createBlock( 'core/list', {}, [
								window.wp.blocks.createBlock(
									'core/list-item',
									{
										content: 'Editor list sentinel',
									}
								),
							] ),
						] ),
						window.wp.blocks.createBlock( 'core/column', {}, [
							window.wp.blocks.createBlock( 'core/code', {
								content: 'const editor = true;',
							} ),
						] ),
					] ),
				] ),
				window.wp.blocks.createBlock( 'core/image', {
					alt: 'Editor image sentinel',
					linkDestination: 'none',
					sizeSlug: 'full',
					url: `${ window.location.origin }/wp-includes/images/w-logo-blue-white-bg.png`,
				} ),
				window.wp.blocks.createBlock( 'core/buttons', {}, [
					window.wp.blocks.createBlock( 'core/button', {
						text: 'Editor button sentinel',
						url: '#editor-e2e-first',
					} ),
				] ),
				window.wp.blocks.createBlock( 'core/accordion', {}, [
					window.wp.blocks.createBlock( 'core/accordion-item', {}, [
						window.wp.blocks.createBlock(
							'core/accordion-heading',
							{
								level: 3,
								title: 'Editor accordion sentinel',
							}
						),
						window.wp.blocks.createBlock(
							'core/accordion-panel',
							{},
							[
								window.wp.blocks.createBlock(
									'core/paragraph',
									{
										content: 'Editor panel sentinel',
									}
								),
							]
						),
					] ),
				] ),
				window.wp.blocks.createBlock( 'core/shortcode', {
					text: '[presenter-url]',
				} ),
				window.wp.blocks.createBlock( 'core/latest-posts', {
					displayPostDate: true,
					postsToShow: 1,
				} ),
			]
		);

		blockEditor.replaceInnerBlocks(
			deck.clientId,
			[ ...deck.innerBlocks, secondSlide, representativeSlide ],
			false
		);
		blockEditor.updateBlockAttributes( firstSlide.clientId, {
			notes: 'Plain speaker note',
		} );
		blockEditor.updateBlockAttributes( firstHeading.clientId, {
			content: 'First editor slide',
		} );
		editor.editPost( { title: 'Presenter native editor smoke' } );
		await editor.savePost();

		return {
			postId: window.wp.data.select( 'core/editor' ).getCurrentPostId(),
			rootNames: window.wp.data
				.select( 'core/block-editor' )
				.getBlocks()
				.map( ( block ) => block.name ),
		};
	} );

	await page.reload( { waitUntil: 'domcontentloaded' } );
	await waitForEditor();
	await dismissVisibleEditorModal();
	await page.evaluate( () => {
		const slide = window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()[ 0 ].innerBlocks[ 0 ];

		window.wp.data
			.dispatch( 'core/block-editor' )
			.selectBlock( slide.clientId );
	} );
	await page.waitForFunction( () => {
		const blockEditor = window.wp.data.select( 'core/block-editor' );
		const slide = blockEditor.getBlocks()[ 0 ].innerBlocks[ 0 ];

		return blockEditor.canInsertBlockType(
			'core/paragraph',
			slide.clientId
		);
	} );

	const reloaded = await page.evaluate( () => {
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
		const representativeSlide = deck.innerBlocks[ 2 ];
		const flatten = ( block ) => [
			block,
			...block.innerBlocks.flatMap( flatten ),
		];
		const representativeBlocks = representativeSlide
			? flatten( representativeSlide )
			: [];
		const findRepresentative = ( name ) =>
			representativeBlocks.find( ( block ) => block.name === name );

		return {
			deckHeight: deck.attributes.height,
			deckAspectRatio: deck.attributes.aspectRatio,
			deckBackgroundTransition: deck.attributes.backgroundTransition,
			deckCenter: deck.attributes.center,
			deckControls: deck.attributes.controls,
			deckMargin: deck.attributes.margin,
			deckProgress: deck.attributes.progress,
			deckTheme: deck.attributes.theme,
			deckTransition: deck.attributes.transition,
			deckWidth: deck.attributes.width,
			firstAnchor: deck.innerBlocks[ 0 ]?.attributes.anchor ?? null,
			firstBackgroundColor:
				deck.innerBlocks[ 0 ]?.attributes.backgroundColor ?? null,
			firstBackgroundImageUrl:
				deck.innerBlocks[ 0 ]?.attributes.backgroundImageUrl ?? null,
			firstBackgroundOpacity:
				deck.innerBlocks[ 0 ]?.attributes.backgroundOpacity ?? null,
			firstBackgroundPosition:
				deck.innerBlocks[ 0 ]?.attributes.backgroundPosition ?? null,
			firstBackgroundRepeat:
				deck.innerBlocks[ 0 ]?.attributes.backgroundRepeat ?? null,
			firstBackgroundSize:
				deck.innerBlocks[ 0 ]?.attributes.backgroundSize ?? null,
			firstBackgroundTransition:
				deck.innerBlocks[ 0 ]?.attributes.backgroundTransition ?? null,
			firstAutoAnimate:
				deck.innerBlocks[ 0 ]?.attributes.autoAnimate ?? null,
			firstAutoAnimateId:
				deck.innerBlocks[ 0 ]?.attributes.autoAnimateId ?? null,
			firstHeading:
				deck.innerBlocks[ 0 ]?.innerBlocks[ 0 ]?.attributes.content ??
				null,
			firstHeadingFragment:
				deck.innerBlocks[ 0 ]?.innerBlocks[ 0 ]?.attributes
					.presenterFragment ?? null,
			firstHeadingFragmentEffect:
				deck.innerBlocks[ 0 ]?.innerBlocks[ 0 ]?.attributes
					.presenterFragmentEffect ?? null,
			firstHeadingFragmentIndex:
				deck.innerBlocks[ 0 ]?.innerBlocks[ 0 ]?.attributes
					.presenterFragmentIndex ?? null,
			firstLabel: deck.innerBlocks[ 0 ]?.attributes.label ?? null,
			firstTransition:
				deck.innerBlocks[ 0 ]?.attributes.transition ?? null,
			deckInserterDisabled:
				false ===
				window.wp.blocks.getBlockType( 'presenter/deck' )?.supports
					.inserter,
			editorScriptTags: document.querySelectorAll(
				'script[src*="/build/index.js"]'
			).length,
			invalidBlocks,
			missingBlocks,
			legacyMetaBoxes: document.querySelectorAll( '#slides' ).length,
			legacyScriptTags: document.querySelectorAll(
				'script[src*="edit-slide-admin.js"]'
			).length,
			templateMismatchWarning: document.body.innerText.includes(
				'The content of your post doesn’t match the template assigned to your post type.'
			),
			paragraphAllowedAtRoot:
				blockEditor.canInsertBlockType( 'core/paragraph' ),
			paragraphAllowedInSlide: blockEditor.canInsertBlockType(
				'core/paragraph',
				deck.innerBlocks[ 0 ].clientId
			),
			representativeBlockNames: representativeBlocks.map(
				( block ) => block.name
			),
			representativeButtonUrl:
				findRepresentative( 'core/button' )?.attributes.url ?? null,
			representativeCode:
				findRepresentative( 'core/code' )?.attributes.content ?? null,
			representativeImageAlt:
				findRepresentative( 'core/image' )?.attributes.alt ?? null,
			representativeShortcode:
				findRepresentative( 'core/shortcode' )?.attributes.text ?? null,
			rootNames: blocks.map( ( block ) => block.name ),
			secondAnchor: deck.innerBlocks[ 1 ]?.attributes.anchor ?? null,
			secondNotesFormat:
				deck.innerBlocks[ 1 ]?.attributes.notesFormat ?? null,
			secondTransition:
				deck.innerBlocks[ 1 ]?.attributes.transition ?? null,
			slideCount: deck.innerBlocks.length,
		};
	} );
	await page.waitForFunction( () => {
		const editorDocument =
			document.querySelector( 'iframe[name="editor-canvas"]' )
				?.contentDocument ?? document;

		return Boolean(
			editorDocument.querySelector(
				'.presenter-theme-preview style[data-presenter-theme-preview]'
			)
		);
	} );
	const reloadedThemePreview = await readThemePreview();
	const canvasLayout = await readCanvasLayout();

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
		/^slide-[a-f0-9-]+$/.test( editorIds.generatedAnchor ) &&
		cleanupDeleted &&
		1 === created.rootNames.length &&
		'presenter/deck' === created.rootNames[ 0 ] &&
		1 === reloaded.rootNames.length &&
		'presenter/deck' === reloaded.rootNames[ 0 ] &&
		3 === reloaded.slideCount &&
		1366 === reloaded.deckWidth &&
		768 === reloaded.deckHeight &&
		'custom' === reloaded.deckAspectRatio &&
		0.12 === reloaded.deckMargin &&
		false === reloaded.deckControls &&
		false === reloaded.deckProgress &&
		false === reloaded.deckCenter &&
		'convex' === reloaded.deckTransition &&
		'zoom' === reloaded.deckBackgroundTransition &&
		'white' === reloaded.deckTheme &&
		hasAaronPurpleOption &&
		'rgb(214, 209, 247)' === defaultThemePreview.backgroundColor &&
		defaultThemePreview.backgroundImage?.includes(
			'/aaron-purple/images/asanoha-400px.png'
		) &&
		'rgba(0, 0, 0, 0.5)' === defaultThemePreview.color &&
		defaultThemePreview.hasRevealHierarchy &&
		1 === defaultThemePreview.styleCount &&
		'rgb(255, 255, 255)' === whiteThemePreview.backgroundColor &&
		whiteThemePreview.hasRevealHierarchy &&
		1 === whiteThemePreview.styleCount &&
		defaultThemePreview.bodyFontSize === whiteThemePreview.bodyFontSize &&
		'rgb(18, 52, 86)' ===
			slideBackgroundPreview.firstSlideBackgroundColor &&
		slideBackgroundPreview.firstSlideBackgroundImage?.includes(
			'/wp-includes/images/w-logo-blue-white-bg.png'
		) &&
		'rgb(255, 255, 255)' === reloadedThemePreview.backgroundColor &&
		'rgb(18, 52, 86)' === reloadedThemePreview.firstSlideBackgroundColor &&
		reloadedThemePreview.firstSlideBackgroundImage?.includes(
			'/wp-includes/images/w-logo-blue-white-bg.png'
		) &&
		1 === reloadedThemePreview.styleCount &&
		3 === canvasLayout.rects.length &&
		canvasLayout.rects.every(
			( rect ) =>
				0 < rect.width &&
				Math.abs( rect.width / rect.height - 1366 / 768 ) < 0.01
		) &&
		canvasLayout.gaps.every( ( gap ) => 20 <= gap ) &&
		'editor-e2e-first' === reloaded.firstAnchor &&
		'Opening slide' === reloaded.firstLabel &&
		'fade' === reloaded.firstTransition &&
		'#123456' === reloaded.firstBackgroundColor &&
		`${ baseUrl }/wp-includes/images/w-logo-blue-white-bg.png` ===
			reloaded.firstBackgroundImageUrl &&
		0.45 === reloaded.firstBackgroundOpacity &&
		'bottom right' === reloaded.firstBackgroundPosition &&
		'repeat-x' === reloaded.firstBackgroundRepeat &&
		'contain' === reloaded.firstBackgroundSize &&
		'fade' === reloaded.firstBackgroundTransition &&
		true === reloaded.firstAutoAnimate &&
		'intro' === reloaded.firstAutoAnimateId &&
		'First editor slide' === reloaded.firstHeading &&
		true === reloaded.firstHeadingFragment &&
		'fade-up' === reloaded.firstHeadingFragmentEffect &&
		2 === reloaded.firstHeadingFragmentIndex &&
		'editor-e2e-second' === reloaded.secondAnchor &&
		'markdown' === reloaded.secondNotesFormat &&
		'zoom' === reloaded.secondTransition &&
		reloaded.deckInserterDisabled &&
		1 === reloaded.editorScriptTags &&
		0 === reloaded.invalidBlocks.length &&
		0 === reloaded.missingBlocks.length &&
		0 === reloaded.legacyMetaBoxes &&
		0 === reloaded.legacyScriptTags &&
		! reloaded.templateMismatchWarning &&
		! reloaded.paragraphAllowedAtRoot &&
		reloaded.paragraphAllowedInSlide &&
		[
			'core/heading',
			'core/paragraph',
			'core/group',
			'core/columns',
			'core/column',
			'core/list',
			'core/list-item',
			'core/code',
			'core/image',
			'core/buttons',
			'core/button',
			'core/accordion',
			'core/accordion-item',
			'core/accordion-heading',
			'core/accordion-panel',
			'core/shortcode',
			'core/latest-posts',
		].every( ( name ) =>
			reloaded.representativeBlockNames.includes( name )
		) &&
		'#editor-e2e-first' === reloaded.representativeButtonUrl &&
		'const editor = true;' === reloaded.representativeCode &&
		'Editor image sentinel' === reloaded.representativeImageAlt &&
		'[presenter-url]' === reloaded.representativeShortcode &&
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
				hasAaronPurpleOption,
				defaultThemePreview,
				canvasLayout,
				whiteThemePreview,
				reloadedThemePreview,
				slideBackgroundPreview,
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
