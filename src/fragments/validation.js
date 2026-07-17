export const FRAGMENT_EFFECTS = [
	'',
	'zoom-in',
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
	'current-visible',
	'semi-fade-out',
	'custom',
];

export const MAX_FRAGMENT_INDEX = 9999;
export const MAX_CUSTOM_CLASS_COUNT = 10;
export const MAX_CUSTOM_CLASS_LENGTH = 64;
export const MAX_CUSTOM_CLASSES_LENGTH = 512;

const RESERVED_CLASSES = new Set( [
	'fragment',
	'visible',
	'current-fragment',
	'disabled',
] );
const CLASS_NAME_PATTERN = /^-?[_a-zA-Z]+[_a-zA-Z0-9-]*$/;

/**
 * Normalize a Reveal fragment effect to the supported allowlist.
 *
 * @param {unknown} value Candidate effect.
 * @return {string|undefined} Supported effect or undefined when invalid.
 */
export function normalizeFragmentEffect( value ) {
	return 'string' === typeof value && FRAGMENT_EFFECTS.includes( value )
		? value
		: undefined;
}

/**
 * Normalize an explicitly authored fragment order.
 *
 * @param {unknown} value Candidate order.
 * @return {number|undefined} Valid order or undefined when empty/invalid.
 */
export function normalizeFragmentIndex( value ) {
	if ( '' === value || null === value || undefined === value ) {
		return undefined;
	}

	if (
		( 'number' === typeof value && ! Number.isInteger( value ) ) ||
		( 'string' === typeof value && ! /^(0|[1-9]\d*)$/.test( value ) )
	) {
		return undefined;
	}

	const index = Number( value );

	return Number.isSafeInteger( index ) &&
		index >= 0 &&
		index <= MAX_FRAGMENT_INDEX
		? index
		: undefined;
}

/**
 * Normalize a bounded list of safe, non-Reserved CSS class names.
 *
 * @param {unknown} value Candidate classes.
 * @return {string|undefined} Normalized classes, or undefined when invalid.
 */
export function normalizeCustomFragmentClasses( value ) {
	if ( 'string' !== typeof value ) {
		return undefined;
	}

	const normalized = value.trim().replace( /\s+/g, ' ' );

	if ( '' === normalized ) {
		return '';
	}

	if ( normalized.length > MAX_CUSTOM_CLASSES_LENGTH ) {
		return undefined;
	}

	const classes = normalized.split( ' ' );
	if ( classes.length > MAX_CUSTOM_CLASS_COUNT ) {
		return undefined;
	}

	if (
		classes.some(
			( className ) =>
				className.length > MAX_CUSTOM_CLASS_LENGTH ||
				! CLASS_NAME_PATTERN.test( className ) ||
				RESERVED_CLASSES.has( className )
		)
	) {
		return undefined;
	}

	return [ ...new Set( classes ) ].join( ' ' );
}
