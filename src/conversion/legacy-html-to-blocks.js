import { autop } from '@wordpress/autop';
import { cloneBlock, createBlock, rawHandler } from '@wordpress/blocks';

import { normalizeSlideAnchor } from '../blocks/slide/anchor';
import {
	BACKGROUND_POSITION_OPTIONS,
	BACKGROUND_REPEAT_OPTIONS,
} from '../blocks/slide/advanced-settings';
import {
	areValidRevealDataAttributes,
	isValidSlideClassName,
} from '../blocks/slide/reveal-data';
import { normalizeBackgroundImageUrl } from '../blocks/slide/settings';

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
const TRANSITIONS = [ 'none', 'fade', 'slide', 'convex', 'concave', 'zoom' ];

/**
 * Report whether one retained Custom HTML body is an eligible Reveal stack.
 *
 * @param {string} html            Retained Slide HTML.
 * @param {Object} slideAttributes Outer Presenter Slide attributes.
 * @return {boolean} Whether the complete Slide can become Nested Slides.
 */
export function isLegacyHtmlNestedSlides( html, slideAttributes = {} ) {
	if ( ! canBecomeStackContainer( slideAttributes ) ) {
		return false;
	}

	const sections = getLegacyStackSections( html );
	return Boolean(
		sections &&
			sections.every( ( section ) =>
				mapLegacySectionAttributes( section )
			)
	);
}

/**
 * Convert a retained canonical Reveal stack into Presenter structural blocks.
 *
 * The outer Slide may carry only its label and anchor. Child section wrapper
 * attributes are mapped atomically; an unrepresentable wrapper leaves the
 * complete source untouched.
 *
 * @param {string}   html            Retained Slide HTML.
 * @param {Object}   slideAttributes Outer Presenter Slide attributes.
 * @param {string}   parentClientId  Outer Slide client ID for a fallback anchor.
 * @param {string[]} reservedAnchors Anchors already used elsewhere in the Deck.
 * @return {Object|null} Native Presenter Stack, or null when ineligible.
 */
export function convertLegacyHtmlToNestedSlides(
	html,
	slideAttributes = {},
	parentClientId = '',
	reservedAnchors = []
) {
	if ( ! canBecomeStackContainer( slideAttributes ) ) {
		return null;
	}

	const sections = getLegacyStackSections( html );
	if ( ! sections ) {
		return null;
	}

	const stackAnchor =
		slideAttributes.anchor ||
		`stack-${ parentClientId || 'nested-slides' }`;
	const usedAnchors = new Set( reservedAnchors.filter( Boolean ) );
	usedAnchors.add( stackAnchor );
	const slides = [];

	for ( const [ index, section ] of sections.entries() ) {
		const mapping = mapLegacySectionAttributes( section );
		if ( ! mapping ) {
			return null;
		}

		const baseAnchor =
			mapping.sourceId || `${ stackAnchor }-${ index + 1 }`;
		let anchor = baseAnchor;
		let suffix = 2;
		while ( usedAnchors.has( anchor ) ) {
			anchor = `${ baseAnchor }-${ suffix }`;
			suffix += 1;
		}
		usedAnchors.add( anchor );

		const conversion = convertLegacyHtmlToBlocks( section.innerHTML );
		slides.push(
			createBlock(
				'presenter/slide',
				{ ...mapping.attributes, anchor },
				conversion.blocks
			)
		);
	}

	return createBlock(
		'presenter/stack',
		{
			anchor: stackAnchor,
			label: slideAttributes.label || '',
		},
		slides
	);
}

/**
 * Allow only outer attributes that belong to the structural container.
 *
 * @param {Object} attributes Presenter Slide attributes.
 * @return {boolean} Whether wrapping can preserve the complete outer behavior.
 */
function canBecomeStackContainer( attributes ) {
	return (
		! attributes.className &&
		0 === ( attributes.revealDataAttributes?.length ?? 0 ) &&
		! attributes.hidden &&
		! attributes.notes &&
		( ! attributes.notesFormat || 'plain' === attributes.notesFormat ) &&
		! attributes.transition &&
		! attributes.backgroundColor &&
		! attributes.backgroundImageUrl &&
		! attributes.backgroundSize &&
		! attributes.backgroundPosition &&
		! attributes.backgroundRepeat &&
		undefined === attributes.backgroundOpacity &&
		! attributes.backgroundTransition &&
		! attributes.autoAnimate &&
		! attributes.autoAnimateId &&
		! attributes.autoAnimateRestart
	);
}

