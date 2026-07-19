import { randomBytes, timingSafeEqual } from 'node:crypto';
import {
	lstat,
	mkdir,
	readFile,
	realpath,
	rename,
	writeFile,
} from 'node:fs/promises';
import path from 'node:path';

import {
	comparisonHmac,
	comparisonHmacPending,
	validateComparisonDeckRecord,
} from './comparison-report.mjs';

export const COMPARISON_RESUME_SCHEMA_VERSION = 3;

const digestPattern = /^[a-f0-9]{64}$/;
const sidecarNamePattern = /^deck-[0-9]{6}$/;
const framePattern = /^capture-([0-9]{6})\/frame-([0-9]{6})\.png$/;
const accessClasses = new Set( [ 'public', 'protected', 'nonpublic' ] );
const stages = new Set( [
	'not_checked',
	'access_skipped',
	'legacy_primary_captured',
	'legacy_captured',
	'native_primary_captured',
	'native_captured',
	'compared',
	'capture_failed',
] );
const assetStateOrder = [
	'console-error',
	'page-error',
	'blocked-nonlocal',
	'incomplete-image',
	'local-http-error',
	'local-request-failed',
	'clean',
];
const assetStates = new Set( assetStateOrder );
const failureCodes = new Set( [
	'none',
	'legacy_capture_http_500',
	'legacy_capture_failed',
	'native_capture_failed',
	'capture_asset_failure',
	'rendered_capture_schema',
	'visual_artifact_invalid',
	'visual_comparison_failed',
	'structural_comparison_failed',
	'legacy_nondeterministic',
	'native_nondeterministic',
] );
const notesFormats = new Set( [
	'none',
	'plain',
	'markdown',
	'html',
	'markdown-html',
] );
const frameStates = new Set( [ 'initial', 'final' ] );
const captureSlots = [ 'legacy', 'legacyRepeat', 'native', 'nativeRepeat' ];

export class ComparisonResumeSidecarError extends Error {
	constructor( code ) {
		super( code );
		this.name = 'ComparisonResumeSidecarError';
		this.code = code;
	}
}

const fail = ( code = 'comparison_checkpoint_schema' ) => {
	throw new ComparisonResumeSidecarError( code );
};

const exactKeys = ( value, keys ) => {
	if (
		value === null ||
		typeof value !== 'object' ||
		Array.isArray( value ) ||
		JSON.stringify( Object.keys( value ) ) !== JSON.stringify( keys )
	) {
		fail();
	}
};

const requireDigest = ( value ) => {
	if ( typeof value !== 'string' || ! digestPattern.test( value ) ) {
		fail();
	}
};

const validOrdinal = ( value ) =>
	Number.isSafeInteger( value ) && value >= 1 && value <= 999999;

const isInside = ( root, candidate ) => {
	const relative = path.relative( root, candidate );
	return (
		relative === '' ||
		( ! relative.startsWith( '..' ) && ! path.isAbsolute( relative ) )
	);
};

const validateNormalizedModel = ( model ) => {
	exactKeys( model, [
		'configDigest',
		'height',
		'hierarchyDigest',
		'runtimeReady',
		'slides',
		'themeDigest',
		'width',
	] );
	for ( const digest of [
		model.configDigest,
		model.hierarchyDigest,
		model.themeDigest,
	] ) {
		requireDigest( digest );
	}
	if (
		model.runtimeReady !== true ||
		! Number.isSafeInteger( model.height ) ||
		model.height < 1 ||
		! Number.isSafeInteger( model.width ) ||
		model.width < 1 ||
		! Array.isArray( model.slides ) ||
		model.slides.length < 1
	) {
		fail();
	}
	for ( const slide of model.slides ) {
		exactKeys( slide, [
			'addressDigest',
			'anchorDigest',
			'dataAttributesDigest',
			'fragmentsDigest',
			'notesDigest',
			'notesFormat',
			'renderedOutputDigest',
			'wrapperClassesDigest',
		] );
		for ( const digest of [
			slide.addressDigest,
			slide.anchorDigest,
			slide.dataAttributesDigest,
			slide.fragmentsDigest,
			slide.renderedOutputDigest,
			slide.wrapperClassesDigest,
		] ) {
			requireDigest( digest );
		}
		if (
			! notesFormats.has( slide.notesFormat ) ||
			( slide.notesFormat === 'none' && slide.notesDigest !== null ) ||
			( slide.notesFormat !== 'none' &&
				( typeof slide.notesDigest !== 'string' ||
					! digestPattern.test( slide.notesDigest ) ) )
		) {
			fail();
		}
	}
};

