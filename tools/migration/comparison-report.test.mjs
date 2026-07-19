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

test( 'creates only the fixed content-free running schema', () => {
	const report = create();

	assert.equal( report.schemaVersion, 1 );
	assert.equal( report.mode, 'migration-comparison' );
	assert.equal( report.state, 'running' );
	assert.deepEqual( report.counts, {
		selected: 1,
		structuralPassed: 0,
		structuralFailed: 0,
		visualPassed: 0,
		visualReviewRequired: 0,
		visualSkipped: 0,
	} );
	assert.doesNotMatch(
		JSON.stringify( report ),
		/title|slug|postId|url|html/i
	);
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
	const report = create();
	report.decks[ 0 ].state = 'visual_review_required';
	report.decks[ 0 ].structural.state = 'passed';
	report.decks[ 0 ].structural.legacyManifestDigest = digest( 'legacy' );
	report.decks[ 0 ].structural.nativeManifestDigest = digest( 'native' );
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
