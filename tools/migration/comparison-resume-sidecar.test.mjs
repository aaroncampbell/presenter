import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
	atomicWriteComparisonResumeSidecar,
	comparisonCaptureAuthenticationDigest,
	comparisonFrameArtifactDigest,
	createComparisonResumeSidecar,
	readComparisonResumeSidecar,
	validateComparisonResumeSidecar,
} from './comparison-resume-sidecar.mjs';
import {
	comparisonHmacPending,
	createComparisonReport,
	synchronizeComparisonCounts,
} from './comparison-report.mjs';

const digest = ( value ) =>
	createHash( 'sha256' ).update( value ).digest( 'hex' );
const key = Buffer.alloc( 32, 9 );

const bindings = () => ( {
	runDigest: digest( 'run' ),
	identityDigest: digest( 'identity' ),
	selectionDigest: digest( 'selection' ),
	deckDigest: digest( 'deck' ),
	attemptDigest: digest( 'attempt' ),
	access: 'public',
	visualSelected: true,
} );

const model = () => ( {
	configDigest: digest( 'config' ),
	height: 700,
	hierarchyDigest: digest( 'hierarchy' ),
	runtimeReady: true,
	slides: [
		{
			addressDigest: digest( 'address' ),
			anchorDigest: digest( 'anchor' ),
			dataAttributesDigest: digest( 'data' ),
			fragmentsDigest: digest( 'fragments' ),
			notesDigest: null,
			notesFormat: 'none',
			renderedOutputDigest: digest( 'output' ),
			wrapperClassesDigest: digest( 'classes' ),
		},
	],
	themeDigest: digest( 'theme' ),
	width: 960,
} );

const capture = ( captureOrdinal ) => ( {
	captureOrdinal,
	assetState: 'clean',
	assetStates: [ 'clean' ],
	assetSubstitutions: [],
	frames: [
		{
			file: `capture-${ String( captureOrdinal ).padStart(
				6,
				'0'
			) }/frame-000001.png`,
			frameOrdinal: 1,
			slideOrdinal: 1,
			state: 'initial',
			artifactDigest: digest( `frame-${ captureOrdinal }` ),
		},
	],
	model: model(),
	captureDigest: digest( `capture-${ captureOrdinal }` ),
} );

const fillCaptures = ( sidecar, count ) => {
	for ( const [ index, slot ] of [
		'legacy',
		'legacyRepeat',
		'native',
		'nativeRepeat',
	].entries() ) {
		if ( index < count ) {
			sidecar[ slot ] = capture( index + 1 );
			if ( ! sidecar.visualSelected ) {
				sidecar[ slot ].frames = [];
			}
		}
	}
	return sidecar;
};

const authenticateSidecar = async (
	root,
	sidecar,
	sidecarName = 'deck-000001'
) => {
	for ( const slot of [
		'legacy',
		'legacyRepeat',
		'native',
		'nativeRepeat',
	] ) {
		const item = sidecar[ slot ];
		if ( item === null ) {
			continue;
		}
		for ( const frame of item.frames ) {
			const bytes = Buffer.from(
				`opaque-${ slot }-${ frame.frameOrdinal }`
			);
			const filename = path.join( root, frame.file );
			await mkdir( path.dirname( filename ), { recursive: true } );
			await writeFile( filename, bytes );
			frame.artifactDigest = comparisonFrameArtifactDigest(
				key,
				sidecarName,
				sidecar,
				slot,
				item,
				frame,
				bytes
			);
		}
		item.captureDigest = comparisonCaptureAuthenticationDigest(
			key,
			sidecarName,
			sidecar,
			slot,
			item
		);
	}
	return sidecar;
};

