import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, symlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
	atomicWriteComparisonReport,
	comparisonHmac,
	createComparisonReport,
	synchronizeComparisonCounts,
	validateComparisonReport,
} from './comparison-report.mjs';

const digest = ( value ) =>
	createHash( 'sha256' ).update( value ).digest( 'hex' );

const create = () =>
	createComparisonReport( {
		identityDigest: digest( 'environment' ),
		selectionDigest: digest( 'selection' ),
		decks: [
			{
				deckDigest: digest( 'deck-1' ),
				attemptDigest: digest( 'attempt-1' ),
				access: 'public',
			},
		],
	} );

const skipForAccess = ( access = 'protected' ) => {
	const report = create();
	const deck = report.decks[ 0 ];
	deck.access = access;
	deck.state = 'access_not_captured';
	deck.structural.state = 'skipped';
	deck.structural.reason = 'access_not_captured';
	deck.visual.state = 'skipped';
	deck.visual.reason = 'access_not_captured';

	return synchronizeComparisonCounts( report );
};

const skipVisualForSelection = () => {
	const report = create();
	const deck = report.decks[ 0 ];
	deck.state = 'structural_passed';
	deck.structural.state = 'passed';
	deck.structural.legacySlideCount = 1;
	deck.structural.nativeSlideCount = 1;
	deck.structural.comparedSlideCount = 1;
	deck.structural.legacyManifestDigest = digest( 'legacy-manifest' );
	deck.structural.nativeManifestDigest = digest( 'native-manifest' );
	deck.structural.slides = [
		{
			addressDigest: digest( 'slide-address' ),
			state: 'passed',
			codes: [],
		},
	];
	deck.visual.state = 'skipped';
	deck.visual.reason = 'not_selected';

	return synchronizeComparisonCounts( report );
};

const skipVisualAfterStructuralFailure = () => {
	const report = create();
	const deck = report.decks[ 0 ];
	deck.state = 'structural_failed';
	deck.structural.state = 'failed';
	deck.structural.codes = [ 'slide_count_changed' ];
	deck.visual.state = 'skipped';
	deck.visual.reason = 'not_selected';

	return synchronizeComparisonCounts( report );
};

test( 'creates only the fixed content-free running schema', () => {
	const report = create();

	assert.equal( report.schemaVersion, 2 );
	assert.equal( report.mode, 'migration-comparison' );
	assert.equal( report.state, 'running' );
	assert.deepEqual( report.counts, {
		selected: 1,
		structuralPassed: 0,
		structuralFailed: 0,
		structuralSkipped: 0,
		visualPassed: 0,
		visualReviewRequired: 0,
		visualSkipped: 0,
	} );
	assert.doesNotMatch(
		JSON.stringify( report ),
		/title|slug|postId|url|html/i
	);
} );

test( 'rejects empty selections and empty structural passes', () => {
	assert.throws(
		() =>
			createComparisonReport( {
				identityDigest: digest( 'environment' ),
				selectionDigest: digest( 'selection' ),
				decks: [],
			} ),
		/report_schema/
	);

	const report = create();
	const deck = report.decks[ 0 ];
	deck.state = 'structural_passed';
	deck.structural.state = 'passed';
	deck.structural.legacyManifestDigest = digest( 'legacy' );
	deck.structural.nativeManifestDigest = digest( 'native' );
	deck.visual.state = 'skipped';
	deck.visual.reason = 'not_selected';
	assert.throws(
		() => synchronizeComparisonCounts( report ),
		/report_schema/
	);
} );

test( 'binds terminal evidence and top-level state to exact outcomes', () => {
	const complete = skipVisualForSelection();
	complete.state = 'complete';
	complete.decks[ 0 ].attemptDigest = '0'.repeat( 64 );
	assert.throws(
		() => validateComparisonReport( complete ),
		/report_schema/
	);

	const falselyFailed = skipVisualForSelection();
	falselyFailed.state = 'failed';
	assert.throws(
		() => validateComparisonReport( falselyFailed ),
		/report_schema/
	);

	const captureFailure = create();
	captureFailure.decks[ 0 ].state = 'capture_incomplete';
	captureFailure.decks[ 0 ].visual.state = 'failed';
	captureFailure.decks[ 0 ].visual.reason = 'capture_error';
	synchronizeComparisonCounts( captureFailure );
	captureFailure.state = 'complete';
	assert.throws(
		() => validateComparisonReport( captureFailure ),
		/report_schema/
	);
	captureFailure.state = 'failed';
	validateComparisonReport( captureFailure );
	captureFailure.decks[ 0 ].visual.reason = 'not_selected';
	assert.throws(
		() => validateComparisonReport( captureFailure ),
		/report_schema/
	);
} );

test( 'rejects the superseded report schema version', () => {
	const report = create();
	report.schemaVersion = 1;
	assert.throws( () => validateComparisonReport( report ), /report_schema/ );
} );

