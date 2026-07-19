import assert from 'node:assert/strict';
import test from 'node:test';

import {
	comparisonCaptureOrdinals,
	comparisonFailureCode,
	comparisonIdentityDigest,
	storedComparisonRecord,
	validateAcceptanceCorpus,
} from './rehearsal-comparison.mjs';
import { comparisonHmac } from './comparison-report.mjs';
import { RenderedDeckCaptureError } from './capture-rendered-deck.mjs';
import { ComparisonSchemaError } from './compare-rendered-decks.mjs';
import { VisualArtifactError } from './compare-visual-artifacts.mjs';
import { RenderedCaptureSchemaError } from './normalize-rendered-capture.mjs';
import { SNAPSHOT_SOURCE_SHA256 } from '../snapshot/source-identity.mjs';

const corpus = () => ( {
	snapshotSha256: SNAPSHOT_SOURCE_SHA256.database,
	decks: [ { postId: 1, purpose: 'representative' } ],
} );

test( 'acceptance selection is bound to the authoritative snapshot', () => {
	assert.deepEqual( [ ...validateAcceptanceCorpus( corpus() ) ], [ 1 ] );

	const stale = corpus();
	stale.snapshotSha256 = 'A'.repeat( 64 );
	assert.throws(
		() => validateAcceptanceCorpus( stale ),
		/acceptance_snapshot_changed/
	);

	const malformed = corpus();
	malformed.snapshotSha256 = 'not-a-digest';
	assert.throws(
		() => validateAcceptanceCorpus( malformed ),
		/acceptance_schema/
	);
} );

test( 'each deck owns four collision-free capture ordinals', () => {
	assert.deepEqual( comparisonCaptureOrdinals( 0 ), {
		legacyPrimary: 1,
		legacyRepeat: 2,
		nativePrimary: 3,
		nativeRepeat: 4,
	} );
	assert.deepEqual( comparisonCaptureOrdinals( 1 ), {
		legacyPrimary: 5,
		legacyRepeat: 6,
		nativePrimary: 7,
		nativeRepeat: 8,
	} );
	assert.throws( () => comparisonCaptureOrdinals( -1 ) );
	assert.deepEqual( comparisonCaptureOrdinals( 249998 ), {
		legacyPrimary: 999993,
		legacyRepeat: 999994,
		nativePrimary: 999995,
		nativeRepeat: 999996,
	} );
	assert.throws( () => comparisonCaptureOrdinals( 249999 ) );

	const corpusOrdinals = Array.from( { length: 65 }, ( _, index ) =>
		Object.values( comparisonCaptureOrdinals( index ) )
	).flat();
	assert.equal( new Set( corpusOrdinals ).size, corpusOrdinals.length );
	assert( corpusOrdinals.every( ( ordinal ) => ordinal > 0 ) );
} );

test( 'comparison identity binds both snapshot sources and visual membership', () => {
	const key = Buffer.alloc( 32, 5 );
	const options = {
		fileDigests: [ 'a'.repeat( 64 ) ],
		visualPostIds: new Set( [ 1, 2 ] ),
	};
	const baseline = comparisonIdentityDigest( key, options );
	assert.notEqual(
		comparisonIdentityDigest( key, {
			...options,
			visualPostIds: new Set( [ 1, 3 ] ),
		} ),
		baseline
	);
	assert.notEqual(
		comparisonIdentityDigest( key, {
			...options,
			snapshotSha256: {
				...SNAPSHOT_SOURCE_SHA256,
				wpContent: 'A'.repeat( 64 ),
			},
		} ),
		baseline
	);
} );

test( 'stored comparison reconstruction is pure and integrity-bound', () => {
	const key = Buffer.alloc( 32, 7 );
	const reportRecord = {
		deckDigest: 'a'.repeat( 64 ),
		attemptDigest: 'b'.repeat( 64 ),
		access: 'public',
		state: 'structural_passed',
		structural: {},
		visual: {},
	};
	const sidecar = {
		schemaVersion: 2,
		identityDigest: 'c'.repeat( 64 ),
		selectionDigest: 'd'.repeat( 64 ),
		deckDigest: reportRecord.deckDigest,
		attemptDigest: reportRecord.attemptDigest,
		access: reportRecord.access,
		visualSelected: false,
		stage: 'compared',
		reportRecord,
		comparisonDigest: '',
	};
	const envelope = {
		schemaVersion: sidecar.schemaVersion,
		identityDigest: sidecar.identityDigest,
		selectionDigest: sidecar.selectionDigest,
		deckDigest: sidecar.deckDigest,
		attemptDigest: sidecar.attemptDigest,
		access: sidecar.access,
		visualSelected: sidecar.visualSelected,
		reportRecord,
	};
	sidecar.comparisonDigest = comparisonHmac(
		key,
		'comparison-record',
		JSON.stringify( envelope )
	);
	const reconstructed = storedComparisonRecord( sidecar, key );
	assert.deepEqual( reconstructed, reportRecord );
	assert.notEqual( reconstructed, reportRecord );
	reconstructed.state = 'changed';
	assert.equal( sidecar.reportRecord.state, 'structural_passed' );

	for ( const mutate of [
		( value ) => ( value.schemaVersion = 3 ),
		( value ) => ( value.identityDigest = 'e'.repeat( 64 ) ),
		( value ) => ( value.selectionDigest = 'f'.repeat( 64 ) ),
		( value ) => ( value.deckDigest = '0'.repeat( 64 ) ),
		( value ) => ( value.attemptDigest = '1'.repeat( 64 ) ),
		( value ) => ( value.access = 'protected' ),
		( value ) => ( value.visualSelected = true ),
		( value ) => ( value.reportRecord.state = 'changed' ),
	] ) {
		const changed = structuredClone( sidecar );
		mutate( changed );
		assert.throws(
			() => storedComparisonRecord( changed, key ),
			/comparison_result_changed/
		);
	}
} );

test( 'comparison failures map to fixed phase-appropriate codes', () => {
	assert.equal(
		comparisonFailureCode(
			new RenderedDeckCaptureError( 'private-detail' ),
			'legacy_capture_failed'
		),
		'legacy_capture_failed'
	);
	assert.equal(
		comparisonFailureCode(
			new RenderedCaptureSchemaError(),
			'visual_comparison_failed'
		),
		'rendered_capture_schema'
	);
	assert.equal(
		comparisonFailureCode(
			new ComparisonSchemaError(),
			'visual_comparison_failed'
		),
		'structural_comparison_failed'
	);
	assert.equal(
		comparisonFailureCode(
			new VisualArtifactError( 'invalid-png' ),
			'visual_comparison_failed'
		),
		'visual_artifact_invalid'
	);
	assert.equal(
		comparisonFailureCode(
			{ code: 'native_nondeterministic' },
			'visual_comparison_failed'
		),
		'native_nondeterministic'
	);
} );
