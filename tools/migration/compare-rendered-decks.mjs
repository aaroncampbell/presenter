/**
 * Pure comparison primitives for migration rehearsal reports.
 *
 * Inputs contain only normalized metadata and SHA-256 digests. Comparison
 * results deliberately omit expected and actual values so a durable report
 * cannot disclose presentation content.
 */

export const COMPARISON_SCHEMA_VERSION = 1;

export const VISUAL_MODES = Object.freeze( [
	'editor',
	'standard',
	'speaker',
	'print',
] );

const DIGEST_PATTERN = /^[a-f0-9]{64}$/;
const NOTES_FORMATS = new Set( [
	'none',
	'plain',
	'markdown',
	'html',
	'markdown-html',
] );
const CAPTURE_STATUSES = new Set( [ 'captured', 'error' ] );

export class ComparisonSchemaError extends Error {
	constructor() {
		super( 'Migration comparison input does not match schema version 1.' );
		this.name = 'ComparisonSchemaError';
		this.code = 'INPUT_SCHEMA_INVALID';
	}
}

const invalid = () => {
	throw new ComparisonSchemaError();
};

const isPlainObject = ( value ) =>
	typeof value === 'object' &&
	value !== null &&
	! Array.isArray( value ) &&
	Object.getPrototypeOf( value ) === Object.prototype;

const hasExactKeys = ( value, keys ) => {
	if ( ! isPlainObject( value ) ) {
		invalid();
	}

	const actualKeys = Object.keys( value ).sort();
	const expectedKeys = [ ...keys ].sort();
	if (
		actualKeys.length !== expectedKeys.length ||
		actualKeys.some( ( key, index ) => key !== expectedKeys[ index ] )
	) {
		invalid();
	}
};

const validateDigest = ( value ) => {
	if ( typeof value !== 'string' || ! DIGEST_PATTERN.test( value ) ) {
		invalid();
	}
};

const validateSlide = ( slide ) => {
	hasExactKeys( slide, [
		'addressDigest',
		'anchorDigest',
		'notesFormat',
		'notesDigest',
		'fragmentsDigest',
		'dataAttributesDigest',
		'renderedOutputDigest',
		'wrapperClassesDigest',
	] );

	validateDigest( slide.addressDigest );
	validateDigest( slide.anchorDigest );
	validateDigest( slide.fragmentsDigest );
	validateDigest( slide.dataAttributesDigest );
	validateDigest( slide.renderedOutputDigest );
	validateDigest( slide.wrapperClassesDigest );

	if ( ! NOTES_FORMATS.has( slide.notesFormat ) ) {
		invalid();
	}

	if ( slide.notesFormat === 'none' ) {
		if ( slide.notesDigest !== null ) {
			invalid();
		}
	} else {
		validateDigest( slide.notesDigest );
	}
};

const validateDeck = ( deck ) => {
	hasExactKeys( deck, [
		'configDigest',
		'runtimeReady',
		'width',
		'height',
		'themeDigest',
		'hierarchyDigest',
		'slides',
	] );

	if (
		typeof deck.runtimeReady !== 'boolean' ||
		! Number.isInteger( deck.width ) ||
		deck.width <= 0 ||
		! Number.isInteger( deck.height ) ||
		deck.height <= 0
	) {
		invalid();
	}

	validateDigest( deck.themeDigest );
	validateDigest( deck.configDigest );
	validateDigest( deck.hierarchyDigest );
	if ( ! Array.isArray( deck.slides ) ) {
		invalid();
	}
	deck.slides.forEach( validateSlide );
};

const validateVisualCapture = ( capture ) => {
	hasExactKeys( capture, [
		'mode',
		'captureStatus',
		'differingPixels',
		'totalPixels',
	] );

	if (
		! VISUAL_MODES.includes( capture.mode ) ||
		! CAPTURE_STATUSES.has( capture.captureStatus )
	) {
		invalid();
	}

	if ( capture.captureStatus === 'error' ) {
		if (
			capture.differingPixels !== null ||
			capture.totalPixels !== null
		) {
			invalid();
		}
		return;
	}

	if (
		! Number.isSafeInteger( capture.differingPixels ) ||
		capture.differingPixels < 0 ||
		! Number.isSafeInteger( capture.totalPixels ) ||
		capture.totalPixels <= 0 ||
		capture.differingPixels > capture.totalPixels
	) {
		invalid();
	}
};

const validateInput = ( input ) => {
	hasExactKeys( input, [ 'schemaVersion', 'legacy', 'native', 'visual' ] );
	if ( input.schemaVersion !== COMPARISON_SCHEMA_VERSION ) {
		invalid();
	}

	validateDeck( input.legacy );
	validateDeck( input.native );

	if (
		! Array.isArray( input.visual ) ||
		input.visual.length < 1 ||
		input.visual.length > VISUAL_MODES.length
	) {
		invalid();
	}

	input.visual.forEach( validateVisualCapture );
	const modes = input.visual.map( ( capture ) => capture.mode );
	if ( new Set( modes ).size !== modes.length ) {
		invalid();
	}
};

const addDeckDiagnostic = ( diagnostics, code ) => {
	diagnostics.push( { code, scope: 'deck' } );
};

