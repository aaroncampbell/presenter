/* eslint-disable no-console -- This command emits content-free verification results. */
/**
 * Verify a native Presenter deck through the real local WordPress route.
 */

import { chromium } from '@playwright/test';

const targetUrl =
	process.env.PRESENTER_NATIVE_URL ??
	'http://localhost:8888/?slideshow=presenter-m4-native-smoke';
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
		throw new Error( `Native deck returned HTTP ${ response?.status() }.` );
	}

	await page.waitForFunction(
		() => window.presenterReveal?.getInstance()?.isReady() === true
	);
	await page.keyboard.press( 'Tab' );
	const skipLinkFocused = await page.evaluate( () => {
		const skipLink = document.querySelector( '.skip-link' );

		return skipLink?.ownerDocument.activeElement === skipLink;
	} );
	await page.keyboard.press( 'Enter' );
	const skipTargetFocused = await page.evaluate( () => {
		const target = document.querySelector( '#presenter-presentation' );

		return target?.ownerDocument.activeElement === target;
	} );

	const result = await page.evaluate( () => {
		const instance = window.presenterReveal.getInstance();
		const directSlides = document.querySelectorAll(
			'[data-presenter-reveal-root] > .slides > section'
		);
		const config = instance.getConfig();

		instance.slide( 1 );

		return {
			activeSlideVisible:
				instance.getCurrentSlide()?.getAttribute( 'aria-hidden' ) !==
				'true',
			backgroundCount: document.querySelectorAll(
				'[data-presenter-reveal-root] > .backgrounds .slide-background'
			).length,
			center: config.center,
			controls: config.controls,
			currentSlideId: instance.getCurrentSlide()?.id,
			directSlideCount: directSlides.length,
			firstBackgroundColor: directSlides[ 0 ]?.getAttribute(
				'data-background-color'
			),
			firstBackgroundImage: directSlides[ 0 ]?.getAttribute(
				'data-background-image'
			),
			firstTransition:
				directSlides[ 0 ]?.getAttribute( 'data-transition' ),
			height: config.height,
			hasDeckWrapper: Boolean(
				document.querySelector(
					'[data-presenter-reveal-root] > .slides > .wp-block-presenter-deck'
				)
			),
			hiddenSlideExcluded: ! document.querySelector( '#hidden-native' ),
			inactiveSlideHidden:
				directSlides[ 0 ]?.getAttribute( 'aria-hidden' ) === 'true',
			keyboard: config.keyboard,
			language: document.documentElement.lang,
			liveStatusRegion: Boolean(
				document.querySelector(
					'.aria-status[aria-live="polite"][aria-atomic="true"]'
				)
			),
			notesMarkdown: Boolean(
				document.querySelector(
					'#second-native > aside.notes[data-markdown]'
				)
			),
			margin: config.margin,
			progress: config.progress,
			revealSlideCount: instance.getTotalSlides(),
			themeStylesheet:
				document.querySelector( '#reveal-theme-css' )?.href,
			transition: config.transition,
			backgroundTransition: config.backgroundTransition,
			title: document.title,
			viewportMetaCount: document.querySelectorAll(
				'meta[name="viewport"]'
			).length,
			width: config.width,
		};
	} );

	const passed =
		2 === result.directSlideCount &&
		2 === result.revealSlideCount &&
		2 === result.backgroundCount &&
		! result.hasDeckWrapper &&
		result.hiddenSlideExcluded &&
		1440 === result.width &&
		810 === result.height &&
		0.1 === result.margin &&
		false === result.controls &&
		false === result.progress &&
		false === result.center &&
		true === result.keyboard &&
		'convex' === result.transition &&
		'zoom' === result.backgroundTransition &&
		'second-native' === result.currentSlideId &&
		'fade' === result.firstTransition &&
		'#123456' === result.firstBackgroundColor &&
		'http://localhost:8888/wp-includes/images/w-logo-blue-white-bg.png' ===
			result.firstBackgroundImage &&
		result.themeStylesheet?.includes( '/build/reveal/theme/white.css' ) &&
		result.notesMarkdown &&
		result.activeSlideVisible &&
		result.inactiveSlideHidden &&
		result.liveStatusRegion &&
		Boolean( result.language ) &&
		Boolean( result.title ) &&
		1 === result.viewportMetaCount &&
		skipLinkFocused &&
		skipTargetFocused &&
		0 === pageErrors.length &&
		0 === consoleErrors.length;

	console.log(
		JSON.stringify(
			{
				...result,
				consoleErrors,
				pageErrors,
				passed,
				skipLinkFocused,
				skipTargetFocused,
				targetUrl,
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
