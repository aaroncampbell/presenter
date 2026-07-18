/* eslint-disable no-console -- This command reports preparation progress. */
import { mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { spawnSync } from 'node:child_process';

const executableUpload = /\.(?:cgi|phar|php\d*|phtml|pl|py|sh)$/i;
const repositoryRoot = resolve(
	dirname( fileURLToPath( import.meta.url ) ),
	'../..'
);
const sourceRoot = process.env.PRESENTER_SNAPSHOT_SOURCE
	? resolve( process.env.PRESENTER_SNAPSHOT_SOURCE )
	: resolve( repositoryRoot, '..' );
const snapshotRoot = resolve( repositoryRoot, 'local/snapshot' );
const uploadsRoot = resolve( snapshotRoot, 'wp-content/uploads' );
const archive = resolve( sourceRoot, 'aarondcampbell-wp-content.tar.bz2' );

function samePath( first, second ) {
	const normalize = ( path ) =>
		process.platform === 'win32'
			? resolve( path ).toLowerCase()
			: resolve( path );

	return normalize( first ) === normalize( second );
}

export function assertSnapshotNotMounted(
	target = uploadsRoot,
	runCommand = spawnSync
) {
	const containers = runCommand( 'docker', [ 'ps', '--quiet' ], {
		encoding: 'utf8',
	} );

	if ( containers.error?.code === 'ENOENT' ) {
		return;
	}

	if ( containers.error ) {
		throw containers.error;
	}

	if ( containers.status !== 0 ) {
		throw new Error(
			'Unable to confirm that the snapshot environment is stopped.'
		);
	}

	const containerIds = containers.stdout.split( /\s+/ ).filter( Boolean );
	if ( containerIds.length === 0 ) {
		return;
	}

	const inspection = runCommand( 'docker', [ 'inspect', ...containerIds ], {
		encoding: 'utf8',
		maxBuffer: 16 * 1024 * 1024,
	} );

	if ( inspection.error ) {
		throw inspection.error;
	}

	if ( inspection.status !== 0 ) {
		throw new Error(
			'Unable to confirm that the snapshot environment is stopped.'
		);
	}

	let inspectedContainers;
	try {
		inspectedContainers = JSON.parse( inspection.stdout );
	} catch {
		throw new Error( 'Docker returned invalid container inspection data.' );
	}

	const mounted = inspectedContainers.some( ( container ) =>
		( container.Mounts || [] ).some(
			( mount ) =>
				mount.Type === 'bind' &&
				typeof mount.Source === 'string' &&
				samePath( mount.Source, target )
		)
	);

	if ( mounted ) {
		throw new Error(
			'Stop the snapshot environment before preparing its uploads.'
		);
	}
}

function main() {
	assertSnapshotNotMounted();

	if ( ! uploadsRoot.startsWith( `${ snapshotRoot }${ sep }` ) ) {
		throw new Error(
			'Refusing to prepare uploads outside the snapshot directory.'
		);
	}

	rmSync( uploadsRoot, { force: true, recursive: true } );
	mkdirSync( uploadsRoot, { recursive: true } );

	const exclusionPatterns = [
		'*.cgi',
		'*.phar',
		'*.php',
		'*.php[0-9]',
		'*.phtml',
		'*.pl',
		'*.py',
		'*.sh',
	];
	const extractionArguments = [
		'-xjf',
		archive,
		'-C',
		uploadsRoot,
		'--strip-components=2',
		...exclusionPatterns.map( ( pattern ) => `--exclude=${ pattern }` ),
		'wp-content/uploads',
	];
	const extraction = spawnSync( 'tar', extractionArguments, {
		stdio: 'inherit',
	} );

	if ( extraction.error ) {
		throw extraction.error;
	}

	if ( extraction.status !== 0 ) {
		throw new Error( 'Unable to extract the snapshot uploads.' );
	}

	let fileCount = 0;
	const pendingDirectories = [ uploadsRoot ];

	while ( pendingDirectories.length > 0 ) {
		const directory = pendingDirectories.pop();

		for ( const entry of readdirSync( directory, {
			withFileTypes: true,
		} ) ) {
			const path = resolve( directory, entry.name );

			if ( entry.isDirectory() ) {
				pendingDirectories.push( path );
				continue;
			}

			if ( ! entry.isFile() ) {
				throw new Error(
					`Unsupported filesystem entry in uploads: ${ relative(
						uploadsRoot,
						path
					) }`
				);
			}

			if ( executableUpload.test( entry.name ) ) {
				throw new Error(
					`Executable-like upload was not excluded: ${ relative(
						uploadsRoot,
						path
					) }`
				);
			}

			fileCount += 1;
		}
	}

	writeFileSync(
		resolve( uploadsRoot, '.htaccess' ),
		`Options -Indexes -ExecCGI
<FilesMatch "\\.(?:cgi|phar|php[0-9]*|phtml|pl|py|sh)$">
    Require all denied
</FilesMatch>
`,
		{ encoding: 'utf8', flag: 'wx' }
	);

	console.log( `Prepared ${ fileCount } non-executable upload files.` );
}

if (
	process.argv[ 1 ] &&
	import.meta.url === pathToFileURL( resolve( process.argv[ 1 ] ) ).href
) {
	main();
}
