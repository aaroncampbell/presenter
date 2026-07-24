import assert from 'node:assert/strict';
import test from 'node:test';

import { validateRehearsalDiscovery } from './rehearsal-discovery.mjs';

const envelope = () => ( {
	schemaVersion: 1,
	mode: 'dry-run',
	count: 2,
	reports: [
		{ postId: 10, status: 'ready', blockerCodes: [] },
		{ postId: 20, status: 'ready', blockerCodes: [] },
	],
} );

test( 'fresh discovery requires every deck to be migration-ready', () => {
	assert.deepEqual( validateRehearsalDiscovery( envelope(), 2 ), [ 10, 20 ] );

	const blocked = envelope();
	blocked.reports[ 0 ].status = 'blocked';
	blocked.reports[ 0 ].blockerCodes = [ 'legacy_post_content' ];
	assert.throws(
		() => validateRehearsalDiscovery( blocked, 2 ),
		/corpus_not_ready/u
	);
} );

test( 'resume accepts only a selected native deck content blocker', () => {
	const blocked = envelope();
	blocked.reports[ 1 ].status = 'blocked';
	blocked.reports[ 1 ].blockerCodes = [ 'legacy_post_content' ];
	for ( const stage of [ 'prepared', 'applied' ] ) {
		const records = [ { stage: 'restored' }, { stage } ];
		assert.deepEqual(
			validateRehearsalDiscovery( blocked, 2, records ),
			[ 10, 20 ]
		);
	}

	const records = [ { stage: 'restored' }, { stage: 'applied' } ];

	for ( const mutate of [
		( value ) => ( value.records[ 1 ].stage = 'pending' ),
		( value ) =>
			value.envelope.reports[ 1 ].blockerCodes.push( 'legacy_theme' ),
	] ) {
		const invalid = {
			envelope: structuredClone( blocked ),
			records: structuredClone( records ),
		};
		mutate( invalid );
		assert.throws(
			() =>
				validateRehearsalDiscovery(
					invalid.envelope,
					2,
					invalid.records
				),
			/corpus_not_ready/u
		);
	}
} );

test( 'resume permits a selected prefix but requires later decks to be ready', () => {
	const partial = envelope();
	partial.count = 3;
	partial.reports.push( {
		postId: 30,
		status: 'ready',
		blockerCodes: [],
	} );
	partial.reports[ 1 ].status = 'blocked';
	partial.reports[ 1 ].blockerCodes = [ 'legacy_post_content' ];
	const records = [ { stage: 'restored' }, { stage: 'prepared' } ];

	assert.deepEqual(
		validateRehearsalDiscovery( partial, 3, records ),
		[ 10, 20, 30 ]
	);

	partial.reports[ 1 ].status = 'ready';
	partial.reports[ 1 ].blockerCodes = [];
	partial.reports[ 2 ].status = 'blocked';
	partial.reports[ 2 ].blockerCodes = [ 'legacy_post_content' ];
	assert.throws(
		() => validateRehearsalDiscovery( partial, 3, records ),
		/corpus_not_ready/u
	);
} );
