/* eslint-disable no-console -- This gate emits one fixed content-free result. */
import assert from 'node:assert/strict';
import { createHash, randomBytes } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';

import { chromium } from '@playwright/test';

import { captureRenderedDeck } from '../migration/capture-rendered-deck.mjs';
import { compareRenderedDecks } from '../migration/compare-rendered-decks.mjs';
import { compareVisualArtifacts } from '../migration/compare-visual-artifacts.mjs';
import {
	atomicWriteComparisonReport,
	comparisonHmac,
	createComparisonReport,
	NORMALIZATION_VERSION,
	REPORT_SCHEMA_VERSION,
	synchronizeComparisonCounts,
} from '../migration/comparison-report.mjs';
import { normalizeRenderedCapture } from '../migration/normalize-rendered-capture.mjs';

const repositoryRoot = process.cwd();
const wpEnv = path.join(
	repositoryRoot,
	'node_modules',
	'@wordpress',
	'env',
	'bin',
	'wp-env'
);
const origin = 'http://localhost:8888';
const viewport = { height: 720, width: 1280 };
const runName = `run-${ randomBytes( 12 ).toString( 'hex' ) }`;
const artifactRoot = path.join(
	repositoryRoot,
	'local',
	'migration-comparison-gate',
	runName
);
const key = randomBytes( 32 );

const runWp = ( args ) => {
	const result = spawnSync(
		process.execPath,
		[ wpEnv, 'run', 'cli', 'wp', ...args ],
		{ cwd: repositoryRoot, encoding: 'utf8' }
	);
	if ( result.status !== 0 ) {
		throw new Error( 'wp_command_failed' );
	}
	return result.stdout;
};

const jsonLine = ( output ) => {
	const line = output
		.split( /\r?\n/u )
		.find( ( candidate ) => candidate.startsWith( '{' ) );
	if ( ! line ) {
		throw new Error( 'wp_json_missing' );
	}
	return JSON.parse( line );
};

const migration = ( mode, postId ) => {
	const args = [ 'presenter', 'migration', mode, String( postId ) ];
	if ( mode !== 'status' ) {
		args.push( '--yes' );
	}
	const envelope = jsonLine( runWp( args ) );
	assert.equal( envelope.schemaVersion, 1, 'migration_schema' );
	assert.equal( envelope.mode, mode, 'migration_schema' );
	return envelope;
};

const privateState = ( postId ) =>
	JSON.stringify(
		jsonLine(
			runWp( [
				'eval',
				`$id=${ postId };echo wp_json_encode(array('post'=>get_post($id,ARRAY_A),'meta'=>get_post_meta($id),'revisions'=>array_keys(wp_get_post_revisions($id))));`,
			] )
		)
	);

const capture = ( browser, postId, ordinal, options = {} ) =>
	captureRenderedDeck( {
		...options,
		browser,
		captureOrdinal: ordinal,
		deckUrl: `${ origin }/?post_type=slideshow&p=${ postId }`,
		expectedOrigin: origin,
		privateRoot: artifactRoot,
		viewport,
	} );

const assertCaptureClean = ( captured ) => {
	assert.equal( captured.assets.state, 'clean', 'capture_assets_incomplete' );
	assert.equal( captured.assets.consoleError, 0, 'capture_console_error' );
	assert.equal( captured.assets.pageError, 0, 'capture_page_error' );
};

const visualInput = ( result ) => ( {
	captureStatus: 'captured',
	differingPixels: result.frames.reduce(
		( count, frame ) => count + frame.differingPixels,
		0
	),
	mode: 'standard',
	totalPixels: result.frames.reduce(
		( count, frame ) => count + frame.totalPixels,
		0
	),
} );

const artifactDigest = async ( captures ) => {
	const digests = [];
	for ( const captured of captures ) {
		for ( const frame of captured.frames ) {
			digests.push(
				comparisonHmac(
					key,
					'visual-frame',
					await readFile( path.join( artifactRoot, frame.file ) )
				)
			);
		}
	}
	return comparisonHmac(
		key,
		'visual-artifact-set',
		JSON.stringify( digests )
	);
};

