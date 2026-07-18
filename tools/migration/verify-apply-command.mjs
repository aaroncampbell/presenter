/* eslint-disable no-console -- This command emits content-free verification results. */
/**
 * Exercise the real migration apply WP-CLI command and persisted representation.
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
	const code = `$id=${ postId };$post=get_post($id);$blocks=parse_blocks($post->post_content);$slides=get_post_meta($id,'_presenter_slides',false);$theme=get_post_meta($id,'_presenter-theme',false);$short=get_post_meta($id,'_presenter-short-url',false);$backups=get_post_meta($id,'_presenter_migration_backup_v1',false);$journal=get_post_meta($id,'_presenter_migration_journal_v1',false);$mode=get_post_meta($id,'_presenter_deck_mode',false);$revisions=array_keys(wp_get_post_revisions($id));$lock=get_option('presenter_migration_lock_'.$id,null);$inner=$blocks[0]['innerBlocks']??array();$children=$inner[0]['innerBlocks']??array();echo wp_json_encode(array('content'=>$post->post_content,'modified'=>$post->post_modified,'modifiedGmt'=>$post->post_modified_gmt,'noncontent'=>array($post->post_name,$post->post_title,$post->post_excerpt,$post->menu_order,$post->post_status,$post->post_password),'legacyHash'=>hash('sha256',maybe_serialize(array($slides,$theme,$short))),'artifactHash'=>hash('sha256',maybe_serialize(array($backups,$journal,$mode,$revisions,$lock))),'backupCount'=>count($backups),'journalCount'=>count($journal),'journalState'=>$journal?end($journal)['toState']:null,'mode'=>$mode,'revisionIds'=>$revisions,'lock'=>$lock,'rootCount'=>count($blocks),'rootName'=>$blocks[0]['blockName']??null,'slideCount'=>count($inner),'slideName'=>$inner[0]['blockName']??null,'childCount'=>count($children),'childName'=>$children[0]['blockName']??null,'containsSentinel'=>str_contains($post->post_content,'apply-private-ready-content-sentinel')));`;
	return evalJson( code );
}

function footprint( state ) {
	return JSON.stringify( {
		content: state.content,
		modified: state.modified,
		modifiedGmt: state.modifiedGmt,
		noncontent: state.noncontent,
		legacyHash: state.legacyHash,
		artifactHash: state.artifactHash,
		backupCount: state.backupCount,
		journalCount: state.journalCount,
		journalState: state.journalState,
		mode: state.mode,
		revisionIds: state.revisionIds,
		lock: state.lock,
	} );
}

function assertContentFree( command ) {
	const forbidden = [
		'apply-private-ready-content-sentinel',
		'apply-private-unprepared-content-sentinel',
		'Presenter apply excerpt sentinel',
		'apply-fixture-password',
		'apply-fixture-short-url',
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
			'presenter_migration_apply_fixtures',
			'--format=json',
		] ).stdout
	)
);
const readyId = fixtureState.fixtures[ 'presenter-m7-apply-ready' ].postId;
const unpreparedId =
	fixtureState.fixtures[ 'presenter-m7-apply-unprepared' ].postId;

const missing = runWp( [ 'presenter', 'migration', 'apply', '--yes' ], 1 );
assert.match(
	missing.stderr + missing.stdout,
	/post-id is required|usage:.*<post-id>/is
);

const unprepared = jsonEnvelope(
	runWp(
		[ 'presenter', 'migration', 'apply', String( unpreparedId ), '--yes' ],
		1
	).stdout,
	'apply'
);
assert( unprepared.envelope.codes.includes( 'not_applicable' ) );
assertContentFree( unprepared );

const original = inspectPrivateState( readyId );
const initialStatus = jsonEnvelope(
	runWp( [ 'presenter', 'migration', 'status', String( readyId ) ] ).stdout,
	'status'
);
assert.equal( initialStatus.envelope.capabilities.canPrepare, true );
assertContentFree( initialStatus );

const prepared = jsonEnvelope(
	runWp( [ 'presenter', 'migration', 'prepare', String( readyId ), '--yes' ] )
		.stdout,
	'prepare'
);
assert( prepared.envelope.codes.includes( 'prepared' ) );
assert.equal( prepared.envelope.capabilities.canApply, true );
assertContentFree( prepared );

const beforeApply = inspectPrivateState( readyId );
assert.equal( beforeApply.content, original.content );
assert.equal( beforeApply.modified, original.modified );
assert.equal( beforeApply.modifiedGmt, original.modifiedGmt );
assert.deepEqual( beforeApply.noncontent, original.noncontent );
assert.equal( beforeApply.legacyHash, original.legacyHash );

const applied = jsonEnvelope(
	runWp( [ 'presenter', 'migration', 'apply', String( readyId ), '--yes' ] )
		.stdout,
	'apply'
);
assert( applied.envelope.codes.includes( 'applied' ) );
assert.equal( applied.envelope.journal.state, 'applied' );
assert.equal( applied.envelope.capabilities.canRestore, true );
assertContentFree( applied );

const afterApply = inspectPrivateState( readyId );
assert.notEqual( afterApply.content, original.content );
assert.equal( afterApply.modified, original.modified );
assert.equal( afterApply.modifiedGmt, original.modifiedGmt );
assert.deepEqual( afterApply.noncontent, original.noncontent );
assert.equal( afterApply.legacyHash, original.legacyHash );
assert.deepEqual( afterApply.mode, [ 'native' ] );
assert.equal( afterApply.journalState, 'applied' );
assert.equal( afterApply.journalCount, 2 );
assert.equal( afterApply.backupCount, 1 );
assert.equal( afterApply.lock, null );
assert.equal( afterApply.rootCount, 1 );
assert.equal( afterApply.rootName, 'presenter/deck' );
assert.equal( afterApply.slideCount, 1 );
assert.equal( afterApply.slideName, 'presenter/slide' );
assert.equal( afterApply.childCount, 1 );
assert.equal( afterApply.childName, 'core/html' );
assert.equal( afterApply.containsSentinel, true );

const finalStatus = jsonEnvelope(
	runWp( [ 'presenter', 'migration', 'status', String( readyId ) ] ).stdout,
	'status'
);
assert.equal( finalStatus.envelope.deckMode, 'native' );
assert.equal( finalStatus.envelope.journal.state, 'applied' );
assert.equal( finalStatus.envelope.journal.integrity, 'verified' );
assert.equal( finalStatus.envelope.content.classification, 'target' );
assert.equal( finalStatus.envelope.source.retained, 'match' );
assert.equal( finalStatus.envelope.backup.state, 'verified' );
assert.equal( finalStatus.envelope.backup.revision, 'verified' );
assert.equal( finalStatus.envelope.capabilities.canApply, false );
assert.equal( finalStatus.envelope.capabilities.canRestore, true );
assert.deepEqual( finalStatus.envelope.codes, [] );
assertContentFree( finalStatus );

const appliedFootprint = footprint( afterApply );
const rerun = jsonEnvelope(
	runWp( [ 'presenter', 'migration', 'apply', String( readyId ), '--yes' ] )
		.stdout,
	'apply'
);
assert( rerun.envelope.codes.includes( 'already_applied' ) );
assertContentFree( rerun );
assert.equal(
	footprint( inspectPrivateState( readyId ) ),
	appliedFootprint,
	'An exact apply rerun must not change content or append artifacts.'
);

console.log(
	JSON.stringify( {
		applyWasIdempotent: true,
		contentWasVerified: true,
		failedApplyWasNonzero: true,
		passed: true,
		retainedSourceWasPreserved: true,
	} )
);