const validateCapture = ( capture, visualSelected ) => {
	if ( capture === null ) {
		return;
	}
	exactKeys( capture, [
		'captureOrdinal',
		'assetState',
		'assetStates',
		'frames',
		'model',
		'captureDigest',
	] );
	if (
		! validOrdinal( capture.captureOrdinal ) ||
		! assetStates.has( capture.assetState ) ||
		! Array.isArray( capture.assetStates ) ||
		capture.assetStates.length < 1 ||
		new Set( capture.assetStates ).size !== capture.assetStates.length ||
		capture.assetStates.some( ( state ) => ! assetStates.has( state ) ) ||
		JSON.stringify( capture.assetStates ) !==
			JSON.stringify(
				assetStateOrder.filter( ( state ) =>
					capture.assetStates.includes( state )
				)
			) ||
		! capture.assetStates.includes( capture.assetState ) ||
		( capture.assetState === 'clean' ) !==
			( capture.assetStates.length === 1 &&
				capture.assetStates[ 0 ] === 'clean' ) ||
		! Array.isArray( capture.frames ) ||
		( visualSelected && capture.frames.length < 1 ) ||
		( ! visualSelected && capture.frames.length !== 0 )
	) {
		fail();
	}
	requireDigest( capture.captureDigest );
	validateNormalizedModel( capture.model );
	let expectedFrame = 1;
	let expectedSlide = 1;
	let previousState = null;
	for ( const frame of capture.frames ) {
		exactKeys( frame, [
			'file',
			'frameOrdinal',
			'slideOrdinal',
			'state',
			'artifactDigest',
		] );
		const match =
			typeof frame.file === 'string'
				? frame.file.match( framePattern )
				: null;
		if ( previousState === null ) {
			if ( frame.slideOrdinal !== 1 || frame.state !== 'initial' ) {
				fail();
			}
		} else if ( frame.slideOrdinal === expectedSlide ) {
			if ( previousState !== 'initial' || frame.state !== 'final' ) {
				fail();
			}
		} else if ( frame.slideOrdinal === expectedSlide + 1 ) {
			expectedSlide++;
			if ( frame.state !== 'initial' ) {
				fail();
			}
		} else {
			fail();
		}
		if (
			match === null ||
			Number( match[ 1 ] ) !== capture.captureOrdinal ||
			Number( match[ 2 ] ) !== frame.frameOrdinal ||
			frame.frameOrdinal !== expectedFrame ||
			! validOrdinal( frame.slideOrdinal ) ||
			frame.slideOrdinal !== expectedSlide ||
			! frameStates.has( frame.state )
		) {
			fail();
		}
		requireDigest( frame.artifactDigest );
		previousState = frame.state;
		expectedFrame++;
	}
	if ( visualSelected && expectedSlide !== capture.model.slides.length ) {
		fail();
	}
};

const validateCaptureSlots = ( sidecar ) => {
	const captures = captureSlots.map( ( slot ) => sidecar[ slot ] );
	let count = 0;
	for ( const capture of captures ) {
		if ( capture === null ) {
			break;
		}
		count++;
	}
	if ( captures.slice( count ).some( ( capture ) => capture !== null ) ) {
		fail();
	}
	if ( count > 0 ) {
		const first = captures[ 0 ].captureOrdinal;
		if ( first % 4 !== 1 ) {
			fail();
		}
		for ( let index = 0; index < count; index++ ) {
			if ( captures[ index ].captureOrdinal !== first + index ) {
				fail();
			}
		}
	}
	return count;
};

/**
 * Create an empty, identity-bound per-deck resume checkpoint.
 *
 * @param {Object}  root0                 Sidecar identity.
 * @param {string}  root0.runDigest       Opaque rehearsal-run digest.
 * @param {string}  root0.identityDigest  Environment and toolchain digest.
 * @param {string}  root0.selectionDigest Ordered corpus-selection digest.
 * @param {string}  root0.deckDigest      Opaque deck digest.
 * @param {string}  root0.attemptDigest   Opaque migration-attempt digest.
 * @param {string}  root0.access          Fixed access classification.
 * @param {boolean} root0.visualSelected  Whether visual artifacts are selected.
 * @return {Object} Valid empty sidecar.
 */
