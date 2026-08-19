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
$section_validator = new \Presenter\Legacy_Section_Validator();
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
			'sectionClassification' => $section_validator->classify( $content ),
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

function snapshotPlans() {
	const output = runWp( [
		'presenter',
		'migration',
		'dry-run',
		'--limit=100',
		'--offset=0',
	] );
	const line = output
		.split( /\r?\n/ )
		.map( ( candidate ) => candidate.trim() )
		.find( ( candidate ) => candidate.startsWith( '{' ) );
	if ( ! line ) {
		throw new Error( 'Snapshot planner audit returned no data.' );
	}

	const envelope = JSON.parse( line );
	if (
		1 !== envelope.schemaVersion ||
		'dry-run' !== envelope.mode ||
		! Array.isArray( envelope.reports ) ||
		envelope.count !== envelope.reports.length
	) {
		throw new Error( 'Snapshot planner audit returned invalid data.' );
	}

	const planner = {
		deckCount: envelope.count,
		readyDecks: 0,
		blockedDecks: 0,
		outcomes: {},
		warningCodes: {},
	};
	for ( const report of envelope.reports ) {
		if ( 'ready' === report.status ) {
			planner.readyDecks++;
		} else {
			planner.blockedDecks++;
		}
		for ( const slide of report.slides ?? [] ) {
			planner.outcomes[ slide.outcome ] =
				( planner.outcomes[ slide.outcome ] ?? 0 ) + 1;
			for ( const warning of slide.warningCodes ?? [] ) {
				planner.warningCodes[ warning ] =
					( planner.warningCodes[ warning ] ?? 0 ) + 1;
			}
		}
	}

	return planner;
}

function addSample( collection, key, location ) {
	collection[ key ] ??= [];
	if ( collection[ key ].length < 12 ) {
		collection[ key ].push( location );
	}
}