const fixture = jsonLine(
	runWp( [
		'option',
		'get',
		'presenter_migration_comparison_fixtures',
		'--format=json',
	] )
);
assert.equal( fixture.schemaVersion, 1, 'fixture_schema' );
const targetId =
	fixture.fixtures[ 'presenter-migration-comparison-target' ].postId;
const neighborId =
	fixture.fixtures[ 'presenter-migration-comparison-neighbor' ].postId;
const neighborBefore = comparisonHmac(
	key,
	'neighbor-state',
	privateState( neighborId )
);

await mkdir( artifactRoot, { recursive: true } );
let browser;
let applied = false;
try {
	browser = await chromium.launch( { headless: true } );
	const legacyA = await capture( browser, targetId, 1 );
	const legacyB = await capture( browser, targetId, 2 );
	const structuralOnly = await capture( browser, neighborId, 5, {
		captureFrames: false,
	} );
	assertCaptureClean( legacyA );
	assertCaptureClean( legacyB );
	assertCaptureClean( structuralOnly );
	assert.equal(
		structuralOnly.frames.length,
		0,
		'structural_capture_wrote_frames'
	);

	const prepared = migration( 'prepare', targetId );
	assert( prepared.codes.includes( 'prepared' ), 'prepare_failed' );
	const preparedState = privateState( targetId );
	const attemptDigest = comparisonHmac(
		key,
		'prepared-attempt',
		preparedState
	);
	const appliedResult = migration( 'apply', targetId );
	assert( appliedResult.codes.includes( 'applied' ), 'apply_failed' );
	applied = true;

	const nativeA = await capture( browser, targetId, 3 );
	const nativeB = await capture( browser, targetId, 4 );
	assertCaptureClean( nativeA );
	assertCaptureClean( nativeB );
	const baselineRepeatDirectory = 'diff-000001';
	const nativeRepeatDirectory = 'diff-000002';
	const crossDirectory = 'diff-000003';
	await Promise.all( [
		mkdir( path.join( artifactRoot, baselineRepeatDirectory ) ),
		mkdir( path.join( artifactRoot, nativeRepeatDirectory ) ),
		mkdir( path.join( artifactRoot, crossDirectory ) ),
	] );
	const baselineRepeat = await compareVisualArtifacts( {
		baselineFiles: legacyA.frames.map( ( frame ) => frame.file ),
		candidateFiles: legacyB.frames.map( ( frame ) => frame.file ),
		diffDirectory: baselineRepeatDirectory,
		privateRoot: artifactRoot,
	} );
	const nativeRepeat = await compareVisualArtifacts( {
		baselineFiles: nativeA.frames.map( ( frame ) => frame.file ),
		candidateFiles: nativeB.frames.map( ( frame ) => frame.file ),
		diffDirectory: nativeRepeatDirectory,
		privateRoot: artifactRoot,
	} );
	assert.equal( baselineRepeat.state, 'passed', 'legacy_nondeterministic' );
	assert.equal( nativeRepeat.state, 'passed', 'native_nondeterministic' );
	const visual = await compareVisualArtifacts( {
		baselineFiles: legacyA.frames.map( ( frame ) => frame.file ),
		candidateFiles: nativeA.frames.map( ( frame ) => frame.file ),
		diffDirectory: crossDirectory,
		privateRoot: artifactRoot,
	} );

	const legacy = normalizeRenderedCapture( legacyA, key );
	const native = normalizeRenderedCapture( nativeA, key );
	const comparison = compareRenderedDecks( {
		legacy,
		native,
		schemaVersion: 1,
		visual: [ visualInput( visual ) ],
	} );
	assert.equal(
		comparison.structural.status,
		'passed',
		`structural_changed:${ comparison.structural.diagnostics
			.map( ( diagnostic ) => diagnostic.code )
			.join( ',' ) }`
	);

	const browserVersion = browser.version();
	const identityFiles = await Promise.all(
		[
			'package-lock.json',
			'tools/migration/capture-rendered-deck.mjs',
			'tools/migration/compare-rendered-decks.mjs',
			'tools/migration/normalize-rendered-capture.mjs',
		].map( ( filename ) =>
			readFile( path.join( repositoryRoot, filename ) )
		)
	);
	const identityDigest = comparisonHmac(
		key,
		'comparison-environment',
		JSON.stringify( {
			browserVersion,
			fileDigests: identityFiles.map( ( contents ) =>
				createHash( 'sha256' ).update( contents ).digest( 'hex' )
			),
			nodeVersion: process.version,
			normalizationVersion: NORMALIZATION_VERSION,
			origin,
			reportSchemaVersion: REPORT_SCHEMA_VERSION,
			viewport,
		} )
	);
	const report = createComparisonReport( {
		decks: [
			{
				access: 'public',
				attemptDigest,
				deckDigest: comparisonHmac(
					key,
					'deck-identity',
					String( targetId )
				),
			},
		],
		identityDigest,
		selectionDigest: comparisonHmac( key, 'selection', String( targetId ) ),
	} );
	const record = report.decks[ 0 ];
	const structuralCodes = [
		...new Set(
			comparison.structural.diagnostics.map( ( item ) => item.code )
		),
	].sort();
	record.structural = {
		state: comparison.structural.status,
		reason: 'none',
		codes: structuralCodes,
		legacySlideCount: legacy.slides.length,
		nativeSlideCount: native.slides.length,
		comparedSlideCount: Math.min(
			legacy.slides.length,
			native.slides.length
		),
		legacyManifestDigest: comparisonHmac(
			key,
			'legacy-structural-manifest',
			JSON.stringify( legacy )
		),
		nativeManifestDigest: comparisonHmac(
			key,
			'native-structural-manifest',
			JSON.stringify( native )
		),
		slides: legacy.slides
			.slice( 0, Math.min( legacy.slides.length, native.slides.length ) )
			.map( ( slide, slideIndex ) => {
				const codes = comparison.structural.diagnostics
					.filter( ( item ) => item.slideIndex === slideIndex )
					.map( ( item ) => item.code )
					.sort();
				return {
					addressDigest: slide.addressDigest,
					state: codes.length === 0 ? 'passed' : 'failed',
					codes,
				};
			} ),
	};
	record.visual = {
		state: visual.state,
		reason:
			visual.state === 'review_required' ? 'visual_difference' : 'none',
		comparedFrames: visual.comparedFrames,
		changedFrames: visual.changedFrames,
		maximumChangedPixelRatio: visual.maximumChangedPixelRatio,
		aggregateDigest: await artifactDigest( [ legacyA, nativeA ] ),
	};
	record.state =
		visual.state === 'review_required'
			? 'visual_review_required'
			: 'visual_passed';
	report.state = 'complete';
	synchronizeComparisonCounts( report );
	await atomicWriteComparisonReport( artifactRoot, 'report', report );

	const restored = migration( 'restore', targetId );
	assert( restored.codes.includes( 'restored' ), 'restore_failed' );
	applied = false;
	assert.equal(
		comparisonHmac( key, 'neighbor-state', privateState( neighborId ) ),
		neighborBefore,
		'neighbor_changed'
	);
	const status = migration( 'status', targetId );
	assert.equal( status.deckMode, 'legacy', 'target_not_restored' );

	console.log(
		JSON.stringify( {
			comparedFrames: visual.comparedFrames,
			passed: true,
			structural: comparison.structural.status,
			visual: visual.state,
		} )
	);
} finally {
	if ( applied ) {
		try {
			migration( 'restore', targetId );
		} catch {
			// The original fixed failure remains authoritative.
		}
	}
	await browser?.close().catch( () => {} );
	key.fill( 0 );
}
