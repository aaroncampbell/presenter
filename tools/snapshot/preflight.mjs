/* eslint-disable no-console -- This command emits a content-free safety report. */
import { createHash } from 'node:crypto';
import {
	createReadStream,
	existsSync,
	lstatSync,
	readdirSync,
	readFileSync,
} from 'node:fs';
import http from 'node:http';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const origin = new URL( 'http://localhost:8890' );
const expectedContentSecurityPolicy =
	"default-src 'self'; base-uri 'self'; connect-src 'self'; font-src 'self' data:; frame-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; object-src 'none'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';";
const expectedSourceHashes = {
	'aarondcampbell.sql':
		'9DB21AD19A5A8DD8A75BF3C778508B1648629DDEDAB51BD7A8CF9A20512EA41A',
	'aarondcampbell-wp-content.tar.bz2':
		'708D35A7E7CEAD69F850C100F3A9C5185EF839C5D4DC72ECA0C4879B8306F298',
};
const executableUpload = /\.(?:cgi|phar|php\d*|phtml|pl|py|sh)$/i;
const repositoryRoot = resolve(
	dirname( fileURLToPath( import.meta.url ) ),
	'../..'
);
const snapshotEnvironment = resolve( repositoryRoot, 'tools/snapshot/env' );
const sourceRoot = process.env.PRESENTER_SNAPSHOT_SOURCE
	? resolve( process.env.PRESENTER_SNAPSHOT_SOURCE )
	: resolve( repositoryRoot, '..' );
const wpEnv = resolve(
	repositoryRoot,
	'node_modules/@wordpress/env/bin/wp-env'
);
const uploadsRoot = resolve(
	repositoryRoot,
	'local/snapshot/wp-content/uploads'
);
const corpusFiles = [
	resolve( repositoryRoot, 'local/acceptance-corpus/corpus.json' ),
	resolve( repositoryRoot, 'local/acceptance-corpus/baseline/manifest.json' ),
];

function fail( code ) {
	console.log( JSON.stringify( { status: 'fail', code } ) );
	process.exit( 1 );
}

function sha256File( path ) {
	return new Promise( ( resolveHash, reject ) => {
		const hash = createHash( 'sha256' );
		const stream = createReadStream( path );

		stream.on( 'error', reject );
		stream.on( 'data', ( chunk ) => hash.update( chunk ) );
		stream.on( 'end', () =>
			resolveHash( hash.digest( 'hex' ).toUpperCase() )
		);
	} );
}

async function verifySourceContinuity() {
	for ( const [ filename, expectedHash ] of Object.entries(
		expectedSourceHashes
	) ) {
		const path = resolve( sourceRoot, filename );

		if (
			! existsSync( path ) ||
			( await sha256File( path ) ) !== expectedHash
		) {
			fail( 'source_continuity' );
		}
	}

	for ( const path of corpusFiles ) {
		if ( ! existsSync( path ) ) {
			fail( 'corpus_continuity' );
		}

		let manifest;
		try {
			manifest = JSON.parse( readFileSync( path, 'utf8' ) );
		} catch {
			fail( 'corpus_continuity' );
		}

		if (
			manifest.snapshotSha256 !==
			expectedSourceHashes[ 'aarondcampbell.sql' ]
		) {
			fail( 'corpus_continuity' );
		}
	}
}

function inspectUploads() {
	if ( ! existsSync( uploadsRoot ) ) {
		fail( 'uploads_missing' );
	}

	let fileCount = 0;
	let directoryCount = 0;
	const pending = [ uploadsRoot ];

	while ( pending.length > 0 ) {
		const directory = pending.pop();
		const directoryStat = lstatSync( directory );

		if ( directoryStat.isSymbolicLink() || ! directoryStat.isDirectory() ) {
			fail( 'uploads_entry_type' );
		}

		directoryCount += 1;
		for ( const entry of readdirSync( directory ) ) {
			const path = resolve( directory, entry );
			const stat = lstatSync( path );

			if ( stat.isSymbolicLink() ) {
				fail( 'uploads_entry_type' );
			}

			if ( stat.isDirectory() ) {
				pending.push( path );
				continue;
			}

			if ( ! stat.isFile() ) {
				fail( 'uploads_entry_type' );
			}

			if ( executableUpload.test( entry ) ) {
				fail( 'uploads_executable' );
			}

			fileCount += 1;
		}
	}

	return { directoryCount, fileCount };
}

function collectWordPressChecks() {
	const command = spawnSync(
		process.execPath,
		[
			wpEnv,
			'run',
			'cli',
			'wp',
			'eval',
			"require '/var/www/html/wp-content/plugins/presenter/tools/snapshot/collect-preflight.php';",
		],
		{
			cwd: snapshotEnvironment,
			encoding: 'utf8',
			maxBuffer: 1024 * 1024,
		}
	);

	if ( command.error ) {
		fail( 'wordpress_preflight' );
	}

	const reportLine = command.stdout
		.split( /\r?\n/ )
		.map( ( line ) => line.trim() )
		.find( ( line ) => line.startsWith( '{"status":' ) );
	let result;
	try {
		result = JSON.parse( reportLine );
	} catch {
		fail( 'wordpress_preflight' );
	}

	if ( result.status !== 'pass' ) {
		const safeCode = /^[a-z_]+$/.test( result.code )
			? result.code
			: 'wordpress_preflight';
		fail( safeCode );
	}

	if ( command.status !== 0 ) {
		fail( 'wordpress_preflight' );
	}

	return result;
}

function inspectHeaders() {
	return new Promise( ( resolveHeaders ) => {
		const request = http.request(
			{
				hostname: origin.hostname,
				method: 'HEAD',
				path: '/',
				port: origin.port,
			},
			( response ) => {
				response.resume();
				const robots = response.headers[ 'x-robots-tag' ];
				const policy = response.headers[ 'content-security-policy' ];

				if (
					response.statusCode !== 200 ||
					'noindex, nofollow, noarchive' !== robots ||
					expectedContentSecurityPolicy !== policy
				) {
					fail( 'response_headers' );
				}

				resolveHeaders( 2 );
			}
		);

		request.setTimeout( 5000, () => request.destroy() );
		request.on( 'error', () => fail( 'snapshot_port' ) );
		request.end();
	} );
}

await verifySourceContinuity();
const uploads = inspectUploads();
const wordpress = collectWordPressChecks();
const headerCount = await inspectHeaders();

console.log(
	JSON.stringify( {
		status: 'pass',
		checks: {
			corpusContinuity: 'verified',
			headers: 'restricted',
			snapshot: 'isolated',
			uploads: 'nonExecutableRegularFiles',
			wordpress: wordpress.checks,
		},
		counts: {
			...wordpress.counts,
			corpusManifests: corpusFiles.length,
			headers: headerCount,
			sourceFiles: Object.keys( expectedSourceHashes ).length,
			uploadDirectories: uploads.directoryCount,
			uploadFiles: uploads.fileCount,
		},
	} )
);
