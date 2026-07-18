/* eslint-disable no-console -- This command emits a compact verification result. */
/**
 * Compare characterized nested-section behavior in Reveal 4 and Reveal 6.
 */

import assert from 'node:assert/strict';
import path from 'node:path';

import { chromium } from '@playwright/test';

const browser = await chromium.launch( { headless: true } );
const markup = `
	<div class="reveal">
		<div class="slides">
			<section id="legacy-wrapper">
				<section id="opaque-outer">
					<section id="nested-one">One</section>
					<section id="nested-two">Two</section>
				</section>
			</section>
			<section id="horizontal-exit">Exit</section>
		</div>
	</div>
`;

async function characterize( scriptPath ) {
	const page = await browser.newPage();
	const errors = [];
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	await page.setContent( markup );
	await page.addScriptTag( { path: scriptPath } );

	const result = await page.evaluate( async () => {
		await window.Reveal.initialize( {
			controls: false,
			hash: false,
			progress: false,
			transition: 'none',
		} );

		const instance = window.Reveal;
		const initial = {
			current: instance.getCurrentSlide()?.id,
			horizontal: instance
				.getHorizontalSlides()
				.map( ( slide ) => slide.id ),
			indices: instance.getIndices(),
			routes: instance.availableRoutes(),
			slides: instance.getSlides().map( ( slide ) => slide.id ),
			vertical: instance.getVerticalSlides().map( ( slide ) => slide.id ),
		};

		instance.down();
		const afterDown = {
			current: instance.getCurrentSlide()?.id,
			indices: instance.getIndices(),
		};
		instance.right();
		const afterRight = {
			current: instance.getCurrentSlide()?.id,
			indices: instance.getIndices(),
		};

		return { afterDown, afterRight, initial };
	} );

	assert.deepEqual( errors, [] );
	await page.close();

	return result;
}

try {
	const reveal4 = await characterize(
		path.resolve( 'reveal.js/dist/reveal.js' )
	);
	const reveal6 = await characterize(
		path.resolve( 'node_modules/reveal.js/dist/reveal.js' )
	);

	assert.deepEqual( reveal6, reveal4 );
	assert.deepEqual( reveal6.initial.horizontal, [
		'legacy-wrapper',
		'horizontal-exit',
	] );
	assert.deepEqual( reveal6.initial.vertical, [ 'opaque-outer' ] );
	assert.deepEqual( reveal6.initial.slides, [
		'nested-one',
		'nested-two',
		'horizontal-exit',
	] );
	assert.equal( reveal6.initial.current, 'opaque-outer' );
	assert.equal( reveal6.initial.routes.down, false );
	assert.equal( reveal6.afterDown.current, 'opaque-outer' );
	assert.equal( reveal6.afterRight.current, 'horizontal-exit' );
	assert.equal( reveal6.afterRight.indices.h, 1 );

	console.log(
		JSON.stringify( {
			nestedSections: 2,
			passed: true,
			reveal4And6Match: true,
		} )
	);
} finally {
	await browser.close();
}
