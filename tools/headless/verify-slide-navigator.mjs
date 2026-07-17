/* eslint-disable no-console -- This command emits content-free verification results. */
/**
 * Exercise the slide navigator against a large native deck in the real editor.
 */

import { chromium } from '@playwright/test';

const baseUrl = process.env.WP_BASE_URL ?? 'http://localhost:8888';
const username = process.env.WP_ADMIN_USER ?? 'admin';
const password = process.env.WP_ADMIN_PASSWORD ?? 'password';
const fixtureSlug = 'presenter-m5-navigator-smoke';
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

const waitForEditor = () =>
	page.waitForFunction( () => {
		const editor = window.wp?.data?.select( 'core/block-editor' );
		const deck = editor?.getBlocks()[ 0 ];

		return (
			'presenter/deck' === deck?.name &&
			60 === editor.getBlocks( deck.clientId ).length
		);
	} );

const dismissVisibleEditorModal = async () => {
	const visibleModal = page.locator(
		'.components-modal__screen-overlay:visible'
	);

	if ( 0 < ( await visibleModal.count() ) ) {
		await visibleModal.getByRole( 'button', { name: /close/i } ).click();
	}
};

const getNavigatorState = () =>
	page.evaluate( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];
		const slides = editor.getBlocks( deck.clientId );

		return {
			anchors: slides.map( ( slide ) => slide.attributes.anchor ?? '' ),
			hidden: slides.map( ( slide ) =>
				Boolean( slide.attributes.hidden )
			),
			selectedBlockId: editor.getSelectedBlockClientId(),
			slideIds: slides.map( ( slide ) => slide.clientId ),
		};
	} );

