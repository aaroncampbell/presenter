export const TYPED_REVEAL_DATA_NAMES = new Set( [
	'data-auto-animate',
	'data-auto-animate-id',
	'data-auto-animate-restart',
	'data-background-color',
	'data-background-image',
	'data-background-opacity',
	'data-background-position',
	'data-background-repeat',
	'data-background-size',
	'data-background-transition',
	'data-transition',
	'data-visibility',
] );

const URL_REVEAL_DATA_NAMES = new Set( [
	'data-background-iframe',
	'data-background-video',
] );
const DATA_NAME_PATTERN = /^data-[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/;
const CLASS_NAME_PATTERN = /^-?[A-Za-z_][A-Za-z0-9_-]*$/;

/**
 * Validate a Slide wrapper class list without normalizing it.
 *
 * @param {*} value Candidate class list.
 * @return {boolean} Whether the exact value can be saved.
 */
export function isValidSlideClassName( value ) {
	if ( 'string' !== typeof value || value.length > 512 ) {
		return false;
	}
	if ( '' === value ) {
		return true;
	}

	const tokens = value.split( /\s+/ );
	return (
		tokens.length <= 20 &&
		new Set( tokens ).size === tokens.length &&
		tokens.every(
			( token ) => token.length <= 64 && CLASS_NAME_PATTERN.test( token )
		)
	);
}

/**
 * Validate an ordered list of generic Reveal data attributes atomically.
 *
 * Names use their rendered form (`data-chart`). Presenter 1.x stored only the
 * suffix (`chart`), so migration prepends `data-` exactly once.
 *
 * @param {*} attributes Candidate records.
 * @return {boolean} Whether the exact list can be saved.
 */
export function areValidRevealDataAttributes( attributes ) {
	if ( ! Array.isArray( attributes ) || attributes.length > 100 ) {
		return false;
	}

	const names = new Set();
	return attributes.every( ( attribute ) => {
		if (
			null === attribute ||
			'object' !== typeof attribute ||
			Array.isArray( attribute ) ||
			2 !== Object.keys( attribute ).length ||
			! Object.hasOwn( attribute, 'name' ) ||
			! Object.hasOwn( attribute, 'value' ) ||
			'string' !== typeof attribute.name ||
			'string' !== typeof attribute.value
		) {
			return false;
		}

		const { name, value } = attribute;
		if (
			name.length > 64 ||
			! DATA_NAME_PATTERN.test( name ) ||
			name.startsWith( 'data-presenter-' ) ||
			TYPED_REVEAL_DATA_NAMES.has( name ) ||
			names.has( name ) ||
			value.length > 65535 ||
			! isValidRevealDataUrl( name, value )
		) {
			return false;
		}

		names.add( name );
		return true;
	} );
}

function isValidRevealDataUrl( name, value ) {
	if ( ! URL_REVEAL_DATA_NAMES.has( name ) ) {
		return true;
	}

	const urls =
		'data-background-video' === name ? value.split( ',' ) : [ value ];
	return urls.every( ( candidate ) => {
		const url = candidate.trim();
		if (
			/^\/(?!\/)/.test( url ) &&
			! /[\u0000-\u001f\u007f]/.test( url )
		) {
			return true;
		}
		if ( url.startsWith( '//' ) && ! /[\u0000-\u001f\u007f]/.test( url ) ) {
			try {
				return Boolean( new URL( `https:${ url }` ).host );
			} catch {
				return false;
			}
		}
		try {
			const parsed = new URL( url );
			return [ 'http:', 'https:' ].includes( parsed.protocol );
		} catch {
			return false;
		}
	} );
}
