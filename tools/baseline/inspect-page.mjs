/* eslint-disable no-console -- This command reports browser diagnostics to the terminal. */
import { chromium } from '@playwright/test';

const url =
	process.argv[ 2 ] ||
	'http://localhost:8890/slideshow/wordcamp-asia-2024-building-and-leveraging-your-reputation/';
const browser = await chromium.launch();
const page = await browser.newPage( {
	viewport: { height: 720, width: 1280 },
} );
const criticalErrors = [];
const warnings = [];
const loadedAssets = [];

page.on( 'console', ( message ) => {
	if ( message.type() === 'error' ) {
		const text = `console: ${ message.text() }`;
		if (
			message.text().includes( 'Content Security Policy' ) ||
			message.text().includes( 'Failed to load resource' )
		) {
			warnings.push( text );
		} else {
			criticalErrors.push( text );
		}
	}
} );
page.on( 'pageerror', ( error ) =>
	criticalErrors.push( `page: ${ error.message }` )
);
page.on( 'requestfailed', ( request ) => {
	const issue = `request: ${ request.url() } (${
		request.failure()?.errorText || 'failed'
	})`;
	if ( new URL( request.url() ).origin === new URL( url ).origin ) {
		criticalErrors.push( issue );
	} else {
		warnings.push( issue );
	}
} );
page.on( 'response', ( response ) => {
	if (
		[ 'script', 'stylesheet' ].includes( response.request().resourceType() )
	) {
		loadedAssets.push( `${ response.status() } ${ response.url() }` );
	}
	if ( response.status() >= 400 ) {
		const issue = `response: ${ response.status() } ${ response.url() }`;
		if (
			[ 'script', 'stylesheet' ].includes(
				response.request().resourceType()
			)
		) {
			criticalErrors.push( issue );
		} else {
			warnings.push( issue );
		}
	}
} );

const response = await page.goto( url, { waitUntil: 'networkidle' } );
await page.waitForTimeout( 1000 );

const state = await page.evaluate( () => ( {
	currentSlideId:
		document.querySelector( '.reveal .slides section.present' )?.id || null,
	revealAvailable: typeof window.Reveal !== 'undefined',
	revealReady:
		typeof window.Reveal !== 'undefined' &&
		typeof window.Reveal.isReady === 'function' &&
		window.Reveal.isReady(),
	sectionCount: document.querySelectorAll( '.reveal .slides > section' )
		.length,
} ) );

console.log( `HTTP status: ${ response?.status() || 'unknown' }` );
console.log( `Reveal available: ${ state.revealAvailable }` );
console.log( `Reveal ready: ${ state.revealReady }` );
console.log( `Current slide: ${ state.currentSlideId || 'none' }` );
console.log( `Top-level sections: ${ state.sectionCount }` );

for ( const asset of loadedAssets ) {
	console.log( `asset: ${ asset }` );
}

for ( const warning of warnings ) {
	console.warn( warning );
}

for ( const error of criticalErrors ) {
	console.error( error );
}

await browser.close();

if (
	! state.revealReady ||
	! state.currentSlideId ||
	criticalErrors.length > 0
) {
	process.exitCode = 1;
}
