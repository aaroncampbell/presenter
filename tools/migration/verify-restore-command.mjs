/* eslint-disable no-console -- This command emits content-free verification results. */
/**
 * Exercise the real migration restore WP-CLI command and persisted representation.
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

function jsonLine( output ) {
	const line = output
		.split( /\r?\n/ )
		.find( ( candidate ) => candidate.startsWith( '{' ) );
	assert( line, 'Expected one JSON output line.' );
	return line;
}

function jsonEnvelope( output, mode ) {
	const line = jsonLine( output );
	const envelope = JSON.parse( line );
	assert.equal( envelope.schemaVersion, 1 );
	assert.equal( envelope.mode, mode );
	return { envelope, line };
}

function evalJson( code ) {
	return JSON.parse( jsonLine( runWp( [ 'eval', code ] ).stdout ) );
}

function inspectPrivateState( postId ) {
	const code = `$id=${ postId };$post=get_post($id);$slides=get_post_meta($id,'_presenter_slides',false);$theme=get_post_meta($id,'_presenter-theme',false);$short=get_post_meta($id,'_presenter-short-url',false);$fixture=get_post_meta($id,'_presenter_restore_fixture_artifact',false);$backups=get_post_meta($id,'_presenter_migration_backup_v1',false);$journal=get_post_meta($id,'_presenter_migration_journal_v1',false);$mode=get_post_meta($id,'_presenter_deck_mode',false);$revisions=wp_get_post_revisions($id);$revisionData=array();foreach($revisions as $revision){$revisionData[$revision->ID]=array($revision->post_content,$revision->post_title,$revision->post_excerpt,$revision->post_name,$revision->post_status,$revision->post_password,$revision->menu_order);}$lock=get_option('presenter_migration_lock_'.$id,null);echo wp_json_encode(array('content'=>$post->post_content,'modified'=>$post->post_modified,'modifiedGmt'=>$post->post_modified_gmt,'noncontent'=>array($post->post_name,$post->post_title,$post->post_excerpt,$post->menu_order,$post->post_status,$post->post_password),'legacyHash'=>hash('sha256',maybe_serialize(array($slides,$theme,$short))),'fixtureHash'=>hash('sha256',maybe_serialize($fixture)),'backupHash'=>hash('sha256',maybe_serialize($backups)),'journalHash'=>hash('sha256',maybe_serialize($journal)),'revisionHash'=>hash('sha256',maybe_serialize($revisionData)),'backupCount'=>count($backups),'journalCount'=>count($journal),'journalState'=>$journal?end($journal)['toState']:null,'mode'=>$mode,'modeExists'=>metadata_exists('post',$id,'_presenter_deck_mode'),'revisionIds'=>array_map('intval',array_keys($revisions)),'lock'=>$lock));`;
	return evalJson( code );
}

function footprint( state ) {
	return JSON.stringify( state );
}

function assertContentFree( command ) {
	const forbidden = [
		'restore-private-ready-content-sentinel',
		'restore-private-unapplied-content-sentinel',
		'Presenter restore excerpt sentinel',
		'restore-fixture-password',
		'restore-fixture-short-url',
		'restore-artifact-private-sentinel',
		'preconditionHash',
		'retainedLegacyHash',
		'deckModeHash',
		'originalContentHash',
		'targetContentHash',
		'targetContent',
		'backupId',
		'revisionId',
		'backupReference',
		'preparationReference',
		'envelopeHash',
		'eventHash',
		'payload',
		'context',
	];
	for ( const sentinel of forbidden ) {
		assert.equal(
			command.line.includes( sentinel ),
			false,
			`${ command.envelope.mode } output exposed private value: ${ sentinel }`
		);
	}
}

const fixtureState = JSON.parse(
	jsonLine(
		runWp( [
			'option',
			'get',
			'presenter_migration_restore_fixtures',
			'--format=json',
		] ).stdout
	)
);
const readyId = fixtureState.fixtures[ 'presenter-m7-restore-ready' ].postId;
const unappliedId =
	fixtureState.fixtures[ 'presenter-m7-restore-unapplied' ].postId;

const missing = runWp( [ 'presenter', 'migration', 'restore', '--yes' ], 1 );
assert.match(
	missing.stderr + missing.stdout,
	/post-id is required|usage:.*<post-id>/is
);

const unapplied = jsonEnvelope(
	runWp(
		[ 'presenter', 'migration', 'restore', String( unappliedId ), '--yes' ],
		1
	).stdout,
	'restore'
);
assert( unapplied.envelope.codes.includes( 'not_restorable' ) );
assertContentFree( unapplied );

const original = inspectPrivateState( readyId );
const prepared = jsonEnvelope(
	runWp( [ 'presenter', 'migration', 'prepare', String( readyId ), '--yes' ] )
		.stdout,
	'prepare'
);
assert( prepared.envelope.codes.includes( 'prepared' ) );
assertContentFree( prepared );

const applied = jsonEnvelope(
	runWp( [ 'presenter', 'migration', 'apply', String( readyId ), '--yes' ] )
		.stdout,
	'apply'
);
assert( applied.envelope.codes.includes( 'applied' ) );
assertContentFree( applied );

const beforeRestore = inspectPrivateState( readyId );
assert.notEqual( beforeRestore.content, original.content );
assert.deepEqual( beforeRestore.mode, [ 'native' ] );
assert.equal( beforeRestore.modeExists, true );
assert.equal( beforeRestore.legacyHash, original.legacyHash );
assert.equal( beforeRestore.fixtureHash, original.fixtureHash );

const restored = jsonEnvelope(
	runWp( [ 'presenter', 'migration', 'restore', String( readyId ), '--yes' ] )
		.stdout,
	'restore'
);
assert( restored.envelope.codes.includes( 'restored' ) );
assert.equal( restored.envelope.journal.state, 'restored' );
assertContentFree( restored );

const afterRestore = inspectPrivateState( readyId );
assert.equal( afterRestore.content, original.content );
assert.equal( afterRestore.modified, original.modified );
assert.equal( afterRestore.modifiedGmt, original.modifiedGmt );
assert.deepEqual( afterRestore.noncontent, original.noncontent );
assert.equal( afterRestore.legacyHash, original.legacyHash );
assert.equal( afterRestore.fixtureHash, original.fixtureHash );
assert.equal( afterRestore.backupHash, beforeRestore.backupHash );
assert.equal( afterRestore.revisionHash, beforeRestore.revisionHash );
assert.deepEqual( afterRestore.revisionIds, beforeRestore.revisionIds );
assert.equal( afterRestore.modeExists, false );
assert.equal( afterRestore.journalState, 'restored' );
assert.equal( afterRestore.journalCount, 4 );
assert.equal( afterRestore.backupCount, 1 );
assert.equal( afterRestore.lock, null );

const finalStatus = jsonEnvelope(
	runWp( [ 'presenter', 'migration', 'status', String( readyId ) ] ).stdout,
	'status'
);
assert.equal( finalStatus.envelope.deckMode, 'legacy' );
assert.equal( finalStatus.envelope.journal.state, 'restored' );
assert.equal( finalStatus.envelope.journal.integrity, 'verified' );
assert.equal( finalStatus.envelope.content.classification, 'original' );
assert.equal( finalStatus.envelope.source.retained, 'match' );
assert.equal( finalStatus.envelope.backup.state, 'verified' );
assert.equal( finalStatus.envelope.backup.revision, 'verified' );
assert.equal( finalStatus.envelope.capabilities.canRestore, false );
assertContentFree( finalStatus );

const restoredFootprint = footprint( afterRestore );
const rerun = jsonEnvelope(
	runWp( [ 'presenter', 'migration', 'restore', String( readyId ), '--yes' ] )
		.stdout,
	'restore'
);
assert( rerun.envelope.codes.includes( 'already_restored' ) );
assertContentFree( rerun );
assert.equal(
	footprint( inspectPrivateState( readyId ) ),
	restoredFootprint,
	'An exact restore rerun must not change content or append artifacts.'
);

console.log(
	JSON.stringify( {
		artifactsWerePreserved: true,
		originalRepresentationWasRestored: true,
		passed: true,
		restoreWasIdempotent: true,
	} )
);
