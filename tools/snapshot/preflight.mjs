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

import { SNAPSHOT_SOURCE_SHA256 } from './source-identity.mjs';

const origin = new URL( 'http://localhost:8890' );
const expectedContentSecurityPolicy =
	"default-src 'self'; base-uri 'self'; connect-src 'self'; font-src 'self' data:; frame-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; object-src 'none'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; worker-src 'self' blob:;";
const expectedSourceHashes = {
	'aarondcampbell.sql': SNAPSHOT_SOURCE_SHA256.database,
	'aarondcampbell-wp-content.tar.bz2': SNAPSHOT_SOURCE_SHA256.wpContent,
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
const snapshotChartAssets = [
	{
		digest: '2963D382F7D6B8828971511E2F7B59C3DA23CB79BA4612A5771496E792676450',
		path: resolve(
			repositoryRoot,
			'tools/snapshot/assets/google-line-chart-compat.js'
		),
		publicPath:
			'/wp-content/presenter-snapshot-assets/google-charts/loader.js',
	},
	{
		digest: '6C2DCB0990B029E7A163A4F87C58BD55F394D20CED51AF92E1C9E422154F6791',
		path: resolve(
			repositoryRoot,
			'node_modules/chart.js-legacy/dist/chart.min.js'
		),
		publicPath:
			'/wp-content/presenter-snapshot-assets/chart.js/3.5.1/chart.min.js',
	},
];
const corpusFiles = [
	resolve( repositoryRoot, 'local/acceptance-corpus/corpus.json' ),
	resolve( repositoryRoot, 'local/acceptance-corpus/baseline/manifest.json' ),
];
const arguments_ = new Set( process.argv.slice( 2 ) );
const resumeSafe = arguments_.has( '--resume-safe' );

if ( [ ...arguments_ ].some( ( argument ) => argument !== '--resume-safe' ) ) {
	fail( 'arguments' );
}

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

/**
 * Verify the exact same-origin chart implementations used by the snapshot.
 *
 * Chart.js is installed from the pinned package lock and must retain the SRI
 * bytes stored in historical decks. The Google-compatible renderer is
 * independently authored and digest-bound here so visual evidence cannot
 * silently change its rendering basis.
 *
 * @return {number} Verified asset count.
 */
function verifySnapshotChartAssets() {
	for ( const asset of snapshotChartAssets ) {
		if (
			! existsSync( asset.path ) ||
			! lstatSync( asset.path ).isFile()
		) {
			fail( 'snapshot_chart_asset' );
		}

		const digest = createHash( 'sha256' )
			.update( readFileSync( asset.path ) )
			.digest( 'hex' )
			.toUpperCase();
		if ( digest !== asset.digest ) {
			fail( 'snapshot_chart_asset_identity' );
		}
	}

	return snapshotChartAssets.length;
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

function inspectDockerBindings() {
	const installPathCommand = spawnSync(
		process.execPath,
		[ wpEnv, 'install-path' ],
		{
			cwd: snapshotEnvironment,
			encoding: 'utf8',
			maxBuffer: 1024 * 1024,
		}
	);
	const installPath = installPathCommand.stdout
		.split( /\r?\n/ )
		.map( ( line ) => line.trim() )
		.find( ( line ) => /^[A-Za-z]:[\\/]/.test( line ) );

	if ( installPathCommand.status !== 0 || ! installPath ) {
		fail( 'docker_bindings' );
	}

	const projectName = installPath
		.replace( /[\\/]+$/, '' )
		.split( /[\\/]/ )
		.pop();
	const dockerCommand = spawnSync(
		'docker',
		[
			'ps',
			'--filter',
			`label=com.docker.compose.project=${ projectName }`,
			'--format',
			'{{.ID}}',
		],
		{
			encoding: 'utf8',
			maxBuffer: 1024 * 1024,
			windowsHide: true,
		}
	);
	const containerIds = dockerCommand.stdout
		.split( /\r?\n/ )
		.filter( Boolean );
	const publishedBindings = containerIds.flatMap( ( containerId ) => {
		const inspectCommand = spawnSync(
			'docker',
			[
				'inspect',
				containerId,
				'--format',
				'{{json .NetworkSettings.Ports}}',
			],
			{
				encoding: 'utf8',
				maxBuffer: 1024 * 1024,
				windowsHide: true,
			}
		);

		if ( inspectCommand.status !== 0 ) {
			fail( 'docker_bindings' );
		}

		let ports;
		try {
			ports = JSON.parse( inspectCommand.stdout.trim() );
		} catch {
			fail( 'docker_bindings' );
		}

		return Object.values( ports ).flatMap( ( bindings ) => bindings || [] );
	} );

	if (
		dockerCommand.status !== 0 ||
		containerIds.length === 0 ||
		publishedBindings.length === 0 ||
		publishedBindings.some(
			( binding ) =>
				binding.HostIp !== '127.0.0.1' ||
				! /^[0-9]+$/.test( binding.HostPort )
		)
	) {
		fail( 'docker_bindings' );
	}

	return publishedBindings.length;
}

function collectWordPressChecks() {
	const evaluation = resumeSafe
		? "define( 'PRESENTER_SNAPSHOT_RESUME_SAFE', true ); require '/var/www/html/wp-content/plugins/presenter/tools/snapshot/collect-preflight.php';"
		: "require '/var/www/html/wp-content/plugins/presenter/tools/snapshot/collect-preflight.php';";
	const command = spawnSync(
		process.execPath,
		[ wpEnv, 'run', 'cli', 'wp', 'eval', evaluation ],
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
	const inspect = ( path, validate ) =>
		new Promise( ( resolveHeaders ) => {
			const request = http.request(
				{
					hostname: origin.hostname,
					method: 'HEAD',
					path,
					port: origin.port,
				},
				( response ) => {
					response.resume();
					const contentType = response.headers[ 'content-type' ];
					const robots = response.headers[ 'x-robots-tag' ];
					const policy =
						response.headers[ 'content-security-policy' ];

					if (
						! validate(
							response.statusCode,
							robots,
							policy,
							contentType
						)
					) {
						fail( 'response_headers' );
					}

					resolveHeaders();
				}
			);

			request.setTimeout( 5000, () => request.destroy() );
			request.on( 'error', () => fail( 'snapshot_port' ) );
			request.end();
		} );

	return Promise.all( [
		inspect(
			'/',
			( status, robots, policy ) =>
				200 === status &&
				'noindex, nofollow, noarchive' === robots &&
				expectedContentSecurityPolicy === policy
		),
		inspect(
			'/wp-content/plugins/presenter/local/acceptance-corpus/.hmac-key',
			( status ) => 403 === status || 404 === status
		),
		inspect(
			'/wp-content/plugins/presenter/local/acceptance-corpus/corpus.json',
			( status ) => 403 === status || 404 === status
		),
	] ).then( () => 4 );
}

/**
 * Hash the exact JavaScript bytes served by each snapshot chart mount.
 *
 * @return {Promise<number>} Verified mount count.
 */
function inspectSnapshotChartMounts() {
	return Promise.all(
		snapshotChartAssets.map(
			( asset ) =>
				new Promise( ( resolveAsset ) => {
					const request = http.get(
						{
							hostname: origin.hostname,
							path: asset.publicPath,
							port: origin.port,
						},
						( response ) => {
							const contentType =
								response.headers[ 'content-type' ] ?? '';
							if (
								response.statusCode !== 200 ||
								! /^(?:application|text)\/javascript\b/u.test(
									contentType
								)
							) {
								fail( 'snapshot_chart_mount' );
							}

							const hash = createHash( 'sha256' );
							let size = 0;
							response.on( 'data', ( chunk ) => {
								size += chunk.length;
								if ( size > 1024 * 1024 ) {
									request.destroy();
									fail( 'snapshot_chart_mount_size' );
								}
								hash.update( chunk );
							} );
							response.on( 'end', () => {
								if (
									hash.digest( 'hex' ).toUpperCase() !==
									asset.digest
								) {
									fail( 'snapshot_chart_mount_identity' );
								}
								resolveAsset();
							} );
						}
					);

					request.setTimeout( 5000, () => request.destroy() );
					request.on( 'error', () => fail( 'snapshot_chart_mount' ) );
				} )
		)
	).then( () => snapshotChartAssets.length );
}

await verifySourceContinuity();
const snapshotChartAssetCount = verifySnapshotChartAssets();
const uploads = inspectUploads();
const dockerBindings = inspectDockerBindings();
const wordpress = collectWordPressChecks();
const headerCount = await inspectHeaders();
const snapshotChartMountCount = await inspectSnapshotChartMounts();

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
			dockerBindings,
			headers: headerCount,
			snapshotChartAssets: snapshotChartAssetCount,
			snapshotChartMounts: snapshotChartMountCount,
			sourceFiles: Object.keys( expectedSourceHashes ).length,
			uploadDirectories: uploads.directoryCount,
			uploadFiles: uploads.fileCount,
		},
	} )
);
