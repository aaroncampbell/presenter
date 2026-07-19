import { createHmac, randomBytes } from 'node:crypto';
import { lstat, mkdir, realpath, rename, writeFile } from 'node:fs/promises';
import path from 'node:path';

export const REPORT_SCHEMA_VERSION = 2;
export const NORMALIZATION_VERSION = 1;

const digestPattern = /^[a-f0-9]{64}$/;
const runDirectoryPattern = /^[a-z0-9][a-z0-9-]{0,95}$/;
const reportStates = new Set( [ 'running', 'failed', 'complete' ] );
const accessClasses = new Set( [ 'public', 'protected', 'nonpublic' ] );
const deckStates = new Set( [
	'not_checked',
	'access_not_captured',
	'structural_passed',
	'structural_failed',
	'capture_incomplete',
	'visual_passed',
	'visual_review_required',
] );
const structuralStates = new Set( [
	'not_checked',
	'passed',
	'failed',
	'skipped',
] );
const structuralReasons = new Set( [ 'none', 'access_not_captured' ] );
const visualStates = new Set( [
	'not_checked',
	'passed',
	'review_required',
	'failed',
	'skipped',
] );
const structuralCodes = new Set( [
	'anchor_changed',
	'data_attribute_changed',
	'deck_dimensions_changed',
	'deck_config_changed',
	'fragment_sequence_changed',
	'notes_changed',
	'rendered_content_changed',
	'runtime_not_ready',
	'slide_address_changed',
	'slide_count_changed',
	'slide_order_changed',
	'stack_hierarchy_changed',
	'theme_changed',
	'wrapper_class_changed',
] );
const visualReasons = new Set( [
	'none',
	'access_not_captured',
	'not_selected',
	'asset_failure',
	'capture_error',
	'nondeterministic',
	'runtime_not_ready',
	'visual_difference',
] );

const exactKeys = ( value, expected, code = 'report_schema' ) => {
	if (
		! value ||
		typeof value !== 'object' ||
		Array.isArray( value ) ||
		JSON.stringify( Object.keys( value ) ) !== JSON.stringify( expected )
	) {
		throw new Error( code );
	}
};

const validCount = ( value ) => Number.isSafeInteger( value ) && value >= 0;

const requireDigest = ( value ) => {
	if ( typeof value !== 'string' || ! digestPattern.test( value ) ) {
		throw new Error( 'report_schema' );
	}
};

const requireFixedCodes = ( codes ) => {
	if (
		! Array.isArray( codes ) ||
		codes.some( ( code ) => ! structuralCodes.has( code ) ) ||
		new Set( codes ).size !== codes.length ||
		JSON.stringify( codes ) !== JSON.stringify( [ ...codes ].sort() )
	) {
		throw new Error( 'report_schema' );
	}
};

/**
 * Create a domain-separated keyed digest without accepting arbitrary objects.
 *
 * Callers must serialize exact, versioned identity records before invoking this
 * helper. Requiring bytes avoids accidental non-deterministic object coercion.
 *
 * @param {Buffer|Uint8Array} key     Private comparison key.
 * @param {string}            purpose Fixed domain label.
 * @param {Buffer|string}     value   Exact bytes to protect.
 * @return {string} Lowercase HMAC-SHA256.
 */
export const comparisonHmac = ( key, purpose, value ) => {
	if (
		! ( key instanceof Uint8Array ) ||
		key.byteLength < 32 ||
		typeof purpose !== 'string' ||
		! /^[a-z][a-z0-9-]{0,63}$/.test( purpose ) ||
		! ( typeof value === 'string' || value instanceof Uint8Array )
	) {
		throw new Error( 'invalid_hmac_input' );
	}

	return createHmac( 'sha256', key )
		.update(
			`presenter-comparison-v${ REPORT_SCHEMA_VERSION }\0${ purpose }\0`
		)
		.update( value )
		.digest( 'hex' );
};

/**
 * Create the initial exact-schema, content-free report.
 *
 * @param {Object}   input                 Initial report identity.
 * @param {string}   input.identityDigest  Environment/toolchain digest.
 * @param {string}   input.selectionDigest Ordered selection digest.
 * @param {Object[]} input.decks           Opaque deck identities.
 * @return {Object} Valid running report.
 */
