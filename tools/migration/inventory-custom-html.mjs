/* eslint-disable no-console -- This local audit emits a content-free inventory. */
/** Inventory Custom HTML that would remain after full legacy conversion. */

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

import { chromium } from '@playwright/test';

const repositoryRoot = path.resolve( import.meta.dirname, '..', '..' );
const snapshotEnvironment = path.join(
	repositoryRoot,
	'tools',
	'snapshot',
	'env'
);
const wpEnv = path.join(
	repositoryRoot,
	'node_modules',
	'@wordpress',
	'env',
	'bin',
	'wp-env'
);
const baseUrl = process.env.PRESENTER_SNAPSHOT_URL ?? 'http://localhost:8890';
const username = process.env.PRESENTER_SNAPSHOT_ADMIN_USER ?? 'presenter-local';
const password = process.env.PRESENTER_SNAPSHOT_ADMIN_PASSWORD;
const origin = new URL( baseUrl );

if (
	'http:' !== origin.protocol ||
	! [ 'localhost', '127.0.0.1' ].includes( origin.hostname )
) {
	throw new Error( 'Custom HTML inventory is restricted to local HTTP.' );
}
if ( ! password ) {
	throw new Error( 'PRESENTER_SNAPSHOT_ADMIN_PASSWORD is required.' );
}

function runWp( args ) {
	const result = spawnSync(
		process.execPath,
		[ wpEnv, 'run', 'cli', 'wp', ...args ],
		{
			cwd: snapshotEnvironment,
			encoding: 'utf8',
			maxBuffer: 64 * 1024 * 1024,
			windowsHide: true,
		}
	);
	if ( result.error || 0 !== result.status ) {
		throw new Error( 'Snapshot inventory query failed.' );
	}
	return result.stdout;
}

function snapshotSlides() {
	const code = String.raw`
$query = new WP_Query( array(
	'post_type' => 'slideshow',
	'post_status' => array_keys( get_post_stati() ),
	'posts_per_page' => -1,
	'fields' => 'ids',
	'orderby' => 'ID',
	'order' => 'ASC',
	'meta_key' => '_presenter_slides',
	'suppress_filters' => true,
) );
$result = array();
foreach ( $query->posts as $post_id ) {
	$slides = get_post_meta( $post_id, '_presenter_slides', false );
	foreach ( $slides as $offset => $slide ) {
		$content = is_object( $slide ) && isset( $slide->content ) ? (string) $slide->content : '';
		$blocks = apply_filters( 'presenter_migration_slide_blocks', null, $content, array(), $offset );
		$result[] = array(
			'postId' => (int) $post_id,
			'slide' => is_object( $slide ) && isset( $slide->number ) ? (int) $slide->number : $offset + 1,
			'convertedBlocks' => is_array( $blocks ) ? array_values( array_map( static fn( $block ) => $block['blockName'] ?? null, $blocks ) ) : null,
			'content' => is_array( $blocks ) ? null : base64_encode( $content ),
		);
	}
}
echo wp_json_encode( $result );`;
	const output = runWp( [ 'eval', code ] );
	const line = output
		.split( /\r?\n/ )
		.map( ( candidate ) => candidate.trim() )
		.find( ( candidate ) => candidate.startsWith( '[' ) );
	if ( ! line ) {
		throw new Error( 'Snapshot inventory returned no data.' );
	}
	const slides = JSON.parse( line );
	if ( ! Array.isArray( slides ) ) {
		throw new Error( 'Snapshot inventory returned invalid data.' );
	}
	return slides.map( ( slide ) => ( {
		...slide,
		content:
			typeof slide.content === 'string'
				? Buffer.from( slide.content, 'base64' ).toString( 'utf8' )
				: null,
	} ) );
}

function addSample( collection, key, location ) {
	collection[ key ] ??= [];
	if ( collection[ key ].length < 12 ) {
		collection[ key ].push( location );
	}
}

const slides = snapshotSlides();
const browser = await chromium.launch( { headless: true } );
const page = await browser.newPage();