const reportRecord = ( {
	visualSelected = true,
	structuralState = 'passed',
	assetFailure = false,
} = {} ) => {
	const identity = bindings();
	const report = createComparisonReport( {
		identityDigest: identity.identityDigest,
		selectionDigest: identity.selectionDigest,
		decks: [ identity ],
	} );
	const record = report.decks[ 0 ];
	record.visual.assetBasis = 'snapshot-original';
	record.visual.substitutionDigest = digest( 'empty-substitution-set' );
	record.structural.state = structuralState;
	record.structural.codes =
		structuralState === 'failed' ? [ 'slide_count_changed' ] : [];
	record.structural.legacySlideCount = 1;
	record.structural.nativeSlideCount = structuralState === 'passed' ? 1 : 2;
	record.structural.comparedSlideCount = 1;
	record.structural.legacyManifestDigest = digest( 'legacy-manifest' );
	record.structural.nativeManifestDigest = digest( 'native-manifest' );
	record.structural.slides = [
		{
			addressDigest: digest( 'address' ),
			state: 'passed',
			codes: [],
		},
	];
	if ( assetFailure ) {
		record.visual.state = 'failed';
		record.visual.reason = 'asset_failure';
		record.state = 'capture_incomplete';
	} else if ( visualSelected ) {
		record.visual.state = 'passed';
		record.visual.comparedFrames = 1;
		record.visual.aggregateDigest = digest( 'visual-artifacts' );
		record.state =
			structuralState === 'passed'
				? 'visual_passed'
				: 'structural_failed';
	} else {
		record.visual.state = 'skipped';
		record.visual.reason = 'not_selected';
		record.state =
			structuralState === 'passed'
				? 'structural_passed'
				: 'structural_failed';
	}
	synchronizeComparisonCounts( report );
	return record;
};

test( 'creates only the exact digest-bound empty schema', () => {
	const sidecar = createComparisonResumeSidecar( bindings() );

	assert.equal( sidecar.schemaVersion, 4 );
	assert.equal( sidecar.stage, 'not_checked' );
	assert.equal( sidecar.comparisonDigest, comparisonHmacPending );
	assert.equal( sidecar.reportRecord, null );
	assert.deepEqual( sidecar.legacy, null );
	assert.deepEqual( sidecar.legacyRepeat, null );
	assert.deepEqual( sidecar.nativeRepeat, null );
	assert.doesNotMatch(
		JSON.stringify( sidecar ),
		/title|slug|postId|url|canonicalHtml|authored/i
	);
} );

test( 'rejects the superseded resume sidecar schema', () => {
	const sidecar = createComparisonResumeSidecar( bindings() );
	sidecar.schemaVersion = 2;
	assert.throws(
		() => validateComparisonResumeSidecar( sidecar ),
		/comparison_checkpoint_schema/
	);
} );

test( 'accepts exact capture, comparison, skip, and fixed failure stages', () => {
	const legacy = createComparisonResumeSidecar( bindings() );
	legacy.stage = 'legacy_primary_captured';
	fillCaptures( legacy, 1 );
	validateComparisonResumeSidecar( legacy );

	legacy.stage = 'legacy_captured';
	fillCaptures( legacy, 2 );
	validateComparisonResumeSidecar( legacy );

	legacy.stage = 'native_primary_captured';
	fillCaptures( legacy, 3 );
	validateComparisonResumeSidecar( legacy );

	legacy.stage = 'native_captured';
	fillCaptures( legacy, 4 );
	validateComparisonResumeSidecar( legacy );

	legacy.stage = 'compared';
	legacy.comparisonDigest = digest( 'comparison' );
	legacy.reportRecord = reportRecord();
	validateComparisonResumeSidecar( legacy );

	const skipped = createComparisonResumeSidecar( {
		...bindings(),
		access: 'protected',
		visualSelected: false,
	} );
	skipped.stage = 'access_skipped';
	validateComparisonResumeSidecar( skipped );

	const failed = createComparisonResumeSidecar( bindings() );
	failed.stage = 'capture_failed';
	failed.failureCode = 'legacy_capture_failed';
	validateComparisonResumeSidecar( failed );
} );

test( 'binds compared report evidence to identity and visual selection', () => {
	for ( const structuralState of [ 'passed', 'failed' ] ) {
		for ( const visualSelected of [ true, false ] ) {
			const sidecar = createComparisonResumeSidecar( {
				...bindings(),
				visualSelected,
			} );
			sidecar.stage = 'compared';
			fillCaptures( sidecar, 4 );
			sidecar.comparisonDigest = digest( 'comparison' );
			sidecar.reportRecord = reportRecord( {
				structuralState,
				visualSelected,
			} );
			assert.equal( validateComparisonResumeSidecar( sidecar ), sidecar );
		}
	}
} );

