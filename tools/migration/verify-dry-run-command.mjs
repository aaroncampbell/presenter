/* eslint-disable no-console -- This command emits content-free verification results. */
/**
 * Run and validate the real migration dry-run WP-CLI command.
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

function runWp( args ) {
	const result = spawnSync(
		process.execPath,
		[ wpEnv, 'run', 'cli', 'wp', ...args ],
		{
			cwd: process.cwd(),
			encoding: 'utf8',
		}
	);

	assert.equal(
		result.status,
		0,
		`WP-CLI failed: ${ result.stderr || result.stdout }`
	);

	return result.stdout;
}

const fixtureState = JSON.parse(
	runWp( [
		'option',
		'get',
		'presenter_migration_dry_run_fixtures',
		'--format=json',
	] )
);
const commandOutput = runWp( [
	'presenter',
	'migration',
	'dry-run',
	'--limit=100',
	'--offset=0',
] );
const jsonLine = commandOutput
	.split( /\r?\n/ )
	.find( ( line ) => line.startsWith( '{"schemaVersion"' ) );

assert( jsonLine, 'The dry-run command did not emit its JSON envelope.' );
const envelope = JSON.parse( jsonLine );
assert.equal( envelope.schemaVersion, 1 );
assert.equal( envelope.mode, 'dry-run' );

const readyId = fixtureState.fixtures[ 'presenter-m7-ready' ].postId;
const blockedId = fixtureState.fixtures[ 'presenter-m7-blocked' ].postId;
const ready = envelope.reports.find( ( report ) => report.postId === readyId );
const blocked = envelope.reports.find(
	( report ) => report.postId === blockedId
);

assert( ready, 'The ready fixture is missing from the dry-run report.' );
assert.equal( ready.status, 'ready' );
assert.deepEqual( ready.blockerCodes, [] );
assert( ready.warningCodes.includes( 'duplicate_data_attribute_normalized' ) );
assert.deepEqual(
	ready.slides.map( ( slide ) => slide.sourceIndex ),
	[ 1, 0 ],
	'Slides must be ordered numerically while retaining source indices.'
);

assert( blocked, 'The blocked fixture is missing from the dry-run report.' );
assert.equal( blocked.status, 'blocked' );
assert.deepEqual( blocked.blockerCodes, [
	'legacy_html_notes',
	'legacy_nested_sections',
	'legacy_post_content',
	'legacy_theme',
] );

console.log(
	JSON.stringify( {
		blockedPostId: blockedId,
		passed: true,
		readyPostId: readyId,
		reportCount: envelope.count,
	} )
);
