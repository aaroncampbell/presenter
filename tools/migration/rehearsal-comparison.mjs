import assert from 'node:assert/strict';
import { createHash, timingSafeEqual } from 'node:crypto';
import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { isDeepStrictEqual } from 'node:util';

import { chromium } from '@playwright/test';

import {
	captureRenderedDeck,
	RenderedDeckCaptureError,
} from './capture-rendered-deck.mjs';
import {
	compareRenderedDecks,
	compareRenderedStructures,
	ComparisonSchemaError,
} from './compare-rendered-decks.mjs';
import {
	assertVisualArtifactsEqual,
	compareVisualArtifacts,
	VisualArtifactError,
} from './compare-visual-artifacts.mjs';
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
	comparisonCaptureAuthenticationDigest,
	comparisonFrameArtifactDigest,
	createComparisonResumeSidecar,
	readComparisonResumeSidecar,
} from './comparison-resume-sidecar.mjs';
import {
	normalizeRenderedCapture,
	RenderedCaptureSchemaError,
} from './normalize-rendered-capture.mjs';
import { SNAPSHOT_SOURCE_SHA256 } from '../snapshot/source-identity.mjs';

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

export const comparisonFailureWaitsForRestore = ( resumeStage ) =>
	[ 'applied', 'restoring', 'restored' ].includes( resumeStage );

export const comparisonCaptureOrdinals = ( index ) => {
	assert(
		Number.isSafeInteger( index ) && index >= 0 && index < 249999,
		'comparison_capture_ordinal'
	);
	return {
		legacyPrimary: index * 4 + 1,
		legacyRepeat: index * 4 + 2,
		nativePrimary: index * 4 + 3,
		nativeRepeat: index * 4 + 4,
	};
};

export const comparisonIdentityDigest = (
	key,
	{
		fileDigests,
		visualPostIds,
		nodeVersion = process.version,
		snapshotSha256 = SNAPSHOT_SOURCE_SHA256,
	}
) =>
	comparisonHmac(
		key,
		'comparison-environment',
		JSON.stringify( {
			browserEngine: 'chromium',
			fileDigests,
			nodeVersion,
			normalizationVersion: NORMALIZATION_VERSION,
			origin: ORIGIN,
			reportSchemaVersion: REPORT_SCHEMA_VERSION,
			snapshotSha256,
			viewport: VIEWPORT,
			visualPostIds: [ ...visualPostIds ],
		} )
	);

const attemptDigest = ( key, record ) =>
	[ 'pending', 'baseline' ].includes( record.stage )
		? comparisonHmacPending
		: comparisonHmac(
				key,
				'migration-attempt',
				record.preparedRevisionDigest
		  );

const authenticateCheckpointCapture = async ( {
	capture,
	key,
	ordinal,
	privateRoot,
	sidecar,
	sidecarName: checkpointName,
	slot,
} ) => {
	const checkpoint = {
		captureOrdinal: ordinal,
		assetState: capture.assetState,
		assetStates: capture.assetStates,
		frames: [],
		model: capture.model,
		captureDigest: comparisonHmacPending,
	};
	for ( const frame of capture.frames ) {
		const authenticated = {
			...frame,
			artifactDigest: comparisonHmacPending,
		};
		authenticated.artifactDigest = comparisonFrameArtifactDigest(
			key,
			checkpointName,
			sidecar,
			slot,
			checkpoint,
			authenticated,
			await readFile( path.join( privateRoot, frame.file ) )
		);
		checkpoint.frames.push( authenticated );
	}
	checkpoint.captureDigest = comparisonCaptureAuthenticationDigest(
		key,
		checkpointName,
		sidecar,
		slot,
		checkpoint
	);
	return checkpoint;
};

const checkpointCapture = async ( options ) =>
	authenticateCheckpointCapture( {
		...options,
		capture: {
			assetState: options.capture.assets.state,
			assetStates: options.capture.assets.states,
			frames: options.capture.frames,
			model: normalizeRenderedCapture( options.capture, options.key ),
		},
	} );

class NondeterministicCaptureError extends Error {
	constructor( code ) {
		super( code );
		this.code = code;
		this.name = 'NondeterministicCaptureError';
	}
}