try {
	await page.goto( new URL( '/wp-login.php', origin ).href, {
		waitUntil: 'domcontentloaded',
	} );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /\/wp-admin\// );
	await page.goto(
		new URL( '/wp-admin/post-new.php?post_type=slideshow', origin ).href,
		{ waitUntil: 'domcontentloaded' }
	);
	await page.waitForFunction(
		() =>
			typeof window.wp?.blocks?.rawHandler === 'function' &&
			typeof window.wp?.autop?.autop === 'function'
	);

	const report = {
		schemaVersion: 1,
		deckCount: new Set( slides.map( ( slide ) => slide.postId ) ).size,
		slideCount: slides.length,
		completeSlideConversions: 0,
		completeSlideBlockCounts: {},
		rawHandlerSlides: 0,
		nativeOnlySlides: 0,
		mixedSlides: 0,
		customHtmlOnlySlides: 0,
		customHtmlBlockCount: 0,
		customHtmlSignatures: {},
		samples: {},
	};

	for ( const slide of slides ) {
		const location = { postId: slide.postId, slide: slide.slide };
		if ( Array.isArray( slide.convertedBlocks ) ) {
			report.completeSlideConversions++;
			for ( const name of slide.convertedBlocks ) {
				report.completeSlideBlockCounts[ name ] =
					( report.completeSlideBlockCounts[ name ] ?? 0 ) + 1;
			}
			continue;
		}

		assert.equal( typeof slide.content, 'string' );
		const inspected = await page.evaluate( ( html ) => {
			const container = document.createElement( 'div' );
			container.innerHTML = window.wp.autop.autop( html );
			container
				.querySelectorAll( 'header, div' )
				.forEach( ( element ) => {
					if ( 0 === element.attributes.length ) {
						element.replaceWith( ...element.childNodes );
					}
				} );
			container
				.querySelectorAll( 'blockquote > footer' )
				.forEach( ( element ) => {
					if ( 0 === element.attributes.length ) {
						element.replaceWith( ...element.childNodes );
					}
				} );
			const blocks = window.wp.blocks.rawHandler( {
				HTML: container.innerHTML,
			} );
			const signatures = [];
			let blockCount = 0;
			let htmlCount = 0;
			const visit = ( items ) => {
				for ( const block of items ) {
					blockCount++;
					if ( 'core/html' === block.name ) {
						htmlCount++;
						const htmlContainer = document.createElement( 'div' );
						htmlContainer.innerHTML =
							block.attributes.content ?? '';
						const tags = new Set(
							[ ...htmlContainer.querySelectorAll( '*' ) ].map(
								( node ) => node.tagName.toLowerCase()
							)
						);
						const categories = [
							[ 'script', tags.has( 'script' ) ],
							[ 'style', tags.has( 'style' ) ],
							[ 'iframe', tags.has( 'iframe' ) ],
							[ 'svg', tags.has( 'svg' ) ],
							[ 'table', tags.has( 'table' ) ],
							[ 'definition-list', tags.has( 'dl' ) ],
							[ 'form', tags.has( 'form' ) ],
							[
								'media',
								[ 'audio', 'video', 'object', 'embed' ].some(
									( tag ) => tags.has( tag )
								),
							],
							[ 'canvas', tags.has( 'canvas' ) ],
							[
								'custom-element',
								[ ...tags ].some( ( tag ) =>
									tag.includes( '-' )
								),
							],
						]
							.filter( ( entry ) => entry[ 1 ] )
							.map( ( entry ) => entry[ 0 ] );
						signatures.push(
							0 < categories.length
								? categories.sort().join( '+' )
								: `markup:${
										[ ...tags ].sort().join( '+' ) || 'text'
								  }`
						);
					}
					visit( block.innerBlocks ?? [] );
				}
			};
			visit( blocks );
			return { blockCount, htmlCount, signatures };
		}, slide.content );

		report.rawHandlerSlides++;
		report.customHtmlBlockCount += inspected.htmlCount;
		if ( 0 === inspected.htmlCount ) {
			report.nativeOnlySlides++;
		} else if ( inspected.htmlCount === inspected.blockCount ) {
			report.customHtmlOnlySlides++;
		} else {
			report.mixedSlides++;
		}
		for ( const signature of inspected.signatures ) {
			report.customHtmlSignatures[ signature ] =
				( report.customHtmlSignatures[ signature ] ?? 0 ) + 1;
			addSample( report.samples, signature, location );
		}
	}

	report.customHtmlSignatures = Object.fromEntries(
		Object.entries( report.customHtmlSignatures ).sort(
			( left, right ) =>
				right[ 1 ] - left[ 1 ] || left[ 0 ].localeCompare( right[ 0 ] )
		)
	);
	console.log( JSON.stringify( report, null, 2 ) );
} finally {
	await browser.close();
}
