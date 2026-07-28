import { autop } from '@wordpress/autop';
import { cloneBlock, createBlock, rawHandler } from '@wordpress/blocks';

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
	const blocks = promoteQuoteCitations(
		promoteLegacyGroups( rawHandler( { HTML: paragraphized } ) )
	);
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
 * Promote safe legacy layout wrappers into native Core Groups.
 *
 * This recognizes the exact repeated title-panel style or a div carrying only
 * classes whose complete contents become native blocks. Any extra attribute,
 * unsupported style, or residual Custom HTML remains untouched.
 *
 * @param {Object[]} blocks Converted WordPress blocks.
 * @return {Object[]} Blocks with safe legacy groups promoted.
 */
function promoteLegacyGroups( blocks ) {
	return blocks.map( ( block ) => {
		const innerBlocks = promoteLegacyGroups( block.innerBlocks ?? [] );
		if ( 'core/html' !== block.name ) {
			return cloneBlock( block, block.attributes, innerBlocks );
		}

		const panel = getLegacyPanel( block.attributes?.content ?? '' );
		const classedGroup = panel
			? null
			: getClassedLegacyGroup( block.attributes?.content ?? '' );
		const wrapper = panel?.element ?? classedGroup;
		if ( ! wrapper ) {
			return cloneBlock( block, block.attributes, innerBlocks );
		}
		const groupBlocks = promoteLegacyGroups(
			rawHandler( { HTML: wrapper.innerHTML } )
		);
		if (
			0 !== countBlocksByName( groupBlocks, 'core/html' ) ||
			( panel &&
				( 1 !== groupBlocks.length ||
					'core/heading' !== groupBlocks[ 0 ].name ) )
		) {
			return cloneBlock( block, block.attributes, innerBlocks );
		}

		const attributes = {
			layout: { type: 'default' },
		};
		if ( classedGroup ) {
			attributes.className = classedGroup.className;
		} else {
			const spacing = {
				padding: {
					top: '20px',
					right: '20px',
					bottom: '20px',
					left: '20px',
				},
			};
			if ( panel.hasTopMargin ) {
				spacing.margin = { top: '16em' };
			}
			attributes.style = {
				color: { background: 'rgba(0, 0, 0, 0.7)' },
				spacing,
			};
		}

		return createBlock( 'core/group', attributes, groupBlocks );
	} );
}

/**
 * Return a class-only div that can retain its exact theme/layout classes.
 *
 * @param {string} html Candidate Custom HTML content.
 * @return {HTMLElement|null} Classed wrapper, when structurally exact.
 */
function getClassedLegacyGroup( html ) {
	const container = document.createElement( 'div' );
	container.innerHTML = html;
	const meaningfulNodes = getMeaningfulNodes( container );
	const wrapper = meaningfulNodes[ 0 ];
	return 1 === meaningfulNodes.length &&
		ELEMENT_NODE === wrapper?.nodeType &&
		'DIV' === wrapper.tagName &&
		1 === wrapper.attributes.length &&
		wrapper.hasAttribute( 'class' ) &&
		'' !== wrapper.className.trim()
		? wrapper
		: null;
}

/**
 * Parse the exact legacy title-panel contract.
 *
 * @param {string} html Candidate Custom HTML content.
 * @return {{ element: HTMLElement, hasTopMargin: boolean }|null} Panel data.
 */
