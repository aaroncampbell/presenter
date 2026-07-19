/* eslint-disable no-console -- This command reports aggregate rehearsal progress. */
/**
 * Rehearse the reversible Presenter migration against the isolated snapshot.
 *
 * Deck identifiers and child-command output deliberately remain in memory. The
 * durable manifest contains only aggregate counts, enums, and one-way digests.
 */

import assert from 'node:assert/strict';
import { createHash, createHmac, randomBytes } from 'node:crypto';
import {
	existsSync,
	mkdirSync,
	readdirSync,
	readFileSync,
	renameSync,
	statSync,
	unlinkSync,
	writeFileSync,
} from 'node:fs';
import http from 'node:http';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

import {
	comparisonFailureWaitsForRestore,
	RehearsalComparison,
} from './rehearsal-comparison.mjs';

const repositoryRoot = resolve(
	dirname( fileURLToPath( import.meta.url ) ),
	'../..'
);
const workspaceRoot = resolve( repositoryRoot, '..' );
const snapshotEnvironment = resolve( repositoryRoot, 'tools/snapshot/env' );
const outputRoot = resolve( workspaceRoot, 'migration-rehearsal' );
const rehearsalKey = resolve(
	workspaceRoot,
	'presenter-rehearsal-hmac-key.txt'
);
const wpEnv = resolve(
	repositoryRoot,
	'node_modules/@wordpress/env/bin/wp-env'
);
const collector =
	'/var/www/html/wp-content/plugins/presenter/tools/migration/collect-rehearsal-state.php';
const snapshotOrigin = new URL( 'http://localhost:8890' );
const npmCli =
	process.env.npm_execpath ??
	resolve( dirname( process.execPath ), 'node_modules/npm/bin/npm-cli.js' );
const expectedCorpusSize = 65;
const digestPattern = /^[a-f0-9]{64}$/;
const pendingDigest = sha256( 'pending' );
const hostLockPath = resolve( outputRoot, '.presenter-rehearsal.lock' );
const hostGuardPath = resolve( outputRoot, '.presenter-rehearsal.guard' );
const resultCodes = {
	prepare: new Set( [ 'prepared', 'already_prepared' ] ),
	apply: new Set( [ 'applied', 'already_applied' ] ),
	restore: new Set( [ 'restored', 'already_restored' ] ),
};

function failUsage() {
	console.error(
		'Usage: node tools/migration/rehearse-corpus.mjs --yes [--limit=<1-65>] [--resume]'
	);
	process.exit( 1 );
}

function parseArguments( argv ) {
	let confirmed = false;
	let resume = false;
	let limit = expectedCorpusSize;
	let limitProvided = false;

	for ( let index = 0; index < argv.length; index += 1 ) {
		const argument = argv[ index ];
		if ( argument === '--yes' ) {
			confirmed = true;
			continue;
		}
		if ( argument === '--resume' ) {
			resume = true;
			continue;
		}
		if ( argument === '--limit' ) {
			index += 1;
			limit = Number( argv[ index ] );
			limitProvided = true;
			continue;
		}
		if ( argument.startsWith( '--limit=' ) ) {
			limit = Number( argument.slice( '--limit='.length ) );
			limitProvided = true;
			continue;
		}
		failUsage();
	}

	if (
		! confirmed ||
		! Number.isSafeInteger( limit ) ||
		limit < 1 ||
		limit > expectedCorpusSize
	) {
		failUsage();
	}

	return { limit, limitProvided, resume };
}

function sha256( value ) {
	return createHash( 'sha256' ).update( value ).digest( 'hex' );
}

function rehearsalHmac( domain, value ) {
	assert( existsSync( rehearsalKey ), 'collector_key_missing' );
	const key = readFileSync( rehearsalKey );
	try {
		return createHmac( 'sha256', key )
			.update( 'presenter-rehearsal-v1\0' )
			.update( domain )
			.update( '\0' )
			.update( value )
			.digest( 'hex' );
	} finally {
		key.fill( 0 );
	}
}

function processIsAlive( pid ) {
	try {
		process.kill( pid, 0 );
		return true;
	} catch ( error ) {
		return error && error.code === 'EPERM';
	}
}

function acquireHostLock() {
	const nonceDigest = sha256( randomBytes( 32 ) );
	const contents = JSON.stringify( {
		schemaVersion: 1,
		pid: process.pid,
		nonceDigest,
	} );
	const guardContents = JSON.stringify( {
		schemaVersion: 1,
		pid: process.pid,
		nonceDigest: sha256( randomBytes( 32 ) ),
	} );
	try {
		writeFileSync( hostGuardPath, guardContents, { flag: 'wx' } );
	} catch ( error ) {
		if ( ! error || error.code !== 'EEXIST' ) {
			throw new Error( 'host_lock_guarded' );
		}
		let existingGuard;
		try {
			existingGuard = JSON.parse( readFileSync( hostGuardPath, 'utf8' ) );
			requireExactKeys(
				existingGuard,
				[ 'schemaVersion', 'pid', 'nonceDigest' ],
				'host_guard_schema'
			);
			assert.equal( existingGuard.schemaVersion, 1, 'host_guard_schema' );
			assert(
				Number.isSafeInteger( existingGuard.pid ) &&
					existingGuard.pid > 0,
				'host_guard_schema'
			);
			validateDigest( existingGuard.nonceDigest, 'host_guard_schema' );
		} catch {
			throw new Error( 'host_guard_invalid' );
		}
		if (
			processIsAlive( existingGuard.pid ) ||
			Date.now() - statSync( hostGuardPath ).mtimeMs < 5 * 60 * 1000
		) {
			throw new Error( 'host_lock_guarded' );
		}
		const staleGuardPath = resolve(
			outputRoot,
			`.stale-guard-${ existingGuard.nonceDigest }`
		);
		try {
			renameSync( hostGuardPath, staleGuardPath );
			writeFileSync( hostGuardPath, guardContents, { flag: 'wx' } );
		} catch {
			throw new Error( 'host_lock_guarded' );
		}
	}

	try {
		try {
			writeFileSync( hostLockPath, contents, { flag: 'wx' } );
			return { contents, nonceDigest };
		} catch ( error ) {
			if ( ! error || error.code !== 'EEXIST' ) {
				throw new Error( 'host_lock_create' );
			}
		}

		let existing;
		try {
			existing = JSON.parse( readFileSync( hostLockPath, 'utf8' ) );
			requireExactKeys(
				existing,
				[ 'schemaVersion', 'pid', 'nonceDigest' ],
				'host_lock_schema'
			);
			assert.equal( existing.schemaVersion, 1, 'host_lock_schema' );
			assert(
				Number.isSafeInteger( existing.pid ) && existing.pid > 0,
				'host_lock_schema'
			);
			validateDigest( existing.nonceDigest, 'host_lock_schema' );
		} catch {
			throw new Error( 'host_lock_invalid' );
		}
		if ( processIsAlive( existing.pid ) ) {
			throw new Error( 'host_lock_active' );
		}

		// Require a dead owner and an old file before an atomic stale takeover.
		if ( Date.now() - statSync( hostLockPath ).mtimeMs < 5 * 60 * 1000 ) {
			throw new Error( 'host_lock_recent' );
		}
		const stalePath = resolve(
			outputRoot,
			`.stale-lock-${ existing.nonceDigest }`
		);
		renameSync( hostLockPath, stalePath );
		writeFileSync( hostLockPath, contents, { flag: 'wx' } );
		return { contents, nonceDigest };
	} finally {
		try {
			if ( readFileSync( hostGuardPath, 'utf8' ) === guardContents ) {
				unlinkSync( hostGuardPath );
			}
		} catch {
			// A guard ownership mismatch is fail-closed for later contenders.
		}
	}
}