const undoAndWaitFor = async ( predicate, argument ) => {
	await page.evaluate( () =>
		window.wp.data.dispatch( 'core/editor' ).undo()
	);
	await page.waitForFunction( predicate, argument );
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
	const fixtureEditLink = await page
		.getByRole( 'link', {
			name: 'Presenter M5 Navigator Smoke',
			exact: true,
		} )
		.first()
		.getAttribute( 'href' );
	const postId = Number(
		new URL( fixtureEditLink ?? '', baseUrl ).searchParams.get( 'post' )
	);

	if ( ! postId ) {
		throw new Error(
			`Navigator fixture "${ fixtureSlug }" was not found.`
		);
	}

	await page.goto(
		`${ baseUrl }/wp-admin/post.php?post=${ postId }&action=edit`,
		{ waitUntil: 'domcontentloaded' }
	);
	await waitForEditor();
	await dismissVisibleEditorModal();

	await page.getByRole( 'button', { name: 'Slides', exact: true } ).click();
	const navigator = page.getByRole( 'navigation', {
		name: 'Slide navigator',
		exact: true,
	} );
	await navigator.waitFor();

	const slideSelectControls = navigator.getByRole( 'button', {
		name: /^Slide \d+: /,
	} );
	const initialState = await getNavigatorState();
	const semanticList = navigator.getByRole( 'list' );
	const initialSemantics = {
		hasNavigation: await navigator.isVisible(),
		hasOrderedList:
			'OL' ===
			( await semanticList.evaluate( ( element ) => element.tagName ) ),
		slideSelectCount: await slideSelectControls.count(),
	};
	const renderedSlideLabels = await navigator
		.locator( '.presenter-slide-navigator-select' )
		.evaluateAll( ( controls ) =>
			controls.map( ( control ) => control.getAttribute( 'aria-label' ) )
		);
	if ( ! renderedSlideLabels.includes( 'Slide 2: Heading fallback title' ) ) {
		throw new Error(
			`Unexpected navigator labels: ${ JSON.stringify(
				renderedSlideLabels.slice( 0, 5 )
			) }`
		);
	}
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
	const tenthSlideItem = navigator
		.getByRole( 'button', {
			name: 'Slide 10: Navigator slide 10',
			exact: true,
		} )
		.locator( 'xpath=ancestor::li[1]' );
	const hiddenState = {
		hasBadge: await tenthSlideItem
			.getByText( 'Hidden', { exact: true } )
			.isVisible(),
		hasShowControl: await tenthSlideItem
			.getByRole( 'button', { name: 'Show slide 10', exact: true } )
			.isVisible(),
	};

	await navigator
		.getByRole( 'button', {
			name: 'Slide 2: Heading fallback title',
			exact: true,
		} )
		.click();
	await page.waitForFunction( ( slideId ) => {
		return (
			slideId ===
			window.wp.data
				.select( 'core/block-editor' )
				.getSelectedBlockClientId()
		);
	}, initialState.slideIds[ 1 ] );
	const selectedSecond =
		'true' ===
		( await navigator
			.getByRole( 'button', {
				name: 'Slide 2: Heading fallback title',
				exact: true,
			} )
			.getAttribute( 'aria-current' ) );

	await navigator.getByRole( 'button', { name: 'Add slide' } ).click();
	await page.waitForFunction( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const deck = editor.getBlocks()[ 0 ];

		return 61 === editor.getBlocks( deck.clientId ).length;
	} );
	await undoAndWaitFor( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		return (
			60 === editor.getBlocks( editor.getBlocks()[ 0 ].clientId ).length
		);
	} );

	await navigator
		.getByRole( 'button', { name: 'Duplicate slide 2', exact: true } )
		.click();
	await page.waitForFunction( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const slides = editor.getBlocks( editor.getBlocks()[ 0 ].clientId );
		const anchors = slides.map( ( slide ) => slide.attributes.anchor );

		return (
			61 === slides.length &&
			Boolean( anchors[ 2 ] ) &&
			61 === new Set( anchors ).size
		);
	} );
	const duplicateState = await getNavigatorState();
	await undoAndWaitFor( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		return (
			60 === editor.getBlocks( editor.getBlocks()[ 0 ].clientId ).length
		);
	} );

	await navigator
		.getByRole( 'button', { name: 'Hide slide 2', exact: true } )
		.click();
	await page.waitForFunction( ( slideId ) => {
		return Boolean(
			window.wp.data.select( 'core/block-editor' ).getBlock( slideId )
				?.attributes.hidden
		);
	}, initialState.slideIds[ 1 ] );
	await undoAndWaitFor( ( slideId ) => {
		return ! window.wp.data
			.select( 'core/block-editor' )
			.getBlock( slideId )?.attributes.hidden;
	}, initialState.slideIds[ 1 ] );

	await navigator
		.getByRole( 'button', { name: 'Delete slide 2', exact: true } )
		.click();
	await page.waitForFunction( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		return (
			59 === editor.getBlocks( editor.getBlocks()[ 0 ].clientId ).length
		);
	} );
	await undoAndWaitFor( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		return (
			60 === editor.getBlocks( editor.getBlocks()[ 0 ].clientId ).length
		);
	} );

	const moveDown = navigator.getByRole( 'button', {
		name: 'Move slide 2 down',
		exact: true,
	} );
	await moveDown.focus();
	await page.keyboard.press( 'Enter' );
	await page.waitForFunction( ( slideId ) => {
		const editor = window.wp.data.select( 'core/block-editor' );
		return (
			slideId ===
			editor.getBlocks( editor.getBlocks()[ 0 ].clientId )[ 2 ]?.clientId
		);
	}, initialState.slideIds[ 1 ] );
	await undoAndWaitFor( ( slideId ) => {
		const editor = window.wp.data.select( 'core/block-editor' );
		return (
			slideId ===
			editor.getBlocks( editor.getBlocks()[ 0 ].clientId )[ 1 ]?.clientId
		);
	}, initialState.slideIds[ 1 ] );

	const draggedSlide = navigator.locator(
		`[data-presenter-slide-id="${ initialState.slideIds[ 0 ] }"]`
	);
	const dropZone = navigator.locator( '[data-presenter-drop-index="2"]' );
	const dataTransfer = await page.evaluateHandle(
		() => new window.DataTransfer()
	);
	// Exercise Chromium's native HTML drag pipeline directly. Playwright's
	// pointer helper cannot release over a target clipped by WP's sidebar.
	await draggedSlide.dispatchEvent( 'dragstart', { dataTransfer } );
	await dropZone.dispatchEvent( 'dragenter', { dataTransfer } );
	await dropZone.dispatchEvent( 'dragover', { dataTransfer } );
	await dropZone.dispatchEvent( 'drop', { dataTransfer } );
	await draggedSlide.dispatchEvent( 'dragend', { dataTransfer } );
	await dataTransfer.dispose();
	await page.waitForFunction( ( slideId ) => {
		const editor = window.wp.data.select( 'core/block-editor' );
		return (
			slideId ===
			editor.getBlocks( editor.getBlocks()[ 0 ].clientId )[ 1 ]?.clientId
		);
	}, initialState.slideIds[ 0 ] );
	await undoAndWaitFor( ( slideId ) => {
		const editor = window.wp.data.select( 'core/block-editor' );
		return (
			slideId ===
			editor.getBlocks( editor.getBlocks()[ 0 ].clientId )[ 0 ]?.clientId
		);
	}, initialState.slideIds[ 0 ] );

	await navigator
		.getByRole( 'button', {
			name: 'Slide 60: Navigator slide 60',
			exact: true,
		} )
		.click();
	await page.waitForFunction( ( slideId ) => {
		return (
			slideId ===
			window.wp.data
				.select( 'core/block-editor' )
				.getSelectedBlockClientId()
		);
	}, initialState.slideIds[ 59 ] );
	const finalSlideSelected =
		'true' ===
		( await navigator
			.getByRole( 'button', {
				name: 'Slide 60: Navigator slide 60',
				exact: true,
			} )
			.getAttribute( 'aria-current' ) );
	const finalState = await getNavigatorState();

	const cleanupDeleted = await page.evaluate( async ( id ) => {
		const result = await window.wp.data
			.dispatch( 'core' )
			.deleteEntityRecord( 'postType', 'slideshow', id, { force: true } );

		return true === result?.deleted;
	}, postId );
	const duplicateRegistrationWarnings = consoleWarnings.filter( ( warning ) =>
		/already registered/i.test( warning )
	);
	const passed =
		initialSemantics.hasNavigation &&
		initialSemantics.hasOrderedList &&
		60 === initialSemantics.slideSelectCount &&
		Object.values( labels ).every( ( count ) => 1 === count ) &&
		hiddenState.hasBadge &&
		hiddenState.hasShowControl &&
		selectedSecond &&
		61 === duplicateState.slideIds.length &&
		61 === new Set( duplicateState.anchors ).size &&
		finalSlideSelected &&
		JSON.stringify( initialState.anchors ) ===
			JSON.stringify( finalState.anchors ) &&
		JSON.stringify( initialState.hidden ) ===
			JSON.stringify( finalState.hidden ) &&
		cleanupDeleted &&
		0 === pageErrors.length &&
		0 === consoleErrors.length &&
		0 === duplicateRegistrationWarnings.length;

	console.log(
		JSON.stringify(
			{
				cleanupDeleted,
				consoleErrors,
				consoleWarningCount: consoleWarnings.length,
				duplicateRegistrationWarnings,
				finalSlideSelected,
				hiddenState,
				initialSemantics,
				labels,
				pageErrors,
				passed,
				postId,
				selectedSecond,
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
