import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import test from 'node:test';

import { assertSnapshotNotMounted } from './prepare-uploads.mjs';
import {
	resolveSourceRoot,
	validateArchiveEntries,
} from './verify-sources.mjs';

test( 'the configured source is the source directory itself', () => {
	assert.equal(
		resolveSourceRoot( 'private-sources', '/repository' ),
		resolve( 'private-sources' )
	);
	assert.equal(
		resolveSourceRoot( undefined, '/repository' ),
		resolve( '/repository', '..' )
	);
} );

test( 'archive validation accepts only regular files and directories', () => {
	assert.deepEqual(
		validateArchiveEntries(
			[
				'wp-content/uploads/',
				'wp-content/uploads/photo.jpg',
				'wp-content/uploads/rejected.php',
			],
			[
				'drwxr-xr-x owner/group 0 date wp-content/uploads/',
				'-rw-r--r-- owner/group 1 date wp-content/uploads/photo.jpg',
				'-rw-r--r-- owner/group 1 date wp-content/uploads/rejected.php',
			]
		),
		{ excludedExecutableEntries: 1, uploadEntries: 3 }
	);

	assert.throws(
		() =>
			validateArchiveEntries(
				[ 'wp-content/uploads/link' ],
				[ 'lrwxr-xr-x owner/group 0 date wp-content/uploads/link' ]
			),
		/unsupported entry type/
	);
} );

test( 'preparation refuses a directory mounted in a running container', () => {
	const target = resolve( 'snapshot/uploads' );
	const responses = [
		{ status: 0, stdout: 'container-id\n' },
		{
			status: 0,
			stdout: JSON.stringify( [
				{ Mounts: [ { Type: 'bind', Source: target } ] },
			] ),
		},
	];
	const runCommand = () => responses.shift();

	assert.throws(
		() => assertSnapshotNotMounted( target, runCommand ),
		/Stop the snapshot environment/
	);
} );
