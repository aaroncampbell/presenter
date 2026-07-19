import assert from 'node:assert/strict';
import test from 'node:test';

import {
	compareRenderedDecks,
	ComparisonSchemaError,
} from './compare-rendered-decks.mjs';

const DIGESTS = {
	a: 'a'.repeat( 64 ),
	b: 'b'.repeat( 64 ),
	c: 'c'.repeat( 64 ),
	d: 'd'.repeat( 64 ),
	e: 'e'.repeat( 64 ),
	f: 'f'.repeat( 64 ),
};

const makeSlide = () => ( {
	addressDigest: DIGESTS.f,
	anchorDigest: DIGESTS.a,
	notesFormat: 'markdown',
	notesDigest: DIGESTS.b,
	fragmentsDigest: DIGESTS.c,
	dataAttributesDigest: DIGESTS.d,
	renderedOutputDigest: DIGESTS.e,
	wrapperClassesDigest: DIGESTS.a,
} );

const makeDeck = () => ( {
	configDigest: DIGESTS.d,
	width: 1920,
	height: 1080,
	runtimeReady: true,
	themeDigest: DIGESTS.f,
	hierarchyDigest: DIGESTS.e,
	slides: [ makeSlide(), { ...makeSlide(), anchorDigest: DIGESTS.b } ],
} );

const makeVisual = () =>
	[ 'editor', 'standard', 'speaker', 'print' ].map( ( mode ) => ( {
		mode,
		captureStatus: 'captured',
		differingPixels: 0,
		totalPixels: 100,
	} ) );

const makeInput = () => ( {
	schemaVersion: 1,
	legacy: makeDeck(),
	native: makeDeck(),
	visual: makeVisual(),
} );

test( 'exact structural and visual matches pass', () => {
	assert.deepEqual( compareRenderedDecks( makeInput() ), {
		schemaVersion: 1,
		status: 'passed',
		structural: { status: 'passed', diagnostics: [] },
		visual: {
			status: 'passed',
			modes: [ 'editor', 'standard', 'speaker', 'print' ].map(
				( mode ) => ( {
					mode,
					status: 'passed',
					code: 'VISUAL_IDENTICAL',
				} )
			),
		},
	} );
} );

const structuralMutations = [
	[
		'runtime readiness',
		( input ) => ( input.native.runtimeReady = false ),
		'runtime_not_ready',
		'deck',
	],
	[
		'dimensions',
		( input ) => ( input.native.width = 1280 ),
		'deck_dimensions_changed',
		'deck',
	],
	[
		'configuration',
		( input ) => ( input.native.configDigest = DIGESTS.a ),
		'deck_config_changed',
		'deck',
	],
	[
		'theme',
		( input ) => ( input.native.themeDigest = DIGESTS.a ),
		'theme_changed',
		'deck',
	],
	[
		'hierarchy',
		( input ) => ( input.native.hierarchyDigest = DIGESTS.a ),
		'stack_hierarchy_changed',
		'deck',
	],
	[
		'slide count',
		( input ) => input.native.slides.pop(),
		'slide_count_changed',
		'deck',
	],
	[
		'anchor/order',
		( input ) => ( input.native.slides[ 0 ].anchorDigest = DIGESTS.f ),
		'anchor_changed',
		'slide',
	],
	[
		'address',
		( input ) => ( input.native.slides[ 0 ].addressDigest = DIGESTS.a ),
		'slide_address_changed',
		'slide',
	],
	[
		'notes format',
		( input ) => ( input.native.slides[ 0 ].notesFormat = 'html' ),
		'notes_changed',
		'slide',
	],
	[
		'notes content',
		( input ) => ( input.native.slides[ 0 ].notesDigest = DIGESTS.f ),
		'notes_changed',
		'slide',
	],
	[
		'fragments',
		( input ) => ( input.native.slides[ 0 ].fragmentsDigest = DIGESTS.f ),
		'fragment_sequence_changed',
		'slide',
	],
	[
		'data attributes',
		( input ) =>
			( input.native.slides[ 0 ].dataAttributesDigest = DIGESTS.f ),
		'data_attribute_changed',
		'slide',
	],
	[
		'rendered output',
		( input ) =>
			( input.native.slides[ 0 ].renderedOutputDigest = DIGESTS.f ),
		'rendered_content_changed',
		'slide',
	],
	[
		'wrapper class',
		( input ) =>
			( input.native.slides[ 0 ].wrapperClassesDigest = DIGESTS.f ),
		'wrapper_class_changed',
		'slide',
	],
];

for ( const [ label, mutate, code, scope ] of structuralMutations ) {
	test( `detects a ${ label } mutation`, () => {
		const input = makeInput();
		mutate( input );
		const diagnostics =
			compareRenderedDecks( input ).structural.diagnostics;
		assert.ok(
			diagnostics.some(
				( diagnostic ) =>
					diagnostic.code === code &&
					diagnostic.scope === scope &&
					( scope === 'deck' || diagnostic.slideIndex === 0 )
			)
		);
	} );
}

