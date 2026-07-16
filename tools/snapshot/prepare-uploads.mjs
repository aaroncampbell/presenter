/* eslint-disable no-console -- This command reports preparation progress. */
import { mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const executableUpload = /\.(?:cgi|phar|php\d*|phtml|pl|py|sh)$/i;
const repositoryRoot = resolve(
	dirname( fileURLToPath( import.meta.url ) ),
	'../..'
);
const sourceRoot = resolve(
	process.env.PRESENTER_SNAPSHOT_SOURCE || repositoryRoot,
	'..'
);
const snapshotRoot = resolve( repositoryRoot, 'local/snapshot' );
const uploadsRoot = resolve( snapshotRoot, 'wp-content/uploads' );
const archive = resolve( sourceRoot, 'aarondcampbell-wp-content.tar.bz2' );

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

	for ( const entry of readdirSync( directory, { withFileTypes: true } ) ) {
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