export const createComparisonReport = ( {
	identityDigest,
	selectionDigest,
	decks,
} ) =>
	validateComparisonReport( {
		schemaVersion: REPORT_SCHEMA_VERSION,
		mode: 'migration-comparison',
		state: 'running',
		identityDigest,
		selectionDigest,
		counts: {
			selected: decks.length,
			structuralPassed: 0,
			structuralFailed: 0,
			structuralSkipped: 0,
			visualPassed: 0,
			visualReviewRequired: 0,
			visualSkipped: 0,
		},
		decks: decks.map( ( { deckDigest, attemptDigest, access } ) => ( {
			deckDigest,
			attemptDigest,
			access,
			state: 'not_checked',
			structural: {
				state: 'not_checked',
				reason: 'none',
				codes: [],
				legacySlideCount: 0,
				nativeSlideCount: 0,
				comparedSlideCount: 0,
				legacyManifestDigest: comparisonHmacPending,
				nativeManifestDigest: comparisonHmacPending,
				slides: [],
			},
			visual: {
				state: 'not_checked',
				reason: 'none',
				comparedFrames: 0,
				changedFrames: 0,
				maximumChangedPixelRatio: 0,
				aggregateDigest: comparisonHmacPending,
			},
		} ) ),
	} );

export const comparisonHmacPending =
	'0000000000000000000000000000000000000000000000000000000000000000';

/**
 * Validate and return an exact content-free report.
 *
 * @param {Object} report Candidate report.
 * @return {Object} Validated report.
 */