test( 'accepts asset-failure evidence for every public comparison tier', () => {
	for ( const visualSelected of [ true, false ] ) {
		const sidecar = createComparisonResumeSidecar( {
			...bindings(),
			visualSelected,
		} );
		sidecar.stage = 'compared';
		fillCaptures( sidecar, 4 );
		sidecar.legacy.assetState = 'console-error';
		sidecar.legacy.assetStates = [ 'console-error' ];
		sidecar.comparisonDigest = digest( 'comparison' );
		sidecar.reportRecord = reportRecord( {
			assetFailure: true,
			visualSelected,
		} );
		assert.equal( validateComparisonResumeSidecar( sidecar ), sidecar );
	}
} );

test( 'binds report disposition to captured asset cleanliness', () => {
	const cleanAssetFailure = createComparisonResumeSidecar( bindings() );
	cleanAssetFailure.stage = 'compared';
	fillCaptures( cleanAssetFailure, 4 );
	cleanAssetFailure.comparisonDigest = digest( 'comparison' );
	cleanAssetFailure.reportRecord = reportRecord( { assetFailure: true } );
	assert.throws(
		() => validateComparisonResumeSidecar( cleanAssetFailure ),
		/comparison_checkpoint_schema/
	);

	const dirtyVisualPass = createComparisonResumeSidecar( bindings() );
	dirtyVisualPass.stage = 'compared';
	fillCaptures( dirtyVisualPass, 4 );
	dirtyVisualPass.native.assetState = 'console-error';
	dirtyVisualPass.native.assetStates = [ 'console-error' ];
	dirtyVisualPass.comparisonDigest = digest( 'comparison' );
	dirtyVisualPass.reportRecord = reportRecord();
	assert.throws(
		() => validateComparisonResumeSidecar( dirtyVisualPass ),
		/comparison_checkpoint_schema/
	);
} );

test( 'rejects mismatched compared evidence and premature stored records', () => {
	const compared = createComparisonResumeSidecar( bindings() );
	compared.stage = 'compared';
	fillCaptures( compared, 4 );
	compared.comparisonDigest = digest( 'comparison' );
	compared.reportRecord = reportRecord();

	const mutations = [
		( sidecar ) =>
			( sidecar.reportRecord.deckDigest = digest( 'different-deck' ) ),
		( sidecar ) =>
			( sidecar.reportRecord.attemptDigest =
				digest( 'different-attempt' ) ),
		( sidecar ) => ( sidecar.reportRecord.access = 'protected' ),
		( sidecar ) => ( sidecar.reportRecord.state = 'structural_passed' ),
		( sidecar ) => ( sidecar.reportRecord.visual.state = 'skipped' ),
		( sidecar ) => ( sidecar.reportRecord.visual.reason = 'not_selected' ),
		( sidecar ) => ( sidecar.reportRecord.title = 'Private sentinel' ),
	];
	for ( const mutate of mutations ) {
		const candidate = structuredClone( compared );
		mutate( candidate );
		assert.throws(
			() => validateComparisonResumeSidecar( candidate ),
			/comparison_checkpoint_schema/
		);
	}

	const premature = createComparisonResumeSidecar( bindings() );
	premature.reportRecord = reportRecord();
	assert.throws(
		() => validateComparisonResumeSidecar( premature ),
		/comparison_checkpoint_schema/
	);
} );

test( 'binds structural-only selection to empty visual frame sets', () => {
	const structuralOnly = createComparisonResumeSidecar( {
		...bindings(),
		visualSelected: false,
	} );
	structuralOnly.stage = 'legacy_captured';
	fillCaptures( structuralOnly, 2 );
	structuralOnly.legacy.model.slides.push( {
		...structuralOnly.legacy.model.slides[ 0 ],
		addressDigest: digest( 'second-address' ),
	} );
	validateComparisonResumeSidecar( structuralOnly );

	const selectedWithoutFrames = structuredClone( structuralOnly );
	selectedWithoutFrames.visualSelected = true;
	assert.throws(
		() => validateComparisonResumeSidecar( selectedWithoutFrames ),
		/comparison_checkpoint_schema/
	);

	const unselectedWithFrames = structuredClone( structuralOnly );
	unselectedWithFrames.legacy = capture( 1 );
	assert.throws(
		() => validateComparisonResumeSidecar( unselectedWithFrames ),
		/comparison_checkpoint_schema/
	);

	assert.throws(
		() =>
			createComparisonResumeSidecar( {
				...bindings(),
				access: 'nonpublic',
				visualSelected: true,
			} ),
		/comparison_checkpoint_schema/
	);
} );

