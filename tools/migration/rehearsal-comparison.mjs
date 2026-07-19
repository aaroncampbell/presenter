import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';

import { chromium } from '@playwright/test';

import { captureRenderedDeck } from './capture-rendered-deck.mjs';
import {
	compareRenderedDecks,
	compareRenderedStructures,
} from './compare-rendered-decks.mjs';
import { compareVisualArtifacts } from './compare-visual-artifacts.mjs';
import {
	atomicWriteComparisonReport,
	comparisonHmac,
	comparisonHmacPending,
	createComparisonReport,
	NORMALIZATION_VERSION,
	REPORT_SCHEMA_VERSION,
	synchronizeComparisonCounts,
} from './comparison-report.mjs';
import {
	atomicWriteComparisonResumeSidecar,
	createComparisonResumeSidecar,
	readComparisonResumeSidecar,
} from './comparison-resume-sidecar.mjs';
import { normalizeRenderedCapture } from './normalize-rendered-capture.mjs';

const VIEWPORT = Object.freeze( { height: 720, width: 1280 } );
const ORIGIN = 'http://localhost:8890';
const ACCEPTANCE_CORPUS = path.join(
	process.cwd(),
	'local',
	'acceptance-corpus',
	'corpus.json'
);

const exactKeys = ( value, keys, code ) => {
	assert(
		value !== null && typeof value === 'object' && ! Array.isArray( value ),
		code
	);
	assert.deepEqual( Object.keys( value ), keys, code );
};

const sidecarName = ( index ) =>
	`deck-${ String( index + 1 ).padStart( 6, '0' ) }`;

const captureOrdinal = ( index, representation ) =>
	index * 2 + ( representation === 'legacy' ? 1 : 2 );

const attemptDigest = ( key, record ) =>
	[ 'pending', 'baseline' ].includes( record.stage )
		? comparisonHmacPending
		: comparisonHmac(
				key,
				'migration-attempt',
				record.preparedRevisionDigest
		  );

const checkpointCapture = ( capture, key, ordinal ) => ( {
	captureOrdinal: ordinal,
	assetState: capture.assets.state,
	assetStates: capture.assets.states,
	frames: capture.frames,
	model: normalizeRenderedCapture( capture, key ),
} );

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

const structuralRecord = ( comparison, legacy, native, key ) => {
	const codes = [
		...new Set(
			comparison.structural.diagnostics.map( ( item ) => item.code )
		),
	].sort();
	const comparedSlideCount = Math.min(
		legacy.slides.length,
		native.slides.length
	);

	return {
		state: comparison.structural.status,
		reason: 'none',
		codes,
		legacySlideCount: legacy.slides.length,
		nativeSlideCount: native.slides.length,
		comparedSlideCount,
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
			.slice( 0, comparedSlideCount )
			.map( ( slide, slideIndex ) => {
				const slideCodes = comparison.structural.diagnostics
					.filter( ( item ) => item.slideIndex === slideIndex )
					.map( ( item ) => item.code )
					.sort();
				return {
					addressDigest: slide.addressDigest,
					state: slideCodes.length === 0 ? 'passed' : 'failed',
					codes: slideCodes,
				};
			} ),
	};
};