function releaseHostLock( lock ) {
	if ( ! lock || ! existsSync( hostLockPath ) ) {
		return;
	}
	try {
		if ( readFileSync( hostLockPath, 'utf8' ) === lock.contents ) {
			unlinkSync( hostLockPath );
		}
	} catch {
		// Never remove a lock whose ownership cannot be proved byte-for-byte.
	}
}

function safeEnvironment( additions = {} ) {
	const environment = { ...process.env, ...additions };
	for ( const key of Object.keys( environment ) ) {
		if (
			key === 'PRESENTER_SNAPSHOT_ADMIN_PASSWORD' ||
			( key.startsWith( 'PRESENTER_' ) &&
				/(?:HMAC|PASSWORD|SECRET)/i.test( key ) )
		) {
			delete environment[ key ];
		}
	}
	return environment;
}

function runChild( executable, args, options = {} ) {
	const result = spawnSync( executable, args, {
		cwd: options.cwd ?? repositoryRoot,
		encoding: 'utf8',
		env: safeEnvironment( options.env ),
		maxBuffer: 16 * 1024 * 1024,
		windowsHide: true,
		input: options.input,
	} );

	if ( result.error || result.status !== ( options.status ?? 0 ) ) {
		throw new Error( options.code ?? 'child_command_failed' );
	}
	return result.stdout;
}

function runNpm( args, options = {} ) {
	if ( process.platform === 'win32' ) {
		assert( existsSync( npmCli ), 'npm_cli_missing' );
		return runChild( process.execPath, [ npmCli, ...args ], options );
	}

	return runChild( 'npm', args, options );
}

function jsonLine( output, failureCode ) {
	const candidates = output
		.split( /\r?\n/ )
		.map( ( line ) => line.trim() )
		.filter( ( line ) => line.startsWith( '{' ) );
	if ( candidates.length !== 1 ) {
		throw new Error( failureCode );
	}
	try {
		return JSON.parse( candidates[ 0 ] );
	} catch {
		throw new Error( failureCode );
	}
}

function runWp( args, code ) {
	return runChild( process.execPath, [ wpEnv, 'run', 'cli', 'wp', ...args ], {
		cwd: snapshotEnvironment,
		code,
	} );
}

function runWpWithRetries( args, code ) {
	for ( let attempt = 1; attempt <= 3; attempt += 1 ) {
		try {
			return runWp( args, code );
		} catch ( error ) {
			if ( attempt === 3 ) {
				throw error;
			}
		}
	}

	throw new Error( code );
}

function requireExactKeys( value, keys, code ) {
	assert(
		value && typeof value === 'object' && ! Array.isArray( value ),
		code
	);
	assert.deepEqual( Object.keys( value ).sort(), [ ...keys ].sort(), code );
}

function validateDryRun( envelope ) {
	requireExactKeys(
		envelope,
		[ 'schemaVersion', 'mode', 'count', 'reports' ],
		'discovery_schema'
	);
	assert.equal( envelope.schemaVersion, 1, 'discovery_schema' );
	assert.equal( envelope.mode, 'dry-run', 'discovery_schema' );
	assert.equal( envelope.count, expectedCorpusSize, 'corpus_size' );
	assert.equal( envelope.reports.length, expectedCorpusSize, 'corpus_size' );

	let previous = 0;
	const ids = new Set();
	for ( const report of envelope.reports ) {
		assert(
			Number.isSafeInteger( report.postId ) && report.postId > previous,
			'corpus_order'
		);
		assert.equal( report.status, 'ready', 'corpus_not_ready' );
		assert.deepEqual( report.blockerCodes, [], 'corpus_not_ready' );
		previous = report.postId;
		ids.add( report.postId );
	}
	assert.equal( ids.size, expectedCorpusSize, 'corpus_unique' );
	return envelope.reports.map( ( report ) => report.postId );
}

function discover() {
	const output = runWp(
		[ 'presenter', 'migration', 'dry-run', '--limit=100', '--offset=0' ],
		'discovery_command'
	);
	return validateDryRun( jsonLine( output, 'discovery_json' ) );
}

function validateDigest( value, code ) {
	assert( typeof value === 'string' && digestPattern.test( value ), code );
}