export const assertDeterministicCapturePair = async ( {
	code,
	primary,
	privateRoot,
	repeat,
	visualSelected,
} ) => {
	const stableMetadata = ( capture ) => ( {
		assetState: capture.assetState,
		assetStates: capture.assetStates,
		frames: capture.frames.map( ( frame ) => ( {
			frameOrdinal: frame.frameOrdinal,
			slideOrdinal: frame.slideOrdinal,
			state: frame.state,
		} ) ),
		model: capture.model,
	} );
	if (
		! isDeepStrictEqual(
			stableMetadata( primary ),
			stableMetadata( repeat )
		)
	) {
		throw new NondeterministicCaptureError( code );
	}
	if ( visualSelected ) {
		try {
			await assertVisualArtifactsEqual( {
				baselineFiles: primary.frames.map( ( frame ) => frame.file ),
				candidateFiles: repeat.frames.map( ( frame ) => frame.file ),
				privateRoot,
			} );
		} catch ( error ) {
			if ( error?.code === 'visual-artifacts-changed' ) {
				throw new NondeterministicCaptureError( code );
			}
			throw error;
		}
	}
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
	if ( failureCode === 'capture_asset_failure' ) {
		record.visual.reason = 'asset_failure';
	} else if (
		[ 'legacy_nondeterministic', 'native_nondeterministic' ].includes(
			failureCode
		)
	) {
		record.visual.reason = 'nondeterministic';
	} else {
		record.visual.reason = 'capture_error';
	}
};

export const comparisonFailureCode = ( error, fallback ) => {
	if (
		[ 'legacy_nondeterministic', 'native_nondeterministic' ].includes(
			error?.code
		)
	) {
		return error.code;
	}
	if ( error instanceof RenderedCaptureSchemaError ) {
		return 'rendered_capture_schema';
	}
	if ( error instanceof ComparisonSchemaError ) {
		return 'structural_comparison_failed';
	}
	if ( error instanceof VisualArtifactError ) {
		return 'visual_artifact_invalid';
	}
	if ( error instanceof RenderedDeckCaptureError ) {
		return fallback;
	}
	return fallback;
};

const comparisonRecordEnvelope = ( sidecar ) => ( {
	schemaVersion: sidecar.schemaVersion,
	runDigest: sidecar.runDigest,
	identityDigest: sidecar.identityDigest,
	selectionDigest: sidecar.selectionDigest,
	deckDigest: sidecar.deckDigest,
	attemptDigest: sidecar.attemptDigest,
	access: sidecar.access,
	visualSelected: sidecar.visualSelected,
	reportRecord: sidecar.reportRecord,
} );

const comparisonRecordDigest = ( sidecar, key ) =>
	comparisonHmac(
		key,
		'comparison-record',
		JSON.stringify( comparisonRecordEnvelope( sidecar ) )
	);

export const storedComparisonRecord = ( sidecar, key ) => {
	assert.equal( sidecar.stage, 'compared', 'comparison_incomplete' );
	assert( sidecar.reportRecord, 'comparison_record_missing' );
	const expected = comparisonRecordDigest( sidecar, key );
	assert(
		timingSafeEqual(
			Buffer.from( expected, 'hex' ),
			Buffer.from( sidecar.comparisonDigest, 'hex' )
		),
		'comparison_result_changed'
	);
	return structuredClone( sidecar.reportRecord );
};

