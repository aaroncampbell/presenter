/* eslint-disable no-console -- This command reports bootstrap progress. */
import {
	createReadStream,
	existsSync,
	mkdirSync,
	writeFileSync,
} from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';

const repositoryRoot = resolve(
	dirname( fileURLToPath( import.meta.url ) ),
	'../..'
);
const snapshotEnvironment = resolve( repositoryRoot, 'tools/snapshot/env' );
const sourceRoot = process.env.PRESENTER_SNAPSHOT_SOURCE
	? resolve( process.env.PRESENTER_SNAPSHOT_SOURCE )
	: resolve( repositoryRoot, '..' );
const sqlPath = resolve( sourceRoot, 'aarondcampbell.sql' );
const privateLocalRoot = resolve( repositoryRoot, 'local' );
const wpEnv = resolve(
	repositoryRoot,
	'node_modules/@wordpress/env/bin/wp-env'
);
const localOrigin = 'http://localhost:8890';
const expectedPrefix = 'NVwKgD_';
const password = process.env.PRESENTER_SNAPSHOT_ADMIN_PASSWORD;
const arguments_ = new Set( process.argv.slice( 2 ) );
const childEnvironment = { ...process.env };
delete childEnvironment.PRESENTER_SNAPSHOT_ADMIN_PASSWORD;

const help = `Usage: node tools/snapshot/bootstrap.mjs [--validate]

Build the isolated Presenter production snapshot from verified private sources.

Environment:
  PRESENTER_SNAPSHOT_ADMIN_PASSWORD  Required local administrator password
                                     (at least 16 bytes; never logged).
  PRESENTER_SNAPSHOT_SOURCE          Optional directory containing the SQL and
                                     wp-content archive. Defaults to the
                                     repository's parent directory.

Options:
  --validate  Verify prerequisites and private source integrity without writing,
              starting containers, or importing data.
  --help      Show this help text.
`;

if ( arguments_.has( '--help' ) ) {
	console.log( help );
	process.exit( 0 );
}

if ( [ ...arguments_ ].some( ( argument ) => argument !== '--validate' ) ) {
	throw new Error( 'Unknown argument. Run with --help for usage.' );
}

if ( typeof password !== 'string' || Buffer.byteLength( password ) < 16 ) {
	throw new Error(
		'PRESENTER_SNAPSHOT_ADMIN_PASSWORD must contain at least 16 bytes.'
	);
}

for ( const requiredPath of [ snapshotEnvironment, wpEnv ] ) {
	if ( ! existsSync( requiredPath ) ) {
		throw new Error( 'A required snapshot bootstrap path is missing.' );
	}
}

function run( command, args, options = {} ) {
	return new Promise( ( resolveRun, reject ) => {
		const child = spawn( command, args, {
			cwd: options.cwd || repositoryRoot,
			env: childEnvironment,
			stdio: [ 'pipe', 'pipe', 'pipe' ],
			windowsHide: true,
		} );
		let stdout = '';
		let stderr = '';

		child.stdout.on( 'data', ( chunk ) => {
			stdout += chunk.toString();
			process.stdout.write( chunk );
		} );
		child.stderr.on( 'data', ( chunk ) => {
			stderr += chunk.toString();
			process.stderr.write( chunk );
		} );
		child.on( 'error', reject );
		child.on( 'close', ( code ) => {
			if ( code !== 0 ) {
				reject(
					new Error(
						options.failureMessage ||
							`Snapshot command failed with exit code ${ code }.`
					)
				);
				return;
			}

			resolveRun( { stdout, stderr } );
		} );

		if ( options.inputStream ) {
			options.inputStream.on( 'error', ( error ) => {
				child.stdin.destroy( error );
			} );
			options.inputStream.pipe( child.stdin );
		} else {
			child.stdin.end( options.input || '' );
		}
	} );
}

function runNodeScript( relativePath, options = {} ) {
	return run(
		process.execPath,
		[ resolve( repositoryRoot, relativePath ) ],
		options
	);
}

function runWp( args, options = {} ) {
	return run(
		process.execPath,
		[
			wpEnv,
			'run',
			'cli',
			'--env-cwd=wp-content/plugins/presenter',
			'--',
			'wp',
			...args,
		],
		{
			...options,
			cwd: snapshotEnvironment,
		}
	);
}

