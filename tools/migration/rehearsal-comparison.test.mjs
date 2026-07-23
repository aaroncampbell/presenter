import assert from 'node:assert/strict';
import { mkdir, mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
	assertDeterministicCapturePair,
	assertMatchingSubstitutionBasis,
	comparisonCaptureOrdinals,
	comparisonFailureWaitsForRestore,
	comparisonFailureCode,
	comparisonIdentityDigest,
	RehearsalComparison,
	storedComparisonRecord,
	validateAcceptanceCorpus,
} from './rehearsal-comparison.mjs';

test( 'legacy and native captures require the same offline asset basis', () => {
	const legacy = {
		assetSubstitutions: [ { entryDigest: 'a'.repeat( 64 ), count: 2 } ],
	};
	assert.doesNotThrow( () =>
		assertMatchingSubstitutionBasis( legacy, structuredClone( legacy ) )
	);
	const native = structuredClone( legacy );
	native.assetSubstitutions[ 0 ].count = 1;
	assert.throws(
		() => assertMatchingSubstitutionBasis( legacy, native ),
		( error ) => error.code === 'substitution_basis_changed'
	);
} );
import { comparisonHmac } from './comparison-report.mjs';
import {
	atomicWriteComparisonResumeSidecar,
	comparisonCaptureAuthenticationDigest,
	createComparisonResumeSidecar,
	readComparisonResumeSidecar,
} from './comparison-resume-sidecar.mjs';
import { RenderedDeckCaptureError } from './capture-rendered-deck.mjs';
import { ComparisonSchemaError } from './compare-rendered-decks.mjs';
import { VisualArtifactError } from './compare-visual-artifacts.mjs';
import { RenderedCaptureSchemaError } from './normalize-rendered-capture.mjs';
import { SNAPSHOT_SOURCE_SHA256 } from '../snapshot/source-identity.mjs';

const corpus = () => ( {
	snapshotSha256: SNAPSHOT_SOURCE_SHA256.database,
	decks: [ { postId: 1, purpose: 'representative' } ],
} );

