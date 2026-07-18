/* eslint-disable no-console -- This command reports verification progress. */
import { createHash } from 'node:crypto';
import { createReadStream, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { spawnSync } from 'node:child_process';

const expected = {
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

export function resolveSourceRoot( configuredSource, root = repositoryRoot ) {
	return configuredSource
		? resolve( configuredSource )
		: resolve( root, '..' );
}

const sourceRoot = resolveSourceRoot( process.env.PRESENTER_SNAPSHOT_SOURCE );

function hashFile( path ) {
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

export function assertSafeArchivePath( path ) {
	if ( /^(?:[\\/]|[a-z]:[\\/])/i.test( path ) ) {
		throw new Error( 'Archive contains an absolute path.' );
	}

	if ( path.replaceAll( '\\', '/' ).split( '/' ).includes( '..' ) ) {
		throw new Error( 'Archive contains a parent-directory path segment.' );
	}
}

export function validateArchiveEntries( paths, verboseEntries ) {
	if ( paths.length !== verboseEntries.length ) {
		throw new Error(
			'Archive listings returned inconsistent entry counts.'
		);
	}

	let uploadEntries = 0;
	let excludedExecutableEntries = 0;

	for ( const [ index, path ] of paths.entries() ) {
		assertSafeArchivePath( path );

		const entryType = verboseEntries[ index ]?.[ 0 ];
		if ( entryType !== '-' && entryType !== 'd' ) {
			throw new Error(
				`Archive contains an unsupported entry type: ${ path }`
			);
		}

		const normalized = path.replaceAll( '\\', '/' );
		if ( /^(?:\.\/)?wp-content\/uploads\//.test( normalized ) ) {
			uploadEntries += 1;
			if ( executableUpload.test( normalized ) ) {
				excludedExecutableEntries += 1;
			}
		}
	}

	if ( uploadEntries === 0 ) {
		throw new Error( 'Archive contains no wp-content/uploads entries.' );
	}

	return { excludedExecutableEntries, uploadEntries };
}

async function main() {
	for ( const [ filename, expectedHash ] of Object.entries( expected ) ) {
		const path = resolve( sourceRoot, filename );

		if ( ! existsSync( path ) ) {
			throw new Error( `Missing snapshot source: ${ filename }` );
		}

		const actualHash = await hashFile( path );

		if ( actualHash !== expectedHash ) {
			throw new Error( `SHA-256 mismatch for ${ filename }.` );
		}

		console.log( `Verified ${ filename }.` );
	}

	const archive = resolve( sourceRoot, 'aarondcampbell-wp-content.tar.bz2' );
	const listing = spawnSync( 'tar', [ '-tjf', archive ], {
		encoding: 'utf8',
		maxBuffer: 64 * 1024 * 1024,
	} );
	const verboseListing = spawnSync( 'tar', [ '-tvjf', archive ], {
		encoding: 'utf8',
		maxBuffer: 64 * 1024 * 1024,
	} );

	if ( listing.error ) {
		throw listing.error;
	}

	if ( verboseListing.error ) {
		throw verboseListing.error;
	}

	if ( listing.status !== 0 || verboseListing.status !== 0 ) {
		throw new Error( 'Unable to inspect the wp-content archive.' );
	}

	const { excludedExecutableEntries, uploadEntries } = validateArchiveEntries(
		listing.stdout.split( /\r?\n/ ).filter( Boolean ),
		verboseListing.stdout.split( /\r?\n/ ).filter( Boolean )
	);

	console.log(
		`Archive paths and entry types are safe; found ${ uploadEntries } upload entries and ${ excludedExecutableEntries } executable-like upload entries to exclude.`
	);
}

if (
	process.argv[ 1 ] &&
	import.meta.url === pathToFileURL( resolve( process.argv[ 1 ] ) ).href
) {
	await main();
}