console.log( 'Verifying private snapshot sources.' );
await runNodeScript( 'tools/snapshot/verify-sources.mjs' );

if ( arguments_.has( '--validate' ) ) {
	console.log(
		'Snapshot bootstrap prerequisites are valid; no changes made.'
	);
	process.exit( 0 );
}

async function bootstrap() {
	mkdirSync( privateLocalRoot, { recursive: true } );
	writeFileSync(
		resolve( privateLocalRoot, '.htaccess' ),
		'Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n',
		'utf8'
	);

	console.log( 'Preparing the non-executable uploads mount.' );
	await runNodeScript( 'tools/snapshot/prepare-uploads.mjs' );

	console.log( 'Starting the loopback-only snapshot environment.' );
	await runNodeScript( 'tools/snapshot/start.mjs', {
		failureMessage: 'Unable to start the isolated snapshot environment.',
	} );

	console.log( 'Resetting the isolated database.' );
	await runWp( [
		'db',
		'reset',
		'--yes',
		'--skip-plugins',
		'--skip-themes',
	] );
	await runWp( [
		'config',
		'set',
		'table_prefix',
		expectedPrefix,
		'--type=variable',
		'--skip-plugins',
		'--skip-themes',
	] );
	const prefixResult = await runWp( [
		'config',
		'get',
		'table_prefix',
		'--type=variable',
		'--skip-plugins',
		'--skip-themes',
	] );
	if ( prefixResult.stdout.trim() !== expectedPrefix ) {
		throw new Error( 'The isolated database prefix assertion failed.' );
	}

	console.log( 'Streaming the verified SQL into the isolated database.' );
	await runWp( [ 'db', 'import', '-', '--skip-plugins', '--skip-themes' ], {
		inputStream: createReadStream( sqlPath ),
		failureMessage: 'Unable to import the verified snapshot SQL.',
	} );

	console.log( 'Restricting the snapshot to approved plugins.' );
	await runWp( [
		'plugin',
		'deactivate',
		'--all',
		'--skip-plugins',
		'--skip-themes',
	] );
	await runWp( [
		'option',
		'update',
		'active_plugins',
		'[]',
		'--format=json',
		'--skip-plugins',
		'--skip-themes',
	] );
	await runWp( [
		'plugin',
		'activate',
		'presenter',
		'--skip-plugins',
		'--skip-themes',
	] );
	await runWp( [
		'plugin',
		'activate',
		'aarondcampbell-presenter-themes',
		'--skip-plugins',
		'--skip-themes',
	] );

	console.log( 'Replacing production URLs with the isolated local origin.' );
	for ( const productionOrigin of [
		'https://www.aarondcampbell.com',
		'http://www.aarondcampbell.com',
		'https://aarondcampbell.com',
		'http://aarondcampbell.com',
	] ) {
		await runWp( [
			'search-replace',
			productionOrigin,
			localOrigin,
			'--all-tables-with-prefix',
			'--precise',
			'--recurse-objects',
			'--report-changed-only',
			'--skip-plugins',
			'--skip-themes',
		] );
	}
	await runWp( [ 'option', 'update', 'home', localOrigin ] );
	await runWp( [ 'option', 'update', 'siteurl', localOrigin ] );
	await runWp( [ 'option', 'update', 'blog_public', '0' ] );
	await runWp( [ 'rewrite', 'flush', '--hard' ] );

	console.log(
		'Replacing imported credentials with local-only credentials.'
	);
	await runWp(
		[
			'eval-file',
			'tools/snapshot/bootstrap-users.php',
			'--skip-plugins',
			'--skip-themes',
		],
		{ input: password }
	);

	console.log( 'Running final snapshot safety assertions.' );
	const assertionResult = await runWp(
		[ 'eval-file', 'tools/snapshot/assert-bootstrap.php' ],
		{ input: password }
	);
	if (
		! assertionResult.stdout.includes( 'PRESENTER_SNAPSHOT_BOOTSTRAP_OK' )
	) {
		throw new Error(
			'The snapshot safety assertion marker was not returned.'
		);
	}

	console.log(
		'The isolated Presenter snapshot is ready at http://localhost:8890.'
	);
}

try {
	await bootstrap();
} catch ( error ) {
	console.error(
		'Snapshot bootstrap stopped. Destroy the isolated snapshot environment and rebuild it from the beginning before retrying.'
	);
	throw error;
}
