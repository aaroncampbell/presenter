/* eslint-disable no-console -- This command emits a compact verification result. */
/**
 * Verify legacy representation behavior through the real WordPress route.
 */

import assert from 'node:assert/strict';

import { chromium } from '@playwright/test';

const targetUrl =
	process.env.PRESENTER_MIGRATION_REPRESENTATION_URL ??
	'http://localhost:8888/?slideshow=presenter-migration-representation-smoke';
const browser = await chromium.launch( { headless: true } );

try {
	const page = await browser.newPage();
	const pageErrors = [];
	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );

	const response = await page.goto( targetUrl, { waitUntil: 'networkidle' } );
	assert.equal( response?.status(), 200 );
	await page.waitForFunction(
		() => window.presenterReveal?.getInstance()?.isReady() === true
	);

	const rendered = await page.evaluate( () => {
		const stack = document.querySelector( '.slides > #legacy-stack' );
		const htmlNotes = document.querySelector( '#html-notes > aside.notes' );
		const markdownHtmlNotes = document.querySelector(
			'#markdown-html-notes > aside.notes'
		);

		return {
			children: [ ...stack.querySelectorAll( ':scope > section' ) ].map(
				( child ) => ( {
					background: child.getAttribute( 'data-background' ),
					backgroundSize: child.getAttribute(
						'data-background-size'
					),
					className: child.className,
					id: child.id,
				} )
			),
			htmlNotes: htmlNotes.innerHTML,
			htmlNotesMarkdown: htmlNotes.hasAttribute( 'data-markdown' ),
			indices: window.presenterReveal.getInstance().getIndices(),
			markdownHtmlNotes: markdownHtmlNotes.innerHTML,
			markdownHtmlNotesMarkdown:
				markdownHtmlNotes.hasAttribute( 'data-markdown' ),
			stackClass: stack.classList.contains( 'stack' ),
		};
	} );

	assert.equal( rendered.stackClass, true );
	assert.equal( rendered.indices.h, 0 );
	assert.equal( rendered.indices.v, 0 );
	assert.deepEqual(
		rendered.children.map( ( child ) => child.id ),
		[ 'vertical-one', 'vertical-two' ]
	);
	assert.equal( rendered.children[ 0 ].background, '#112233' );
	assert( rendered.children[ 0 ].className.includes( 'legacy-vertical' ) );
	assert.equal( rendered.children[ 1 ].backgroundSize, 'contain' );
	assert.equal(
		rendered.htmlNotes,
		'<p>Safe <strong>HTML</strong> notes.</p>'
	);
	assert.equal( rendered.htmlNotesMarkdown, false );
	assert.equal(
		rendered.markdownHtmlNotes.trim(),
		'<p>Safe <strong>Markdown</strong> HTML notes.</p>'
	);
	assert.equal( rendered.markdownHtmlNotesMarkdown, true );

	await page.keyboard.press( 'ArrowDown' );
	await page.waitForFunction(
		() => window.presenterReveal.getInstance().getIndices().v === 1
	);
	await page.keyboard.press( 'ArrowRight' );
	await page.waitForFunction(
		() => window.presenterReveal.getInstance().getIndices().h === 1
	);
	assert.deepEqual( pageErrors, [] );

	const printPage = await browser.newPage();
	const printUrl = new URL( targetUrl );
	printUrl.searchParams.set( 'print-pdf', '' );
	const printResponse = await printPage.goto( printUrl.href, {
		waitUntil: 'networkidle',
	} );
	assert.equal( printResponse?.status(), 200 );
	await printPage.waitForFunction(
		() =>
			window.presenterReveal?.getInstance()?.isReady() === true &&
			document.documentElement.classList.contains( 'print-pdf' )
	);
	assert.equal( await printPage.locator( '#vertical-one' ).count(), 1 );
	assert.equal( await printPage.locator( '#vertical-two' ).count(), 1 );

	console.log(
		JSON.stringify( {
			htmlNotes: true,
			markdownHtmlNotes: true,
			passed: true,
			verticalSlides: 2,
		} )
	);
} finally {
	await browser.close();
}