export const createComparisonResumeSidecar = ( {
	runDigest,
	identityDigest,
	selectionDigest,
	deckDigest,
	attemptDigest = comparisonHmacPending,
	access,
	visualSelected,
} ) =>
	validateComparisonResumeSidecar( {
		schemaVersion: COMPARISON_RESUME_SCHEMA_VERSION,
		mode: 'migration-comparison-resume',
		runDigest,
		identityDigest,
		selectionDigest,
		deckDigest,
		attemptDigest,
		access,
		visualSelected,
		stage: 'not_checked',
		legacy: null,
		legacyRepeat: null,
		native: null,
		nativeRepeat: null,
		diffRetryOrdinal: 1,
		comparisonDigest: comparisonHmacPending,
		reportRecord: null,
		failureCode: 'none',
		checkpointDigest: comparisonHmacPending,
	} );

/**
 * Validate the exact digest-only resume checkpoint schema.
 *
 * @param {Object} sidecar Candidate sidecar.
 * @return {Object} Valid sidecar.
 */
export const validateComparisonResumeSidecar = ( sidecar ) => {
	exactKeys( sidecar, [
		'schemaVersion',
		'mode',
		'runDigest',
		'identityDigest',
		'selectionDigest',
		'deckDigest',
		'attemptDigest',
		'access',
		'visualSelected',
		'stage',
		'legacy',
		'legacyRepeat',
		'native',
		'nativeRepeat',
		'diffRetryOrdinal',
		'comparisonDigest',
		'reportRecord',
		'failureCode',
		'checkpointDigest',
	] );
	if (
		sidecar.schemaVersion !== COMPARISON_RESUME_SCHEMA_VERSION ||
		sidecar.mode !== 'migration-comparison-resume' ||
		! accessClasses.has( sidecar.access ) ||
		typeof sidecar.visualSelected !== 'boolean' ||
		( sidecar.access !== 'public' && sidecar.visualSelected ) ||
		! stages.has( sidecar.stage ) ||
		! validOrdinal( sidecar.diffRetryOrdinal ) ||
		! failureCodes.has( sidecar.failureCode )
	) {
		fail();
	}
	for ( const digest of [
		sidecar.runDigest,
		sidecar.identityDigest,
		sidecar.selectionDigest,
		sidecar.deckDigest,
		sidecar.attemptDigest,
		sidecar.comparisonDigest,
		sidecar.checkpointDigest,
	] ) {
		requireDigest( digest );
	}
	validateCapture( sidecar.legacy, sidecar.visualSelected );
	validateCapture( sidecar.legacyRepeat, sidecar.visualSelected );
	validateCapture( sidecar.native, sidecar.visualSelected );
	validateCapture( sidecar.nativeRepeat, sidecar.visualSelected );
	const captureCount = validateCaptureSlots( sidecar );
	if ( sidecar.reportRecord !== null ) {
		try {
			validateComparisonDeckRecord( sidecar.reportRecord );
		} catch {
			fail();
		}
		if (
			sidecar.reportRecord.deckDigest !== sidecar.deckDigest ||
			sidecar.reportRecord.attemptDigest !== sidecar.attemptDigest ||
			sidecar.reportRecord.access !== sidecar.access
		) {
			fail();
		}
		const structural = sidecar.reportRecord.structural;
		const visual = sidecar.reportRecord.visual;
		const assetsClean =
			sidecar.legacy?.assetState === 'clean' &&
			sidecar.legacyRepeat?.assetState === 'clean' &&
			sidecar.native?.assetState === 'clean' &&
			sidecar.nativeRepeat?.assetState === 'clean';
		const assetFailureDisposition =
			sidecar.access === 'public' &&
			! assetsClean &&
			captureCount === 4 &&
			[ 'passed', 'failed' ].includes( structural.state ) &&
			visual.state === 'failed' &&
			visual.reason === 'asset_failure' &&
			sidecar.reportRecord.state === 'capture_incomplete';
		const exactSelectionDisposition =
			assetFailureDisposition ||
			( assetsClean &&
				( sidecar.visualSelected
					? sidecar.access === 'public' &&
					  [ 'passed', 'failed' ].includes( structural.state ) &&
					  [ 'passed', 'review_required' ].includes(
							visual.state
					  ) &&
					  ( structural.state === 'failed'
							? sidecar.reportRecord.state === 'structural_failed'
							: sidecar.reportRecord.state ===
							  ( visual.state === 'passed'
									? 'visual_passed'
									: 'visual_review_required' ) )
					: sidecar.access === 'public' &&
					  [ 'passed', 'failed' ].includes( structural.state ) &&
					  visual.state === 'skipped' &&
					  visual.reason === 'not_selected' &&
					  sidecar.reportRecord.state ===
							( structural.state === 'passed'
								? 'structural_passed'
								: 'structural_failed' ) ) );
		if ( ! exactSelectionDisposition ) {
			fail();
		}
	}

	const pending = sidecar.comparisonDigest === comparisonHmacPending;
	const attemptBound = sidecar.attemptDigest !== comparisonHmacPending;
	const clean = sidecar.failureCode === 'none';
	const recordPending = sidecar.reportRecord === null;
	const exactFailurePrefix =
		( [ 'legacy_capture_http_500', 'legacy_capture_failed' ].includes(
			sidecar.failureCode
		) &&
			captureCount <= 1 ) ||
		( sidecar.failureCode === 'native_capture_failed' &&
			[ 2, 3 ].includes( captureCount ) ) ||
		( sidecar.failureCode === 'legacy_nondeterministic' &&
			captureCount === 2 ) ||
		( sidecar.failureCode === 'native_nondeterministic' &&
			captureCount === 4 ) ||
		( [
			'capture_asset_failure',
			'rendered_capture_schema',
			'visual_artifact_invalid',
			'visual_comparison_failed',
			'structural_comparison_failed',
		].includes( sidecar.failureCode ) &&
			captureCount <= 4 );
	const validStage =
		( sidecar.stage === 'not_checked' &&
			captureCount === 0 &&
			pending &&
			recordPending &&
			clean ) ||
		( sidecar.stage === 'access_skipped' &&
			sidecar.access !== 'public' &&
			captureCount === 0 &&
			pending &&
			recordPending &&
			clean ) ||
		( sidecar.stage === 'legacy_primary_captured' &&
			sidecar.access === 'public' &&
			captureCount === 1 &&
			pending &&
			recordPending &&
			clean ) ||
		( sidecar.stage === 'legacy_captured' &&
			sidecar.access === 'public' &&
			captureCount === 2 &&
			pending &&
			recordPending &&
			clean ) ||
		( sidecar.stage === 'native_primary_captured' &&
			sidecar.access === 'public' &&
			captureCount === 3 &&
			attemptBound &&
			pending &&
			recordPending &&
			clean ) ||
		( sidecar.stage === 'native_captured' &&
			sidecar.access === 'public' &&
			captureCount === 4 &&
			attemptBound &&
			pending &&
			recordPending &&
			clean ) ||
		( sidecar.stage === 'compared' &&
			sidecar.access === 'public' &&
			captureCount === 4 &&
			attemptBound &&
			! pending &&
			! recordPending &&
			clean ) ||
		( sidecar.stage === 'capture_failed' &&
			! clean &&
			exactFailurePrefix &&
			pending &&
			recordPending );
	if ( ! validStage ) {
		fail();
	}

	return sidecar;
};

