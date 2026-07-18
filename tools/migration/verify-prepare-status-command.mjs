/* eslint-disable no-console -- This command emits content-free verification results. */
/**
 * Exercise the real migration prepare/status WP-CLI commands.
 */

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

const wpEnv = path.join(
	process.cwd(),
	'node_modules',
	'@wordpress',
	'env',
	'bin',
	'wp-env'
);

function runWp( args, expectedStatus = 0 ) {
	const result = spawnSync(
		process.execPath,
		[ wpEnv, 'run', 'cli', 'wp', ...args ],
		{ cwd: process.cwd(), encoding: 'utf8' }
	);

	assert.equal(
		result.status,
		expectedStatus,
		`Unexpected WP-CLI status for ${ args.join( ' ' ) }: ${
			result.stderr || result.stdout
		}`
	);

	return result;
}

function jsonEnvelope( output, mode ) {
	const line = output
		.split( /\r?\n/ )
		.find( ( candidate ) => candidate.startsWith( '{"schemaVersion"' ) );
	assert( line, `The ${ mode } command did not emit its JSON envelope.` );

	const envelope = JSON.parse( line );
	assert.equal( envelope.schemaVersion, 1 );
	assert.equal( envelope.mode, mode );
	return { envelope, line };
}

function footprint( postId ) {
	const code = `$id=${ postId };$post=get_post($id);$value=array('content'=>$post->post_content,'modified'=>$post->post_modified_gmt,'legacy'=>get_post_meta($id,'_presenter_slides',false),'backup'=>get_post_meta($id,'_presenter_migration_backup_v1',false),'journal'=>get_post_meta($id,'_presenter_migration_journal_v1',false),'mode'=>get_post_meta($id,'_presenter_deck_mode',false),'revisions'=>array_keys(wp_get_post_revisions($id)),'lock'=>get_option('presenter_migration_lock_'.$id,null));echo hash('sha256',maybe_serialize($value));`;
	return runWp( [ 'eval', code ] ).stdout.match( /[a-f0-9]{64}/ )?.[ 0 ];
}

const fixtureState = JSON.parse(
	runWp( [
		'option',
		'get',
		'presenter_migration_prepare_status_fixtures',
		'--format=json',
	] ).stdout.match( /\{.*\}/ )?.[ 0 ] ?? '{}'
);
const readyId = fixtureState.fixtures[ 'presenter-m7-prepare-ready' ].postId;
const blockedId =
	fixtureState.fixtures[ 'presenter-m7-prepare-blocked' ].postId;

const missingPrepare = runWp(
	[ 'presenter', 'migration', 'prepare', '--yes' ],
	1
);
assert.match(
	missingPrepare.stderr + missingPrepare.stdout,
	/post-id is required|usage:.*<post-id>/is
);
const missingStatus = runWp( [ 'presenter', 'migration', 'status' ], 1 );
assert.match(
	missingStatus.stderr + missingStatus.stdout,
	/post-id is required|usage:.*<post-id>/is
);

const beforeStatus = footprint( readyId );
const initialStatus = jsonEnvelope(
	runWp( [ 'presenter', 'migration', 'status', String( readyId ) ] ).stdout,
	'status'
);
assert.equal( initialStatus.envelope.capabilities.canPrepare, true );
assert.equal(
	footprint( readyId ),
	beforeStatus,
	'Status must perform zero writes.'
);

const prepared = jsonEnvelope(
	runWp( [ 'presenter', 'migration', 'prepare', String( readyId ), '--yes' ] )
		.stdout,
	'prepare'
);
assert( prepared.envelope.codes.includes( 'prepared' ) );
assert.equal( prepared.envelope.capabilities.canApply, true );

const preparedFootprint = footprint( readyId );
const rerun = jsonEnvelope(
	runWp( [ 'presenter', 'migration', 'prepare', String( readyId ), '--yes' ] )
		.stdout,
	'prepare'
);
assert( rerun.envelope.codes.includes( 'already_prepared' ) );
assert.equal(
	footprint( readyId ),
	preparedFootprint,
	'An exact prepare rerun must not append safety artifacts or revisions.'
);

const beforePreparedStatus = footprint( readyId );
const preparedStatus = jsonEnvelope(
	runWp( [ 'presenter', 'migration', 'status', String( readyId ) ] ).stdout,
	'status'
);
assert.equal( preparedStatus.envelope.capabilities.canApply, true );
assert.equal(
	footprint( readyId ),
	beforePreparedStatus,
	'Prepared status inspection must perform zero writes.'
);

const privateSentinels = [
	'prepare-private-ready-content-sentinel',
	'prepare-private-blocked-post-sentinel',
	'prepare-private-blocked-slide-sentinel',
	'preconditionHash',
	'retainedLegacyHash',
	'originalContentHash',
	'targetContentHash',
	'backupId',
	'revisionId',
	'backupReference',
	'preparationReference',
];
for ( const sentinel of privateSentinels ) {
	assert.equal(
		prepared.line.includes( sentinel ),
		false,
		`Prepare output exposed private field: ${ sentinel }`
	);
	assert.equal(
		preparedStatus.line.includes( sentinel ),
		false,
		`Status output exposed private field: ${ sentinel }`
	);
}

const failed = runWp(
	[ 'presenter', 'migration', 'prepare', String( blockedId ), '--yes' ],
	1
);
const failedEnvelope = jsonEnvelope( failed.stdout, 'prepare' );
assert( failedEnvelope.envelope.codes.includes( 'not_preparable' ) );
assert.equal( failedEnvelope.envelope.capabilities.canApply, false );

console.log(
	JSON.stringify( {
		failedPrepareWasNonzero: true,
		passed: true,
		prepareWasIdempotent: true,
		statusWasReadOnly: true,
	} )
);
