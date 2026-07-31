import { comparisonHmac } from './comparison-report.mjs';

export class RenderedCaptureSchemaError extends Error {
	constructor() {
		super( 'rendered_capture_schema' );
		this.name = 'RenderedCaptureSchemaError';
		this.code = 'rendered_capture_schema';
	}
}

const invalid = () => {
	throw new RenderedCaptureSchemaError();
};

const isRecord = ( value ) =>
	value !== null && typeof value === 'object' && ! Array.isArray( value );

const digest = ( key, purpose, value ) =>
	comparisonHmac( key, purpose, JSON.stringify( value ) );

/**
 * Convert an in-memory browser capture into the digest-only comparison model.
 *
 * This is the sole boundary where authored DOM values become keyed digests.
 * Callers must discard the raw capture after this function returns.
 *
 * @param {Object}            capture Raw capture from captureRenderedDeck().
 * @param {Buffer|Uint8Array} key     Private comparison key.
 * @return {Object} Strict digest-only deck model.
 */
export const normalizeRenderedCapture = ( capture, key ) => {
	if (
		! isRecord( capture ) ||
		capture.schemaVersion !== 1 ||
		! isRecord( capture.metadata ) ||
		! isRecord( capture.metadata.dimensions ) ||
		! Number.isSafeInteger( capture.metadata.dimensions.width ) ||
		! Number.isSafeInteger( capture.metadata.dimensions.height ) ||
		! isRecord( capture.metadata.semanticConfig ) ||
		! Array.isArray( capture.metadata.hierarchy ) ||
		! Array.isArray( capture.metadata.themeStylesheets ) ||
		! Array.isArray( capture.slides ) ||
		capture.slides.length !== capture.slideCount
	) {
		invalid();
	}

	const slides = capture.slides.map( ( slide ) => {
		if (
			! isRecord( slide ) ||
			! Number.isSafeInteger( slide.horizontalIndex ) ||
			! Number.isSafeInteger( slide.verticalIndex ) ||
			! isRecord( slide.attributes ) ||
			! Array.isArray( slide.fragments ) ||
			! Array.isArray( slide.notes ) ||
			slide.notes.length > 1 ||
			typeof slide.canonicalHtml !== 'string'
		) {
			invalid();
		}
		const note = slide.notes[ 0 ] ?? null;
		if (
			note !== null &&
			( ! isRecord( note ) ||
				typeof note.html !== 'string' ||
				typeof note.markdown !== 'boolean' )
		) {
			invalid();
		}
		const dataAttributes = Object.fromEntries(
			Object.entries( slide.attributes ).filter( ( [ name, value ] ) => {
				if ( typeof value !== 'string' ) {
					invalid();
				}
				return name.startsWith( 'data-' );
			} )
		);
		if (
			dataAttributes[ 'data-background' ] &&
			dataAttributes[ 'data-background' ] ===
				dataAttributes[ 'data-background-image' ]
		) {
			delete dataAttributes[ 'data-background' ];
		}
		const classNames =
			typeof slide.attributes.class === 'string'
				? slide.attributes.class.split( /\s+/u ).filter( Boolean )
				: [];
		const anchor =
			typeof slide.attributes.id === 'string' ? slide.attributes.id : '';
		let notesFormat = 'none';
		if ( note !== null ) {
			notesFormat = note.markdown ? 'markdown' : 'plain';
		}

		return {
			addressDigest: digest( key, 'slide-address', [
				slide.horizontalIndex,
				slide.verticalIndex,
			] ),
			anchorDigest: digest( key, 'slide-anchor', anchor ),
			dataAttributesDigest: digest(
				key,
				'slide-data-attributes',
				dataAttributes
			),
			fragmentsDigest: digest( key, 'slide-fragments', slide.fragments ),
			notesDigest:
				note === null ? null : digest( key, 'slide-notes', note.html ),
			notesFormat,
			renderedOutputDigest: digest(
				key,
				'slide-rendered-output',
				slide.canonicalHtml
			),
			wrapperClassesDigest: digest(
				key,
				'slide-wrapper-classes',
				classNames
			),
		};
	} );

	return {
		configDigest: digest(
			key,
			'deck-config',
			capture.metadata.semanticConfig
		),
		height: capture.metadata.dimensions.height,
		hierarchyDigest: digest(
			key,
			'deck-hierarchy',
			capture.metadata.hierarchy
		),
		runtimeReady: true,
		slides,
		themeDigest: digest(
			key,
			'deck-theme',
			capture.metadata.themeStylesheets
		),
		width: capture.metadata.dimensions.width,
	};
};