const addSlideDiagnostic = ( diagnostics, code, slideIndex ) => {
	diagnostics.push( { code, scope: 'slide', slideIndex } );
};

const compareStructure = ( legacy, native ) => {
	const diagnostics = [];

	if ( ! legacy.runtimeReady || ! native.runtimeReady ) {
		addDeckDiagnostic( diagnostics, 'runtime_not_ready' );
	}
	if ( legacy.width !== native.width || legacy.height !== native.height ) {
		addDeckDiagnostic( diagnostics, 'deck_dimensions_changed' );
	}
	if ( legacy.configDigest !== native.configDigest ) {
		addDeckDiagnostic( diagnostics, 'deck_config_changed' );
	}
	if ( legacy.themeDigest !== native.themeDigest ) {
		addDeckDiagnostic( diagnostics, 'theme_changed' );
	}
	if ( legacy.hierarchyDigest !== native.hierarchyDigest ) {
		addDeckDiagnostic( diagnostics, 'stack_hierarchy_changed' );
	}
	if ( legacy.slides.length !== native.slides.length ) {
		addDeckDiagnostic( diagnostics, 'slide_count_changed' );
	}

	const legacyAnchors = legacy.slides.map( ( slide ) => slide.anchorDigest );
	const nativeAnchors = native.slides.map( ( slide ) => slide.anchorDigest );
	if (
		legacyAnchors.some(
			( anchor, index ) => anchor !== nativeAnchors[ index ]
		) &&
		[ ...legacyAnchors ].sort().join( ':' ) ===
			[ ...nativeAnchors ].sort().join( ':' )
	) {
		addDeckDiagnostic( diagnostics, 'slide_order_changed' );
	}

	const comparableSlides = Math.min(
		legacy.slides.length,
		native.slides.length
	);
	for ( let index = 0; index < comparableSlides; index++ ) {
		const legacySlide = legacy.slides[ index ];
		const nativeSlide = native.slides[ index ];

		if ( legacySlide.anchorDigest !== nativeSlide.anchorDigest ) {
			addSlideDiagnostic( diagnostics, 'anchor_changed', index );
		}
		if ( legacySlide.addressDigest !== nativeSlide.addressDigest ) {
			addSlideDiagnostic( diagnostics, 'slide_address_changed', index );
		}
		if ( legacySlide.notesFormat !== nativeSlide.notesFormat ) {
			addSlideDiagnostic( diagnostics, 'notes_changed', index );
		}
		if (
			legacySlide.notesFormat === nativeSlide.notesFormat &&
			legacySlide.notesDigest !== nativeSlide.notesDigest
		) {
			addSlideDiagnostic( diagnostics, 'notes_changed', index );
		}
		if ( legacySlide.fragmentsDigest !== nativeSlide.fragmentsDigest ) {
			addSlideDiagnostic(
				diagnostics,
				'fragment_sequence_changed',
				index
			);
		}
		if (
			legacySlide.dataAttributesDigest !==
			nativeSlide.dataAttributesDigest
		) {
			addSlideDiagnostic( diagnostics, 'data_attribute_changed', index );
		}
		if (
			legacySlide.renderedOutputDigest !==
			nativeSlide.renderedOutputDigest
		) {
			addSlideDiagnostic(
				diagnostics,
				'rendered_content_changed',
				index
			);
		}
		if (
			legacySlide.wrapperClassesDigest !==
			nativeSlide.wrapperClassesDigest
		) {
			addSlideDiagnostic( diagnostics, 'wrapper_class_changed', index );
		}
	}

	return {
		status: diagnostics.length === 0 ? 'passed' : 'failed',
		diagnostics,
	};
};

const classifyVisual = ( captures ) => {
	const modes = captures
		.map( ( capture ) => capture.mode )
		.sort(
			( first, second ) =>
				VISUAL_MODES.indexOf( first ) - VISUAL_MODES.indexOf( second )
		)
		.map( ( mode ) => {
			const capture = captures.find( ( item ) => item.mode === mode );
			if ( capture.captureStatus === 'error' ) {
				return {
					mode,
					status: 'failed',
					code: 'VISUAL_CAPTURE_FAILED',
				};
			}

			if ( capture.differingPixels > 0 ) {
				return {
					mode,
					status: 'review_required',
					code: 'VISUAL_DIFFERENCE_REQUIRES_REVIEW',
				};
			}

			return { mode, status: 'passed', code: 'VISUAL_IDENTICAL' };
		} );

	let status = 'passed';
	if ( modes.some( ( mode ) => mode.status === 'failed' ) ) {
		status = 'failed';
	} else if ( modes.some( ( mode ) => mode.status === 'review_required' ) ) {
		status = 'review_required';
	}

	return { status, modes };
};

/**
 * Compare a normalized legacy/native deck pair and precomputed visual diffs.
 *
 * @param {Object} input Strict schema-versioned comparison input.
 * @return {Object} A redacted, deterministic comparison result.
 */
export const compareRenderedDecks = ( input ) => {
	validateInput( input );

	const structural = compareStructure( input.legacy, input.native );
	const visual = classifyVisual( input.visual );

	return {
		schemaVersion: COMPARISON_SCHEMA_VERSION,
		status:
			structural.status === 'failed' || visual.status === 'failed'
				? 'failed'
				: visual.status,
		structural,
		visual,
	};
};
