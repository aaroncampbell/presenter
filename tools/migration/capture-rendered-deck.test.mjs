import assert from 'node:assert/strict';
import test from 'node:test';

import { chromium } from '@playwright/test';

import {
	captureCanonicalStructure,
	captureDeckMetadata,
} from './capture-rendered-deck.mjs';

const withPage = async ( callback ) => {
	const browser = await chromium.launch( { headless: true } );
	try {
		const page = await browser.newPage();
		return await callback( page );
	} finally {
		await browser.close();
	}
};

test( 'canonical DOM removes only Reveal-owned slide and fragment state', () =>
	withPage( async ( page ) => {
		await page.setContent( `
			<div class="reveal"><div class="slides">
				<section class="authored present has-dark-background" hidden aria-hidden="true" data-index-h="0" data-fragment="-1" data-authored="yes" style="color: red; display: none; top: 42px">
					<span class="visible authored-child" aria-hidden="true">Authored accessibility</span>
					<p class="fragment custom visible current-fragment" data-fragment-index="2" data-auto-animate-target="runtime-id" aria-hidden="true">Fragment</p>
				</section>
			</div></div>
		` );

		const [ slide ] = await captureCanonicalStructure( page );
		assert.deepEqual( slide.attributes, {
			class: 'authored',
			'data-authored': 'yes',
			style: 'color: red;',
		} );
		assert.match(
			slide.canonicalHtml,
			/<span aria-hidden="true" class="authored-child visible">/
		);
		assert.match(
			slide.canonicalHtml,
			/<p aria-hidden="true" class="custom fragment" data-fragment-index="2">/
		);
		assert.equal(
			slide.canonicalHtml.includes( 'has-dark-background' ),
			false
		);
		assert.equal( slide.canonicalHtml.includes( 'data-index-h' ), false );
		assert.equal( slide.canonicalHtml.includes( ' hidden' ), false );
	} ) );

test( 'metadata treats Reveal 4 and 6 theme paths and URL options semantically', () =>
	withPage( async ( page ) => {
		await page.setContent( `
			<link id="reveal-css" rel="stylesheet" href="data:text/css,core">
			<link id="reveal-theme-css" rel="stylesheet" href="http://localhost:8890/wp-content/plugins/presenter/reveal.js/dist/theme/black.css?ver=4.3.1">
			<div class="reveal"><div class="slides"><section></section></div></div>
		` );
		await page.evaluate( () => {
			window.Reveal = {
				getConfig: () => ( {
					height: 700,
					history: true,
					hash: false,
					width: 960,
				} ),
			};
		} );
		const legacy = await captureDeckMetadata( page );

		await page.evaluate( () => {
			document.querySelector( '#reveal-theme-css' ).href =
				'http://localhost:8890/wp-content/plugins/presenter/build/reveal/theme/black.css?ver=6.0.1';
			window.Reveal.getConfig = () => ( {
				height: 700,
				history: false,
				hash: true,
				width: 960,
			} );
		} );
		const native = await captureDeckMetadata( page );

		assert.deepEqual( legacy.semanticConfig, { urlNavigation: true } );
		assert.deepEqual( native.semanticConfig, legacy.semanticConfig );
		assert.deepEqual( legacy.themeStylesheets, [
			{ identity: 'builtin:black', media: 'all' },
		] );
		assert.deepEqual( native.themeStylesheets, legacy.themeStylesheets );
	} ) );