const normalizedModel = () => ( {
	configDigest: 'a'.repeat( 64 ),
	height: 700,
	hierarchyDigest: 'b'.repeat( 64 ),
	runtimeReady: true,
	slides: [
		{
			addressDigest: 'c'.repeat( 64 ),
			anchorDigest: 'd'.repeat( 64 ),
			dataAttributesDigest: 'e'.repeat( 64 ),
			fragmentsDigest: 'f'.repeat( 64 ),
			notesDigest: null,
			notesFormat: 'none',
			renderedOutputDigest: '1'.repeat( 64 ),
			wrapperClassesDigest: '2'.repeat( 64 ),
		},
	],
	themeDigest: '3'.repeat( 64 ),
	width: 960,
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
		schemaVersion: 4,
		runDigest: '2'.repeat( 64 ),
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
		runDigest: sidecar.runDigest,
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
		( value ) => ( value.runDigest = '3'.repeat( 64 ) ),
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

test( 'repeat determinism requires exact models, assets, and frame metadata', async () => {
	const capture = {
		assetState: 'clean',
		assetStates: [ 'clean' ],
		assetSubstitutions: [],
		frames: [],
		model: {
			configDigest: 'a'.repeat( 64 ),
			height: 700,
			hierarchyDigest: 'b'.repeat( 64 ),
			runtimeReady: true,
			slides: [],
			themeDigest: 'c'.repeat( 64 ),
			width: 960,
		},
	};
	await assertDeterministicCapturePair( {
		code: 'legacy_nondeterministic',
		primary: capture,
		privateRoot: '.',
		repeat: structuredClone( capture ),
		visualSelected: false,
	} );
	for ( const mutate of [
		( value ) => ( value.model.width = 961 ),
		( value ) => ( value.assetState = 'console-error' ),
		( value ) => value.assetStates.push( 'console-error' ),
		( value ) =>
			value.assetSubstitutions.push( {
				entryDigest: 'd'.repeat( 64 ),
				count: 1,
			} ),
		( value ) =>
			value.frames.push( {
				frameOrdinal: 1,
				slideOrdinal: 1,
				state: 'initial',
			} ),
	] ) {
		const changed = structuredClone( capture );
		mutate( changed );
		await assert.rejects(
			assertDeterministicCapturePair( {
				code: 'legacy_nondeterministic',
				primary: capture,
				privateRoot: '.',
				repeat: changed,
				visualSelected: false,
			} ),
			( error ) => error.code === 'legacy_nondeterministic'
		);
	}
} );

test( 'coordinator resumes only the missing legacy and native repeat slots', async () => {
	const key = Buffer.alloc( 32, 11 );
	const makeCoordinator = async ( record ) => {
		const runDirectory = await mkdtemp(
			path.join( tmpdir(), 'presenter-coordinator-' )
		);
		const coordinator = new RehearsalComparison( {
			identityDigest: '4'.repeat( 64 ),
			key,
			records: [ record ],
			runDigest: '5'.repeat( 64 ),
			runDirectory,
			selectedPostIds: [ 1 ],
			selectionDigest: '6'.repeat( 64 ),
			visualPostIds: new Set(),
		} );
		await mkdir( coordinator.privateRoot, { recursive: true } );
		return coordinator;
	};
	const authenticatedCapture = ( sidecar, slot, ordinal ) => {
		const capture = {
			captureOrdinal: ordinal,
			assetState: 'clean',
			assetStates: [ 'clean' ],
			assetSubstitutions: [],
			frames: [],
			model: normalizedModel(),
			captureDigest: '0'.repeat( 64 ),
		};
		capture.captureDigest = comparisonCaptureAuthenticationDigest(
			key,
			'deck-000001',
			sidecar,
			slot,
			capture
		);
		return capture;
	};

	const legacyCoordinator = await makeCoordinator( {
		deckDigest: '7'.repeat( 64 ),
		stage: 'baseline',
	} );
	const legacySidecar = createComparisonResumeSidecar(
		legacyCoordinator.bindings( 0, 1, 'public' )
	);
	legacySidecar.legacy = authenticatedCapture( legacySidecar, 'legacy', 1 );
	legacySidecar.stage = 'legacy_primary_captured';
	await atomicWriteComparisonResumeSidecar(
		legacyCoordinator.privateRoot,
		'deck-000001',
		legacySidecar,
		key
	);
	const legacyCalls = [];
	legacyCoordinator.captureSlot = async (
		index,
		postId,
		sidecar,
		slot,
		ordinal
	) => {
		legacyCalls.push( { index, ordinal, postId, slot } );
		return authenticatedCapture( sidecar, slot, ordinal );
	};
	await legacyCoordinator.captureLegacy( 0, 1, 'public' );
	assert.deepEqual( legacyCalls, [
		{ index: 0, ordinal: 2, postId: 1, slot: 'legacyRepeat' },
	] );
	assert.equal(
		(
			await readComparisonResumeSidecar(
				legacyCoordinator.privateRoot,
				'deck-000001',
				legacyCoordinator.bindings( 0, 1, 'public' ),
				key
			)
		).stage,
		'legacy_captured'
	);

	const nativeCoordinator = await makeCoordinator( {
		deckDigest: '8'.repeat( 64 ),
		preparedRevisionDigest: '9'.repeat( 64 ),
		stage: 'prepared',
	} );
	const nativeSidecar = createComparisonResumeSidecar(
		nativeCoordinator.bindings( 0, 1, 'public' )
	);
	for ( const [ slot, ordinal ] of [
		[ 'legacy', 1 ],
		[ 'legacyRepeat', 2 ],
		[ 'native', 3 ],
	] ) {
		nativeSidecar[ slot ] = authenticatedCapture(
			nativeSidecar,
			slot,
			ordinal
		);
	}
	nativeSidecar.stage = 'native_primary_captured';
	await atomicWriteComparisonResumeSidecar(
		nativeCoordinator.privateRoot,
		'deck-000001',
		nativeSidecar,
		key
	);
	const nativeCalls = [];
	nativeCoordinator.captureSlot = async (
		index,
		postId,
		sidecar,
		slot,
		ordinal
	) => {
		nativeCalls.push( { index, ordinal, postId, slot } );
		return authenticatedCapture( sidecar, slot, ordinal );
	};
	await nativeCoordinator.captureNative( 0, 1, 'public' );
	assert.deepEqual( nativeCalls, [
		{ index: 0, ordinal: 4, postId: 1, slot: 'nativeRepeat' },
	] );
	assert.equal(
		(
			await readComparisonResumeSidecar(
				nativeCoordinator.privateRoot,
				'deck-000001',
				nativeCoordinator.bindings( 0, 1, 'public' ),
				key
			)
		).stage,
		'native_captured'
	);
	await nativeCoordinator.compareAfterRestore( 0, 1, 'public' );
	const compared = await readComparisonResumeSidecar(
		nativeCoordinator.privateRoot,
		'deck-000001',
		nativeCoordinator.bindings( 0, 1, 'public' ),
		key
	);
	assert.equal( compared.stage, 'compared' );
	assert.equal( compared.reportRecord.state, 'structural_passed' );
	assert.notEqual( compared.checkpointDigest, '0'.repeat( 64 ) );
} );

test( 'nonpublic access remains skipped after exact restore', async () => {
	const key = Buffer.alloc( 32, 13 );
	const runDirectory = await mkdtemp(
		path.join( tmpdir(), 'presenter-nonpublic-coordinator-' )
	);
	const coordinator = new RehearsalComparison( {
		identityDigest: '4'.repeat( 64 ),
		key,
		records: [ { deckDigest: '7'.repeat( 64 ), stage: 'baseline' } ],
		runDigest: '5'.repeat( 64 ),
		runDirectory,
		selectedPostIds: [ 1 ],
		selectionDigest: '6'.repeat( 64 ),
		visualPostIds: new Set(),
	} );
	await mkdir( coordinator.privateRoot, { recursive: true } );

	await coordinator.checkpointAccess( 0, 1, 'nonpublic' );
	await coordinator.compareAfterRestore( 0, 1, 'nonpublic' );

	const sidecar = await readComparisonResumeSidecar(
		coordinator.privateRoot,
		'deck-000001',
		coordinator.bindings( 0, 1, 'nonpublic' ),
		key
	);
	assert.equal( sidecar.stage, 'access_skipped' );
	assert.equal( sidecar.failureCode, 'none' );
} );

test( 'comparison failures map to fixed phase-appropriate codes', () => {
	assert.equal( comparisonFailureWaitsForRestore( 'applied' ), true );
	assert.equal( comparisonFailureWaitsForRestore( 'restoring' ), true );
	assert.equal( comparisonFailureWaitsForRestore( 'restored' ), true );
	assert.equal( comparisonFailureWaitsForRestore( 'prepared' ), false );
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
	assert.equal(
		comparisonFailureCode(
			{ code: 'substitution_basis_changed' },
			'native_capture_failed'
		),
		'substitution_basis_changed'
	);
} );
