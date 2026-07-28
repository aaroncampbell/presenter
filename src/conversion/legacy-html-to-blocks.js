import { autop } from '@wordpress/autop';
import { cloneBlock, rawHandler } from '@wordpress/blocks';

const FRAGMENT_EFFECTS = [
	'fade-out',
	'fade-up',
	'fade-down',
	'fade-left',
	'fade-right',
	'fade-in-then-out',
	'fade-in-then-semi-out',
	'grow',
	'shrink',
	'strike',
	'highlight-red',
	'highlight-green',
	'highlight-blue',
	'highlight-current-red',
	'highlight-current-green',
	'highlight-current-blue',
];
const ELEMENT_NODE = 1;
const TEXT_NODE = 3;

/**
 * Convert one legacy slide body through WordPress's canonical raw HTML handler.
 *
 * The source is paragraphized first because Presenter 1.x passed complete
 * sections through wpautop(). Unsupported markup remains a core/html island.
 *
 * @param {string} html Legacy slide body.
 * @return {{ blocks: Object[], outcome: string }} Conversion result.
 */
export function convertLegacyHtmlToBlocks( html ) {
	if ( 'string' !== typeof html || '' === html.trim() ) {
		return { blocks: [], outcome: 'empty' };
	}

	const paragraphized = normalizeLegacyContainers( autop( html ) );
	const blocks = rawHandler( { HTML: paragraphized } );
	const container = document.createElement( 'div' );
	container.innerHTML = paragraphized;
	const sourceNodes = Array.from( container.childNodes ).filter(
		( node ) =>
			ELEMENT_NODE === node.nodeType ||
			( TEXT_NODE === node.nodeType && '' !== node.textContent.trim() )
	);
	const decorated =
		sourceNodes.length === blocks.length
			? blocks.map( ( block, index ) =>
					decorateFragments( block, sourceNodes[ index ] )
			  )
			: blocks;
	const htmlCount = countBlocksByName( decorated, 'core/html' );
	let outcome = 'mixed';
	if ( 0 === htmlCount ) {
		outcome = 'native';
	} else if ( htmlCount === countBlocks( decorated ) ) {
		outcome = 'custom-html';
	}

	return {
		blocks: decorated,
		outcome,
	};
}

/**
 * Remove legacy wrappers that carry no attributes or Reveal behavior.
 *
 * Canonical section stacks, styled containers, classed layout wrappers, and
 * arbitrary footers remain byte-for-byte input to the raw handler. A bare
 * header or div contributes only block flow, while the historical quote
 * footer is equivalent to its sole cite child.
 *
 * @param {string} html Paragraphized legacy HTML.
 * @return {string} Conservatively normalized HTML.
 */
function normalizeLegacyContainers( html ) {
	const container = document.createElement( 'div' );
	container.innerHTML = html;

	container.querySelectorAll( 'header, div' ).forEach( ( element ) => {
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

	return container.innerHTML;
}

/**
 * Decorate a converted block with Reveal fragment metadata from its source.
 *
 * @param {Object} block      Converted WordPress block.
 * @param {Node}   sourceNode Corresponding legacy DOM node.
 * @return {Object} Decorated WordPress block.
 */
function decorateFragments( block, sourceNode ) {
	if ( ELEMENT_NODE !== sourceNode.nodeType ) {
		return block;
	}

	const attributes = { ...block.attributes };
	const sourceClasses = Array.from( sourceNode.classList );
	if ( sourceClasses.includes( 'fragment' ) ) {
		const effect = FRAGMENT_EFFECTS.find( ( value ) =>
			sourceClasses.includes( value )
		);
		const reserved = new Set( [
			'fragment',
			'visible',
			'current-fragment',
			...( effect ? [ effect ] : [] ),
		] );
		const customClasses = sourceClasses.filter(
			( value ) => ! reserved.has( value )
		);
		attributes.presenterFragment = true;
		attributes.presenterFragmentEffect =
			effect || ( 0 < customClasses.length ? 'custom' : '' );
		attributes.presenterFragmentCustomClasses = customClasses.join( ' ' );

		const rawIndex = sourceNode.getAttribute( 'data-fragment-index' );
		if ( null !== rawIndex && /^\d+$/.test( rawIndex ) ) {
			attributes.presenterFragmentIndex = Number.parseInt( rawIndex, 10 );
		}

		if ( 'string' === typeof attributes.className ) {
			attributes.className = attributes.className
				.split( /\s+/ )
				.filter( ( value ) => value && ! reserved.has( value ) )
				.join( ' ' );
			if ( '' === attributes.className ) {
				delete attributes.className;
			}
		}
	}

	let innerBlocks = block.innerBlocks;
	const sourceChildren = Array.from( sourceNode.children );
	if ( sourceChildren.length === innerBlocks.length ) {
		innerBlocks = innerBlocks.map( ( child, index ) =>
			decorateFragments( child, sourceChildren[ index ] )
		);
	}

	return cloneBlock( block, attributes, innerBlocks );
}

/**
 * Count a block tree.
 *
 * @param {Object[]} blocks WordPress blocks.
 * @return {number} Recursive block count.
 */
function countBlocks( blocks ) {
	return blocks.reduce(
		( count, block ) => count + 1 + countBlocks( block.innerBlocks ),
		0
	);
}

/**
 * Count named blocks in a block tree.
 *
 * @param {Object[]} blocks WordPress blocks.
 * @param {string}   name   Block name.
 * @return {number} Recursive matching block count.
 */
function countBlocksByName( blocks, name ) {
	return blocks.reduce(
		( count, block ) =>
			count +
			( name === block.name ? 1 : 0 ) +
			countBlocksByName( block.innerBlocks, name ),
		0
	);
}
