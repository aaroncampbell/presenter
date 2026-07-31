const HEX_COLOR_PATTERN = /^#[\da-f]{6}$/i;

/**
 * Normalize a strict hexadecimal color value.
 *
 * @param {string} value Authored color.
 * @return {string|undefined} Normalized color, or undefined when invalid.
 */
export function normalizeHexColor( value ) {
	const color = value.trim();

	if ( '' === color ) {
		return '';
	}

	return HEX_COLOR_PATTERN.test( color ) ? color.toLowerCase() : undefined;
}

/**
 * Match Reveal's light/dark background classification for editor previews.
 *
 * @param {string} value Authored slide background color.
 * @return {string} Reveal contrast class, or an empty string when invalid.
 */
export function getBackgroundContrastClass( value ) {
	const color = normalizeHexColor( value );

	if ( ! color ) {
		return '';
	}

	const red = parseInt( color.slice( 1, 3 ), 16 );
	const green = parseInt( color.slice( 3, 5 ), 16 );
	const blue = parseInt( color.slice( 5, 7 ), 16 );
	const brightness = ( red * 299 + green * 587 + blue * 114 ) / 1000;

	return brightness < 128 ? 'has-dark-background' : 'has-light-background';
}

/**
 * Normalize a browser-loaded background image URL.
 *
 * @param {string} value Authored URL.
 * @return {string|undefined} Trimmed HTTP(S) URL, empty value, or undefined.
 */
export function normalizeBackgroundImageUrl( value ) {
	const url = value.trim();

	if ( '' === url ) {
		return '';
	}
	if ( /[\u0000-\u001f\u007f]/.test( url ) ) {
		return undefined;
	}
	if ( /^\/(?!\/)/.test( url ) ) {
		return url;
	}
	if ( url.startsWith( '//' ) ) {
		try {
			return new URL( `https:${ url }` ).host ? url : undefined;
		} catch {
			return undefined;
		}
	}

	try {
		const parsed = new URL( url );

		return [ 'http:', 'https:' ].includes( parsed.protocol )
			? url
			: undefined;
	} catch {
		return undefined;
	}
}

/**
 * Resolve the background image displayed by editor previews.
 *
 * Migration planner versions before 3 preserved Reveal's data-background
 * shorthand as a generic attribute. Typed settings always take precedence.
 *
 * @param {Object} attributes Slide attributes.
 * @return {string} Safe background image URL, or an empty string.
 */
export function getPreviewBackgroundImageUrl( attributes ) {
	if ( attributes.backgroundImageUrl ) {
		return (
			normalizeBackgroundImageUrl( attributes.backgroundImageUrl ) ?? ''
		);
	}

	const shorthand = attributes.revealDataAttributes?.find(
		( attribute ) => 'data-background' === attribute.name
	)?.value;

	return 'string' === typeof shorthand
		? normalizeBackgroundImageUrl( shorthand ) ?? ''
		: '';
}
