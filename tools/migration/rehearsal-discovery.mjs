import assert from 'node:assert/strict';
import { isDeepStrictEqual } from 'node:util';

const requireExactKeys = ( value, keys ) => {
	assert(
		value && typeof value === 'object' && ! Array.isArray( value ),
		'discovery_schema'
	);
	assert.deepEqual(
		Object.keys( value ).sort(),
		[ ...keys ].sort(),
		'discovery_schema'
	);
};

const isReady = ( report ) =>
	report.status === 'ready' &&
	Array.isArray( report.blockerCodes ) &&
	report.blockerCodes.length === 0;

const isNativeResumeBlocker = ( report, record ) =>
	[ 'prepared', 'applied' ].includes( record?.stage ) &&
	report.status === 'blocked' &&
	Array.isArray( report.blockerCodes ) &&
	isDeepStrictEqual( report.blockerCodes, [ 'legacy_post_content' ] );

/**
 * Validate deterministic corpus discovery before a fresh or resumed run.
 *
 * A crash can leave the active deck in its verified native representation.
 * Its generated block content makes an otherwise valid Dry Run report blocked
 * by `legacy_post_content`; the runner's later representation checks prove that
 * exact state. Every other deck must remain independently migration-ready.
 *
 * @param {Object}        envelope           Dry Run discovery envelope.
 * @param {number}        expectedCorpusSize Exact corpus size.
 * @param {Array<Object>} [resumeRecords]    Persisted ordered run records.
 * @return {Array<number>} Ordered post IDs.
 */
export const validateRehearsalDiscovery = (
	envelope,
	expectedCorpusSize,
	resumeRecords = null
) => {
	requireExactKeys( envelope, [
		'schemaVersion',
		'mode',
		'count',
		'reports',
	] );
	assert.equal( envelope.schemaVersion, 1, 'discovery_schema' );
	assert.equal( envelope.mode, 'dry-run', 'discovery_schema' );
	assert.equal( envelope.count, expectedCorpusSize, 'corpus_size' );
	assert.equal( envelope.reports.length, expectedCorpusSize, 'corpus_size' );
	if ( resumeRecords !== null ) {
		assert(
			Array.isArray( resumeRecords ) &&
				resumeRecords.length > 0 &&
				resumeRecords.length <= expectedCorpusSize,
			'resume_manifest_state'
		);
	}

	let previous = 0;
	const ids = new Set();
	for ( const [ index, report ] of envelope.reports.entries() ) {
		assert(
			Number.isSafeInteger( report.postId ) && report.postId > previous,
			'corpus_order'
		);
		assert(
			isReady( report ) ||
				( resumeRecords !== null &&
					isNativeResumeBlocker( report, resumeRecords[ index ] ) ),
			'corpus_not_ready'
		);
		previous = report.postId;
		ids.add( report.postId );
	}
	assert.equal( ids.size, expectedCorpusSize, 'corpus_unique' );
	return envelope.reports.map( ( report ) => report.postId );
};
