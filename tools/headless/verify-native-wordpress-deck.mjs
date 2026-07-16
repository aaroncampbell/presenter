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

	const result = await page.evaluate( () => {
		const instance = window.presenterReveal.getInstance();
		const directSlides = document.querySelectorAll(
			'[data-presenter-reveal-root] > .slides > section'
		);
		const config = instance.getConfig();

		instance.slide( 1 );

		return {
			currentSlideId: instance.getCurrentSlide()?.id,
			directSlideCount: directSlides.length,
			height: config.height,
			hasDeckWrapper: Boolean(
				document.querySelector(
					'[data-presenter-reveal-root] > .slides > .wp-block-presenter-deck'
				)
			),
			notesMarkdown: Boolean(
				document.querySelector(
					'#second-native > aside.notes[data-markdown]'
				)
			),
			width: config.width,
		};
	} );

	const passed =
		2 === result.directSlideCount &&
		! result.hasDeckWrapper &&
		1440 === result.width &&
		810 === result.height &&
		'second-native' === result.currentSlideId &&
		result.notesMarkdown &&
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