const slides = snapshotSlides();
const planner = snapshotPlans();
assert.equal(
	planner.deckCount,
	new Set( slides.map( ( slide ) => slide.postId ) ).size
);
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
			typeof window.wp?.blocks?.getBlockContent === 'function' &&
			typeof window.wp?.blocks?.parse === 'function' &&
			typeof window.wp?.blocks?.serialize === 'function' &&
			typeof window.wp?.autop?.autop === 'function'
	);

	const report = {
		schemaVersion: 5,
		deckCount: new Set( slides.map( ( slide ) => slide.postId ) ).size,
		slideCount: slides.length,
		planner,
		completeSlideConversions: 0,
		completeSlideBlockCounts: {},
		classedGroupConversions: 0,
		legacyPanelConversions: 0,
		quoteCitationConversions: 0,
		rawHandlerSlides: 0,
		nativeOnlySlides: 0,
		mixedSlides: 0,
		customHtmlOnlySlides: 0,
		customHtmlBlockCount: 0,
		stackSlidesWithCustomHtml: 0,
		stackCustomHtmlBlockCount: 0,
		stackClassifications: {},
		nonStackSlidesWithCustomHtml: 0,
		nonStackCustomHtmlBlockCount: 0,
		customHtmlSignatures: {},
		nonStackCustomHtmlSignatures: {},
		nonStackCustomHtmlStructures: {},
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
			const getHtmlBlockContent = ( block ) =>
				'core/html' === block?.name
					? window.wp.blocks.getBlockContent( block )
					: '';
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
			let quoteCitationConversions = 0;
			const promoteQuoteCitations = ( items ) =>
				items.map( ( block ) => {
					const innerBlocks = promoteQuoteCitations(
						block.innerBlocks ?? []
					);
					if (
						'core/quote' !== block.name ||
						'' !== String( block.attributes?.citation ?? '' )
					) {
						return { ...block, innerBlocks };
					}

					const candidates = innerBlocks
						.map( ( innerBlock ) => {
							if ( 'core/html' !== innerBlock.name ) {
								return null;
							}
							const citationContainer =
								document.createElement( 'div' );
							citationContainer.innerHTML =
								getHtmlBlockContent( innerBlock );
							const meaningfulNodes = [
								...citationContainer.childNodes,
							].filter(
								( node ) =>
									1 === node.nodeType ||
									( 3 === node.nodeType &&
										'' !== node.textContent.trim() )
							);
							const cite = meaningfulNodes[ 0 ];
							return 1 === meaningfulNodes.length &&
								1 === cite?.nodeType &&
								'CITE' === cite.tagName &&
								0 === cite.attributes.length &&
								0 === cite.children.length
								? { block: innerBlock, cite }
								: null;
						} )
						.filter( Boolean );
					if ( 1 !== candidates.length ) {
						return { ...block, innerBlocks };
					}
					const [ candidate ] = candidates;
					quoteCitationConversions++;

					return {
						...block,
						attributes: {
							...block.attributes,
							citation: candidate.cite.innerHTML,
						},
						innerBlocks: innerBlocks.filter(
							( innerBlock ) => innerBlock !== candidate.block
						),
					};
				} );
			let classedGroupConversions = 0;
			let legacyPanelConversions = 0;
			const containsCustomHtml = ( items ) =>
				items.some(
					( block ) =>
						'core/html' === block.name ||
						containsCustomHtml( block.innerBlocks ?? [] )
				);
			const promoteLegacyGroups = ( items ) =>
				items.map( ( block ) => {
					const innerBlocks = promoteLegacyGroups(
						block.innerBlocks ?? []
					);
					if ( 'core/html' !== block.name ) {
						return { ...block, innerBlocks };
					}

					const wrapperContainer = document.createElement( 'div' );
					wrapperContainer.innerHTML = getHtmlBlockContent( block );
					const meaningfulNodes = [
						...wrapperContainer.childNodes,
					].filter(
						( node ) =>
							1 === node.nodeType ||
							( 3 === node.nodeType &&
								'' !== node.textContent.trim() )
					);
					const wrapper = meaningfulNodes[ 0 ];
					if (
						1 !== meaningfulNodes.length ||
						1 !== wrapper?.nodeType ||
						'DIV' !== wrapper.tagName ||
						1 !== wrapper.attributes.length
					) {
						return { ...block, innerBlocks };
					}

					let hasTopMargin = false;
					const isClassedGroup =
						wrapper.hasAttribute( 'class' ) &&
						'' !== wrapper.className.trim();
					if ( ! isClassedGroup ) {
						if ( ! wrapper.hasAttribute( 'style' ) ) {
							return { ...block, innerBlocks };
						}
						const properties = Array.from(
							{ length: wrapper.style.length },
							( unused, index ) => wrapper.style.item( index )
						).sort();
						const propertySets = [
							[ 'background-color', 'padding' ],
							[
								'background-color',
								'padding-bottom',
								'padding-left',
								'padding-right',
								'padding-top',
							],
						];
						const same = ( left, right ) =>
							left.length === right.length &&
							left.every(
								( value, index ) => value === right[ index ]
							);
						hasTopMargin = propertySets.some( ( propertySet ) =>
							same(
								properties,
								[ ...propertySet, 'margin-top' ].sort()
							)
						);
						if (
							( ! hasTopMargin &&
								! propertySets.some( ( propertySet ) =>
									same( properties, propertySet )
								) ) ||
							'rgba(0, 0, 0, 0.7)' !==
								wrapper.style.backgroundColor ||
							'20px' !== wrapper.style.padding ||
							( hasTopMargin &&
								'16em' !== wrapper.style.marginTop ) ||
							properties.some( ( property ) =>
								wrapper.style.getPropertyPriority( property )
							)
						) {
							return { ...block, innerBlocks };
						}

						const panelChildren = [ ...wrapper.childNodes ].filter(
							( node ) =>
								1 === node.nodeType ||
								( 3 === node.nodeType &&
									'' !== node.textContent.trim() )
						);
						const heading = panelChildren[ 0 ];
						if (
							1 !== panelChildren.length ||
							1 !== heading?.nodeType ||
							'H2' !== heading.tagName ||
							0 !== heading.attributes.length
						) {
							return { ...block, innerBlocks };
						}
					}

					const groupBlocks = promoteLegacyGroups(
						window.wp.blocks.rawHandler( {
							HTML: wrapper.innerHTML,
						} )
					);
					if (
						containsCustomHtml( groupBlocks ) ||
						( ! isClassedGroup &&
							( 1 !== groupBlocks.length ||
								'core/heading' !== groupBlocks[ 0 ].name ) )
					) {
						return { ...block, innerBlocks };
					}

					const attributes = {
						layout: { type: 'default' },
					};
					if ( isClassedGroup ) {
						attributes.className = wrapper.className;
						classedGroupConversions++;
					} else {
						const spacing = {
							padding: {
								top: '20px',
								right: '20px',
								bottom: '20px',
								left: '20px',
							},
						};
						if ( hasTopMargin ) {
							spacing.margin = { top: '16em' };
						}
						attributes.style = {
							color: {
								background: 'rgba(0, 0, 0, 0.7)',
							},
							spacing,
						};
						legacyPanelConversions++;
					}

					return window.wp.blocks.createBlock(
						'core/group',
						attributes,
						groupBlocks
					);
				} );
			const convertedBlocks = promoteQuoteCitations(
				promoteLegacyGroups(
					window.wp.blocks.rawHandler( {
						HTML: container.innerHTML,
					} )
				)
			);
			const blocks = window.wp.blocks.parse(
				window.wp.blocks.serialize( convertedBlocks )
			);
			const htmlBlocks = [];
			let blockCount = 0;
			let htmlCount = 0;
			const describeElement = ( element, depth = 0 ) => {
				const tag = element.tagName.toLowerCase();
				const classes = [ ...element.classList ].sort();
				const styles = [ ...element.style ].sort();
				const attributes = [ ...element.attributes ]
					.map( ( attribute ) => attribute.name.toLowerCase() )
					.filter( ( name ) => 'class' !== name && 'style' !== name )
					.sort();
				const details = [
					0 < classes.length ? `class:${ classes.join( '.' ) }` : '',
					0 < styles.length ? `style:${ styles.join( ',' ) }` : '',
					0 < attributes.length
						? `attrs:${ attributes.join( ',' ) }`
						: '',
				]
					.filter( Boolean )
					.join( ';' );
				const children =
					depth < 3
						? [ ...element.children ].map( ( child ) =>
								describeElement( child, depth + 1 )
						  )
						: [];
				return `${ tag }${ details ? `[${ details }]` : '' }${
					0 < children.length ? `(${ children.join( ',' ) })` : ''
				}`;
			};
			const visit = ( items, parentPath = 'root' ) => {
				for ( const block of items ) {
					blockCount++;
					const attributeKeys = Object.keys( block.attributes ?? {} )
						.filter( ( key ) => 'content' !== key )
						.sort();
					const blockPath = `${ parentPath }>${
						block.name ?? 'unknown'
					}${
						0 < attributeKeys.length
							? `[attrs:${ attributeKeys.join( ',' ) }]`
							: ''
					}`;
					if ( 'core/html' === block.name ) {
						htmlCount++;
						const htmlContainer = document.createElement( 'div' );
						htmlContainer.innerHTML = getHtmlBlockContent( block );
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
						const signature =
							0 < categories.length
								? categories.sort().join( '+' )
								: `markup:${
										[ ...tags ].sort().join( '+' ) || 'text'
								  }`;
						const roots = [ ...htmlContainer.children ].map(
							( element ) => describeElement( element )
						);
						htmlBlocks.push( {
							signature,
							structure: `${ blockPath }|${
								roots.join( '+' ) || 'text'
							}`,
						} );
					}
					visit( block.innerBlocks ?? [], blockPath );
				}
			};
			visit( blocks );
			return {
				blockCount,
				classedGroupConversions,
				htmlBlocks,
				htmlCount,
				legacyPanelConversions,
				quoteCitationConversions,
			};
		}, slide.content );

		report.rawHandlerSlides++;
		report.classedGroupConversions += inspected.classedGroupConversions;
		report.legacyPanelConversions += inspected.legacyPanelConversions;
		report.quoteCitationConversions += inspected.quoteCitationConversions;
		report.customHtmlBlockCount += inspected.htmlCount;
		if ( 0 < inspected.htmlCount ) {
			if ( typeof slide.sectionClassification === 'string' ) {
				report.stackSlidesWithCustomHtml++;
				report.stackCustomHtmlBlockCount += inspected.htmlCount;
				report.stackClassifications[ slide.sectionClassification ] =
					( report.stackClassifications[
						slide.sectionClassification
					] ?? 0 ) + 1;
			} else {
				report.nonStackSlidesWithCustomHtml++;
				report.nonStackCustomHtmlBlockCount += inspected.htmlCount;
			}
		}
		if ( 0 === inspected.htmlCount ) {
			report.nativeOnlySlides++;
		} else if ( inspected.htmlCount === inspected.blockCount ) {
			report.customHtmlOnlySlides++;
		} else {
			report.mixedSlides++;
		}
		for ( const htmlBlock of inspected.htmlBlocks ) {
			const { signature, structure } = htmlBlock;
			report.customHtmlSignatures[ signature ] =
				( report.customHtmlSignatures[ signature ] ?? 0 ) + 1;
			addSample( report.samples, signature, location );
			if ( null === slide.sectionClassification ) {
				report.nonStackCustomHtmlSignatures[ signature ] =
					( report.nonStackCustomHtmlSignatures[ signature ] ?? 0 ) +
					1;
				report.nonStackCustomHtmlStructures[ structure ] =
					( report.nonStackCustomHtmlStructures[ structure ] ?? 0 ) +
					1;
				addSample( report.samples, structure, location );
			}
		}
	}

	report.customHtmlSignatures = Object.fromEntries(
		Object.entries( report.customHtmlSignatures ).sort(
			( left, right ) =>
				right[ 1 ] - left[ 1 ] || left[ 0 ].localeCompare( right[ 0 ] )
		)
	);
	report.nonStackCustomHtmlSignatures = Object.fromEntries(
		Object.entries( report.nonStackCustomHtmlSignatures ).sort(
			( left, right ) =>
				right[ 1 ] - left[ 1 ] || left[ 0 ].localeCompare( right[ 0 ] )
		)
	);
	report.nonStackCustomHtmlStructures = Object.fromEntries(
		Object.entries( report.nonStackCustomHtmlStructures ).sort(
			( left, right ) =>
				right[ 1 ] - left[ 1 ] || left[ 0 ].localeCompare( right[ 0 ] )
		)
	);
	console.log( JSON.stringify( report, null, 2 ) );
} finally {
	await browser.close();
}