function validateCollector( state, postId ) {
	const topKeys = [
		'schemaVersion',
		'status',
		'postId',
		'accessClass',
		'post',
		'legacyMeta',
		'nonMigrationMeta',
		'taxonomy',
		'migrationArtifacts',
		'journal',
		'marker',
		'lock',
		'revisions',
		'blocks',
		'authoredStateDigest',
	];
	requireExactKeys( state, topKeys, 'collector_schema' );
	assert.equal( state.schemaVersion, 1, 'collector_schema' );
	assert.equal( state.status, 'pass', 'collector_failed' );
	assert.equal( state.postId, postId, 'collector_post' );
	assert(
		[ 'public', 'protected', 'nonpublic' ].includes( state.accessClass ),
		'collector_access'
	);
	validateDigest( state.authoredStateDigest, 'collector_digest' );

	requireExactKeys(
		state.post,
		[ 'rowDigest', 'fields', 'timestamps' ],
		'collector_schema'
	);
	validateDigest( state.post.rowDigest, 'collector_digest' );
	requireExactKeys(
		state.post.fields,
		[
			'contentDigest',
			'titleDigest',
			'excerptDigest',
			'nameDigest',
			'statusDigest',
			'passwordDigest',
			'menuOrderDigest',
		],
		'collector_schema'
	);
	requireExactKeys(
		state.post.timestamps,
		[ 'modifiedDigest', 'modifiedGmtDigest' ],
		'collector_schema'
	);
	for ( const digest of [
		...Object.values( state.post.fields ),
		...Object.values( state.post.timestamps ),
	] ) {
		validateDigest( digest, 'collector_digest' );
	}

	requireExactKeys(
		state.legacyMeta,
		[ 'slides', 'theme', 'shortUrl' ],
		'collector_schema'
	);
	for ( const entry of Object.values( state.legacyMeta ) ) {
		requireExactKeys(
			entry,
			[ 'exists', 'rowCount', 'valuesDigest' ],
			'collector_schema'
		);
		assert.equal( typeof entry.exists, 'boolean', 'collector_schema' );
		assert(
			Number.isSafeInteger( entry.rowCount ) && entry.rowCount >= 0,
			'collector_schema'
		);
		validateDigest( entry.valuesDigest, 'collector_digest' );
	}

	for ( const [ section, keys ] of [
		[ state.nonMigrationMeta, [ 'rowCount', 'keyCount', 'digest' ] ],
		[ state.taxonomy, [ 'rowCount', 'digest' ] ],
		[ state.revisions, [ 'count', 'records', 'recordsDigest' ] ],
	] ) {
		requireExactKeys( section, keys, 'collector_schema' );
	}
	assert(
		Number.isSafeInteger( state.nonMigrationMeta.rowCount ) &&
			state.nonMigrationMeta.rowCount >= 0,
		'collector_schema'
	);
	assert(
		Number.isSafeInteger( state.nonMigrationMeta.keyCount ) &&
			state.nonMigrationMeta.keyCount >= 0,
		'collector_schema'
	);
	validateDigest( state.nonMigrationMeta.digest, 'collector_digest' );
	assert(
		Number.isSafeInteger( state.taxonomy.rowCount ) &&
			state.taxonomy.rowCount >= 0,
		'collector_schema'
	);
	validateDigest( state.taxonomy.digest, 'collector_digest' );
	assert(
		Number.isSafeInteger( state.revisions.count ) &&
			state.revisions.count >= 0,
		'collector_schema'
	);
	assert.equal(
		state.revisions.records.length,
		state.revisions.count,
		'collector_schema'
	);
	for ( const record of state.revisions.records ) {
		requireExactKeys(
			record,
			[ 'idDigest', 'recordDigest' ],
			'collector_schema'
		);
		validateDigest( record.idDigest, 'collector_digest' );
		validateDigest( record.recordDigest, 'collector_digest' );
	}
	validateDigest( state.revisions.recordsDigest, 'collector_digest' );

	requireExactKeys(
		state.migrationArtifacts,
		[ 'backup', 'journal', 'marker' ],
		'collector_schema'
	);
	for ( const artifact of Object.values( state.migrationArtifacts ) ) {
		requireExactKeys(
			artifact,
			[ 'rowCount', 'digest' ],
			'collector_schema'
		);
		assert(
			Number.isSafeInteger( artifact.rowCount ) && artifact.rowCount >= 0,
			'collector_schema'
		);
		validateDigest( artifact.digest, 'collector_digest' );
	}
	requireExactKeys(
		state.journal,
		[ 'state', 'sequence' ],
		'collector_schema'
	);
	assert(
		[
			'none',
			'apply_prepared',
			'applied',
			'apply_rolled_back',
			'restore_prepared',
			'restored',
			'recovery_required',
			'invalid',
		].includes( state.journal.state ),
		'collector_schema'
	);
	assert(
		Number.isSafeInteger( state.journal.sequence ) &&
			state.journal.sequence >= 0,
		'collector_schema'
	);
	requireExactKeys(
		state.marker,
		[ 'state', 'rowCount' ],
		'collector_schema'
	);
	assert(
		[ 'absent', 'native', 'invalid' ].includes( state.marker.state ),
		'collector_schema'
	);
	assert(
		Number.isSafeInteger( state.marker.rowCount ) &&
			state.marker.rowCount >= 0,
		'collector_schema'
	);
	requireExactKeys( state.lock, [ 'presence' ], 'collector_schema' );
	assert(
		[ 'absent', 'present' ].includes( state.lock.presence ),
		'collector_schema'
	);
	requireExactKeys(
		state.blocks,
		[
			'parseState',
			'rootCount',
			'deckCount',
			'slideCount',
			'totalCount',
			'rootName',
			'slideName',
		],
		'collector_schema'
	);
	assert(
		[ 'empty', 'legacy', 'native', 'invalid' ].includes(
			state.blocks.parseState
		),
		'collector_schema'
	);
	assert(
		[ 'presenter/deck', 'presenter/slide', 'other', 'none' ].includes(
			state.blocks.rootName
		),
		'collector_schema'
	);
	assert(
		[ 'presenter/slide', 'other', 'none' ].includes(
			state.blocks.slideName
		),
		'collector_schema'
	);
	for ( const count of [
		state.blocks.rootCount,
		state.blocks.deckCount,
		state.blocks.slideCount,
		state.blocks.totalCount,
	] ) {
		assert(
			Number.isSafeInteger( count ) && count >= 0,
			'collector_schema'
		);
	}
	return state;
}

function collect( postId ) {
	assert( existsSync( rehearsalKey ), 'collector_key_missing' );
	const key = readFileSync( rehearsalKey );
	const php = `putenv('PRESENTER_REHEARSAL_POST_ID=${ postId }');require '${ collector }';`;
	try {
		let output;
		for ( let attempt = 1; attempt <= 3; attempt += 1 ) {
			try {
				output = runChild(
					process.execPath,
					[ wpEnv, 'run', 'cli', 'wp', 'eval', php ],
					{
						cwd: snapshotEnvironment,
						code: 'collector_command',
						input: key,
					}
				);
				break;
			} catch ( error ) {
				if ( attempt === 3 ) {
					throw error;
				}
			}
		}
		assert( typeof output === 'string', 'collector_command' );
		return validateCollector(
			jsonLine( output, 'collector_json' ),
			postId
		);
	} finally {
		key.fill( 0 );
	}
}

function exactCollectedState( state ) {
	return JSON.stringify( state );
}

function assertBaselineRevisionsPreserved( baseline, current ) {
	assert(
		current.count === baseline.length ||
			current.count === baseline.length + 1,
		'migration_revision_count'
	);
	const currentRecords = new Set(
		current.records.map(
			( record ) => `${ record.idDigest }:${ record.recordDigest }`
		)
	);
	for ( const record of baseline ) {
		assert(
			currentRecords.has(
				`${ record.idDigest }:${ record.recordDigest }`
			),
			'baseline_revision_changed'
		);
	}
}

