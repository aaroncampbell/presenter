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
	if ( /^\/(?!\/)/.test( url ) && ! /[\u0000-\u001f\u007f]/.test( url ) ) {
		return url;
	}
	if ( url.startsWith( '//' ) && ! /[\u0000-\u001f\u007f]/.test( url ) ) {
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