export const validateComparisonReport = ( report ) => {
	exactKeys( report, [
		'schemaVersion',
		'mode',
		'state',
		'identityDigest',
		'selectionDigest',
		'counts',
		'decks',
	] );
	if (
		report.schemaVersion !== REPORT_SCHEMA_VERSION ||
		report.mode !== 'migration-comparison' ||
		! reportStates.has( report.state )
	) {
		throw new Error( 'report_schema' );
	}
	requireDigest( report.identityDigest );
	requireDigest( report.selectionDigest );
	exactKeys( report.counts, [
		'selected',
		'structuralPassed',
		'structuralFailed',
		'structuralSkipped',
		'visualPassed',
		'visualReviewRequired',
		'visualSkipped',
	] );
	if (
		! Object.values( report.counts ).every( validCount ) ||
		! Array.isArray( report.decks ) ||
		report.decks.length < 1 ||
		report.decks.length !== report.counts.selected
	) {
		throw new Error( 'report_schema' );
	}

	const deckDigests = new Set();
	for ( const deck of report.decks ) {
		exactKeys( deck, [
			'deckDigest',
			'attemptDigest',
			'access',
			'state',
			'structural',
			'visual',
		] );
		requireDigest( deck.deckDigest );
		requireDigest( deck.attemptDigest );
		if (
			deckDigests.has( deck.deckDigest ) ||
			! accessClasses.has( deck.access ) ||
			! deckStates.has( deck.state )
		) {
			throw new Error( 'report_schema' );
		}
		deckDigests.add( deck.deckDigest );
		if (
			deck.state !== 'not_checked' &&
			deck.attemptDigest === comparisonHmacPending
		) {
			throw new Error( 'report_schema' );
		}

		const structural = deck.structural;
		exactKeys( structural, [
			'state',
			'reason',
			'codes',
			'legacySlideCount',
			'nativeSlideCount',
			'comparedSlideCount',
			'legacyManifestDigest',
			'nativeManifestDigest',
			'slides',
		] );
		if (
			! structuralStates.has( structural.state ) ||
			! structuralReasons.has( structural.reason ) ||
			! [
				structural.legacySlideCount,
				structural.nativeSlideCount,
				structural.comparedSlideCount,
			].every( validCount ) ||
			! Array.isArray( structural.slides )
		) {
			throw new Error( 'report_schema' );
		}
		requireFixedCodes( structural.codes );
		requireDigest( structural.legacyManifestDigest );
		requireDigest( structural.nativeManifestDigest );
		for ( const slide of structural.slides ) {
			exactKeys( slide, [ 'addressDigest', 'state', 'codes' ] );
			requireDigest( slide.addressDigest );
			if ( ! [ 'passed', 'failed' ].includes( slide.state ) ) {
				throw new Error( 'report_schema' );
			}
			requireFixedCodes( slide.codes );
		}
		if (
			structural.comparedSlideCount !== structural.slides.length ||
			new Set( structural.slides.map( ( slide ) => slide.addressDigest ) )
				.size !== structural.slides.length
		) {
			throw new Error( 'report_schema' );
		}
		if (
			structural.state === 'not_checked' &&
			( structural.reason !== 'none' ||
				structural.codes.length !== 0 ||
				structural.legacySlideCount !== 0 ||
				structural.nativeSlideCount !== 0 ||
				structural.comparedSlideCount !== 0 ||
				structural.legacyManifestDigest !== comparisonHmacPending ||
				structural.nativeManifestDigest !== comparisonHmacPending )
		) {
			throw new Error( 'report_schema' );
		}
		if (
			structural.state === 'passed' &&
			( structural.reason !== 'none' ||
				structural.codes.length !== 0 ||
				structural.legacySlideCount < 1 ||
				structural.legacySlideCount !== structural.nativeSlideCount ||
				structural.comparedSlideCount !== structural.legacySlideCount ||
				structural.legacyManifestDigest === comparisonHmacPending ||
				structural.nativeManifestDigest === comparisonHmacPending ||
				structural.slides.some(
					( slide ) => slide.state !== 'passed'
				) )
		) {
			throw new Error( 'report_schema' );
		}
		if (
			structural.state === 'failed' &&
			( structural.reason !== 'none' ||
				( structural.codes.length === 0 &&
					! structural.slides.some(
						( slide ) => slide.state === 'failed'
					) ) )
		) {
			throw new Error( 'report_schema' );
		}
		if (
			structural.state === 'skipped' &&
			( structural.reason !== 'access_not_captured' ||
				structural.codes.length !== 0 ||
				structural.legacySlideCount !== 0 ||
				structural.nativeSlideCount !== 0 ||
				structural.comparedSlideCount !== 0 ||
				structural.legacyManifestDigest !== comparisonHmacPending ||
				structural.nativeManifestDigest !== comparisonHmacPending ||
				structural.slides.length !== 0 )
		) {
			throw new Error( 'report_schema' );
		}

		const visual = deck.visual;
		exactKeys( visual, [
			'state',
			'reason',
			'comparedFrames',
			'changedFrames',
			'maximumChangedPixelRatio',
			'aggregateDigest',
		] );
		if (
			! visualStates.has( visual.state ) ||
			! visualReasons.has( visual.reason ) ||
			! validCount( visual.comparedFrames ) ||
			! validCount( visual.changedFrames ) ||
			visual.changedFrames > visual.comparedFrames ||
			typeof visual.maximumChangedPixelRatio !== 'number' ||
			! Number.isFinite( visual.maximumChangedPixelRatio ) ||
			visual.maximumChangedPixelRatio < 0 ||
			visual.maximumChangedPixelRatio > 1
		) {
			throw new Error( 'report_schema' );
		}
		requireDigest( visual.aggregateDigest );
		if (
			visual.state === 'not_checked' &&
			( visual.reason !== 'none' ||
				visual.comparedFrames !== 0 ||
				visual.changedFrames !== 0 ||
				visual.maximumChangedPixelRatio !== 0 ||
				visual.aggregateDigest !== comparisonHmacPending )
		) {
			throw new Error( 'report_schema' );
		}
		if (
			visual.state === 'passed' &&
			( visual.reason !== 'none' ||
				visual.comparedFrames === 0 ||
				visual.changedFrames !== 0 ||
				visual.maximumChangedPixelRatio !== 0 ||
				visual.aggregateDigest === comparisonHmacPending )
		) {
			throw new Error( 'report_schema' );
		}
		if (
			visual.state === 'review_required' &&
			( visual.reason !== 'visual_difference' ||
				visual.comparedFrames === 0 ||
				visual.changedFrames === 0 ||
				visual.maximumChangedPixelRatio === 0 ||
				visual.aggregateDigest === comparisonHmacPending )
		) {
			throw new Error( 'report_schema' );
		}
		if (
			visual.state === 'skipped' &&
			( ! [ 'access_not_captured', 'not_selected' ].includes(
				visual.reason
			) ||
				visual.comparedFrames !== 0 ||
				visual.changedFrames !== 0 ||
				visual.maximumChangedPixelRatio !== 0 ||
				visual.aggregateDigest !== comparisonHmacPending )
		) {
			throw new Error( 'report_schema' );
		}
		if (
			visual.state === 'failed' &&
			[
				'none',
				'visual_difference',
				'access_not_captured',
				'not_selected',
			].includes( visual.reason )
		) {
			throw new Error( 'report_schema' );
		}

		const accessNotCaptured =
			deck.state === 'access_not_captured' &&
			deck.access !== 'public' &&
			structural.state === 'skipped' &&
			structural.reason === 'access_not_captured' &&
			visual.state === 'skipped' &&
			visual.reason === 'access_not_captured';
		const visualNotSelected =
			deck.access === 'public' &&
			structural.reason === 'none' &&
			visual.state === 'skipped' &&
			visual.reason === 'not_selected' &&
			( ( deck.state === 'structural_passed' &&
				structural.state === 'passed' ) ||
				( deck.state === 'structural_failed' &&
					structural.state === 'failed' ) );
		if (
			[
				deck.state === 'access_not_captured',
				structural.state === 'skipped',
				visual.state === 'skipped',
			].some( Boolean ) &&
			! accessNotCaptured &&
			! visualNotSelected
		) {
			throw new Error( 'report_schema' );
		}

		const dispositionMatches =
			( deck.state === 'not_checked' &&
				structural.state === 'not_checked' &&
				visual.state === 'not_checked' ) ||
			accessNotCaptured ||
			visualNotSelected ||
			( deck.state === 'structural_failed' &&
				structural.state === 'failed' ) ||
			( deck.state === 'capture_incomplete' &&
				visual.state === 'failed' ) ||
			( deck.state === 'visual_passed' &&
				structural.state === 'passed' &&
				visual.state === 'passed' ) ||
			( deck.state === 'visual_review_required' &&
				structural.state === 'passed' &&
				visual.state === 'review_required' );
		if ( ! dispositionMatches ) {
			throw new Error( 'report_schema' );
		}
	}

	const expectedCounts = {
		selected: report.decks.length,
		structuralPassed: report.decks.filter(
			( deck ) => deck.structural.state === 'passed'
		).length,
		structuralFailed: report.decks.filter(
			( deck ) => deck.structural.state === 'failed'
		).length,
		structuralSkipped: report.decks.filter(
			( deck ) => deck.structural.state === 'skipped'
		).length,
		visualPassed: report.decks.filter(
			( deck ) => deck.visual.state === 'passed'
		).length,
		visualReviewRequired: report.decks.filter(
			( deck ) => deck.visual.state === 'review_required'
		).length,
		visualSkipped: report.decks.filter(
			( deck ) => deck.visual.state === 'skipped'
		).length,
	};
	if (
		JSON.stringify( report.counts ) !== JSON.stringify( expectedCounts )
	) {
		throw new Error( 'report_schema' );
	}
	if (
		report.state === 'complete' &&
		report.decks.some(
			( deck ) =>
				deck.structural.state === 'not_checked' ||
				deck.visual.state === 'not_checked' ||
				deck.state === 'capture_incomplete' ||
				deck.visual.state === 'failed'
		)
	) {
		throw new Error( 'report_schema' );
	}
	if (
		report.state === 'failed' &&
		! report.decks.some(
			( deck ) =>
				deck.state === 'capture_incomplete' ||
				deck.visual.state === 'failed'
		)
	) {
		throw new Error( 'report_schema' );
	}

	return report;
};