function assertVerifiedRevisionBinding( statusEnvelope ) {
	assert.equal(
		statusEnvelope.journal.integrity,
		'verified',
		'migration_revision_journal_unverified'
	);
	assert.equal(
		statusEnvelope.backup.revision,
		'verified',
		'migration_revision_unbound'
	);
}

function assertPreparedRevisionCheckpoint( record, state ) {
	assert.notEqual(
		record.preparedRevisionDigest,
		pendingDigest,
		'prepared_revision_missing'
	);
	assert.equal(
		state.revisions.recordsDigest,
		record.preparedRevisionDigest,
		'prepared_revision_changed'
	);
}

function validateEnvelope( envelope, mode, postId, acceptedCodes = null ) {
	requireExactKeys(
		envelope,
		[
			'schemaVersion',
			'mode',
			'postId',
			'deckMode',
			'journal',
			'lock',
			'source',
			'content',
			'backup',
			'plan',
			'capabilities',
			'codes',
		],
		`${ mode }_schema`
	);
	assert.equal( envelope.schemaVersion, 1, `${ mode }_schema` );
	assert.equal( envelope.mode, mode, `${ mode }_schema` );
	assert.equal( envelope.postId, postId, `${ mode }_post` );
	assert( Array.isArray( envelope.codes ), `${ mode }_schema` );
	if ( acceptedCodes ) {
		assert(
			envelope.codes.some( ( code ) => acceptedCodes.has( code ) ),
			`${ mode }_result`
		);
	}
	return envelope;
}

function migrationCommand( mode, postId ) {
	let output;
	for ( let attempt = 1; attempt <= 3; attempt += 1 ) {
		try {
			output = runWp(
				[ 'presenter', 'migration', mode, String( postId ), '--yes' ],
				`${ mode }_command`
			);
			break;
		} catch ( error ) {
			if ( attempt === 3 ) {
				throw error;
			}
		}
	}
	assert( typeof output === 'string', `${ mode }_command` );
	return validateEnvelope(
		jsonLine( output, `${ mode }_json` ),
		mode,
		postId,
		resultCodes[ mode ]
	);
}

function status( postId ) {
	const output = runWpWithRetries(
		[ 'presenter', 'migration', 'status', String( postId ) ],
		'status_command'
	);
	const envelope = jsonLine( output, 'status_json' );
	return validateEnvelope( envelope, 'status', postId );
}

function assertState( state, expected ) {
	for ( const [ path, value ] of Object.entries( expected ) ) {
		const actual = path
			.split( '.' )
			.reduce( ( cursor, key ) => cursor[ key ], state );
		if ( actual !== value ) {
			throw new Error(
				`state_${ path.replaceAll( '.', '_' ).toLowerCase() }`
			);
		}
	}
}

function assertLegacyBlocks( state ) {
	if ( ! [ 'empty', 'legacy' ].includes( state.blocks.parseState ) ) {
		throw new Error( 'state_blocks_not_legacy' );
	}
}

function requestLocalOnce( path ) {
	return new Promise( ( resolveRequest, reject ) => {
		const request = http.get(
			new URL( path, snapshotOrigin ),
			{ headers: { 'User-Agent': 'Presenter-local-rehearsal/1' } },
			( response ) => {
				const chunks = [];
				response.on( 'data', ( chunk ) => chunks.push( chunk ) );
				response.on( 'end', () =>
					resolveRequest( {
						status: response.statusCode,
						location: response.headers.location,
						body: Buffer.concat( chunks ).toString( 'utf8' ),
					} )
				);
			}
		);
		request.setTimeout( 10000, () =>
			request.destroy( new Error( 'http_timeout' ) )
		);
		request.on( 'error', () =>
			reject( new Error( 'http_request_failed' ) )
		);
	} );
}

async function requestLocal( path ) {
	for ( let attempt = 1; attempt <= 3; attempt += 1 ) {
		try {
			return await requestLocalOnce( path );
		} catch ( error ) {
			if ( attempt === 3 ) {
				throw error;
			}
		}
	}

	throw new Error( 'http_request_failed' );
}

async function localPage( postId ) {
	let path = `/?post_type=slideshow&p=${ postId }`;
	for ( let redirects = 0; redirects < 4; redirects += 1 ) {
		const response = await requestLocal( path );
		if ( ! [ 301, 302, 303, 307, 308 ].includes( response.status ) ) {
			return response;
		}
		assert( typeof response.location === 'string', 'http_redirect' );
		const target = new URL( response.location, snapshotOrigin );
		assert.equal(
			target.origin,
			snapshotOrigin.origin,
			'http_external_redirect'
		);
		path = `${ target.pathname }${ target.search }`;
	}
	throw new Error( 'http_redirect_limit' );
}

async function verifyNativeHttp( postId, accessClass ) {
	const response = await localPage( postId );
	const nativeRoot = response.body.includes( 'data-presenter-reveal-root' );
	const frontendAsset = response.body.includes(
		'/presenter/build/frontend.js'
	);
	const revealAsset = response.body.includes(
		'/presenter/build/reveal/reveal.css'
	);
	if ( accessClass === 'nonpublic' ) {
		assert(
			[ 401, 403, 404 ].includes( response.status ),
			'nonpublic_native_exposed'
		);
		assert.equal(
			nativeRoot || frontendAsset || revealAsset,
			false,
			'nonpublic_native_reveal_leak'
		);
		return 'not_public';
	}

	assert.equal( response.status, 200, 'http_status' );

	if ( accessClass === 'protected' ) {
		assert.equal(
			nativeRoot || frontendAsset || revealAsset,
			false,
			'protected_reveal_leak'
		);
		assert.equal( response.body.length, 0, 'protected_response_changed' );
		return 'password_suppressed';
	}

	assert(
		nativeRoot && frontendAsset && revealAsset,
		'native_runtime_missing'
	);
	for ( const assetPath of [
		'/wp-content/plugins/presenter/build/frontend.js',
		'/wp-content/plugins/presenter/build/reveal/reveal.css',
	] ) {
		const asset = await requestLocal( assetPath );
		assert.equal( asset.status, 200, 'native_asset_missing' );
		assert( asset.body.length > 0, 'native_asset_empty' );
	}
	return 'native_verified';
}