const assertNoLink = async ( candidate ) => {
	try {
		if ( ( await lstat( candidate ) ).isSymbolicLink() ) {
			fail( 'comparison_checkpoint_path_unsafe' );
		}
	} catch ( error ) {
		if ( error?.code !== 'ENOENT' ) {
			throw error;
		}
	}
};

const requireKey = ( key ) => {
	if ( ! ( key instanceof Uint8Array ) || key.byteLength < 32 ) {
		fail( 'comparison_checkpoint_key' );
	}
};

const comparisonCheckpointDigest = ( key, sidecar ) => {
	const { checkpointDigest: ignored, ...checkpoint } = sidecar;
	return comparisonHmac(
		key,
		'comparison-checkpoint-v3',
		JSON.stringify( checkpoint )
	);
};

const slotContext = ( slot ) => {
	const contexts = {
		legacy: [ 'legacy', 'primary' ],
		legacyRepeat: [ 'legacy', 'repeat' ],
		native: [ 'native', 'primary' ],
		nativeRepeat: [ 'native', 'repeat' ],
	};
	if ( ! Object.hasOwn( contexts, slot ) ) {
		fail();
	}
	return contexts[ slot ];
};

const authenticationContext = ( sidecar, sidecarName, slot ) => {
	const [ phase, role ] = slotContext( slot );
	return [
		COMPARISON_RESUME_SCHEMA_VERSION,
		sidecar.runDigest,
		sidecar.identityDigest,
		sidecar.selectionDigest,
		sidecar.deckDigest,
		sidecar.access,
		sidecar.visualSelected,
		sidecarName,
		phase,
		role,
		phase === 'legacy' ? comparisonHmacPending : sidecar.attemptDigest,
	];
};