test( 'rejects raw content, traversal, inconsistent assets, and invalid stages', () => {
	const sidecar = createComparisonResumeSidecar( bindings() );
	sidecar.stage = 'legacy_captured';
	fillCaptures( sidecar, 2 );

	const withContent = structuredClone( sidecar );
	withContent.legacy.model.slides[ 0 ].canonicalHtml =
		'Private authored sentinel';
	assert.throws(
		() => validateComparisonResumeSidecar( withContent ),
		/comparison_checkpoint_schema/
	);

	const traversal = structuredClone( sidecar );
	traversal.legacy.frames[ 0 ].file = '../private.png';
	assert.throws(
		() => validateComparisonResumeSidecar( traversal ),
		/comparison_checkpoint_schema/
	);

	const assets = structuredClone( sidecar );
	assets.legacy.assetStates = [ 'clean', 'page-error' ];
	assert.throws(
		() => validateComparisonResumeSidecar( assets ),
		/comparison_checkpoint_schema/
	);

	const premature = structuredClone( sidecar );
	premature.stage = 'compared';
	fillCaptures( premature, 3 );
	assert.throws(
		() => validateComparisonResumeSidecar( premature ),
		/comparison_checkpoint_schema/
	);
} );

test( 'requires exact capture prefixes for nondeterministic failures', () => {
	const legacy = createComparisonResumeSidecar( bindings() );
	legacy.stage = 'capture_failed';
	legacy.failureCode = 'legacy_nondeterministic';
	fillCaptures( legacy, 2 );
	validateComparisonResumeSidecar( legacy );
	legacy.legacyRepeat = null;
	assert.throws(
		() => validateComparisonResumeSidecar( legacy ),
		/comparison_checkpoint_schema/
	);

	const native = createComparisonResumeSidecar( bindings() );
	native.stage = 'capture_failed';
	native.failureCode = 'native_nondeterministic';
	fillCaptures( native, 4 );
	validateComparisonResumeSidecar( native );
	native.nativeRepeat = null;
	assert.throws(
		() => validateComparisonResumeSidecar( native ),
		/comparison_checkpoint_schema/
	);

	const substitutions = createComparisonResumeSidecar( bindings() );
	substitutions.stage = 'capture_failed';
	substitutions.failureCode = 'substitution_basis_changed';
	fillCaptures( substitutions, 4 );
	validateComparisonResumeSidecar( substitutions );
	substitutions.nativeRepeat = null;
	assert.throws(
		() => validateComparisonResumeSidecar( substitutions ),
		/comparison_checkpoint_schema/
	);
} );

test( 'atomically writes, reads, and verifies exact bindings', async () => {
	const root = await mkdtemp( path.join( tmpdir(), 'presenter-resume-' ) );
	const sidecar = createComparisonResumeSidecar( bindings() );
	const target = await atomicWriteComparisonResumeSidecar(
		root,
		'deck-000001',
		sidecar,
		key
	);

	assert.equal( path.dirname( path.dirname( target ) ), root );
	assert.deepEqual( JSON.parse( await readFile( target, 'utf8' ) ), sidecar );
	assert.deepEqual(
		await readComparisonResumeSidecar(
			root,
			'deck-000001',
			bindings(),
			key
		),
		sidecar
	);
	sidecar.diffRetryOrdinal = 2;
	await atomicWriteComparisonResumeSidecar(
		root,
		'deck-000001',
		sidecar,
		key
	);
	assert.equal(
		(
			await readComparisonResumeSidecar(
				root,
				'deck-000001',
				undefined,
				key
			)
		).diffRetryOrdinal,
		2
	);
	await assert.rejects(
		readComparisonResumeSidecar(
			root,
			'deck-000001',
			{
				...bindings(),
				attemptDigest: digest( 'other-attempt' ),
			},
			key
		),
		/comparison_checkpoint_mismatch/
	);
	await assert.rejects(
		atomicWriteComparisonResumeSidecar( root, '../escape', sidecar, key ),
		/comparison_checkpoint_path_unsafe/
	);
} );