async function verifyLegacyHttp(
	postId,
	accessClass,
	allowServerError = false
) {
	const response = await localPage( postId );
	const revealRoot = response.body.includes( 'data-presenter-reveal-root' );
	const legacyScript = response.body.includes(
		'/presenter/reveal.js/dist/reveal.js'
	);
	const nativeScript = response.body.includes(
		'/presenter/build/frontend.js'
	);

	if ( accessClass === 'nonpublic' ) {
		assert(
			[ 401, 403, 404 ].includes( response.status ),
			'nonpublic_exposed'
		);
		assert.equal(
			revealRoot || legacyScript || nativeScript,
			false,
			'nonpublic_reveal_leak'
		);
		return 'not_public';
	}

	if (
		accessClass === 'public' &&
		response.status === 500 &&
		allowServerError
	) {
		return 'server_error';
	}
	if ( response.status !== 200 ) {
		throw new Error(
			Number.isSafeInteger( response.status )
				? `legacy_http_status_${ response.status }`
				: 'legacy_http_status_unknown'
		);
	}
	if ( accessClass === 'protected' ) {
		assert.equal(
			revealRoot || legacyScript || nativeScript,
			false,
			'protected_legacy_reveal_leak'
		);
		assert.equal( response.body.length, 0, 'protected_response_changed' );
		return 'password_suppressed';
	}

	assert( revealRoot && legacyScript, 'legacy_runtime_missing' );
	assert.equal( nativeScript, false, 'legacy_native_asset_leak' );
	for ( const assetPath of [
		'/wp-content/plugins/presenter/reveal.js/dist/reveal.js',
		'/wp-content/plugins/presenter/reveal.js/dist/reveal.css',
	] ) {
		const asset = await requestLocal( assetPath );
		assert.equal( asset.status, 200, 'legacy_asset_missing' );
		assert( asset.body.length > 0, 'legacy_asset_empty' );
	}
	return 'legacy_verified';
}

function atomicManifest( path, manifest ) {
	synchronizeCounts( manifest );
	validateManifest( manifest );
	const temporary = `${ path }.tmp-${ process.pid }-${ randomBytes(
		4
	).toString( 'hex' ) }`;
	writeFileSync( temporary, `${ JSON.stringify( manifest, null, 2 ) }\n`, {
		flag: 'wx',
	} );
	renameSync( temporary, path );
}

function newRunId() {
	return `${ new Date()
		.toISOString()
		.replace( /[^0-9]/g, '' ) }-${ randomBytes( 6 ).toString( 'hex' ) }`;
}

function createManifest( limit, selectionDigest ) {
	return {
		schemaVersion: 1,
		state: 'running',
		selectionDigest,
		limit,
		counts: { selected: limit, completed: 0, failed: 0 },
		decks: Array.from( { length: limit }, ( unused, index ) => ( {
			deckDigest: rehearsalHmac( 'deck-ordinal', String( index ) ),
			stage: 'pending',
			outcome: 'pending',
			access: 'unknown',
			baselineLegacyHttp: 'not_checked',
			nativeHttp: 'not_checked',
			legacyHttp: 'not_checked',
			baselineDigest: pendingDigest,
			finalDigest: pendingDigest,
			baselineRevisions: [],
			preparedRevisionDigest: pendingDigest,
			failureDigest: sha256( 'none' ),
		} ) ),
	};
}

function validateManifest( manifest ) {
	requireExactKeys(
		manifest,
		[
			'schemaVersion',
			'state',
			'selectionDigest',
			'limit',
			'counts',
			'decks',
		],
		'manifest_schema'
	);
	assert.equal( manifest.schemaVersion, 1, 'manifest_schema' );
	assert(
		[ 'running', 'failed', 'complete' ].includes( manifest.state ),
		'manifest_schema'
	);
	validateDigest( manifest.selectionDigest, 'manifest_schema' );
	assert(
		Number.isSafeInteger( manifest.limit ) &&
			manifest.limit > 0 &&
			manifest.limit <= expectedCorpusSize,
		'manifest_schema'
	);
	assert.equal( manifest.decks.length, manifest.limit, 'manifest_schema' );
	requireExactKeys(
		manifest.counts,
		[ 'selected', 'completed', 'failed' ],
		'manifest_schema'
	);
	for ( const count of Object.values( manifest.counts ) ) {
		assert(
			Number.isSafeInteger( count ) && count >= 0,
			'manifest_schema'
		);
	}
	assert.equal( manifest.counts.selected, manifest.limit, 'manifest_schema' );
	const deckDigests = new Set();
	for ( const [ deckIndex, deck ] of manifest.decks.entries() ) {
		requireExactKeys(
			deck,
			[
				'deckDigest',
				'stage',
				'outcome',
				'access',
				'baselineLegacyHttp',
				'nativeHttp',
				'legacyHttp',
				'baselineDigest',
				'finalDigest',
				'baselineRevisions',
				'preparedRevisionDigest',
				'failureDigest',
			],
			'manifest_schema'
		);
		assert(
			[
				'not_checked',
				'legacy_verified',
				'password_suppressed',
				'not_public',
				'server_error',
			].includes( deck.baselineLegacyHttp ),
			'manifest_schema'
		);
		if ( deck.baselineLegacyHttp === 'server_error' ) {
			assert.equal( deckIndex, 4, 'manifest_schema' );
		}
		assert(
			[
				'pending',
				'baseline',
				'prepared',
				'applied',
				'restore_verified',
				'restored',
			].includes( deck.stage ),
			'manifest_schema'
		);
		assert(
			[ 'pending', 'running', 'failed', 'passed' ].includes(
				deck.outcome
			),
			'manifest_schema'
		);
		assert(
			[ 'unknown', 'public', 'protected', 'nonpublic' ].includes(
				deck.access
			),
			'manifest_schema'
		);
		assert(
			[
				'not_checked',
				'native_verified',
				'password_suppressed',
				'not_public',
			].includes( deck.nativeHttp ),
			'manifest_schema'
		);
		assert(
			[
				'not_checked',
				'legacy_verified',
				'password_suppressed',
				'not_public',
				'server_error',
			].includes( deck.legacyHttp ),
			'manifest_schema'
		);
		for ( const value of [
			deck.deckDigest,
			deck.baselineDigest,
			deck.finalDigest,
			deck.preparedRevisionDigest,
			deck.failureDigest,
		] ) {
			validateDigest( value, 'manifest_schema' );
		}
		assert.equal(
			deckDigests.has( deck.deckDigest ),
			false,
			'manifest_schema'
		);
		deckDigests.add( deck.deckDigest );
		assert( Array.isArray( deck.baselineRevisions ), 'manifest_schema' );
		for ( const revision of deck.baselineRevisions ) {
			requireExactKeys(
				revision,
				[ 'idDigest', 'recordDigest' ],
				'manifest_schema'
			);
			validateDigest( revision.idDigest, 'manifest_schema' );
			validateDigest( revision.recordDigest, 'manifest_schema' );
		}
		if ( deck.stage === 'pending' ) {
			assert.equal(
				deck.baselineLegacyHttp,
				'not_checked',
				'manifest_schema'
			);
			assert.equal(
				deck.baselineDigest,
				pendingDigest,
				'manifest_schema'
			);
			assert.deepEqual( deck.baselineRevisions, [], 'manifest_schema' );
		} else {
			assert.notEqual( deck.access, 'unknown', 'manifest_schema' );
			assert.notEqual(
				deck.baselineLegacyHttp,
				'not_checked',
				'manifest_schema'
			);
			assert.notEqual(
				deck.baselineDigest,
				pendingDigest,
				'manifest_schema'
			);
		}
		if (
			[ 'prepared', 'applied', 'restore_verified', 'restored' ].includes(
				deck.stage
			)
		) {
			assert.notEqual(
				deck.preparedRevisionDigest,
				pendingDigest,
				'manifest_schema'
			);
		}
		if ( deck.outcome === 'passed' ) {
			assert.equal( deck.stage, 'restored', 'manifest_schema' );
			assert.equal(
				deck.finalDigest,
				deck.baselineDigest,
				'manifest_schema'
			);
			assert.notEqual(
				deck.legacyHttp,
				'not_checked',
				'manifest_schema'
			);
			assert.equal(
				deck.legacyHttp,
				deck.baselineLegacyHttp,
				'manifest_schema'
			);
			assert.notEqual(
				deck.nativeHttp,
				'not_checked',
				'manifest_schema'
			);
		}
		if ( deck.stage === 'restored' ) {
			assert.equal( deck.outcome, 'passed', 'manifest_schema' );
		}
	}
	assert.equal(
		manifest.counts.completed,
		manifest.decks.filter( ( deck ) => deck.outcome === 'passed' ).length,
		'manifest_schema'
	);
	assert.equal(
		manifest.counts.failed,
		manifest.decks.filter( ( deck ) => deck.outcome === 'failed' ).length,
		'manifest_schema'
	);
	if ( manifest.state === 'complete' ) {
		assert.equal(
			manifest.counts.completed,
			manifest.limit,
			'manifest_schema'
		);
		assert.equal( manifest.counts.failed, 0, 'manifest_schema' );
		assert.equal(
			manifest.decks.filter(
				( deck ) => deck.baselineLegacyHttp === 'server_error'
			).length,
			manifest.limit > 4 ? 1 : 0,
			'manifest_schema'
		);
	}
	return manifest;
}

