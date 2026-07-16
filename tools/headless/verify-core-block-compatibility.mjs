/* eslint-disable no-console -- This command emits verification results. */
/**
 * Verify representative WordPress core blocks through the presentation route.
 */

import { chromium } from '@playwright/test';

const targetUrl =
	process.env.PRESENTER_CORE_BLOCKS_URL ??
	'http://localhost:8888/?slideshow=presenter-m4-core-block-compatibility';
const browser = await chromium.launch( { headless: true } );
const page = await browser.newPage();
const pageErrors = [];
const consoleErrors = [];

page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
page.on( 'console', ( message ) => {
	if ( message.type() === 'error' ) {
		consoleErrors.push( message.text() );
	}
} );

try {
	const response = await page.goto( targetUrl, { waitUntil: 'networkidle' } );

	if ( ! response?.ok() ) {
		throw new Error(
			`Core-block deck returned HTTP ${ response?.status() }.`
		);
	}

	await page.waitForFunction(
		() => window.presenterReveal?.getInstance()?.isReady() === true
	);
	await page.evaluate( () =>
		window.presenterReveal.getInstance().slide( 1 )
	);

	const accordionButton = page.getByRole( 'button', {
		name: 'Reveal compatibility details',
	} );
	await accordionButton.evaluate( ( button ) => button.click() );
	await page.waitForTimeout( 100 );
	await accordionButton.evaluate( ( button ) => {
		button.click();
		button.focus();
	} );
	await page.keyboard.press( 'Enter' );
	await page.waitForTimeout( 100 );

	const result = await page.evaluate( () => {
		const instance = window.presenterReveal.getInstance();
		const slides = document.querySelectorAll(
			'[data-presenter-reveal-root] > .slides > section'
		);
		const image = document.querySelector(
			'#core-media-interactive img[alt="WordPress logo compatibility sentinel"]'
		);
		const accordionToggle = document.querySelector(
			'#core-media-interactive .wp-block-accordion-heading__toggle'
		);
		const accordionPanel = document.querySelector(
			'#core-media-interactive .wp-block-accordion-panel'
		);
		const shortcodeText =
			document.querySelector( '#core-server' )?.textContent;
		instance.slide( 2 );

		return {
			accordionExpanded:
				accordionToggle?.getAttribute( 'aria-expanded' ) === 'true',
			accordionPanelVisible:
				accordionPanel &&
				! accordionPanel.hasAttribute( 'inert' ) &&
				accordionPanel.getAttribute( 'aria-hidden' ) !== 'true',
			buttonHref: document
				.querySelector(
					'#core-media-interactive .wp-block-button__link'
				)
				?.getAttribute( 'href' ),
			codeText:
				document.querySelector( '#core-static code' )?.textContent,
			directSlideCount: slides.length,
			dynamicPostVisible: Boolean(
				[ ...document.querySelectorAll( '#core-server a' ) ].find(
					( link ) =>
						link.textContent?.trim() ===
						'Presenter dynamic post sentinel'
				)
			),
			hasExpectedColumns:
				document.querySelectorAll(
					'#core-static .wp-block-columns > .wp-block-column'
				).length === 2,
			imageLoaded: Boolean( image?.complete && image?.naturalWidth > 0 ),
			listItems: [
				...document.querySelectorAll(
					'#core-static .wp-block-list li'
				),
			].map( ( item ) => item.textContent?.trim() ),
			paragraphLink: document.querySelector( '#core-static p a' )?.href,
			navigationAfterInteraction:
				instance.getCurrentSlide()?.id === 'core-server',
			shortcodeRendered:
				Boolean(
					shortcodeText?.includes(
						'?slideshow=presenter-m4-core-block-compatibility'
					)
				) && ! shortcodeText?.includes( '[presenter-url]' ),
			slideLabels: [ ...slides ].map( ( slide ) =>
				slide.getAttribute( 'aria-label' )
			),
		};
	} );

	const passed =
		3 === result.directSlideCount &&
		result.hasExpectedColumns &&
		JSON.stringify( [ 'Alpha sentinel', 'Beta sentinel' ] ) ===
			JSON.stringify( result.listItems ) &&
		'const presenter = true;' === result.codeText &&
		'https://wordpress.org/' === result.paragraphLink &&
		result.imageLoaded &&
		'#core-static' === result.buttonHref &&
		result.accordionExpanded &&
		result.accordionPanelVisible &&
		result.navigationAfterInteraction &&
		result.shortcodeRendered &&
		result.dynamicPostVisible &&
		JSON.stringify( [
			'Static and nested core blocks',
			'Media and interactive core blocks',
			'Server-rendered core blocks',
		] ) === JSON.stringify( result.slideLabels ) &&
		0 === pageErrors.length &&
		0 === consoleErrors.length;

	console.log(
		JSON.stringify(
			{ ...result, consoleErrors, pageErrors, passed, targetUrl },
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