const artifactDigest = async ( privateRoot, captures, key ) => {
	const digests = [];
	for ( const capture of captures ) {
		for ( const frame of capture.frames ) {
			digests.push(
				comparisonHmac(
					key,
					'visual-frame',
					await readFile( path.join( privateRoot, frame.file ) )
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

const accessSkipped = ( record ) => {
	record.state = 'access_not_captured';
	record.structural.state = 'skipped';
	record.structural.reason = 'access_not_captured';
	record.visual.state = 'skipped';
	record.visual.reason = 'access_not_captured';
};

const captureFailed = ( record, failureCode ) => {
	record.state = 'capture_incomplete';
	record.visual.state = 'failed';
	record.visual.reason =
		failureCode === 'capture_asset_failure'
			? 'asset_failure'
			: 'capture_error';
};

const acceptanceSelection = async () => {
	const corpus = JSON.parse( await readFile( ACCEPTANCE_CORPUS, 'utf8' ) );
	exactKeys( corpus, [ 'snapshotSha256', 'decks' ], 'acceptance_schema' );
	assert(
		typeof corpus.snapshotSha256 === 'string' &&
			/^[A-F0-9]{64}$/.test( corpus.snapshotSha256 ),
		'acceptance_schema'
	);
	assert( Array.isArray( corpus.decks ), 'acceptance_schema' );
	return new Set(
		corpus.decks.map( ( deck ) => {
			exactKeys( deck, [ 'postId', 'purpose' ], 'acceptance_schema' );
			assert(
				Number.isSafeInteger( deck.postId ) && deck.postId > 0,
				'acceptance_schema'
			);
			assert( typeof deck.purpose === 'string', 'acceptance_schema' );
			return deck.postId;
		} )
	);
};

export class RehearsalComparison {
	constructor( options ) {
		Object.assign( this, options );
		this.key = Buffer.from( options.key );
		this.privateRoot = path.join(
			options.runDirectory,
			'comparison-private'
		);
	}

	static async create( {
		key,
		records,
		runDirectory,
		selectedPostIds,
		selectionDigest,
	} ) {
		const identityFiles = await Promise.all(
			[
				'package-lock.json',
				'tools/migration/capture-rendered-deck.mjs',
				'tools/migration/compare-rendered-decks.mjs',
				'tools/migration/compare-visual-artifacts.mjs',
				'tools/migration/comparison-report.mjs',
				'tools/migration/comparison-resume-sidecar.mjs',
				'tools/migration/normalize-rendered-capture.mjs',
				'tools/migration/rehearse-corpus.mjs',
				'tools/migration/rehearsal-comparison.mjs',
			].map( ( filename ) =>
				readFile( path.join( process.cwd(), filename ) )
			)
		);
		const visualPostIds = await acceptanceSelection();
		const selected = new Set( selectedPostIds );
		for ( const postId of visualPostIds ) {
			assert(
				selected.has( postId ) || selectedPostIds.length < 65,
				'acceptance_corpus_changed'
			);
		}
		const identityDigest = comparisonHmac(
			key,
			'comparison-environment',
			JSON.stringify( {
				browserEngine: 'chromium',
				fileDigests: identityFiles.map( ( contents ) =>
					createHash( 'sha256' ).update( contents ).digest( 'hex' )
				),
				nodeVersion: process.version,
				normalizationVersion: NORMALIZATION_VERSION,
				origin: ORIGIN,
				reportSchemaVersion: REPORT_SCHEMA_VERSION,
				viewport: VIEWPORT,
			} )
		);
		const instance = new RehearsalComparison( {
			identityDigest,
			key,
			records,
			runDirectory,
			selectedPostIds,
			selectionDigest,
			visualPostIds,
		} );
		await mkdir( instance.privateRoot, { recursive: true } );
		return instance;
	}

	async browserInstance() {
		if ( ! this.browser ) {
			this.browser = await chromium.launch( { headless: true } );
		}
		return this.browser;
	}

	bindings( index, postId, access ) {
		const record = this.records[ index ];
		return {
			identityDigest: this.identityDigest,
			selectionDigest: this.selectionDigest,
			deckDigest: record.deckDigest,
			attemptDigest: attemptDigest( this.key, record ),
			access,
			visualSelected:
				access === 'public' && this.visualPostIds.has( postId ),
		};
	}

	async readOrCreate(
		index,
		postId,
		access,
		expectedAttemptDigest = undefined
	) {
		const bindings = this.bindings( index, postId, access );
		if ( expectedAttemptDigest !== undefined ) {
			bindings.attemptDigest = expectedAttemptDigest;
		}
		try {
			return await readComparisonResumeSidecar(
				this.privateRoot,
				sidecarName( index ),
				bindings
			);
		} catch ( error ) {
			if ( error?.code !== 'comparison_checkpoint_missing' ) {
				throw error;
			}
			const sidecar = createComparisonResumeSidecar( bindings );
			await atomicWriteComparisonResumeSidecar(
				this.privateRoot,
				sidecarName( index ),
				sidecar
			);
			return sidecar;
		}
	}

	async write( index, sidecar ) {
		await atomicWriteComparisonResumeSidecar(
			this.privateRoot,
			sidecarName( index ),
			sidecar
		);
	}

	async checkpointAccess( index, postId, access ) {
		let sidecar;
		try {
			sidecar = await this.readOrCreate( index, postId, access );
		} catch ( error ) {
			if ( error?.code !== 'comparison_checkpoint_mismatch' ) {
				throw error;
			}
			sidecar = await this.readOrCreate(
				index,
				postId,
				access,
				comparisonHmacPending
			);
		}
		if ( access !== 'public' && sidecar.stage === 'not_checked' ) {
			sidecar.stage = 'access_skipped';
			await this.write( index, sidecar );
		}
	}

	async markLegacyHttpFailure( index, postId, access ) {
		const sidecar = await this.readOrCreate( index, postId, access );
		if ( sidecar.stage === 'not_checked' ) {
			sidecar.stage = 'capture_failed';
			sidecar.failureCode = 'legacy_capture_http_500';
			await this.write( index, sidecar );
		}
	}

	async captureLegacy( index, postId, access ) {
		const sidecar = await this.readOrCreate( index, postId, access );
		if ( access !== 'public' || sidecar.stage !== 'not_checked' ) {
			return;
		}
		try {
			const ordinal = captureOrdinal( index, 'legacy' );
			const captured = await captureRenderedDeck( {
				browser: await this.browserInstance(),
				captureFrames: sidecar.visualSelected,
				captureOrdinal: ordinal,
				deckUrl: `${ ORIGIN }/?post_type=slideshow&p=${ postId }`,
				expectedOrigin: ORIGIN,
				privateRoot: this.privateRoot,
				viewport: VIEWPORT,
			} );
			sidecar.legacy = checkpointCapture( captured, this.key, ordinal );
			sidecar.stage = 'legacy_captured';
			sidecar.failureCode = 'none';
		} catch {
			sidecar.stage = 'capture_failed';
			sidecar.failureCode = 'legacy_capture_failed';
		}
		await this.write( index, sidecar );
	}

	async bindAttempt( index, postId, access ) {
		const bound = this.bindings( index, postId, access ).attemptDigest;
		assert.notEqual(
			bound,
			comparisonHmacPending,
			'comparison_attempt_missing'
		);
		let sidecar;
		try {
			sidecar = await this.readOrCreate( index, postId, access, bound );
		} catch ( error ) {
			if ( error?.code !== 'comparison_checkpoint_mismatch' ) {
				throw error;
			}
			sidecar = await this.readOrCreate(
				index,
				postId,
				access,
				comparisonHmacPending
			);
		}
		if ( sidecar.attemptDigest === comparisonHmacPending ) {
			sidecar.attemptDigest = bound;
			await this.write( index, sidecar );
		} else {
			assert.equal(
				sidecar.attemptDigest,
				bound,
				'comparison_attempt_changed'
			);
		}
	}

	async captureNative( index, postId, access ) {
		const sidecar = await this.readOrCreate( index, postId, access );
		if ( access !== 'public' || sidecar.stage === 'capture_failed' ) {
			return;
		}
		assert.notEqual(
			sidecar.attemptDigest,
			comparisonHmacPending,
			'comparison_attempt_missing'
		);
		if ( sidecar.stage === 'not_checked' ) {
			sidecar.stage = 'capture_failed';
			sidecar.failureCode = 'legacy_capture_failed';
			await this.write( index, sidecar );
			return;
		}
		if ( sidecar.stage === 'legacy_captured' ) {
			try {
				const ordinal = captureOrdinal( index, 'native' );
				const captured = await captureRenderedDeck( {
					browser: await this.browserInstance(),
					captureFrames: sidecar.visualSelected,
					captureOrdinal: ordinal,
					deckUrl: `${ ORIGIN }/?post_type=slideshow&p=${ postId }`,
					expectedOrigin: ORIGIN,
					privateRoot: this.privateRoot,
					viewport: VIEWPORT,
				} );
				sidecar.native = checkpointCapture(
					captured,
					this.key,
					ordinal
				);
				sidecar.stage = 'native_captured';
				sidecar.failureCode = 'none';
			} catch {
				sidecar.stage = 'capture_failed';
				sidecar.failureCode = 'native_capture_failed';
			}
			await this.write( index, sidecar );
		}
	}

	async compareAfterRestore( index, postId, access ) {
		let sidecar = await this.readOrCreate( index, postId, access );
		if ( sidecar.stage !== 'native_captured' ) {
			return;
		}
		try {
			const record = await this.comparisonRecord( index, sidecar );
			sidecar.comparisonDigest = comparisonHmac(
				this.key,
				'comparison-record',
				JSON.stringify( record )
			);
			sidecar.stage = 'compared';
			await this.write( index, sidecar );
		} catch {
			sidecar = await this.readOrCreate( index, postId, access );
			sidecar.stage = 'capture_failed';
			sidecar.failureCode = 'visual_comparison_failed';
			await this.write( index, sidecar );
		}
	}

	async comparisonRecord( index, sidecar ) {
		const report = createComparisonReport( {
			decks: [
				{
					access: sidecar.access,
					attemptDigest: sidecar.attemptDigest,
					deckDigest: sidecar.deckDigest,
				},
			],
			identityDigest: sidecar.identityDigest,
			selectionDigest: sidecar.selectionDigest,
		} );
		const record = report.decks[ 0 ];
		const assetsClean =
			sidecar.legacy.assetState === 'clean' &&
			sidecar.native.assetState === 'clean';
		let comparison;
		let visual;
		if ( sidecar.visualSelected && assetsClean ) {
			const retry = sidecar.diffRetryOrdinal;
			assert( retry < 1000, 'comparison_retry_exhausted' );
			sidecar.diffRetryOrdinal++;
			await this.write( index, sidecar );
			const diffDirectory = `diff-${ String(
				( index + 1 ) * 1000 + retry
			).padStart( 6, '0' ) }`;
			await mkdir( path.join( this.privateRoot, diffDirectory ) );
			visual = await compareVisualArtifacts( {
				baselineFiles: sidecar.legacy.frames.map(
					( frame ) => frame.file
				),
				candidateFiles: sidecar.native.frames.map(
					( frame ) => frame.file
				),
				diffDirectory,
				privateRoot: this.privateRoot,
			} );
			comparison = compareRenderedDecks( {
				legacy: sidecar.legacy.model,
				native: sidecar.native.model,
				schemaVersion: 1,
				visual: [ visualInput( visual ) ],
			} );
		} else {
			comparison = compareRenderedStructures( {
				legacy: sidecar.legacy.model,
				native: sidecar.native.model,
				schemaVersion: 1,
			} );
		}
		record.structural = structuralRecord(
			comparison,
			sidecar.legacy.model,
			sidecar.native.model,
			this.key
		);
		if ( ! assetsClean ) {
			record.visual.state = 'failed';
			record.visual.reason = 'asset_failure';
			record.state = 'capture_incomplete';
		} else if ( sidecar.visualSelected ) {
			record.visual = {
				state: visual.state,
				reason:
					visual.state === 'review_required'
						? 'visual_difference'
						: 'none',
				comparedFrames: visual.comparedFrames,
				changedFrames: visual.changedFrames,
				maximumChangedPixelRatio: visual.maximumChangedPixelRatio,
				aggregateDigest: await artifactDigest(
					this.privateRoot,
					[ sidecar.legacy, sidecar.native ],
					this.key
				),
			};
			if ( record.structural.state === 'failed' ) {
				record.state = 'structural_failed';
			} else {
				record.state =
					visual.state === 'review_required'
						? 'visual_review_required'
						: 'visual_passed';
			}
		} else {
			record.visual.state = 'skipped';
			record.visual.reason = 'not_selected';
			record.state =
				record.structural.state === 'failed'
					? 'structural_failed'
					: 'structural_passed';
		}
		report.state =
			record.state === 'capture_incomplete' ? 'failed' : 'complete';
		synchronizeComparisonCounts( report );
		return report.decks[ 0 ];
	}

	async finalize() {
		const sidecars = [];
		for ( let index = 0; index < this.selectedPostIds.length; index++ ) {
			const postId = this.selectedPostIds[ index ];
			const access = this.records[ index ].access;
			sidecars.push( await this.readOrCreate( index, postId, access ) );
		}
		const report = createComparisonReport( {
			decks: sidecars.map( ( sidecar ) => ( {
				access: sidecar.access,
				attemptDigest: sidecar.attemptDigest,
				deckDigest: sidecar.deckDigest,
			} ) ),
			identityDigest: this.identityDigest,
			selectionDigest: this.selectionDigest,
		} );
		let failed = false;
		for ( let index = 0; index < sidecars.length; index++ ) {
			const sidecar = sidecars[ index ];
			const record = report.decks[ index ];
			if ( sidecar.stage === 'access_skipped' ) {
				accessSkipped( record );
				continue;
			}
			if ( sidecar.stage === 'capture_failed' ) {
				captureFailed( record, sidecar.failureCode );
				failed = true;
				continue;
			}
			assert.equal( sidecar.stage, 'compared', 'comparison_incomplete' );
			const rebuilt = await this.comparisonRecord( index, sidecar );
			assert.equal(
				comparisonHmac(
					this.key,
					'comparison-record',
					JSON.stringify( rebuilt )
				),
				sidecar.comparisonDigest,
				'comparison_result_changed'
			);
			report.decks[ index ] = rebuilt;
			if (
				[ 'capture_incomplete', 'failed' ].includes( rebuilt.state )
			) {
				failed = true;
			}
		}
		report.state = failed ? 'failed' : 'complete';
		synchronizeComparisonCounts( report );
		await atomicWriteComparisonReport(
			this.runDirectory,
			'comparison',
			report
		);
		return report;
	}

	async close() {
		await this.browser?.close();
		this.key.fill( 0 );
	}
}