test( 'structural diagnostics have deterministic order', () => {
	const input = makeInput();
	input.native.width = 1280;
	input.native.configDigest = DIGESTS.b;
	input.native.themeDigest = DIGESTS.a;
	input.native.hierarchyDigest = DIGESTS.a;
	Object.assign( input.native.slides[ 0 ], {
		anchorDigest: DIGESTS.f,
		notesFormat: 'html',
		notesDigest: DIGESTS.f,
		fragmentsDigest: DIGESTS.f,
		dataAttributesDigest: DIGESTS.f,
		renderedOutputDigest: DIGESTS.f,
		wrapperClassesDigest: DIGESTS.f,
	} );

	assert.deepEqual(
		compareRenderedDecks( input ).structural.diagnostics.map(
			( diagnostic ) => diagnostic.code
		),
		[
			'deck_dimensions_changed',
			'deck_config_changed',
			'theme_changed',
			'stack_hierarchy_changed',
			'anchor_changed',
			'notes_changed',
			'fragment_sequence_changed',
			'data_attribute_changed',
			'rendered_content_changed',
			'wrapper_class_changed',
		]
	);
} );

test( 'detects reordered slides separately from changed anchors', () => {
	const input = makeInput();
	input.native.slides.reverse();
	const codes = compareRenderedDecks( input ).structural.diagnostics.map(
		( diagnostic ) => diagnostic.code
	);

	assert.ok( codes.includes( 'slide_order_changed' ) );
} );

test( 'accepts each supported notes representation', () => {
	for ( const notesFormat of [
		'none',
		'plain',
		'markdown',
		'html',
		'markdown-html',
	] ) {
		const input = makeInput();
		for ( const deck of [ input.legacy, input.native ] ) {
			deck.slides[ 0 ].notesFormat = notesFormat;
			deck.slides[ 0 ].notesDigest =
				notesFormat === 'none' ? null : DIGESTS.b;
		}
		assert.equal(
			compareRenderedDecks( input ).structural.status,
			'passed'
		);
	}
} );

test( 'accepts a nonempty subset of fixed visual modes', () => {
	const input = makeInput();
	input.visual = [ input.visual[ 1 ] ];

	assert.deepEqual( compareRenderedDecks( input ).visual, {
		status: 'passed',
		modes: [
			{
				mode: 'standard',
				status: 'passed',
				code: 'VISUAL_IDENTICAL',
			},
		],
	} );
} );

test( 'any nonzero visual difference requires human review', () => {
	const input = makeInput();
	input.visual[ 1 ].differingPixels = 1;
	const result = compareRenderedDecks( input );

	assert.equal( result.status, 'review_required' );
	assert.deepEqual( result.visual.modes[ 1 ], {
		mode: 'standard',
		status: 'review_required',
		code: 'VISUAL_DIFFERENCE_REQUIRES_REVIEW',
	} );
} );

test( 'any visual capture error fails comparison', () => {
	const input = makeInput();
	Object.assign( input.visual[ 3 ], {
		captureStatus: 'error',
		differingPixels: null,
		totalPixels: null,
	} );
	const result = compareRenderedDecks( input );

	assert.equal( result.status, 'failed' );
	assert.deepEqual( result.visual.modes[ 3 ], {
		mode: 'print',
		status: 'failed',
		code: 'VISUAL_CAPTURE_FAILED',
	} );
} );

const invalidMutations = [
	[ 'unknown schema', ( input ) => ( input.schemaVersion = 2 ) ],
	[ 'extra top-level key', ( input ) => ( input.privateUrl = 'secret' ) ],
	[
		'extra slide key',
		( input ) => ( input.legacy.slides[ 0 ].html = '<h1>secret</h1>' ),
	],
	[
		'non-digest content',
		( input ) => ( input.native.slides[ 0 ].notesDigest = 'secret' ),
	],
	[
		'invalid absent notes',
		( input ) =>
			Object.assign( input.native.slides[ 0 ], {
				notesFormat: 'none',
				notesDigest: DIGESTS.a,
			} ),
	],
	[ 'empty visual set', ( input ) => ( input.visual = [] ) ],
	[
		'duplicate visual mode',
		( input ) => ( input.visual[ 3 ].mode = 'speaker' ),
	],
	[
		'invalid pixel count',
		( input ) => ( input.visual[ 0 ].differingPixels = 101 ),
	],
	[
		'capture error details',
		( input ) =>
			Object.assign( input.visual[ 0 ], {
				captureStatus: 'error',
				differingPixels: 0,
				totalPixels: 100,
			} ),
	],
];

for ( const [ label, mutate ] of invalidMutations ) {
	test( `rejects ${ label } with a fixed redacted error`, () => {
		const input = makeInput();
		mutate( input );

		assert.throws(
			() => compareRenderedDecks( input ),
			( error ) => {
				assert.ok( error instanceof ComparisonSchemaError );
				assert.deepEqual(
					{ code: error.code, message: error.message },
					{
						code: 'INPUT_SCHEMA_INVALID',
						message:
							'Migration comparison input does not match schema version 1.',
					}
				);
				assert.ok( ! error.message.includes( 'secret' ) );
				return true;
			}
		);
	} );
}

test( 'comparison output never echoes compared values', () => {
	const input = makeInput();
	input.native.slides[ 0 ].anchorDigest = DIGESTS.f;
	const serialized = JSON.stringify( compareRenderedDecks( input ) );

	assert.ok( ! serialized.includes( DIGESTS.a ) );
	assert.ok( ! serialized.includes( DIGESTS.f ) );
	assert.ok( ! serialized.includes( '1920' ) );
} );