function getLegacyPanel( html ) {
	const container = document.createElement( 'div' );
	container.innerHTML = html;
	const meaningfulNodes = getMeaningfulNodes( container );
	const panel = meaningfulNodes[ 0 ];
	if (
		1 !== meaningfulNodes.length ||
		ELEMENT_NODE !== panel?.nodeType ||
		'DIV' !== panel.tagName ||
		1 !== panel.attributes.length ||
		! panel.hasAttribute( 'style' )
	) {
		return null;
	}

	const properties = Array.from(
		{ length: panel.style.length },
		( unused, index ) => panel.style.item( index )
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
	const hasTopMargin = propertySets.some( ( propertySet ) =>
		arraysEqual( properties, [ ...propertySet, 'margin-top' ].sort() )
	);
	if (
		! hasTopMargin &&
		! propertySets.some( ( propertySet ) =>
			arraysEqual( properties, propertySet )
		)
	) {
		return null;
	}
	if (
		'rgba(0, 0, 0, 0.7)' !== panel.style.backgroundColor ||
		'20px' !== panel.style.padding ||
		( hasTopMargin && '16em' !== panel.style.marginTop ) ||
		properties.some( ( property ) =>
			panel.style.getPropertyPriority( property )
		)
	) {
		return null;
	}

	const children = getMeaningfulNodes( panel );
	const heading = children[ 0 ];
	if (
		1 !== children.length ||
		ELEMENT_NODE !== heading?.nodeType ||
		'H2' !== heading.tagName ||
		0 !== heading.attributes.length
	) {
		return null;
	}

	return { element: panel, hasTopMargin };
}

/**
 * Compare two ordered scalar arrays.
 *
 * @param {string[]} left  First array.
 * @param {string[]} right Second array.
 * @return {boolean} Whether both arrays contain the same ordered values.
 */
function arraysEqual( left, right ) {
	return (
		left.length === right.length &&
		left.every( ( value, index ) => value === right[ index ] )
	);
}

/**
 * Return element nodes and non-whitespace text nodes from a container.
 *
 * @param {Node} container DOM container.
 * @return {Node[]} Meaningful direct children.
 */
function getMeaningfulNodes( container ) {
	return Array.from( container.childNodes ).filter(
		( node ) =>
			ELEMENT_NODE === node.nodeType ||
			( TEXT_NODE === node.nodeType && '' !== node.textContent.trim() )
	);
}

/**
 * Promote plain legacy cite children into Core Quote's native citation field.
 *
 * The raw handler recognizes the surrounding quote but leaves a plain cite as
 * a core/html child. Only the lossless text-only shape is promoted;
 * attributed or formatted citations remain untouched.
 *
 * @param {Object[]} blocks Converted WordPress blocks.
 * @return {Object[]} Blocks with safe quote citations promoted.
 */
function promoteQuoteCitations( blocks ) {
	return blocks.map( ( block ) => {
		const innerBlocks = promoteQuoteCitations( block.innerBlocks ?? [] );
		if (
			'core/quote' !== block.name ||
			'' !== String( block.attributes?.citation ?? '' )
		) {
			return cloneBlock( block, block.attributes, innerBlocks );
		}

		const candidates = innerBlocks
			.map( ( innerBlock ) => ( {
				block: innerBlock,
				cite: getPlainQuoteCitation( innerBlock ),
			} ) )
			.filter( ( candidate ) => candidate.cite );
		if ( 1 !== candidates.length ) {
			return cloneBlock( block, block.attributes, innerBlocks );
		}
		const [ candidate ] = candidates;

		return cloneBlock(
			block,
			{ ...block.attributes, citation: candidate.cite.innerHTML },
			innerBlocks.filter(
				( innerBlock ) => innerBlock !== candidate.block
			)
		);
	} );
}

/**
 * Return a text-only cite when a Custom HTML block contains exactly one.
 *
 * @param {Object} block Candidate inner block.
 * @return {HTMLElement|null} Plain cite element, when losslessly promotable.
 */
function getPlainQuoteCitation( block ) {
	if ( 'core/html' !== block.name ) {
		return null;
	}

	const container = document.createElement( 'div' );
	container.innerHTML = block.attributes?.content ?? '';
	const meaningfulNodes = getMeaningfulNodes( container );
	const cite = meaningfulNodes[ 0 ];
	return 1 === meaningfulNodes.length &&
		ELEMENT_NODE === cite?.nodeType &&
		'CITE' === cite.tagName &&
		0 === cite.attributes.length &&
		0 === cite.children.length
		? cite
		: null;
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