test( 'represents protected and nonpublic access skips explicitly', () => {
	for ( const access of [ 'protected', 'nonpublic' ] ) {
		const report = skipForAccess( access );
		const deck = report.decks[ 0 ];

		assert.equal( deck.state, 'access_not_captured' );
		assert.deepEqual( deck.structural, {
			state: 'skipped',
			reason: 'access_not_captured',
			codes: [],
			legacySlideCount: 0,
			nativeSlideCount: 0,
			comparedSlideCount: 0,
			legacyManifestDigest: '0'.repeat( 64 ),
			nativeManifestDigest: '0'.repeat( 64 ),
			slides: [],
		} );
		assert.equal( report.counts.structuralSkipped, 1 );
		assert.equal( report.counts.visualSkipped, 1 );

		report.state = 'complete';
		assert.equal( validateComparisonReport( report ), report );
	}
} );

test( 'represents an unselected public visual capture as structural-only success', () => {
	const report = skipVisualForSelection();
	const deck = report.decks[ 0 ];

	assert.equal( deck.access, 'public' );
	assert.equal( deck.state, 'structural_passed' );
	assert.equal( deck.structural.state, 'passed' );
	assert.deepEqual( deck.visual, {
		state: 'skipped',
		reason: 'not_selected',
		comparedFrames: 0,
		changedFrames: 0,
		maximumChangedPixelRatio: 0,
		aggregateDigest: '0'.repeat( 64 ),
	} );
	assert.equal( report.counts.structuralPassed, 1 );
	assert.equal( report.counts.structuralSkipped, 0 );
	assert.equal( report.counts.visualSkipped, 1 );

	report.state = 'complete';
	assert.equal( validateComparisonReport( report ), report );
} );

test( 'preserves structural failure when an unselected public visual capture is skipped', () => {
	const report = skipVisualAfterStructuralFailure();
	const deck = report.decks[ 0 ];

	assert.equal( deck.access, 'public' );
	assert.equal( deck.state, 'structural_failed' );
	assert.equal( deck.structural.state, 'failed' );
	assert.equal( deck.visual.state, 'skipped' );
	assert.equal( deck.visual.reason, 'not_selected' );
	assert.equal( report.counts.structuralFailed, 1 );
	assert.equal( report.counts.structuralPassed, 0 );
	assert.equal( report.counts.visualSkipped, 1 );

	report.state = 'complete';
	assert.equal( validateComparisonReport( report ), report );
} );

test( 'rejects mismatched structural dispositions for unselected visual captures', () => {
	const failedAsPassed = skipVisualAfterStructuralFailure();
	failedAsPassed.decks[ 0 ].state = 'structural_passed';
	assert.throws(
		() => validateComparisonReport( failedAsPassed ),
		/report_schema/
	);

	const passedAsFailed = skipVisualForSelection();
	passedAsFailed.decks[ 0 ].state = 'structural_failed';
	assert.throws(
		() => validateComparisonReport( passedAsFailed ),
		/report_schema/
	);
} );

test( 'rejects every malformed unselected-visual representation', () => {
	const mutations = [
		[
			'nonpublic access',
			( report ) => ( report.decks[ 0 ].access = 'nonpublic' ),
		],
		[
			'access skip disposition',
			( report ) => ( report.decks[ 0 ].state = 'access_not_captured' ),
		],
		[
			'visual pass disposition',
			( report ) => ( report.decks[ 0 ].state = 'visual_passed' ),
		],
		[
			'structural skip',
			( report ) => ( report.decks[ 0 ].structural.state = 'skipped' ),
		],
		[
			'structural failure',
			( report ) => ( report.decks[ 0 ].structural.state = 'failed' ),
		],
		[
			'access reason',
			( report ) =>
				( report.decks[ 0 ].visual.reason = 'access_not_captured' ),
		],
		[
			'captured frame',
			( report ) => ( report.decks[ 0 ].visual.comparedFrames = 1 ),
		],
		[
			'changed frame',
			( report ) => {
				report.decks[ 0 ].visual.comparedFrames = 1;
				report.decks[ 0 ].visual.changedFrames = 1;
			},
		],
		[
			'pixel ratio',
			( report ) =>
				( report.decks[ 0 ].visual.maximumChangedPixelRatio = 0.1 ),
		],
		[
			'captured digest',
			( report ) =>
				( report.decks[ 0 ].visual.aggregateDigest =
					digest( 'visual-capture' ) ),
		],
	];

	for ( const [ label, mutate ] of mutations ) {
		const report = skipVisualForSelection();
		mutate( report );
		assert.throws(
			() => validateComparisonReport( report ),
			/report_schema/,
			label
		);
	}
} );