export const comparisonFrameArtifactDigest = (
	key,
	sidecarName,
	sidecar,
	slot,
	capture,
	frame,
	bytes
) => {
	requireKey( key );
	const metadata = [
		...authenticationContext( sidecar, sidecarName, slot ),
		capture.captureOrdinal,
		frame.file,
		frame.frameOrdinal,
		frame.slideOrdinal,
		frame.state,
	];
	return comparisonHmac(
		key,
		'comparison-frame-v3',
		Buffer.concat( [
			Buffer.from( `${ JSON.stringify( metadata ) }\0`, 'utf8' ),
			Buffer.from( bytes ),
		] )
	);
};

export const comparisonCaptureAuthenticationDigest = (
	key,
	sidecarName,
	sidecar,
	slot,
	capture
) => {
	requireKey( key );
	const authenticatedCapture = {
		captureOrdinal: capture.captureOrdinal,
		assetState: capture.assetState,
		assetStates: capture.assetStates,
		frames: capture.frames,
		model: capture.model,
	};
	return comparisonHmac(
		key,
		'comparison-capture-v3',
		JSON.stringify( [
			...authenticationContext( sidecar, sidecarName, slot ),
			authenticatedCapture,
		] )
	);
};

const digestEquals = ( expected, actual ) =>
	timingSafeEqual(
		Buffer.from( expected, 'hex' ),
		Buffer.from( actual, 'hex' )
	);

const validateArtifactPaths = async (
	privateRoot,
	sidecarName,
	sidecar,
	key
) => {
	requireKey( key );
	const root = await realpath( privateRoot ).catch( () => null );
	if ( root === null ) {
		fail( 'comparison_checkpoint_artifact_missing' );
	}
	const deckOrdinal = Number( sidecarName.slice( 'deck-'.length ) );
	if (
		sidecar.legacy !== null &&
		sidecar.legacy.captureOrdinal !== ( deckOrdinal - 1 ) * 4 + 1
	) {
		fail( 'comparison_checkpoint_artifact_changed' );
	}
	for ( const slot of captureSlots ) {
		const capture = sidecar[ slot ];
		for ( const frame of capture?.frames ?? [] ) {
			const candidate = path.resolve( root, frame.file );
			if ( ! isInside( root, candidate ) ) {
				fail( 'comparison_checkpoint_artifact_unsafe' );
			}
			const metadata = await lstat( candidate ).catch( () => null );
			const canonical = await realpath( candidate ).catch( () => null );
			if ( metadata === null || canonical === null ) {
				fail( 'comparison_checkpoint_artifact_missing' );
			}
			if (
				metadata.isSymbolicLink() ||
				! metadata.isFile() ||
				! isInside( root, canonical )
			) {
				fail( 'comparison_checkpoint_artifact_unsafe' );
			}
			const expected = comparisonFrameArtifactDigest(
				key,
				sidecarName,
				sidecar,
				slot,
				capture,
				frame,
				await readFile( canonical )
			);
			if ( ! digestEquals( expected, frame.artifactDigest ) ) {
				fail( 'comparison_checkpoint_artifact_changed' );
			}
		}
		if ( capture !== null ) {
			const expected = comparisonCaptureAuthenticationDigest(
				key,
				sidecarName,
				sidecar,
				slot,
				capture
			);
			if ( ! digestEquals( expected, capture.captureDigest ) ) {
				fail( 'comparison_checkpoint_artifact_changed' );
			}
		}
	}
};

