import { randomBytes } from 'node:crypto';
import {
	lstat,
	mkdir,
	readFile,
	realpath,
	rename,
	writeFile,
} from 'node:fs/promises';
import path from 'node:path';

import { comparisonHmacPending } from './comparison-report.mjs';

export const COMPARISON_RESUME_SCHEMA_VERSION = 1;

const digestPattern = /^[a-f0-9]{64}$/;
const sidecarNamePattern = /^deck-[0-9]{6}$/;
const framePattern = /^capture-([0-9]{6})\/frame-([0-9]{6})\.png$/;
const accessClasses = new Set( [ 'public', 'protected', 'nonpublic' ] );
const stages = new Set( [
	'not_checked',
	'access_skipped',
	'legacy_captured',
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
] );
const notesFormats = new Set( [
	'none',
	'plain',
	'markdown',
	'html',
	'markdown-html',
] );
const frameStates = new Set( [ 'initial', 'final' ] );

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
	validateNormalizedModel( capture.model );
	let expectedFrame = 1;
	let expectedSlide = 1;
	let previousState = null;
	for ( const frame of capture.frames ) {
		exactKeys( frame, [ 'file', 'frameOrdinal', 'slideOrdinal', 'state' ] );
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
		previousState = frame.state;
		expectedFrame++;
	}
	if ( visualSelected && expectedSlide !== capture.model.slides.length ) {
		fail();
	}
};

/**
 * Create an empty, identity-bound per-deck resume checkpoint.
 *
 * @param {Object}  root0                 Sidecar identity.
 * @param {string}  root0.identityDigest  Environment and toolchain digest.
 * @param {string}  root0.selectionDigest Ordered corpus-selection digest.
 * @param {string}  root0.deckDigest      Opaque deck digest.
 * @param {string}  root0.attemptDigest   Opaque migration-attempt digest.
 * @param {string}  root0.access          Fixed access classification.
 * @param {boolean} root0.visualSelected  Whether visual artifacts are selected.
 * @return {Object} Valid empty sidecar.
 */
export const createComparisonResumeSidecar = ( {
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
		identityDigest,
		selectionDigest,
		deckDigest,
		attemptDigest,
		access,
		visualSelected,
		stage: 'not_checked',
		legacy: null,
		native: null,
		diffRetryOrdinal: 1,
		comparisonDigest: comparisonHmacPending,
		failureCode: 'none',
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
		'identityDigest',
		'selectionDigest',
		'deckDigest',
		'attemptDigest',
		'access',
		'visualSelected',
		'stage',
		'legacy',
		'native',
		'diffRetryOrdinal',
		'comparisonDigest',
		'failureCode',
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
		sidecar.identityDigest,
		sidecar.selectionDigest,
		sidecar.deckDigest,
		sidecar.attemptDigest,
		sidecar.comparisonDigest,
	] ) {
		requireDigest( digest );
	}
	validateCapture( sidecar.legacy, sidecar.visualSelected );
	validateCapture( sidecar.native, sidecar.visualSelected );

	const pending = sidecar.comparisonDigest === comparisonHmacPending;
	const attemptBound = sidecar.attemptDigest !== comparisonHmacPending;
	const clean = sidecar.failureCode === 'none';
	const validStage =
		( sidecar.stage === 'not_checked' &&
			sidecar.legacy === null &&
			sidecar.native === null &&
			pending &&
			clean ) ||
		( sidecar.stage === 'access_skipped' &&
			sidecar.access !== 'public' &&
			sidecar.legacy === null &&
			sidecar.native === null &&
			pending &&
			clean ) ||
		( sidecar.stage === 'legacy_captured' &&
			sidecar.access === 'public' &&
			sidecar.legacy !== null &&
			sidecar.native === null &&
			pending &&
			clean ) ||
		( sidecar.stage === 'native_captured' &&
			sidecar.access === 'public' &&
			sidecar.legacy !== null &&
			sidecar.native !== null &&
			attemptBound &&
			pending &&
			clean ) ||
		( sidecar.stage === 'compared' &&
			sidecar.access === 'public' &&
			sidecar.legacy !== null &&
			sidecar.native !== null &&
			attemptBound &&
			! pending &&
			clean ) ||
		( sidecar.stage === 'capture_failed' && ! clean && pending );
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

const validateArtifactPaths = async ( privateRoot, sidecar ) => {
	const root = await realpath( privateRoot ).catch( () => null );
	if ( root === null ) {
		fail( 'comparison_checkpoint_artifact_missing' );
	}
	for ( const capture of [ sidecar.legacy, sidecar.native ] ) {
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
 * @return {Promise<string>} Persisted sidecar path.
 */
export const atomicWriteComparisonResumeSidecar = async (
	privateRoot,
	sidecarName,
	sidecar
) => {
	validateComparisonResumeSidecar( sidecar );
	let target;
	try {
		target = await resolveSidecarPath( privateRoot, sidecarName, true );
		await validateArtifactPaths( privateRoot, sidecar );
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
 * @return {Promise<Object>} Valid, identity-bound sidecar.
 */
export const readComparisonResumeSidecar = async (
	privateRoot,
	sidecarName,
	expected = undefined
) => {
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
	await validateArtifactPaths( privateRoot, parsed );
	if ( expected !== undefined ) {
		exactKeys( expected, [
			'identityDigest',
			'selectionDigest',
			'deckDigest',
			'attemptDigest',
			'access',
			'visualSelected',
		] );
		if (
			Object.entries( expected ).some(
				( [ key, value ] ) => parsed[ key ] !== value
			)
		) {
			fail( 'comparison_checkpoint_mismatch' );
		}
	}
	return parsed;
};