test( 'rejects every malformed access-skip representation', () => {
	const mutations = [
		[
			'public access',
			( report ) => ( report.decks[ 0 ].access = 'public' ),
		],
		[
			'deck disposition',
			( report ) => ( report.decks[ 0 ].state = 'failed' ),
		],
		[
			'structural state',
			( report ) =>
				( report.decks[ 0 ].structural.state = 'not_checked' ),
		],
		[
			'structural reason',
			( report ) => ( report.decks[ 0 ].structural.reason = 'none' ),
		],
		[
			'missing structural reason',
			( report ) => delete report.decks[ 0 ].structural.reason,
		],
		[
			'extra structural detail',
			( report ) =>
				( report.decks[ 0 ].structural.detail = 'private-sentinel' ),
		],
		[
			'structural code',
			( report ) =>
				( report.decks[ 0 ].structural.codes = [
					'runtime_not_ready',
				] ),
		],
		[
			'structural count',
			( report ) => ( report.decks[ 0 ].structural.legacySlideCount = 1 ),
		],
		[
			'structural digest',
			( report ) =>
				( report.decks[ 0 ].structural.legacyManifestDigest =
					digest( 'captured' ) ),
		],
		[
			'visual state',
			( report ) => ( report.decks[ 0 ].visual.state = 'not_checked' ),
		],
		[
			'visual reason',
			( report ) => ( report.decks[ 0 ].visual.reason = 'none' ),
		],
	];

	for ( const [ label, mutate ] of mutations ) {
		const report = skipForAccess();
		mutate( report );
		assert.throws(
			() => validateComparisonReport( report ),
			/report_schema/,
			label
		);
	}
} );

test( 'rejects stale structural skip aggregates', () => {
	const report = skipForAccess();
	report.counts.structuralSkipped = 0;
	assert.throws( () => validateComparisonReport( report ), /report_schema/ );
} );

test( 'rejects additional fields and arbitrary diagnostic text', () => {
	const withContent = create();
	withContent.decks[ 0 ].title = 'Private title sentinel';
	assert.throws(
		() => validateComparisonReport( withContent ),
		/report_schema/
	);

	const withDiagnostic = create();
	withDiagnostic.decks[ 0 ].structural.codes = [ 'Private note sentinel' ];
	assert.throws(
		() => validateComparisonReport( withDiagnostic ),
		/report_schema/
	);
} );

test( 'requires exact attempt and environment bindings', () => {
	for ( const field of [ 'identityDigest', 'selectionDigest' ] ) {
		const report = create();
		report[ field ] = 'not-a-digest';
		assert.throws(
			() => validateComparisonReport( report ),
			/report_schema/
		);
	}

	const report = create();
	report.decks[ 0 ].attemptDigest = digest( 'different-attempt' );
	assert.equal( validateComparisonReport( report ), report );
	assert.notEqual( report.decks[ 0 ].attemptDigest, digest( 'attempt-1' ) );
} );

test( 'accepts only sufficiently keyed, domain-separated byte inputs', () => {
	const key = Buffer.alloc( 32, 7 );
	const first = comparisonHmac( key, 'identity', 'same' );
	const second = comparisonHmac( key, 'attempt', 'same' );

	assert.match( first, /^[a-f0-9]{64}$/ );
	assert.notEqual( first, second );
	assert.throws(
		() => comparisonHmac( Buffer.alloc( 16 ), 'identity', 'same' ),
		/invalid_hmac_input/
	);
	assert.throws(
		() => comparisonHmac( key, 'identity', { authored: 'value' } ),
		/invalid_hmac_input/
	);
} );

test( 'synchronizes aggregate dispositions and rejects stale counts', () => {
	const report = skipVisualForSelection();
	report.decks[ 0 ].state = 'visual_review_required';
	report.decks[ 0 ].visual.state = 'review_required';
	report.decks[ 0 ].visual.reason = 'visual_difference';
	report.decks[ 0 ].visual.comparedFrames = 1;
	report.decks[ 0 ].visual.changedFrames = 1;
	report.decks[ 0 ].visual.maximumChangedPixelRatio = 0.25;
	report.decks[ 0 ].visual.aggregateDigest = digest( 'visual' );

	assert.throws( () => validateComparisonReport( report ), /report_schema/ );
	synchronizeComparisonCounts( report );
	assert.equal( report.counts.structuralPassed, 1 );
	assert.equal( report.counts.visualReviewRequired, 1 );
} );

test( 'atomically writes only beneath an opaque direct child directory', async () => {
	const privateRoot = await mkdtemp(
		path.join( tmpdir(), 'presenter-comparison-' )
	);
	const report = create();
	const target = await atomicWriteComparisonReport(
		privateRoot,
		'run-0123456789abcdef',
		report
	);

	assert.equal( path.dirname( path.dirname( target ) ), privateRoot );
	assert.deepEqual( JSON.parse( await readFile( target, 'utf8' ) ), report );
	await assert.rejects(
		atomicWriteComparisonReport( privateRoot, '../escape', report ),
		/private_path_unsafe/
	);
} );

test(
	'refuses a linked run directory',
	{ skip: process.platform === 'win32' },
	async () => {
		const privateRoot = await mkdtemp(
			path.join( tmpdir(), 'presenter-comparison-' )
		);
		await symlink( tmpdir(), path.join( privateRoot, 'linked-run' ) );

		await assert.rejects(
			atomicWriteComparisonReport( privateRoot, 'linked-run', create() ),
			/private_path_unsafe/
		);
	}
);
