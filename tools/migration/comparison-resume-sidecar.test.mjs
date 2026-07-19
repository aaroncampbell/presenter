import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
	atomicWriteComparisonResumeSidecar,
	createComparisonResumeSidecar,
	readComparisonResumeSidecar,
	validateComparisonResumeSidecar,
} from './comparison-resume-sidecar.mjs';
import { comparisonHmacPending } from './comparison-report.mjs';

const digest = ( value ) =>
	createHash( 'sha256' ).update( value ).digest( 'hex' );

const bindings = () => ( {
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
	frames: [
		{
			file: `capture-${ String( captureOrdinal ).padStart(
				6,
				'0'
			) }/frame-000001.png`,
			frameOrdinal: 1,
			slideOrdinal: 1,
			state: 'initial',
		},
	],
	model: model(),
} );

test( 'creates only the exact digest-bound empty schema', () => {
	const sidecar = createComparisonResumeSidecar( bindings() );

	assert.equal( sidecar.schemaVersion, 1 );
	assert.equal( sidecar.stage, 'not_checked' );
	assert.equal( sidecar.comparisonDigest, comparisonHmacPending );
	assert.deepEqual( sidecar.legacy, null );
	assert.doesNotMatch(
		JSON.stringify( sidecar ),
		/title|slug|postId|url|canonicalHtml|authored/i
	);
} );

test( 'accepts exact capture, comparison, skip, and fixed failure stages', () => {
	const legacy = createComparisonResumeSidecar( bindings() );
	legacy.stage = 'legacy_captured';
	legacy.legacy = capture( 1 );
	validateComparisonResumeSidecar( legacy );

	legacy.stage = 'native_captured';
	legacy.native = capture( 2 );
	validateComparisonResumeSidecar( legacy );

	legacy.stage = 'compared';
	legacy.comparisonDigest = digest( 'comparison' );
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

test( 'binds structural-only selection to empty visual frame sets', () => {
	const structuralOnly = createComparisonResumeSidecar( {
		...bindings(),
		visualSelected: false,
	} );
	structuralOnly.stage = 'legacy_captured';
	structuralOnly.legacy = capture( 1 );
	structuralOnly.legacy.frames = [];
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
	sidecar.legacy = capture( 1 );

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
	premature.native = capture( 2 );
	assert.throws(
		() => validateComparisonResumeSidecar( premature ),
		/comparison_checkpoint_schema/
	);
} );

test( 'atomically writes, reads, and verifies exact bindings', async () => {
	const root = await mkdtemp( path.join( tmpdir(), 'presenter-resume-' ) );
	const sidecar = createComparisonResumeSidecar( bindings() );
	const target = await atomicWriteComparisonResumeSidecar(
		root,
		'deck-000001',
		sidecar
	);

	assert.equal( path.dirname( path.dirname( target ) ), root );
	assert.deepEqual( JSON.parse( await readFile( target, 'utf8' ) ), sidecar );
	assert.deepEqual(
		await readComparisonResumeSidecar( root, 'deck-000001', bindings() ),
		sidecar
	);
	sidecar.diffRetryOrdinal = 2;
	await atomicWriteComparisonResumeSidecar( root, 'deck-000001', sidecar );
	assert.equal(
		( await readComparisonResumeSidecar( root, 'deck-000001' ) )
			.diffRetryOrdinal,
		2
	);
	await assert.rejects(
		readComparisonResumeSidecar( root, 'deck-000001', {
			...bindings(),
			attemptDigest: digest( 'other-attempt' ),
		} ),
		/comparison_checkpoint_mismatch/
	);
	await assert.rejects(
		atomicWriteComparisonResumeSidecar( root, '../escape', sidecar ),
		/comparison_checkpoint_path_unsafe/
	);
} );

test( 'requires every opaque frame to resolve beneath the private root', async () => {
	const root = await mkdtemp( path.join( tmpdir(), 'presenter-resume-' ) );
	const sidecar = createComparisonResumeSidecar( bindings() );
	sidecar.stage = 'legacy_captured';
	sidecar.legacy = capture( 1 );

	await assert.rejects(
		atomicWriteComparisonResumeSidecar( root, 'deck-000001', sidecar ),
		/comparison_checkpoint_artifact_missing/
	);

	const captureDirectory = path.join( root, 'capture-000001' );
	await mkdir( captureDirectory );
	await writeFile(
		path.join( captureDirectory, 'frame-000001.png' ),
		'opaque'
	);
	await atomicWriteComparisonResumeSidecar( root, 'deck-000001', sidecar );
	assert.equal(
		( await readComparisonResumeSidecar( root, 'deck-000001' ) ).stage,
		'legacy_captured'
	);
} );

test( 'fails closed for missing and malformed persisted checkpoints', async () => {
	const root = await mkdtemp( path.join( tmpdir(), 'presenter-resume-' ) );
	await assert.rejects(
		readComparisonResumeSidecar( root, 'deck-000001' ),
		/comparison_checkpoint_missing/
	);

	const directory = path.join( root, 'deck-000001' );
	const valid = createComparisonResumeSidecar( bindings() );
	await atomicWriteComparisonResumeSidecar( root, 'deck-000001', valid );
	await writeFile(
		path.join( directory, 'comparison-resume.json' ),
		'{"private":"sentinel"}',
		'utf8'
	);
	await assert.rejects(
		readComparisonResumeSidecar( root, 'deck-000001' ),
		/comparison_checkpoint_schema/
	);
} );
