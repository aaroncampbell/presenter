/* eslint-disable no-console -- This command emits content-free verification results. */
/**
 * Exercise flat and Nested Slides navigator workflows in the real editor.
 */

import { chromium } from '@playwright/test';

const baseUrl = process.env.WP_BASE_URL ?? 'http://localhost:8888';
const username = process.env.WP_ADMIN_USER ?? 'admin';
const password = process.env.WP_ADMIN_PASSWORD ?? 'password';
const browser = await chromium.launch( { headless: true } );
const context = await browser.newContext( {
	viewport: { height: 1600, width: 1440 },
} );
const page = await context.newPage();
const pageErrors = [];
const consoleErrors = [];
const consoleWarnings = [];

page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
page.on( 'console', ( message ) => {
	if ( 'error' === message.type() ) {
		consoleErrors.push( message.text() );
	}
	if ( 'warning' === message.type() ) {
		consoleWarnings.push( message.text() );
	}
} );

const getState = () =>
	page.evaluate( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		const items = editor.getBlocks( deck.clientId );

		return {
			items: items.map( ( item ) => ( {
				anchor: item.attributes.anchor ?? '',
				clientId: item.clientId,
				name: item.name,
				children: editor
					.getBlocks( item.clientId )
					.map( ( child ) => ( {
						anchor: child.attributes.anchor ?? '',
						clientId: child.clientId,
						name: child.name,
					} ) ),
			} ) ),
			selectedId: editor.getSelectedBlockClientId(),
		};
	} );

const undo = async () => {
	await page.evaluate( () =>
		window.wp.data.dispatch( 'core/editor' ).undo()
	);
	await page.waitForTimeout( 200 );
};

const waitForStructure = ( predicate ) =>
	page.waitForFunction( predicate, undefined, { timeout: 15_000 } );

const openAddMenu = async () => {
	await page
		.getByRole( 'button', { name: 'Add Slide options', exact: true } )
		.click();
};