function latestResumableManifest() {
	assert( existsSync( outputRoot ), 'resume_missing' );
	const candidates = readdirSync( outputRoot, { withFileTypes: true } )
		.filter(
			( entry ) =>
				entry.isDirectory() &&
				existsSync( resolve( outputRoot, entry.name, 'manifest.json' ) )
		)
		.map( ( entry ) => entry.name )
		.sort()
		.reverse();
	const directory = candidates[ 0 ];
	if ( ! directory ) {
		throw new Error( 'resume_missing' );
	}

	const path = resolve( outputRoot, directory, 'manifest.json' );
	const manifest = validateManifest(
		JSON.parse( readFileSync( path, 'utf8' ) )
	);
	if ( manifest.state === 'complete' ) {
		throw new Error( 'resume_missing' );
	}

	return { directory, path, manifest };
}

function classifyResumeState( state, record ) {
	if (
		state.lock.presence !== 'absent' ||
		state.marker.state === 'invalid' ||
		state.journal.state === 'invalid' ||
		state.journal.state === 'recovery_required'
	) {
		throw new Error( 'resume_unsafe' );
	}
	const representation = `${ state.journal.state }:${ state.marker.state }:${ state.blocks.parseState }`;
	if (
		[ 'none:absent:empty', 'none:absent:legacy' ].includes( representation )
	) {
		if ( record.stage === 'pending' ) {
			return 'baseline_new';
		}
		assert.equal( record.stage, 'baseline', 'resume_manifest_state' );
		assertPersistedBaseline( record, state );
		return 'baseline_existing';
	}
	if (
		[
			'apply_prepared:absent:empty',
			'apply_prepared:absent:legacy',
		].includes( representation )
	) {
		assertPersistedBaselineAuthored( record, state );
		return 'prepared';
	}
	if (
		[
			'apply_prepared:absent:native',
			'apply_prepared:native:native',
		].includes( representation )
	) {
		assert.notEqual(
			record.baselineDigest,
			pendingDigest,
			'baseline_missing'
		);
		return 'prepared';
	}
	if ( representation === 'applied:native:native' ) {
		return 'applied';
	}
	if (
		[
			'restore_prepared:native:native',
			'restore_prepared:absent:empty',
			'restore_prepared:absent:native',
			'restore_prepared:absent:legacy',
		].includes( representation )
	) {
		return 'restoring';
	}
	if (
		[ 'restored:absent:empty', 'restored:absent:legacy' ].includes(
			representation
		)
	) {
		return 'restored';
	}
	throw new Error( 'resume_unsafe' );
}

function assertPersistedBaselineAuthored( record, state ) {
	assert.notEqual( record.baselineDigest, pendingDigest, 'baseline_missing' );
	assert.equal(
		state.authoredStateDigest,
		record.baselineDigest,
		'resume_authored_changed'
	);
}

function assertPersistedBaseline( record, state ) {
	assertPersistedBaselineAuthored( record, state );
	assert.deepEqual(
		state.revisions.records,
		record.baselineRevisions,
		'resume_baseline_revisions_changed'
	);
}

function synchronizeCounts( manifest ) {
	manifest.counts.completed = manifest.decks.filter(
		( deck ) => deck.outcome === 'passed'
	).length;
	manifest.counts.failed = manifest.decks.filter(
		( deck ) => deck.outcome === 'failed'
	).length;
}

function progress( manifest ) {
	synchronizeCounts( manifest );
	console.log(
		JSON.stringify( {
			status: manifest.state,
			selected: manifest.counts.selected,
			completed: manifest.counts.completed,
			failed: manifest.counts.failed,
		} )
	);
}

function safeFailureCode( error ) {
	if (
		error instanceof Error &&
		/^[a-z][a-z0-9_]{0,79}$/.test( error.message )
	) {
		return error.message;
	}

	return 'unexpected_failure';
}