const resolveSidecarPath = async ( privateRoot, sidecarName, create ) => {
	if (
		typeof privateRoot !== 'string' ||
		! path.isAbsolute( privateRoot ) ||
		! sidecarNamePattern.test( sidecarName )
	) {
		fail( 'comparison_checkpoint_path_unsafe' );
	}
	await assertNoLink( privateRoot );
	if ( create ) {
		await mkdir( privateRoot, { recursive: true } );
	}
	const root = await realpath( privateRoot ).catch( () => null );
	if ( root === null || root === path.parse( root ).root ) {
		fail(
			create
				? 'comparison_checkpoint_io'
				: 'comparison_checkpoint_missing'
		);
	}
	const directory = path.resolve( root, sidecarName );
	if ( path.dirname( directory ) !== root ) {
		fail( 'comparison_checkpoint_path_unsafe' );
	}
	await assertNoLink( directory );
	if ( create ) {
		await mkdir( directory, { recursive: true } );
	}
	const canonicalDirectory = await realpath( directory ).catch( () => null );
	if ( canonicalDirectory === null ) {
		fail(
			create
				? 'comparison_checkpoint_io'
				: 'comparison_checkpoint_missing'
		);
	}
	if ( path.dirname( canonicalDirectory ) !== root ) {
		fail( 'comparison_checkpoint_path_unsafe' );
	}
	const target = path.join( canonicalDirectory, 'comparison-resume.json' );
	await assertNoLink( target );
	return target;
};

/**
 * Atomically persist one validated sidecar under an opaque deck directory.
 *
 * @param {string} privateRoot Private comparison root.
 * @param {string} sidecarName Opaque `deck-000001` directory name.
 * @param {Object} sidecar     Valid digest-only sidecar.
 * @param {Buffer} key         Private comparison HMAC key.
 * @return {Promise<string>} Persisted sidecar path.
 */
export const atomicWriteComparisonResumeSidecar = async (
	privateRoot,
	sidecarName,
	sidecar,
	key
) => {
	requireKey( key );
	sidecar.checkpointDigest = comparisonHmacPending;
	validateComparisonResumeSidecar( sidecar );
	sidecar.checkpointDigest = comparisonCheckpointDigest( key, sidecar );
	validateComparisonResumeSidecar( sidecar );
	let target;
	try {
		target = await resolveSidecarPath( privateRoot, sidecarName, true );
		await validateArtifactPaths( privateRoot, sidecarName, sidecar, key );
		const temporary = `${ target }.tmp-${ process.pid }-${ randomBytes(
			4
		).toString( 'hex' ) }`;
		await writeFile(
			temporary,
			`${ JSON.stringify( sidecar, null, 2 ) }\n`,
			{
				encoding: 'utf8',
				flag: 'wx',
				mode: 0o600,
			}
		);
		await rename( temporary, target );
		return target;
	} catch ( error ) {
		if ( error instanceof ComparisonResumeSidecarError ) {
			throw error;
		}
		fail( 'comparison_checkpoint_io' );
	}
};

/**
 * Read, validate, and optionally identity-bind one private sidecar.
 *
 * @param {string} privateRoot Private comparison root.
 * @param {string} sidecarName Opaque `deck-000001` directory name.
 * @param {Object} [expected]  Optional exact identity binding.
 * @param {Buffer} key         Private comparison HMAC key.
 * @return {Promise<Object>} Valid, identity-bound sidecar.
 */
export const readComparisonResumeSidecar = async (
	privateRoot,
	sidecarName,
	expected = undefined,
	key
) => {
	requireKey( key );
	let parsed;
	try {
		const target = await resolveSidecarPath(
			privateRoot,
			sidecarName,
			false
		);
		parsed = JSON.parse( await readFile( target, 'utf8' ) );
	} catch ( error ) {
		if ( error instanceof ComparisonResumeSidecarError ) {
			throw error;
		}
		if ( error?.code === 'ENOENT' ) {
			fail( 'comparison_checkpoint_missing' );
		}
		fail( 'comparison_checkpoint_schema' );
	}
	validateComparisonResumeSidecar( parsed );
	if (
		! digestEquals(
			comparisonCheckpointDigest( key, parsed ),
			parsed.checkpointDigest
		)
	) {
		fail( 'comparison_checkpoint_changed' );
	}
	await validateArtifactPaths( privateRoot, sidecarName, parsed, key );
	if ( expected !== undefined ) {
		exactKeys( expected, [
			'runDigest',
			'identityDigest',
			'selectionDigest',
			'deckDigest',
			'attemptDigest',
			'access',
			'visualSelected',
		] );
		if (
			Object.entries( expected ).some(
				( [ field, value ] ) => parsed[ field ] !== value
			)
		) {
			fail( 'comparison_checkpoint_mismatch' );
		}
	}
	return parsed;
};