/**
 * Return exact direct child sections from one complete retained stack.
 *
 * @param {string} html Candidate HTML.
 * @return {HTMLElement[]|null} Direct child sections, or null.
 */
function getLegacyStackSections( html ) {
	if ( 'string' !== typeof html || '' === html.trim() ) {
		return null;
	}

	const container = document.createElement( 'div' );
	container.innerHTML = html;
	const nodes = getMeaningfulNodes( container );

	return 0 < nodes.length &&
		nodes.every(
			( node ) =>
				ELEMENT_NODE === node.nodeType && 'SECTION' === node.tagName
		)
		? nodes
		: null;
}

/**
 * Map one legacy child section wrapper into native Slide attributes.
 *
 * @param {HTMLElement} section Legacy child section.
 * @return {{ attributes: Object, sourceId: string }|null} Exact mapping.
 */
function mapLegacySectionAttributes( section ) {
	const attributes = {};
	const generic = [];
	let sourceId = '';

	for ( const attribute of section.attributes ) {
		const { name, value } = attribute;
		if ( 'id' === name ) {
			sourceId = value;
			if ( normalizeSlideAnchor( value ) !== value ) {
				return null;
			}
			continue;
		}
		if ( 'class' === name ) {
			if ( ! isValidSlideClassName( value ) ) {
				return null;
			}
			if ( value ) {
				attributes.className = value;
			}
			continue;
		}
		if ( ! name.startsWith( 'data-' ) ) {
			return null;
		}

		const typed = mapTypedRevealData( name, value );
		if ( false === typed ) {
			return null;
		}
		if ( typed ) {
			Object.assign( attributes, typed );
		} else {
			generic.push( { name, value } );
		}
	}

	if ( ! areValidRevealDataAttributes( generic ) ) {
		return null;
	}
	if ( generic.length ) {
		attributes.revealDataAttributes = generic;
	}

	return { attributes, sourceId };
}

/**
 * Map exact typed Reveal data values shared with the server migration planner.
 *
 * @param {string} name  Rendered data attribute name.
 * @param {string} value Authored value.
 * @return {Object|false|null} Typed attributes, invalid, or generic.
 */
function mapTypedRevealData( name, value ) {
	if ( 'data-transition' === name ) {
		return TRANSITIONS.includes( value ) ? { transition: value } : false;
	}
	if ( 'data-background' === name ) {
		return normalizeBackgroundImageUrl( value )
			? { backgroundImageUrl: value }
			: null;
	}
	if ( 'data-background-transition' === name ) {
		return TRANSITIONS.includes( value )
			? { backgroundTransition: value }
			: false;
	}
	if ( 'data-background-color' === name ) {
		return /^#[\da-f]{6}$/i.test( value )
			? { backgroundColor: value }
			: false;
	}
	if ( 'data-background-image' === name ) {
		return normalizeBackgroundImageUrl( value )
			? { backgroundImageUrl: value }
			: false;
	}
	if ( 'data-background-size' === name ) {
		return /^(?:auto|cover|contain)(?:\s+(?:auto|(?:100|[1-9]?\d)%))?$/.test(
			value
		)
			? { backgroundSize: value }
			: false;
	}
	if ( 'data-background-position' === name ) {
		return BACKGROUND_POSITION_OPTIONS.includes( value ) && value
			? { backgroundPosition: value }
			: false;
	}
	if ( 'data-background-repeat' === name ) {
		return BACKGROUND_REPEAT_OPTIONS.includes( value ) && value
			? { backgroundRepeat: value }
			: false;
	}
	if ( 'data-background-opacity' === name ) {
		if ( ! /^(?:0(?:\.\d+)?|1(?:\.0+)?)$/.test( value ) ) {
			return false;
		}
		const opacity = Number( value );
		return opacity >= 0 && opacity <= 1
			? { backgroundOpacity: opacity }
			: false;
	}
	if ( 'data-auto-animate' === name ) {
		return '' === value ? { autoAnimate: true } : false;
	}
	if ( 'data-auto-animate-id' === name ) {
		return /^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/.test( value )
			? { autoAnimate: true, autoAnimateId: value }
			: false;
	}
	if ( 'data-auto-animate-restart' === name ) {
		return '' === value
			? { autoAnimate: true, autoAnimateRestart: true }
			: false;
	}
	if ( 'data-visibility' === name ) {
		return 'hidden' === value ? { hidden: true } : false;
	}

	return null;
}

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