/**
 * Recalculate aggregate counts after changing a deck record.
 *
 * @param {Object} report Mutable report.
 * @return {Object} Validated report.
 */
export const synchronizeComparisonCounts = ( report ) => {
	report.counts = {
		selected: report.decks.length,
		structuralPassed: report.decks.filter(
			( deck ) => deck.structural.state === 'passed'
		).length,
		structuralFailed: report.decks.filter(
			( deck ) => deck.structural.state === 'failed'
		).length,
		structuralSkipped: report.decks.filter(
			( deck ) => deck.structural.state === 'skipped'
		).length,
		visualPassed: report.decks.filter(
			( deck ) => deck.visual.state === 'passed'
		).length,
		visualReviewRequired: report.decks.filter(
			( deck ) => deck.visual.state === 'review_required'
		).length,
		visualSkipped: report.decks.filter(
			( deck ) => deck.visual.state === 'skipped'
		).length,
	};
	return validateComparisonReport( report );
};

const assertNoLink = async ( candidate ) => {
	try {
		const metadata = await lstat( candidate );
		if ( metadata.isSymbolicLink() ) {
			throw new Error( 'private_path_unsafe' );
		}
	} catch ( error ) {
		if ( error?.code !== 'ENOENT' ) {
			throw error;
		}
	}
};

/**
 * Atomically persist one report under a caller-owned private run directory.
 *
 * @param {string} privateRoot Private artifact root.
 * @param {string} runName     Opaque direct-child run name.
 * @param {Object} report      Valid content-free report.
 * @return {Promise<string>} Written report path.
 */
export const atomicWriteComparisonReport = async (
	privateRoot,
	runName,
	report
) => {
	validateComparisonReport( report );
	if ( ! runDirectoryPattern.test( runName ) ) {
		throw new Error( 'private_path_unsafe' );
	}

	await mkdir( privateRoot, { recursive: true } );
	await assertNoLink( privateRoot );
	const root = await realpath( privateRoot );
	const runDirectory = path.resolve( root, runName );
	if ( path.dirname( runDirectory ) !== root ) {
		throw new Error( 'private_path_unsafe' );
	}
	await assertNoLink( runDirectory );
	await mkdir( runDirectory, { recursive: true } );
	await assertNoLink( runDirectory );
	if ( path.dirname( await realpath( runDirectory ) ) !== root ) {
		throw new Error( 'private_path_unsafe' );
	}

	const target = path.join( runDirectory, 'comparison-report.json' );
	const temporary = `${ target }.tmp-${ process.pid }-${ randomBytes(
		4
	).toString( 'hex' ) }`;
	await writeFile( temporary, `${ JSON.stringify( report, null, 2 ) }\n`, {
		encoding: 'utf8',
		flag: 'wx',
		mode: 0o600,
	} );
	await rename( temporary, target );
	return target;
};