export const validateAcceptanceCorpus = (
	corpus,
	expectedSnapshotSha256 = SNAPSHOT_SOURCE_SHA256.database
) => {
	exactKeys( corpus, [ 'snapshotSha256', 'decks' ], 'acceptance_schema' );
	assert(
		typeof corpus.snapshotSha256 === 'string' &&
			/^[A-F0-9]{64}$/.test( corpus.snapshotSha256 ),
		'acceptance_schema'
	);
	assert.equal(
		corpus.snapshotSha256,
		expectedSnapshotSha256,
		'acceptance_snapshot_changed'
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

const acceptanceSelection = async () =>
	validateAcceptanceCorpus(
		JSON.parse( await readFile( ACCEPTANCE_CORPUS, 'utf8' ) )
	);

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
		const runDigest = comparisonHmac(
			key,
			'comparison-run-v3',
			path.basename( runDirectory )
		);
		const selected = new Set( selectedPostIds );
		for ( const postId of visualPostIds ) {
			assert(
				selected.has( postId ) || selectedPostIds.length < 65,
				'acceptance_corpus_changed'
			);
		}
		const identityDigest = comparisonIdentityDigest( key, {
			fileDigests: identityFiles.map( ( contents ) =>
				createHash( 'sha256' ).update( contents ).digest( 'hex' )
			),
			visualPostIds,
		} );
		const instance = new RehearsalComparison( {
			identityDigest,
			key,
			records,
			runDigest,
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
			runDigest: this.runDigest,
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
				bindings,
				this.key
			);
		} catch ( error ) {
			if ( error?.code !== 'comparison_checkpoint_missing' ) {
				throw error;
			}
			const sidecar = createComparisonResumeSidecar( bindings );
			await atomicWriteComparisonResumeSidecar(
				this.privateRoot,
				sidecarName( index ),
				sidecar,
				this.key
			);
			return sidecar;
		}
	}

	async write( index, sidecar ) {
		await atomicWriteComparisonResumeSidecar(
			this.privateRoot,
			sidecarName( index ),
			sidecar,
			this.key
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

	async captureSlot( index, postId, sidecar, slot, ordinal ) {
		const captured = await captureRenderedDeck( {
			browser: await this.browserInstance(),
			captureFrames: sidecar.visualSelected,
			captureOrdinal: ordinal,
			deckUrl: `${ ORIGIN }/?post_type=slideshow&p=${ postId }`,
			expectedOrigin: ORIGIN,
			privateRoot: this.privateRoot,
			viewport: VIEWPORT,
		} );
		return checkpointCapture( {
			capture: captured,
			key: this.key,
			ordinal,
			privateRoot: this.privateRoot,
			sidecar,
			sidecarName: sidecarName( index ),
			slot,
		} );
	}

	async captureLegacy( index, postId, access ) {
		const sidecar = await this.readOrCreate( index, postId, access );
		if (
			access !== 'public' ||
			! [ 'not_checked', 'legacy_primary_captured' ].includes(
				sidecar.stage
			)
		) {
			return;
		}
		const ordinals = comparisonCaptureOrdinals( index );
		if ( sidecar.stage === 'not_checked' ) {
			try {
				sidecar.legacy = await this.captureSlot(
					index,
					postId,
					sidecar,
					'legacy',
					ordinals.legacyPrimary
				);
				sidecar.stage = 'legacy_primary_captured';
			} catch ( error ) {
				sidecar.stage = 'capture_failed';
				sidecar.failureCode = comparisonFailureCode(
					error,
					'legacy_capture_failed'
				);
			}
			await this.write( index, sidecar );
			if ( sidecar.stage === 'capture_failed' ) {
				return;
			}
		}
		try {
			sidecar.legacyRepeat = await this.captureSlot(
				index,
				postId,
				sidecar,
				'legacyRepeat',
				ordinals.legacyRepeat
			);
			await assertDeterministicCapturePair( {
				code: 'legacy_nondeterministic',
				primary: sidecar.legacy,
				privateRoot: this.privateRoot,
				repeat: sidecar.legacyRepeat,
				visualSelected: sidecar.visualSelected,
			} );
			sidecar.stage = 'legacy_captured';
			sidecar.failureCode = 'none';
		} catch ( error ) {
			sidecar.stage = 'capture_failed';
			sidecar.failureCode = comparisonFailureCode(
				error,
				'legacy_capture_failed'
			);
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
		if (
			! [ 'legacy_captured', 'native_primary_captured' ].includes(
				sidecar.stage
			)
		) {
			return;
		}
		const ordinals = comparisonCaptureOrdinals( index );
		if ( sidecar.stage === 'legacy_captured' ) {
			try {
				sidecar.native = await this.captureSlot(
					index,
					postId,
					sidecar,
					'native',
					ordinals.nativePrimary
				);
				sidecar.stage = 'native_primary_captured';
			} catch ( error ) {
				sidecar.stage = 'capture_failed';
				sidecar.failureCode = comparisonFailureCode(
					error,
					'native_capture_failed'
				);
			}
			await this.write( index, sidecar );
			if ( sidecar.stage === 'capture_failed' ) {
				return;
			}
		}
		try {
			sidecar.nativeRepeat = await this.captureSlot(
				index,
				postId,
				sidecar,
				'nativeRepeat',
				ordinals.nativeRepeat
			);
			await assertDeterministicCapturePair( {
				code: 'native_nondeterministic',
				primary: sidecar.native,
				privateRoot: this.privateRoot,
				repeat: sidecar.nativeRepeat,
				visualSelected: sidecar.visualSelected,
			} );
			sidecar.stage = 'native_captured';
			sidecar.failureCode = 'none';
		} catch ( error ) {
			sidecar.stage = 'capture_failed';
			sidecar.failureCode = comparisonFailureCode(
				error,
				'native_capture_failed'
			);
		}
		await this.write( index, sidecar );
	}

	async compareAfterRestore( index, postId, access ) {
		let sidecar = await this.readOrCreate( index, postId, access );
		if ( [ 'capture_failed', 'compared' ].includes( sidecar.stage ) ) {
			return;
		}
		assert.equal(
			sidecar.stage,
			'native_captured',
			'comparison_incomplete'
		);
		try {
			const record = await this.comparisonRecord( index, sidecar );
			sidecar.reportRecord = record;
			sidecar.comparisonDigest = comparisonRecordDigest(
				sidecar,
				this.key
			);
			sidecar.stage = 'compared';
			await this.write( index, sidecar );
		} catch ( error ) {
			sidecar = await this.readOrCreate( index, postId, access );
			sidecar.stage = 'capture_failed';
			sidecar.failureCode = comparisonFailureCode(
				error,
				'visual_comparison_failed'
			);
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
			sidecar.legacyRepeat.assetState === 'clean' &&
			sidecar.native.assetState === 'clean' &&
			sidecar.nativeRepeat.assetState === 'clean';
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
					[
						sidecar.legacy,
						sidecar.legacyRepeat,
						sidecar.native,
						sidecar.nativeRepeat,
					],
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
			const rebuilt = storedComparisonRecord( sidecar, this.key );
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