try {
	await page.goto( `${ baseUrl }/wp-login.php`, {
		waitUntil: 'domcontentloaded',
	} );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/ );

	await page.goto( `${ baseUrl }/wp-admin/edit.php?post_type=slideshow`, {
		waitUntil: 'domcontentloaded',
	} );
	const editLink = await page
		.getByRole( 'link', {
			name: 'Presenter M5 Navigator Smoke',
			exact: true,
		} )
		.first()
		.getAttribute( 'href' );
	const postId = Number(
		new URL( editLink ?? '', baseUrl ).searchParams.get( 'post' )
	);

	if ( ! postId ) {
		throw new Error( 'Navigator fixture was not found.' );
	}

	await page.goto(
		`${ baseUrl }/wp-admin/post.php?post=${ postId }&action=edit`,
		{ waitUntil: 'domcontentloaded' }
	);
	await waitForStructure( () => {
		const editor = window.wp?.data?.select( 'core/block-editor' );
		const deck = editor?.getBlocks()[ 0 ];
		return (
			'presenter/deck' === deck?.name &&
			60 === editor.getBlocks( deck.clientId ).length
		);
	} );

	const modal = page.locator( '.components-modal__screen-overlay:visible' );
	if ( await modal.count() ) {
		await modal.getByRole( 'button', { name: /close/i } ).click();
	}

	const rail = page.getByRole( 'complementary', {
		name: 'Slides rail',
		exact: true,
	} );
	await rail.waitFor();
	const navigator = page.getByRole( 'navigation', {
		name: 'Slide navigator',
		exact: true,
	} );
	await navigator.waitFor();
	const chooseSlideOption = async ( toggleName, optionName ) => {
		const toggle = navigator.getByRole( 'button', {
			name: toggleName,
			exact: true,
		} );
		await toggle.locator( 'xpath=ancestor::li[1]' ).hover();
		await toggle.click();
		const menu = page.locator( '.components-dropdown-menu__menu:visible' );
		await menu.waitFor();
		await menu
			.getByRole( 'menuitem', { name: optionName, exact: true } )
			.click();
	};

	const initialState = await getState();
	const editorCanvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	const railBehavior = {
		canvasFollows: false,
		defaultOpen: await navigator.isVisible(),
		settingsCoexist: false,
		selectionPersists: false,
		selectionFollows: false,
		stickyControls: false,
		splitButtonAligned: false,
		collapses: false,
	};

	const settingsButton = page.getByRole( 'button', {
		name: 'Settings',
		exact: true,
	} );
	if ( await settingsButton.count() ) {
		const settingsSidebar = page
			.locator( '.editor-sidebar:visible' )
			.first();
		if ( ! ( await settingsSidebar.count() ) ) {
			await settingsButton.click();
		}
		await settingsSidebar.waitFor();
		const [ railBox, settingsBox ] = await Promise.all( [
			rail.boundingBox(),
			settingsSidebar.boundingBox(),
		] );
		railBehavior.settingsCoexist = Boolean(
			railBox &&
				settingsBox &&
				railBox.x + railBox.width <= settingsBox.x + 1
		);
	}

	await page.evaluate( ( slideId ) => {
		window.wp.data.dispatch( 'core/block-editor' ).selectBlock( slideId );
	}, initialState.items[ 49 ].clientId );
	await page.waitForTimeout( 100 );
	const followedSlide = navigator.locator(
		`[data-presenter-slide-id="${ initialState.items[ 49 ].clientId }"]`
	);
	const followedCanvasSlide = editorCanvas.locator(
		`[data-block="${ initialState.items[ 49 ].clientId }"]`
	);
	const [ navigatorBox, followedBox, canvasBox, followedCanvasBox ] =
		await Promise.all( [
			navigator.boundingBox(),
			followedSlide.boundingBox(),
			page.locator( 'iframe[name="editor-canvas"]' ).boundingBox(),
			followedCanvasSlide.boundingBox(),
		] );
	const selectionFollowGeometry = { followedBox, navigatorBox };
	railBehavior.canvasFollows = Boolean(
		canvasBox &&
			followedCanvasBox &&
			followedCanvasBox.y + followedCanvasBox.height >= canvasBox.y &&
			followedCanvasBox.y <= canvasBox.y + canvasBox.height
	);
	railBehavior.selectionPersists = await navigator.isVisible();
	railBehavior.selectionFollows = Boolean(
		navigatorBox &&
			followedBox &&
			followedBox.y >= navigatorBox.y - 1 &&
			followedBox.y + followedBox.height <=
				navigatorBox.y + navigatorBox.height + 1
	);

	const navigatorHeader = navigator.locator(
		'.presenter-slide-navigator-header'
	);
	await navigator.evaluate( ( element ) => {
		element.scrollTop = element.scrollHeight;
	} );
	await page.waitForTimeout( 50 );
	const [ scrolledNavigatorBox, headerBox ] = await Promise.all( [
		navigator.boundingBox(),
		navigatorHeader.boundingBox(),
	] );
	railBehavior.stickyControls = Boolean(
		scrolledNavigatorBox &&
			headerBox &&
			Math.abs( scrolledNavigatorBox.y - headerBox.y ) <= 1
	);

	const addSlideButton = navigator.getByRole( 'button', {
		name: 'Add Slide',
		exact: true,
	} );
	const addOptionsButton = navigator.getByRole( 'button', {
		name: 'Add Slide options',
		exact: true,
	} );
	const [ addSlideBox, addOptionsBox ] = await Promise.all( [
		addSlideButton.boundingBox(),
		addOptionsButton.boundingBox(),
	] );
	railBehavior.splitButtonAligned = Boolean(
		addSlideBox &&
			addOptionsBox &&
			Math.abs( addSlideBox.y - addOptionsBox.y ) <= 1 &&
			Math.abs(
				addSlideBox.y +
					addSlideBox.height -
					( addOptionsBox.y + addOptionsBox.height )
			) <= 1 &&
			Math.abs( addSlideBox.x + addSlideBox.width - addOptionsBox.x ) <= 1
	);

	await rail
		.getByRole( 'button', { name: 'Collapse Slides rail', exact: true } )
		.click();
	railBehavior.collapses = ! ( await navigator.isVisible() );
	await rail
		.getByRole( 'button', { name: 'Open Slides rail', exact: true } )
		.click();
	await navigator.waitFor();
	await navigator.evaluate( ( element ) => {
		element.scrollTop = 0;
	} );
	await page.evaluate( ( slideId ) => {
		window.wp.data.dispatch( 'core/block-editor' ).selectBlock( slideId );
	}, initialState.items[ 1 ].clientId );
	await page.waitForTimeout( 100 );

	const semantics = {
		hasTree: await navigator.getByRole( 'tree' ).isVisible(),
		slideCount: await navigator
			.locator( '.presenter-slide-navigator-select' )
			.count(),
	};
	const labels = {
		explicit: await navigator
			.getByRole( 'button', {
				name: 'Slide 1: Explicit navigator label',
				exact: true,
			} )
			.count(),
		heading: await navigator
			.getByRole( 'button', {
				name: 'Slide 2: Heading fallback title',
				exact: true,
			} )
			.count(),
		nestedText: await navigator
			.getByRole( 'button', {
				name: 'Slide 3: Nested paragraph fallback title',
				exact: true,
			} )
			.count(),
		untitled: await navigator
			.getByRole( 'button', {
				name: 'Slide 4: Slide 4',
				exact: true,
			} )
			.count(),
	};
	const hiddenOptionsToggle = navigator.getByRole( 'button', {
		name: 'Options for Slide 10',
		exact: true,
	} );
	await hiddenOptionsToggle.locator( 'xpath=ancestor::li[1]' ).hover();
	await hiddenOptionsToggle.click();
	const hiddenMenu = page.locator(
		'.components-dropdown-menu__menu:visible'
	);
	await hiddenMenu.waitFor();
	const hiddenState = {
		hasBadge: await navigator
			.getByRole( 'button', {
				name: 'Slide 10: Navigator slide 10',
				exact: true,
			} )
			.locator( 'xpath=ancestor::li[1]' )
			.getByText( 'Hidden', { exact: true } )
			.isVisible(),
		hasShowControl: await hiddenMenu
			.getByRole( 'menuitem', { name: 'Show', exact: true } )
			.isVisible(),
	};
	await page.keyboard.press( 'Escape' );
	const firstNavigatorCard = navigator.locator(
		`[data-presenter-slide-id="${ initialState.items[ 0 ].clientId }"]`
	);
	const firstActionMenu = firstNavigatorCard.locator(
		'.presenter-slide-navigator-menu'
	);
	const menuOpacityBeforeHover = await firstActionMenu.evaluate(
		( element ) => window.getComputedStyle( element ).opacity
	);
	await firstNavigatorCard.hover();
	await page.waitForTimeout( 150 );
	const [ firstCardBox, firstMenuBox, menuOpacityAfterHover ] =
		await Promise.all( [
			firstNavigatorCard.boundingBox(),
			firstActionMenu.boundingBox(),
			firstActionMenu.evaluate(
				( element ) => window.getComputedStyle( element ).opacity
			),
		] );
	const actionMenuState = {
		appearsOnHover:
			0 === Number.parseFloat( menuOpacityBeforeHover ) &&
			1 === Number.parseFloat( menuOpacityAfterHover ),
		hasNoLegacyActionLinks:
			0 ===
			( await navigator
				.locator( '.presenter-slide-navigator-actions' )
				.count() ),
		isInTopCorner: Boolean(
			firstCardBox &&
				firstMenuBox &&
				firstMenuBox.x > firstCardBox.x + firstCardBox.width / 2 &&
				firstMenuBox.y < firstCardBox.y + firstCardBox.height / 3
		),
	};

	// The primary split-button action adds after at the selected Slide's level.
	await navigator
		.getByRole( 'button', {
			name: 'Slide 2: Heading fallback title',
			exact: true,
		} )
		.click();
	await navigator
		.getByRole( 'button', { name: 'Add Slide', exact: true } )
		.click();
	await waitForStructure( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		return 61 === editor.getBlocks( deck.clientId ).length;
	} );
	const topLevelAddState = await getState();
	await undo();

	// Add nested wraps only a top-level Slide and keeps its anchor.
	await navigator
		.getByRole( 'button', {
			name: 'Slide 2: Heading fallback title',
			exact: true,
		} )
		.click();
	await openAddMenu();
	await page
		.getByRole( 'button', { name: 'Add nested', exact: true } )
		.click();
	await waitForStructure( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		const stack = editor.getBlocks( deck.clientId )[ 1 ];
		return (
			'presenter/stack' === stack?.name &&
			2 === editor.getBlocks( stack.clientId ).length
		);
	} );
	const wrappedState = await getState();
	const stackId = wrappedState.items[ 1 ].clientId;
	const precedingSlideId = wrappedState.items[ 0 ].clientId;
	const followingSlideId = wrappedState.items[ 2 ].clientId;
	const originalSecondId = wrappedState.items[ 1 ].children[ 0 ].clientId;
	const addedNestedId = wrappedState.items[ 1 ].children[ 1 ].clientId;
	const stackBlock = editorCanvas.locator( `[data-block="${ stackId }"]` );
	await stackBlock.waitFor();
	const stackSlides = editorCanvas.locator(
		`[data-block="${ stackId }"]` + '.presenter-stack-editor-slides'
	);
	const revealViewport = editorCanvas.locator(
		'.presenter-deck-editor .reveal-viewport'
	);
	const precedingSlideBlock = editorCanvas.locator(
		`[data-block="${ precedingSlideId }"]`
	);
	const followingSlideBlock = editorCanvas.locator(
		`[data-block="${ followingSlideId }"]`
	);
	const firstNestedBlock = editorCanvas.locator(
		`[data-block="${ originalSecondId }"]`
	);
	const continuationBlock = editorCanvas.locator(
		`[data-block="${ addedNestedId }"]`
	);
	const firstContentBlock = firstNestedBlock
		.locator( '[data-block]' )
		.first();
	const lastContentBlock = firstNestedBlock.locator( '[data-block]' ).last();
	const beforeBoundary = firstNestedBlock.locator(
		'.presenter-slide-boundary-inserter.is-before'
	);
	const afterBoundary = firstNestedBlock.locator(
		'.presenter-slide-boundary-inserter.is-after'
	);
	const firstNestedContentCount = await page.evaluate(
		( rootClientId ) =>
			window.wp.data
				.select( 'core/block-editor' )
				.getBlockCount( rootClientId ),
		originalSecondId
	);
	const [
		stackBox,
		stackSlidesBox,
		revealViewportBox,
		precedingSlideBox,
		followingSlideBox,
		firstNestedBox,
		continuationBox,
		firstContentBox,
		lastContentBox,
		beforeBoundaryBox,
		afterBoundaryBox,
	] = await Promise.all( [
		stackBlock.boundingBox(),
		stackSlides.boundingBox(),
		revealViewport.boundingBox(),
		precedingSlideBlock.boundingBox(),
		followingSlideBlock.boundingBox(),
		firstNestedBlock.boundingBox(),
		continuationBlock.boundingBox(),
		firstContentBlock.boundingBox(),
		lastContentBlock.boundingBox(),
		beforeBoundary.boundingBox(),
		afterBoundary.boundingBox(),
	] );
	const railStyle = await stackSlides.evaluate( ( element ) => {
		const style = window.getComputedStyle( element, '::before' );

		return {
			background: style.backgroundColor,
			bottom: Number.parseFloat( style.bottom ),
			content: style.content,
			left: Number.parseFloat( style.left ),
			right: Number.parseFloat( style.right ),
			top: Number.parseFloat( style.top ),
			width: Number.parseFloat( style.width ),
		};
	} );
	const continuationStyle = await continuationBlock.evaluate( ( element ) => {
		const style = window.getComputedStyle( element );

		return {
			borderInlineStartWidth: Number.parseFloat(
				style.borderInlineStartWidth
			),
		};
	} );

	await beforeBoundary.hover();
	const nativeInsertionPoint = page
		.locator( '.block-editor-block-list__insertion-point.is-with-inserter' )
		.first();
	const nativeBoundaryIndicator = nativeInsertionPoint.locator(
		'.block-editor-block-list__insertion-point-indicator'
	);
	const nativeBoundaryButton = nativeInsertionPoint.getByRole( 'button', {
		name: 'Add block',
		exact: true,
	} );
	await nativeBoundaryButton.waitFor();
	const [
		nativeBoundaryBox,
		nativeBoundaryIndicatorBox,
		nativeBoundaryButtonBox,
	] = await Promise.all( [
		nativeInsertionPoint.boundingBox(),
		nativeBoundaryIndicator.boundingBox(),
		nativeBoundaryButton.boundingBox(),
	] );
	const nativeBoundaryButtonColors = await nativeBoundaryButton.evaluate(
		( element ) => {
			const style = window.getComputedStyle( element );

			return {
				background: style.backgroundColor,
				color: style.color,
			};
		}
	);
	const beforeInsertionPoint = await page.evaluate( () =>
		window.wp.data.select( 'core/block-editor' ).getBlockInsertionPoint()
	);

	await afterBoundary.hover();
	await page.waitForFunction(
		( { count, rootClientId } ) => {
			const insertionPoint = window.wp.data
				.select( 'core/block-editor' )
				.getBlockInsertionPoint();

			return (
				rootClientId === insertionPoint.rootClientId &&
				count === insertionPoint.index
			);
		},
		{
			count: firstNestedContentCount,
			rootClientId: originalSecondId,
		}
	);
	const afterInsertionPoint = await page.evaluate( () =>
		window.wp.data.select( 'core/block-editor' ).getBlockInsertionPoint()
	);
	const nestedCanvas = {
		boundaryContentFullWidth: Boolean(
			firstContentBox &&
				firstNestedBox &&
				Math.abs( firstContentBox.x - firstNestedBox.x ) <= 2 &&
				Math.abs( firstContentBox.width - firstNestedBox.width ) <= 2
		),
		boundaryInsertersAdjacent: Boolean(
			firstContentBox &&
				lastContentBox &&
				beforeBoundaryBox &&
				afterBoundaryBox &&
				Math.abs(
					beforeBoundaryBox.y +
						beforeBoundaryBox.height / 2 -
						firstContentBox.y
				) <= 24 &&
				Math.abs(
					afterBoundaryBox.y +
						afterBoundaryBox.height / 2 -
						( lastContentBox.y + lastContentBox.height )
				) <= 24
		),
		boundaryInsertersMatchNative: Boolean(
			nativeBoundaryBox &&
				nativeBoundaryIndicatorBox &&
				nativeBoundaryButtonBox &&
				Math.abs( nativeBoundaryIndicatorBox.height - 4 ) <= 1 &&
				Math.abs( nativeBoundaryButtonBox.width - 24 ) <= 1 &&
				Math.abs( nativeBoundaryButtonBox.height - 24 ) <= 1 &&
				Math.abs(
					nativeBoundaryButtonBox.x +
						nativeBoundaryButtonBox.width / 2 -
						( nativeBoundaryBox.x + nativeBoundaryBox.width / 2 )
				) <= 1 &&
				Math.abs(
					nativeBoundaryButtonBox.y +
						nativeBoundaryButtonBox.height / 2 -
						( nativeBoundaryBox.y + nativeBoundaryBox.height / 2 )
				) <= 1 &&
				'rgb(56, 88, 233)' === nativeBoundaryButtonColors.background &&
				'rgb(255, 255, 255)' === nativeBoundaryButtonColors.color
		),
		hasBoundaryInserters:
			4 ===
			( await stackBlock
				.getByRole( 'button', {
					name: /Show block inserter (?:before|after) slide content/,
				} )
				.count() ),
		nativeBoundaryIndexes:
			originalSecondId === beforeInsertionPoint.rootClientId &&
			0 === beforeInsertionPoint.index &&
			originalSecondId === afterInsertionPoint.rootClientId &&
			firstNestedContentCount === afterInsertionPoint.index,
		hasNoCornerAppenders:
			0 ===
			( await stackBlock
				.locator( '.presenter-slide-editor > .block-list-appender' )
				.count() ),
		hasNoContainerAppender:
			0 ===
			( await stackBlock
				.locator(
					'.presenter-stack-editor-slides > .block-list-appender'
				)
				.count() ),
		hasNoContext:
			0 ===
			( await stackBlock
				.locator( '.presenter-stack-editor-context' )
				.count() ),
		parentFullWidth: Boolean(
			stackBox &&
				firstNestedBox &&
				Math.abs( firstNestedBox.x - stackBox.x ) <= 2 &&
				Math.abs(
					firstNestedBox.x +
						firstNestedBox.width -
						( stackBox.x + stackBox.width )
				) <= 2
		),
		continuationIndented: Boolean(
			firstNestedBox &&
				continuationBox &&
				continuationBox.x > firstNestedBox.x &&
				continuationBox.width < firstNestedBox.width &&
				Math.abs(
					continuationBox.x +
						continuationBox.width -
						( firstNestedBox.x + firstNestedBox.width )
				) <= 3
		),
		continuationHasNoRailBorder:
			0 === continuationStyle.borderInlineStartWidth,
		continuationHasClass:
			( await continuationBlock.getAttribute( 'class' ) )?.includes(
				'is-presenter-stack-continuation'
			) ?? false,
		nestedGuttersMatch: Boolean(
			revealViewportBox &&
				firstNestedBox &&
				continuationBox &&
				Math.abs(
					firstNestedBox.x -
						revealViewportBox.x -
						( continuationBox.x - firstNestedBox.x )
				) <= 2
		),
		railIsContinuous: Boolean(
			stackSlidesBox &&
				firstNestedBox &&
				continuationBox &&
				'none' !== railStyle.content &&
				Math.abs( railStyle.width - 2 ) <= 1 &&
				Math.abs(
					stackSlidesBox.x +
						( Number.isFinite( railStyle.left )
							? railStyle.left
							: stackSlidesBox.width - railStyle.right ) -
						firstNestedBox.x
				) <= 2 &&
				Math.abs(
					stackSlidesBox.y + railStyle.top - continuationBox.y
				) <= 2 &&
				Math.abs(
					stackSlidesBox.y +
						stackSlidesBox.height -
						railStyle.bottom -
						( continuationBox.y + continuationBox.height )
				) <= 2
		),
		railUsesNestedAccent: 'rgb(140, 83, 208)' === railStyle.background,
		singleStackWrapper:
			( await stackBlock.getAttribute( 'class' ) )?.includes(
				'presenter-stack-editor-slides'
			) &&
			0 ===
				( await stackBlock
					.locator( '.presenter-stack-editor-slides' )
					.count() ),
		stackGapsMatchRegular: Boolean(
			precedingSlideBox &&
				followingSlideBox &&
				firstNestedBox &&
				continuationBox &&
				Math.abs(
					firstNestedBox.y -
						( precedingSlideBox.y + precedingSlideBox.height ) -
						( continuationBox.y -
							( firstNestedBox.y + firstNestedBox.height ) )
				) <= 2 &&
				Math.abs(
					followingSlideBox.y -
						( continuationBox.y + continuationBox.height ) -
						( continuationBox.y -
							( firstNestedBox.y + firstNestedBox.height ) )
				) <= 2
		),
		continuationBox,
		afterBoundaryBox,
		afterInsertionPoint,
		beforeBoundaryBox,
		beforeInsertionPoint,
		firstContentBox,
		firstNestedBox,
		lastContentBox,
		nativeBoundaryBox,
		nativeBoundaryButtonBox,
		nativeBoundaryButtonColors,
		nativeBoundaryIndicatorBox,
		railStyle,
		stackBox,
		stackSlidesBox,
	};

	// Group actions clone the full subtree and confirm destructive deletion.
	await chooseSlideOption( 'Options for Nested Slides 2', 'Duplicate' );
	await waitForStructure( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		const items = editor.getBlocks( deck.clientId );
		return (
			61 === items.length &&
			'presenter/stack' === items[ 1 ]?.name &&
			'presenter/stack' === items[ 2 ]?.name
		);
	} );
	const duplicateStackState = await getState();
	await undo();

	await chooseSlideOption( 'Options for Nested Slides 2', 'Delete' );
	const deleteModal = page.getByRole( 'dialog', {
		name: 'Delete Nested Slides?',
		exact: true,
	} );
	await deleteModal.waitFor();
	await deleteModal
		.getByRole( 'button', { name: 'Cancel', exact: true } )
		.click();
	const cancelPreservedGroup =
		'presenter/stack' === ( await getState() ).items[ 1 ].name;

	await chooseSlideOption( 'Options for Nested Slides 2', 'Delete' );
	await deleteModal
		.getByRole( 'button', {
			name: 'Delete Nested Slides',
			exact: true,
		} )
		.click();
	await waitForStructure( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		return 59 === editor.getBlocks( deck.clientId ).length;
	} );
	const deleteStackState = await getState();
	await undo();

	await navigator
		.getByRole( 'button', {
			name: 'Slide 2.1: Slide 2.1',
			exact: true,
		} )
		.click();

	await openAddMenu();
	const nestedMenu = {
		hasAfter:
			1 ===
			( await page
				.getByRole( 'button', { name: 'Add after', exact: true } )
				.count() ),
		hasBefore:
			1 ===
			( await page
				.getByRole( 'button', { name: 'Add before', exact: true } )
				.count() ),
		hidesNested:
			0 ===
			( await page
				.getByRole( 'button', { name: 'Add nested', exact: true } )
				.count() ),
	};
	await page
		.getByRole( 'button', { name: 'Add before', exact: true } )
		.click();
	await waitForStructure( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		const stack = editor.getBlocks( deck.clientId )[ 1 ];
		return 3 === editor.getBlocks( stack.clientId ).length;
	} );
	const nestedBeforeState = await getState();
	await undo();

	// Slide actions retain anchors and remain one-step undoable.
	await chooseSlideOption( 'Options for Slide 2.1', 'Duplicate' );
	await waitForStructure( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		const stack = editor.getBlocks( deck.clientId )[ 1 ];
		return 3 === editor.getBlocks( stack.clientId ).length;
	} );
	const duplicateState = await getState();
	await undo();

	await chooseSlideOption( 'Options for Slide 2.1', 'Hide' );
	await page.waitForFunction(
		( slideId ) =>
			Boolean(
				window.wp.data.select( 'core/block-editor' ).getBlock( slideId )
					?.attributes.hidden
			),
		addedNestedId
	);
	await undo();

	// Dragging into a group's left gutter moves a child immediately after it.
	const unnestDragSource = navigator.locator(
		`[data-presenter-slide-id="${ addedNestedId }"]`
	);
	const unnestDataTransfer = await page.evaluateHandle(
		() => new window.DataTransfer()
	);
	await unnestDragSource.dispatchEvent( 'dragstart', {
		dataTransfer: unnestDataTransfer,
	} );
	const unnestTarget = navigator.locator(
		`[data-presenter-unnest-target="${ stackId }"]`
	);
	await unnestTarget.waitFor( { state: 'attached' } );
	const unnestTargetOpacityBeforeHover = await unnestTarget.evaluate(
		( element ) => window.getComputedStyle( element ).opacity
	);
	await unnestTarget.dispatchEvent( 'dragenter', {
		dataTransfer: unnestDataTransfer,
	} );
	const unnestPreview = navigator.locator(
		'.presenter-slide-navigator-unnest-preview'
	);
	await unnestPreview.waitFor();
	await page.waitForTimeout( 100 );
	const unnestParentCard = navigator.locator(
		`[data-presenter-slide-id="${ originalSecondId }"]`
	);
	const [
		unnestPreviewBox,
		unnestParentBox,
		unnestSourceHeight,
		unnestTargetOpacityAfterHover,
	] = await Promise.all( [
		unnestPreview.boundingBox(),
		unnestParentCard.boundingBox(),
		unnestDragSource.evaluate( ( element ) => element.offsetHeight ),
		unnestTarget.evaluate(
			( element ) => window.getComputedStyle( element ).opacity
		),
	] );
	const unnestDragFeedback = {
		hidesLeftTargetUntilHovered:
			0 === Number.parseFloat( unnestTargetOpacityBeforeHover ),
		showsOnlyAtLeftEdge:
			1 === Number.parseFloat( unnestTargetOpacityAfterHover ),
		sourceCollapsesAtDestination: 0 === unnestSourceHeight,
		previewsTopLevelPosition: Boolean(
			unnestPreviewBox &&
				unnestParentBox &&
				Math.abs( unnestPreviewBox.x - unnestParentBox.x ) <= 4 &&
				unnestPreviewBox.width >= unnestParentBox.width * 0.95 &&
				unnestPreviewBox.y > unnestParentBox.y
		),
	};
	await unnestPreview.dispatchEvent( 'dragover', {
		dataTransfer: unnestDataTransfer,
	} );
	await unnestPreview.dispatchEvent( 'drop', {
		dataTransfer: unnestDataTransfer,
	} );
	await unnestDragSource.dispatchEvent( 'dragend', {
		dataTransfer: unnestDataTransfer,
	} );
	await unnestDataTransfer.dispose();
	await waitForStructure( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		const items = editor.getBlocks( deck.clientId );
		return (
			'presenter/slide' === items[ 1 ]?.name &&
			'presenter/slide' === items[ 2 ]?.name
		);
	} );
	const unnestDragState = await getState();
	await undo();

	// Moving the second child out unwraps the remaining singleton.
	await chooseSlideOption( 'Options for Slide 2.1', 'Move to top level' );
	await waitForStructure( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		const items = editor.getBlocks( deck.clientId );
		return (
			'presenter/slide' === items[ 1 ]?.name &&
			'presenter/slide' === items[ 2 ]?.name
		);
	} );
	const moveOutState = await getState();
	await undo();

	// A neighboring top-level Slide can enter Nested Slides with one undo.
	await chooseSlideOption(
		'Options for Slide 1',
		'Move into Nested Slides after'
	);
	await waitForStructure( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		const stack = editor.getBlocks( deck.clientId )[ 0 ];
		return (
			'presenter/stack' === stack?.name &&
			3 === editor.getBlocks( stack.clientId ).length
		);
	} );
	const moveInState = await getState();
	await undo();

	// The right edge of a top-level Slide previews and creates a nested group.
	const nestDragSource = navigator.locator(
		`[data-presenter-slide-id="${ initialState.items[ 2 ].clientId }"]`
	);
	const nestDataTransfer = await page.evaluateHandle(
		() => new window.DataTransfer()
	);
	const nestSourceBox = await nestDragSource.boundingBox();
	await nestDragSource.dispatchEvent( 'dragstart', {
		dataTransfer: nestDataTransfer,
	} );
	await page.waitForFunction(
		( slideId ) =>
			document
				.querySelector( `[data-presenter-slide-id="${ slideId }"]` )
				?.classList.contains( 'is-drag-source-placeholder' ),
		initialState.items[ 2 ].clientId
	);
	const [ sourcePlaceholderHeight, sourceContentOpacity ] = await Promise.all(
		[
			nestDragSource.evaluate( ( element ) => element.offsetHeight ),
			nestDragSource
				.locator( ':scope > *' )
				.first()
				.evaluate(
					( element ) => window.getComputedStyle( element ).opacity
				),
		]
	);
	const nestTargetsHiddenBeforeHover = await navigator
		.locator( '.presenter-slide-navigator-nest-zone' )
		.evaluateAll( ( elements ) =>
			elements.every(
				( element ) =>
					0 ===
					Number.parseFloat(
						window.getComputedStyle( element ).opacity
					)
			)
		);
	await navigator.evaluate( ( element ) => {
		element.scrollTop = 0;
	} );
	const dragNavigatorBox = await navigator.boundingBox();
	if ( dragNavigatorBox ) {
		await navigator.dispatchEvent( 'dragover', {
			clientY: dragNavigatorBox.y + dragNavigatorBox.height - 1,
			dataTransfer: nestDataTransfer,
		} );
	}
	const dragEdgeScrollTop = await navigator.evaluate(
		( element ) => element.scrollTop
	);
	const nestTargetCard = navigator.locator(
		`[data-presenter-slide-id="${ initialState.items[ 0 ].clientId }"]`
	);
	const nestTarget = navigator.locator(
		`[data-presenter-nest-target="${ initialState.items[ 0 ].clientId }"]`
	);
	await nestTarget.waitFor();
	await nestTarget.dispatchEvent( 'dragenter', {
		dataTransfer: nestDataTransfer,
	} );
	const nestPreview = nestTargetCard.locator(
		'.presenter-slide-navigator-nest-preview'
	);
	await nestPreview.waitFor();
	await page.waitForTimeout( 100 );
	const [
		nestTargetBox,
		nestPreviewBox,
		nestCardBox,
		nestSourceHeight,
		visibleNestTargetCount,
	] = await Promise.all( [
		nestTarget.boundingBox(),
		nestPreview.boundingBox(),
		nestTargetCard.boundingBox(),
		nestDragSource.evaluate( ( element ) => element.offsetHeight ),
		navigator
			.locator( '.presenter-slide-navigator-nest-zone' )
			.evaluateAll(
				( elements ) =>
					elements.filter(
						( element ) =>
							0 <
							Number.parseFloat(
								window.getComputedStyle( element ).opacity
							)
					).length
			),
	] );
	const nestSourceClass = await nestDragSource.getAttribute( 'class' );
	const nestDragFeedback = {
		autoScrollsAtEdge: dragEdgeScrollTop > 0,
		hidesNestTargetsUntilHovered: nestTargetsHiddenBeforeHover,
		hasSingleSourcePlaceholder: Boolean(
			nestSourceBox &&
				sourcePlaceholderHeight >= nestSourceBox.height * 0.9 &&
				0 === Number.parseFloat( sourceContentOpacity )
		),
		sourceCollapsesAtDestination:
			0 === nestSourceHeight &&
			( nestSourceClass?.includes( 'is-drag-source-collapsed' ) ??
				false ),
		onlyHoveredNestTargetAppears: 1 === visibleNestTargetCount,
		hasIndentedPreview: Boolean(
			nestPreviewBox &&
				nestCardBox &&
				nestSourceBox &&
				nestPreviewBox.x > nestCardBox.x &&
				nestPreviewBox.height >= nestSourceBox.height * 0.65
		),
		hasRightEdgeTarget: Boolean(
			nestTargetBox &&
				nestCardBox &&
				nestTargetBox.width >= nestCardBox.width * 0.28 &&
				nestTargetBox.x > nestCardBox.x + nestCardBox.width / 2
		),
		targetIsHighlighted:
			( await nestTargetCard.getAttribute( 'class' ) )?.includes(
				'is-nest-target'
			) ?? false,
	};
	await nestPreview.dispatchEvent( 'dragover', {
		dataTransfer: nestDataTransfer,
	} );
	await nestPreview.dispatchEvent( 'drop', {
		dataTransfer: nestDataTransfer,
	} );
	await nestDragSource.dispatchEvent( 'dragend', {
		dataTransfer: nestDataTransfer,
	} );
	await nestDataTransfer.dispose();
	await waitForStructure( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		const items = editor.getBlocks( deck.clientId );
		return (
			59 === items.length &&
			'presenter/stack' === items[ 0 ]?.name &&
			2 === editor.getBlocks( items[ 0 ].clientId ).length
		);
	} );
	const nestDragState = await getState();
	await undo();

	// Cross-level drag/drop reuses the same move transform.
	const dragSource = navigator.locator(
		`[data-presenter-slide-id="${ initialState.items[ 2 ].clientId }"]`
	);
	const stackDrop = navigator.locator(
		`[data-presenter-drop-type="stack"][data-presenter-drop-parent="${ stackId }"][data-presenter-drop-before=""]`
	);
	const dataTransfer = await page.evaluateHandle(
		() => new window.DataTransfer()
	);
	await dragSource.dispatchEvent( 'dragstart', { dataTransfer } );
	const dragSourceBox = await dragSource.boundingBox();
	await stackDrop.dispatchEvent( 'dragenter', { dataTransfer } );
	await stackDrop.dispatchEvent( 'dragover', { dataTransfer } );
	await page.waitForTimeout( 160 );
	const activeStackDropBox = await stackDrop.boundingBox();
	const reorderDragFeedback = {
		hasCardSizedPlaceholder: Boolean(
			dragSourceBox &&
				activeStackDropBox &&
				activeStackDropBox.height >= dragSourceBox.height * 0.9
		),
		placeholderIsActive:
			( await stackDrop.getAttribute( 'class' ) )?.includes(
				'is-active'
			) ?? false,
	};
	await stackDrop.dispatchEvent( 'drop', { dataTransfer } );
	await dragSource.dispatchEvent( 'dragend', { dataTransfer } );
	await dataTransfer.dispose();
	await waitForStructure( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		const stack = editor.getBlocks( deck.clientId )[ 1 ];
		return 3 === editor.getBlocks( stack.clientId ).length;
	} );
	const dragState = await getState();
	await undo();

	// Deleting the penultimate child unwraps the remaining Slide.
	await chooseSlideOption( 'Options for Slide 2.1', 'Delete' );
	await waitForStructure( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		return (
			'presenter/slide' === editor.getBlocks( deck.clientId )[ 1 ]?.name
		);
	} );
	const deleteState = await getState();
	await undo();

	// Already-migrated canonical stack HTML can become native structure.
	await navigator
		.getByRole( 'button', {
			name: 'Slide 58: Legacy Nested Slides fixture',
			exact: true,
		} )
		.click();
	await page
		.getByRole( 'button', {
			name: 'Convert to Nested Slides',
			exact: true,
		} )
		.click();
	await waitForStructure( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		const stack = editor.getBlocks( deck.clientId )[ 57 ];
		return (
			'presenter/stack' === stack?.name &&
			2 === editor.getBlocks( stack.clientId ).length
		);
	} );
	const retainedStackConversionState = await getState();
	await undo();

	const duplicateAnchors = duplicateState.items[ 1 ].children.map(
		( slide ) => slide.anchor
	);
	const duplicatedStackAnchors = duplicateStackState.items
		.slice( 1, 3 )
		.flatMap( ( stack ) => [
			stack.anchor,
			...stack.children.map( ( slide ) => slide.anchor ),
		] );
	const targetedWarnings = consoleWarnings.filter( ( warning ) =>
		/ButtonGroup is deprecated|useSelect hook returns different values/.test(
			warning
		)
	);
	const passed =
		Object.values( railBehavior ).every( Boolean ) &&
		Object.values( actionMenuState ).every( Boolean ) &&
		nestedCanvas.boundaryContentFullWidth &&
		nestedCanvas.boundaryInsertersAdjacent &&
		nestedCanvas.boundaryInsertersMatchNative &&
		nestedCanvas.hasBoundaryInserters &&
		nestedCanvas.nativeBoundaryIndexes &&
		nestedCanvas.hasNoCornerAppenders &&
		nestedCanvas.hasNoContainerAppender &&
		nestedCanvas.hasNoContext &&
		nestedCanvas.parentFullWidth &&
		nestedCanvas.continuationIndented &&
		nestedCanvas.continuationHasNoRailBorder &&
		nestedCanvas.continuationHasClass &&
		nestedCanvas.nestedGuttersMatch &&
		nestedCanvas.railIsContinuous &&
		nestedCanvas.railUsesNestedAccent &&
		nestedCanvas.singleStackWrapper &&
		nestedCanvas.stackGapsMatchRegular &&
		semantics.hasTree &&
		60 === semantics.slideCount &&
		Object.values( labels ).every( ( count ) => 1 === count ) &&
		hiddenState.hasBadge &&
		hiddenState.hasShowControl &&
		61 === topLevelAddState.items.length &&
		'presenter/stack' === wrappedState.items[ 1 ].name &&
		'navigator-slide-02' === wrappedState.items[ 1 ].children[ 0 ].anchor &&
		Boolean( wrappedState.items[ 1 ].anchor ) &&
		wrappedState.selectedId === addedNestedId &&
		61 === duplicateStackState.items.length &&
		6 === new Set( duplicatedStackAnchors ).size &&
		cancelPreservedGroup &&
		59 === deleteStackState.items.length &&
		! deleteStackState.items.some(
			( item ) => item.clientId === stackId
		) &&
		Object.values( nestedMenu ).every( Boolean ) &&
		3 === nestedBeforeState.items[ 1 ].children.length &&
		nestedBeforeState.selectedId ===
			nestedBeforeState.items[ 1 ].children[ 1 ].clientId &&
		3 === duplicateState.items[ 1 ].children.length &&
		3 === new Set( duplicateAnchors ).size &&
		Object.values( unnestDragFeedback ).every( Boolean ) &&
		originalSecondId === unnestDragState.items[ 1 ].clientId &&
		addedNestedId === unnestDragState.items[ 2 ].clientId &&
		originalSecondId === moveOutState.items[ 1 ].clientId &&
		addedNestedId === moveOutState.items[ 2 ].clientId &&
		'presenter/stack' === moveInState.items[ 0 ].name &&
		3 === moveInState.items[ 0 ].children.length &&
		Object.values( nestDragFeedback ).every( Boolean ) &&
		'presenter/stack' === nestDragState.items[ 0 ].name &&
		initialState.items[ 0 ].clientId ===
			nestDragState.items[ 0 ].children[ 0 ].clientId &&
		initialState.items[ 2 ].clientId ===
			nestDragState.items[ 0 ].children[ 1 ].clientId &&
		Object.values( reorderDragFeedback ).every( Boolean ) &&
		3 === dragState.items[ 1 ].children.length &&
		59 === dragState.items.length &&
		originalSecondId === deleteState.items[ 1 ].clientId &&
		'presenter/stack' === retainedStackConversionState.items[ 57 ].name &&
		2 === retainedStackConversionState.items[ 57 ].children.length &&
		'legacy-nested-one' ===
			retainedStackConversionState.items[ 57 ].children[ 0 ].anchor &&
		0 === targetedWarnings.length &&
		0 === pageErrors.length &&
		0 === consoleErrors.length;

	console.log(
		JSON.stringify(
			{
				actionMenuState,
				consoleErrors,
				consoleWarningCount: consoleWarnings.length,
				hiddenState,
				labels,
				nestedMenu,
				nestedCanvas,
				nestDragFeedback,
				pageErrors,
				passed,
				postId,
				railBehavior,
				reorderDragFeedback,
				selectionFollowGeometry,
				semantics,
				targetedWarnings,
				unnestDragFeedback,
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