function safeFailureLocation( error ) {
	if ( ! ( error instanceof Error ) || typeof error.stack !== 'string' ) {
		return 0;
	}

	const match = error.stack.match( /rehearse-corpus\.mjs:(\d+):\d+/ );
	return match ? Number( match[ 1 ] ) : 0;
}

const options = parseArguments( process.argv.slice( 2 ) );
mkdirSync( outputRoot, { recursive: true } );
let run;
let manifest;
let activeRecord;
let hostLock;
let comparison;
let comparisonUnavailableError;

try {
	hostLock = acquireHostLock();
	if ( options.resume ) {
		run = latestResumableManifest();
		manifest = run.manifest;
		if ( options.limitProvided ) {
			assert.equal( options.limit, manifest.limit, 'resume_limit' );
		} else {
			options.limit = manifest.limit;
		}
		const resumePreflight = jsonLine(
			runNpm( [ 'run', 'snapshot:preflight', '--', '--resume-safe' ], {
				code: 'resume_preflight_failed',
			} ),
			'resume_preflight_json'
		);
		assert.equal(
			resumePreflight.status,
			'pass',
			'resume_preflight_failed'
		);
		assert.equal(
			resumePreflight.checks.wordpress.migrationState,
			'inspected',
			'resume_preflight_schema'
		);
		manifest.state = 'running';
	} else {
		const preflight = jsonLine(
			runNpm( [ 'run', 'snapshot:preflight' ], {
				code: 'preflight_failed',
			} ),
			'preflight_json'
		);
		assert.equal( preflight.status, 'pass', 'preflight_failed' );
		assert(
			preflight.checks && typeof preflight.checks === 'object',
			'preflight_schema'
		);
		assert(
			preflight.counts && typeof preflight.counts === 'object',
			'preflight_schema'
		);
		const directory = newRunId();
		const runDirectory = resolve( outputRoot, directory );
		mkdirSync( runDirectory, { recursive: false } );
		run = { directory, path: resolve( runDirectory, 'manifest.json' ) };
	}

	const discovered = discover();
	const selected = discovered.slice( 0, options.limit );
	const selectionDigest = rehearsalHmac(
		'ordered-selection',
		selected.join( ',' )
	);
	if ( options.resume ) {
		assert.equal(
			manifest.selectionDigest,
			selectionDigest,
			'resume_corpus_changed'
		);
	} else {
		manifest = createManifest( options.limit, selectionDigest );
		atomicManifest( run.path, manifest );
	}
	const comparisonKey = readFileSync( rehearsalKey );
	try {
		try {
			comparison = await RehearsalComparison.create( {
				key: comparisonKey,
				records: manifest.decks,
				runDirectory: dirname( run.path ),
				selectedPostIds: selected,
				selectionDigest,
			} );
		} catch ( error ) {
			if ( ! options.resume ) {
				throw error;
			}
			comparisonUnavailableError = error;
		}
	} finally {
		comparisonKey.fill( 0 );
	}

	for ( let index = 0; index < selected.length; index += 1 ) {
		const postId = selected[ index ];
		const record = manifest.decks[ index ];
		let deferredComparisonError;
		activeRecord = record;
		if ( record.stage === 'restored' ) {
			record.stage = 'restore_verified';
			record.finalDigest = pendingDigest;
			record.legacyHttp = 'not_checked';
		}
		record.outcome = 'running';

		let current = collect( postId );
		if ( record.access === 'unknown' ) {
			assert.equal(
				record.stage,
				'pending',
				'access_checkpoint_missing'
			);
			record.access = current.accessClass;
		} else {
			assert.equal(
				current.accessClass,
				record.access,
				'access_class_changed'
			);
		}
		let resumeStage = options.resume
			? classifyResumeState( current, record )
			: 'baseline_new';
		if ( ! comparisonUnavailableError ) {
			try {
				await comparison.checkpointAccess(
					index,
					postId,
					current.accessClass
				);
			} catch ( error ) {
				if ( ! options.resume ) {
					throw error;
				}
				comparisonUnavailableError = error;
			}
		}
		if ( comparisonUnavailableError ) {
			if ( comparisonFailureWaitsForRestore( resumeStage ) ) {
				deferredComparisonError = comparisonUnavailableError;
			} else {
				throw comparisonUnavailableError;
			}
		}
		if (
			resumeStage === 'baseline_new' ||
			resumeStage === 'baseline_existing'
		) {
			assertState( current, {
				'journal.state': 'none',
				'marker.state': 'absent',
				'lock.presence': 'absent',
				'migrationArtifacts.backup.rowCount': 0,
				'migrationArtifacts.journal.rowCount': 0,
			} );
			assertLegacyBlocks( current );
			if ( resumeStage === 'baseline_new' ) {
				assert.equal( record.stage, 'pending', 'baseline_replacement' );
				const baselineLegacyHttp = await verifyLegacyHttp(
					postId,
					current.accessClass,
					index === 4
				);
				record.baselineDigest = current.authoredStateDigest;
				record.baselineRevisions = current.revisions.records;
				record.baselineLegacyHttp = baselineLegacyHttp;
				record.stage = 'baseline';
			} else {
				assertPersistedBaseline( record, current );
				assert.notEqual(
					record.baselineLegacyHttp,
					'not_checked',
					'baseline_http_missing'
				);
			}
			atomicManifest( run.path, manifest );
			if ( record.baselineLegacyHttp === 'server_error' ) {
				await comparison.markLegacyHttpFailure(
					index,
					postId,
					current.accessClass
				);
			} else {
				await comparison.captureLegacy(
					index,
					postId,
					current.accessClass
				);
			}

			const beforeStatus = exactCollectedState( current );
			const initialStatus = status( postId );
			assert.equal(
				initialStatus.plan.state,
				'ready',
				'status_not_ready'
			);
			assert.equal(
				initialStatus.capabilities.canPrepare,
				true,
				'status_cannot_prepare'
			);
			current = collect( postId );
			assert.equal(
				exactCollectedState( current ),
				beforeStatus,
				'status_wrote_state'
			);

			migrationCommand( 'prepare', postId );
			migrationCommand( 'prepare', postId );
			current = collect( postId );
			assertState( current, {
				'journal.state': 'apply_prepared',
				'marker.state': 'absent',
				'lock.presence': 'absent',
				'migrationArtifacts.backup.rowCount': 1,
			} );
			assertLegacyBlocks( current );
			assertBaselineRevisionsPreserved(
				record.baselineRevisions,
				current.revisions
			);
			const beforePreparedStatus = exactCollectedState( current );
			const preparedStatus = status( postId );
			assertVerifiedRevisionBinding( preparedStatus );
			assert.equal(
				preparedStatus.capabilities.canApply,
				true,
				'prepared_cannot_apply'
			);
			current = collect( postId );
			assert.equal(
				exactCollectedState( current ),
				beforePreparedStatus,
				'prepared_status_wrote_state'
			);
			record.preparedRevisionDigest = current.revisions.recordsDigest;
			record.stage = 'prepared';
			atomicManifest( run.path, manifest );
			resumeStage = 'prepared';
		}

		if ( resumeStage === 'prepared' ) {
			assert(
				digestPattern.test( record.baselineDigest ),
				'baseline_missing'
			);
			assertBaselineRevisionsPreserved(
				record.baselineRevisions,
				current.revisions
			);
			if ( record.preparedRevisionDigest === pendingDigest ) {
				record.preparedRevisionDigest = current.revisions.recordsDigest;
				record.stage = 'prepared';
				atomicManifest( run.path, manifest );
			} else {
				assert.equal(
					current.revisions.recordsDigest,
					record.preparedRevisionDigest,
					'prepared_revision_changed'
				);
			}
			await comparison.bindAttempt( index, postId, current.accessClass );
			const beforeApplyStatus = exactCollectedState( current );
			const beforeApply = status( postId );
			assertVerifiedRevisionBinding( beforeApply );
			assert.equal(
				beforeApply.journal.state,
				'apply_prepared',
				'apply_resume_state'
			);
			assert.equal(
				beforeApply.journal.integrity,
				'verified',
				'apply_resume_integrity'
			);
			current = collect( postId );
			assert.equal(
				exactCollectedState( current ),
				beforeApplyStatus,
				'apply_status_wrote_state'
			);
			migrationCommand( 'apply', postId );
			migrationCommand( 'apply', postId );
			current = collect( postId );
			assertState( current, {
				'journal.state': 'applied',
				'marker.state': 'native',
				'lock.presence': 'absent',
				'blocks.parseState': 'native',
			} );
			assert.equal(
				current.revisions.recordsDigest,
				record.preparedRevisionDigest,
				'applied_revision_changed'
			);
			record.stage = 'applied';
			atomicManifest( run.path, manifest );
			resumeStage = 'applied';
		}

		if ( resumeStage === 'applied' ) {
			assertPreparedRevisionCheckpoint( record, current );
			record.nativeHttp = await verifyNativeHttp(
				postId,
				current.accessClass
			);
			atomicManifest( run.path, manifest );
			try {
				await comparison.captureNative(
					index,
					postId,
					current.accessClass
				);
			} catch ( error ) {
				deferredComparisonError = error;
			}
			resumeStage = 'restoring';
		}

		if ( resumeStage === 'restoring' ) {
			assertPreparedRevisionCheckpoint( record, current );
			const beforeRestoreStatus = exactCollectedState( current );
			const beforeRestore = status( postId );
			assertVerifiedRevisionBinding( beforeRestore );
			assert.equal(
				beforeRestore.capabilities.canRestore,
				true,
				'restore_resume_not_safe'
			);
			current = collect( postId );
			assert.equal(
				exactCollectedState( current ),
				beforeRestoreStatus,
				'restore_status_wrote_state'
			);
			migrationCommand( 'restore', postId );
			migrationCommand( 'restore', postId );
			current = collect( postId );
			assertState( current, {
				'journal.state': 'restored',
				'marker.state': 'absent',
				'lock.presence': 'absent',
			} );
			assertLegacyBlocks( current );
			assert.equal(
				current.revisions.recordsDigest,
				record.preparedRevisionDigest,
				'restored_revision_changed'
			);
			record.stage = 'restore_verified';
			atomicManifest( run.path, manifest );
			resumeStage = 'restored';
		}

		if ( resumeStage === 'restored' ) {
			assert.equal(
				current.revisions.recordsDigest,
				record.preparedRevisionDigest,
				'final_revision_changed'
			);
			const beforeFinalStatus = exactCollectedState( current );
			const finalStatus = status( postId );
			assert.equal(
				finalStatus.journal.state,
				'restored',
				'final_status_state'
			);
			assert.equal(
				finalStatus.journal.integrity,
				'verified',
				'final_status_integrity'
			);
			assertVerifiedRevisionBinding( finalStatus );
			assert.equal(
				finalStatus.content.classification,
				'original',
				'final_status_content'
			);
			assert.equal( finalStatus.deckMode, 'legacy', 'final_status_mode' );
			const final = collect( postId );
			assert.equal(
				exactCollectedState( final ),
				beforeFinalStatus,
				'final_status_wrote_state'
			);
			assert.equal(
				final.authoredStateDigest,
				record.baselineDigest,
				'authored_state_changed'
			);
			record.legacyHttp = await verifyLegacyHttp(
				postId,
				final.accessClass,
				index === 4
			);
			assert.equal(
				record.legacyHttp,
				record.baselineLegacyHttp,
				'legacy_http_changed'
			);
			record.finalDigest = final.authoredStateDigest;
			if ( deferredComparisonError ) {
				throw deferredComparisonError;
			}
			if ( comparison && ! comparisonUnavailableError ) {
				await comparison.compareAfterRestore(
					index,
					postId,
					final.accessClass
				);
			}
			record.stage = 'restored';
			record.outcome = 'passed';
			record.failureDigest = sha256( 'none' );
			atomicManifest( run.path, manifest );
			progress( manifest );
			activeRecord = undefined;
		}
	}

	if ( comparisonUnavailableError ) {
		throw comparisonUnavailableError;
	}
	await comparison.finalize();
	manifest.state = 'complete';
	atomicManifest( run.path, manifest );
	progress( manifest );
} catch ( error ) {
	const failureCode = safeFailureCode( error );
	const failureLine = safeFailureLocation( error );
	if ( manifest && run ) {
		if ( activeRecord ) {
			activeRecord.outcome = 'failed';
			activeRecord.failureDigest = rehearsalHmac(
				'failure-code',
				error instanceof Error ? error.message : 'unknown_failure'
			);
		}
		manifest.state = 'failed';
		atomicManifest( run.path, manifest );
		progress( manifest );
		console.error(
			JSON.stringify( {
				status: 'failed',
				code: failureCode,
				line: failureLine,
			} )
		);
	} else {
		console.error(
			JSON.stringify( {
				status: 'failed',
				completed: 0,
				failed: 1,
				code: failureCode,
				line: failureLine,
			} )
		);
	}
	process.exitCode = 1;
} finally {
	await comparison?.close().catch( () => {} );
	releaseHostLock( hostLock );
}
