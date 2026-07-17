export const BACKGROUND_SIZE_OPTIONS = [ '', 'cover', 'contain', 'auto' ];
export const BACKGROUND_POSITION_OPTIONS = [
	'',
	'center',
	'top',
	'top right',
	'right',
	'bottom right',
	'bottom',
	'bottom left',
	'left',
	'top left',
];
export const BACKGROUND_REPEAT_OPTIONS = [
	'',
	'no-repeat',
	'repeat',
	'repeat-x',
	'repeat-y',
];

/**
 * Parse a Reveal background opacity.
 *
 * @param {*} value Candidate opacity.
 * @return {number|undefined} Number from zero through one, or undefined.
 */
export function normalizeBackgroundOpacity( value ) {
	if ( '' === value || null === value || undefined === value ) {
		return '';
	}

	const opacity = Number( value );

	return Number.isFinite( opacity ) && opacity >= 0 && opacity <= 1
		? opacity
		: undefined;
}

/**
 * Validate an optional identifier used to group auto-animated slides.
 *
 * @param {*} value Candidate identifier.
 * @return {string|undefined} Valid identifier, empty string, or undefined.
 */
export function normalizeAutoAnimateId( value ) {
	if ( 'string' !== typeof value ) {
		return undefined;
	}

	const normalized = value.trim();

	if ( '' === normalized ) {
		return '';
	}

	return /^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/.test( normalized )
		? normalized
		: undefined;
}

/**
 * Accept only one value from a controlled Slide option list.
 *
 * @param {*}        value   Candidate value.
 * @param {string[]} options Allowed values.
 * @return {string|undefined} Valid option or undefined.
 */
export function normalizeControlledOption( value, options ) {
	return 'string' === typeof value && options.includes( value )
		? value
		: undefined;
}