test( 'requires every opaque frame to resolve beneath the private root', async () => {
	const root = await mkdtemp( path.join( tmpdir(), 'presenter-resume-' ) );
	const sidecar = createComparisonResumeSidecar( bindings() );
	sidecar.stage = 'legacy_primary_captured';
	fillCaptures( sidecar, 1 );

	await assert.rejects(
		atomicWriteComparisonResumeSidecar( root, 'deck-000001', sidecar, key ),
		/comparison_checkpoint_artifact_missing/
	);

	await authenticateSidecar( root, sidecar );
	await atomicWriteComparisonResumeSidecar(
		root,
		'deck-000001',
		sidecar,
		key
	);
	assert.equal(
		(
			await readComparisonResumeSidecar(
				root,
				'deck-000001',
				undefined,
				key
			)
		).stage,
		'legacy_primary_captured'
	);
	await writeFile(
		path.join( root, sidecar.legacy.frames[ 0 ].file ),
		'tampered'
	);
	await assert.rejects(
		readComparisonResumeSidecar( root, 'deck-000001', undefined, key ),
		/comparison_checkpoint_artifact_changed/
	);
} );

test( 'authenticates capture models, run identity, slots, and deck location', async () => {
	const root = await mkdtemp( path.join( tmpdir(), 'presenter-resume-' ) );
	const sidecar = createComparisonResumeSidecar( bindings() );
	sidecar.stage = 'native_captured';
	fillCaptures( sidecar, 4 );
	await authenticateSidecar( root, sidecar );
	const target = await atomicWriteComparisonResumeSidecar(
		root,
		'deck-000001',
		sidecar,
		key
	);

	for ( const mutate of [
		( value ) => value.legacy.model.width++,
		( value ) => ( value.runDigest = digest( 'different-run' ) ),
	] ) {
		const changed = structuredClone( sidecar );
		mutate( changed );
		await writeFile( target, `${ JSON.stringify( changed ) }\n`, 'utf8' );
		await assert.rejects(
			readComparisonResumeSidecar( root, 'deck-000001', undefined, key ),
			/comparison_checkpoint_changed/
		);
	}

	await writeFile( target, `${ JSON.stringify( sidecar ) }\n`, 'utf8' );
	const swapped = structuredClone( sidecar );
	[ swapped.legacy, swapped.legacyRepeat ] = [
		swapped.legacyRepeat,
		swapped.legacy,
	];
	assert.throws(
		() => validateComparisonResumeSidecar( swapped ),
		/comparison_checkpoint_schema/
	);
	await assert.rejects(
		atomicWriteComparisonResumeSidecar( root, 'deck-000002', sidecar, key ),
		/comparison_checkpoint_artifact_changed/
	);
} );

test( 'authenticates failed repeat-determinism decisions', async () => {
	for ( const [ failureCode, captureCount, forgedStage ] of [
		[ 'legacy_nondeterministic', 2, 'legacy_captured' ],
		[ 'native_nondeterministic', 4, 'native_captured' ],
		[ 'substitution_basis_changed', 4, 'native_captured' ],
	] ) {
		const root = await mkdtemp(
			path.join( tmpdir(), 'presenter-resume-' )
		);
		const sidecar = createComparisonResumeSidecar( {
			...bindings(),
			visualSelected: false,
		} );
		sidecar.stage = 'capture_failed';
		sidecar.failureCode = failureCode;
		fillCaptures( sidecar, captureCount );
		await authenticateSidecar( root, sidecar );
		const target = await atomicWriteComparisonResumeSidecar(
			root,
			'deck-000001',
			sidecar,
			key
		);
		const forged = structuredClone( sidecar );
		forged.stage = forgedStage;
		forged.failureCode = 'none';
		validateComparisonResumeSidecar( forged );
		await writeFile( target, `${ JSON.stringify( forged ) }\n`, 'utf8' );
		await assert.rejects(
			readComparisonResumeSidecar( root, 'deck-000001', undefined, key ),
			/comparison_checkpoint_changed/
		);
	}
} );

test( 'fails closed for missing and malformed persisted checkpoints', async () => {
	const root = await mkdtemp( path.join( tmpdir(), 'presenter-resume-' ) );
	await assert.rejects(
		readComparisonResumeSidecar( root, 'deck-000001', undefined, key ),
		/comparison_checkpoint_missing/
	);

	const directory = path.join( root, 'deck-000001' );
	const valid = createComparisonResumeSidecar( bindings() );
	await atomicWriteComparisonResumeSidecar( root, 'deck-000001', valid, key );
	await writeFile(
		path.join( directory, 'comparison-resume.json' ),
		'{"private":"sentinel"}',
		'utf8'
	);
	await assert.rejects(
		readComparisonResumeSidecar( root, 'deck-000001', undefined, key ),
		/comparison_checkpoint_schema/
	);
} );
