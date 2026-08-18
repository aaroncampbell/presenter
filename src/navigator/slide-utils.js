import {
	cloneBlock,
	getBlockAttributesNamesByRole,
	getBlockContent,
} from '@wordpress/blocks';
import { __, sprintf } from '@wordpress/i18n';

const FALLBACK_TEXT_ATTRIBUTE_KEYS = [
	'content',
	'text',
	'value',
	'title',
	'caption',
	'citation',
];

const NAMED_ENTITIES = {
	amp: '&',
	apos: "'",
	gt: '>',
	hellip: '…',
	ldquo: '“',
	lsquo: '‘',
	mdash: '—',
	nbsp: ' ',
	ndash: '–',
	quot: '"',
	rdquo: '”',
	rsquo: '’',
	lt: '<',
};

/**
 * Convert block attribute markup into a short, readable text value.
 *
 * This intentionally avoids browser-only DOM APIs because the helper also runs
 * in unit tests and may be reused outside the editor iframe.
 *
 * @param {*} value Possible block text attribute.
 * @return {string} Plain text with whitespace normalized.
 */
function normalizeText( value ) {
	if (
		value &&
		'object' === typeof value &&
		'function' === typeof value.toString &&
		Object.prototype.toString !== value.toString
	) {
		value = value.toString();
	}

	if ( 'string' !== typeof value ) {
		return '';
	}

	return value
		.replace( /<!--([\s\S]*?)-->/g, ' ' )
		.replace( /<[^>]*>/g, ' ' )
		.replace( /&(#(?:x[\da-f]+|\d+)|[a-z]+);/gi, ( entity, name ) => {
			if ( '#' === name[ 0 ] ) {
				const isHexadecimal = 'x' === name[ 1 ]?.toLowerCase();
				const codePoint = Number.parseInt(
					name.slice( isHexadecimal ? 2 : 1 ),
					isHexadecimal ? 16 : 10
				);

				if (
					Number.isInteger( codePoint ) &&
					codePoint > 0 &&
					codePoint <= 0x10ffff &&
					! ( codePoint >= 0xd800 && codePoint <= 0xdfff )
				) {
					return String.fromCodePoint( codePoint );
				}

				return ' ';
			}

			return NAMED_ENTITIES[ name.toLowerCase() ] ?? ' ';
		} )
		.replace( /\s+/g, ' ' )
		.trim();
}

/**
 * Find the first meaningful textual attribute on a block.
 *
 * @param {Object} block WordPress block instance.
 * @return {string} Normalized text, or an empty string.
 */
function getBlockText( block ) {
	if ( 'core/html' === block?.name ) {
		return normalizeText( getBlockContent( block ) );
	}

	const contentAttributeKeys = getBlockAttributesNamesByRole(
		block?.name,
		'content'
	);
	const textAttributeKeys = contentAttributeKeys.length
		? contentAttributeKeys
		: FALLBACK_TEXT_ATTRIBUTE_KEYS;

	for ( const key of textAttributeKeys ) {
		const text = normalizeText( block?.attributes?.[ key ] );

		if ( text ) {
			return text;
		}
	}

	return '';
}

/**
 * Search a block tree depth-first for the first matching text value.
 *
 * @param {Object[]} blocks    WordPress block instances.
 * @param {Function} predicate Block inclusion predicate.
 * @return {string} First meaningful text, or an empty string.
 */
function findBlockText( blocks, predicate ) {
	for ( const block of blocks ?? [] ) {
		if ( predicate( block ) ) {
			const text = getBlockText( block );

			if ( text ) {
				return text;
			}
		}

		const innerText = findBlockText( block.innerBlocks, predicate );

		if ( innerText ) {
			return innerText;
		}
	}

	return '';
}

/**
 * Get the accessible title used for a slide in the navigator.
 *
 * Authored labels take priority, followed by the first heading and then the
 * first other textual block. Untitled slides receive a localized numbered
 * fallback.
 *
 * @param {Object}        slide       Presenter slide block instance.
 * @param {number|string} slideNumber One-based Slide position.
 * @return {string} Useful slide title.
 */
export function getSlideTitle( slide, slideNumber ) {
	const label = normalizeText( slide?.attributes?.label );

	if ( label ) {
		return label;
	}

	const blocks = slide?.innerBlocks ?? [];
	const heading = findBlockText(
		blocks,
		( block ) => 'core/heading' === block.name
	);

	if ( heading ) {
		return heading;
	}

	const content = findBlockText(
		blocks,
		( block ) => 'core/heading' !== block.name
	);

	if ( content ) {
		return content;
	}

	/* translators: %s: One-based Slide position, such as 2 or 2.1. */
	return sprintf( __( 'Slide %s', 'presenter' ), slideNumber );
}

/**
 * Clone a slide for insertion into the same deck.
 *
 * WordPress's public clone helper assigns new client IDs throughout the block
 * tree. The duplicate receives its stable anchor before insertion so duplicate
 * and undo remain one editor history operation.
 *
 * @param {Object} slide Presenter slide block instance.
 * @return {Object} Independent slide clone with a unique Presenter anchor.
 */
export function cloneSlideForDuplication( slide ) {
	const duplicate = cloneBlock( slide );

	return {
		...duplicate,
		attributes: {
			...duplicate.attributes,
			anchor: `slide-${ duplicate.clientId }`,
		},
	};
}

/**
 * Clone a Nested Slides group and assign fresh anchors to the full subtree.
 *
 * @param {Object} stack Presenter Stack block instance.
 * @return {Object} Independent Stack clone with unique Presenter anchors.
 */
export function cloneStackForDuplication( stack ) {
	const duplicate = cloneBlock( stack );

	return {
		...duplicate,
		attributes: {
			...duplicate.attributes,
			anchor: `stack-${ duplicate.clientId }`,
		},
		innerBlocks: duplicate.innerBlocks.map( ( slide ) => ( {
			...slide,
			attributes: {
				...slide.attributes,
				anchor: `slide-${ slide.clientId }`,
			},
		} ) ),
	};
}

/**
 * Convert an insertion boundary into WordPress's final same-parent move index.
 *
 * Boundaries are numbered before the first slide through after the last slide.
 * WordPress removes the source before inserting it, so downward moves need one
 * position subtracted from their original boundary.
 *
 * @param {number} sourceIndex   Current zero-based slide index.
 * @param {number} boundaryIndex Zero-based insertion boundary.
 * @param {number} slideCount    Number of slides in the deck.
 * @return {number} Clamped final slide index.
 */
export function getDropTargetIndex( sourceIndex, boundaryIndex, slideCount ) {
	const targetIndex =
		sourceIndex < boundaryIndex ? boundaryIndex - 1 : boundaryIndex;

	return Math.max( 0, Math.min( slideCount - 1, targetIndex ) );
}
