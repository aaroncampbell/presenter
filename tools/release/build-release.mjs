import { createHash } from 'node:crypto';
import {
	copyFile,
	lstat,
	mkdir,
	readdir,
	readFile,
	realpath,
	rm,
	writeFile,
} from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { deflateRawSync, inflateRawSync } from 'node:zlib';

const VERSION = '2.0.0';
const PLUGIN_DIRECTORY = 'presenter';
const scriptDirectory = path.dirname( fileURLToPath( import.meta.url ) );
const repositoryRoot = path.resolve( scriptDirectory, '..', '..' );
const releaseRoot = path.join( repositoryRoot, 'release' );
const stagedPluginRoot = path.join( releaseRoot, PLUGIN_DIRECTORY );
const archivePath = path.join( releaseRoot, `presenter-${ VERSION }.zip` );
const manifestPath = path.join(
	releaseRoot,
	`presenter-${ VERSION }.manifest.json`
);
const checksumPath = path.join( releaseRoot, `presenter-${ VERSION }.sha256` );
const SOURCE_REPOSITORY_URL = 'https://github.com/aaroncampbell/presenter';
const THIRD_PARTY_COMPONENTS = [
	[ 'Chart.js', '4.5.1', 'MIT' ],
	[ '@kurkle/color', '0.3.4', 'MIT' ],
	[ 'Reveal.js', '4.3.1', 'MIT' ],
	[ 'Reveal.js', '6.0.1', 'MIT' ],
	[ 'Marked', '4.0.12', 'MIT' ],
	[ 'Marked', '17.0.5', 'MIT' ],
	[ 'Highlight.js', '10.7.2', 'BSD-3-Clause' ],
	[ 'Highlight.js', '11.11.1', 'BSD-3-Clause' ],
	[ 'highlightjs-line-numbers.js', '2.8.0', 'MIT' ],
	[ 'core-js', '3.12.1', 'MIT' ],
	[ 'regenerator-runtime', '0.13.7', 'MIT' ],
	[ 'League Gothic', 'Reveal-bundled version', 'SIL Open Font License' ],
	[
		'Source Sans Pro',
		'Reveal-bundled version',
		'SIL Open Font License 1.1',
	],
];
const REQUIRED_LICENSE_FILES = [
	'docs/third-party-notices.md',
	'build/reveal/LICENSE',
	'licenses/BSD-3-Clause.txt',
	'licenses/MIT.txt',
	'reveal.js/LICENSE',
	'reveal.js/dist/theme/fonts/league-gothic/LICENSE',
	'reveal.js/dist/theme/fonts/source-sans-pro/LICENSE',
];
const REQUIRED_POLICY_FILES = [
	'CHANGELOG.md',
	'CONTRIBUTING.md',
	'SECURITY.md',
	'docs/release-checklist.md',
];

const INCLUDED_FILES = [
	'CHANGELOG.md',
	'CONTRIBUTING.md',
	'README.md',
	'SECURITY.md',
	'presenter.php',
	'readme.txt',
	'reveal.js/LICENSE',
];
const INCLUDED_DIRECTORIES = [
	'blocks',
	'build',
	'css',
	'docs',
	'includes',
	'js',
	'languages',
	'licenses',
	'templates',
	'reveal.js/dist',
	'reveal.js/plugin/highlight',
	'reveal.js/plugin/markdown',
	'reveal.js/plugin/notes',
	'reveal.js/plugin/search',
	'reveal.js/plugin/zoom',
];
const FORBIDDEN_REMOTE_MATH_DEFAULTS = [
	'cdn.jsdelivr.net/npm/katex',
	'cdn.jsdelivr.net/npm/mathjax',
];
const FORBIDDEN_SEGMENTS = new Set( [
	'.git',
	'.github',
	'local',
	'node_modules',
	'release',
	'src',
	'tests',
	'tools',
	'vendor',
] );
const ZIP_LOCAL_HEADER = 0x04034b50;
const ZIP_CENTRAL_HEADER = 0x02014b50;
const ZIP_END = 0x06054b50;
const ZIP_FLAGS = 0x0800;
const ZIP_METHOD = 8;
const ZIP_DATE = 0x0021;
const ZIP_TIME = 0;

const sha256 = ( value ) =>
	createHash( 'sha256' ).update( value ).digest( 'hex' );

/* eslint-disable no-bitwise -- ZIP CRC-32 is defined in terms of bitwise operations. */
const crcTable = Array.from( { length: 256 }, ( _, index ) => {
	let value = index;
	for ( let bit = 0; bit < 8; bit++ ) {
		value = value & 1 ? 0xedb88320 ^ ( value >>> 1 ) : value >>> 1;
	}
	return value >>> 0;
} );

function crc32( value ) {
	let crc = 0xffffffff;
	for ( const byte of value ) {
		crc = crcTable[ ( crc ^ byte ) & 0xff ] ^ ( crc >>> 8 );
	}
	return ( crc ^ 0xffffffff ) >>> 0;
}
/* eslint-enable no-bitwise */

function assertInside( parent, candidate, label ) {
	const relative = path.relative( parent, candidate );
	if (
		relative === '' ||
		relative.startsWith( '..' ) ||
		path.isAbsolute( relative )
	) {
		throw new Error( `${ label } must be inside ${ parent }.` );
	}
}

async function collectDirectory( relativeDirectory ) {
	const absoluteDirectory = path.join( repositoryRoot, relativeDirectory );
	const entries = [];

	async function visit( directory ) {
		for ( const item of await readdir( directory, {
			withFileTypes: true,
		} ) ) {
			const absolutePath = path.join( directory, item.name );
			const stat = await lstat( absolutePath );
			if ( stat.isSymbolicLink() ) {
				throw new Error(
					`Release input may not be a symlink: ${ absolutePath }`
				);
			}
			if ( stat.isDirectory() ) {
				await visit( absolutePath );
			} else if ( stat.isFile() ) {
				entries.push(
					path
						.relative( repositoryRoot, absolutePath )
						.replaceAll( '\\', '/' )
				);
			}
		}
	}

	await visit( absoluteDirectory );
	return entries;
}

async function expectedFiles() {
	const files = [ ...INCLUDED_FILES ];
	for ( const directory of INCLUDED_DIRECTORIES ) {
		files.push( ...( await collectDirectory( directory ) ) );
	}
	return [ ...new Set( files ) ].sort();
}

function assertSafeEntry( relativePath ) {
	const segments = relativePath.split( '/' );
	if (
		relativePath.startsWith( '/' ) ||
		segments.includes( '..' ) ||
		segments.some( ( segment ) => FORBIDDEN_SEGMENTS.has( segment ) )
	) {
		throw new Error( `Forbidden release path: ${ relativePath }` );
	}
}

async function assertVersions() {
	const plugin = await readFile(
		path.join( repositoryRoot, 'presenter.php' ),
		'utf8'
	);
	const readme = await readFile(
		path.join( repositoryRoot, 'readme.txt' ),
		'utf8'
	);
	const packageMetadata = JSON.parse(
		await readFile( path.join( repositoryRoot, 'package.json' ), 'utf8' )
	);
	const bootstrap = await readFile(
		path.join( repositoryRoot, 'includes/class-bootstrap.php' ),
		'utf8'
	);

	if ( ! plugin.includes( ` * Version: ${ VERSION }` ) ) {
		throw new Error( 'Plugin header version is not synchronized.' );
	}
	if ( ! readme.includes( `Stable tag: ${ VERSION }` ) ) {
		throw new Error( 'WordPress readme stable tag is not synchronized.' );
	}
	if ( packageMetadata.version !== VERSION ) {
		throw new Error( 'Package metadata version is not synchronized.' );
	}
	if ( ! bootstrap.includes( `private const VERSION = '${ VERSION }';` ) ) {
		throw new Error( 'Runtime version is not synchronized.' );
	}

	for ( const block of [ 'chart', 'deck', 'slide' ] ) {
		const metadata = JSON.parse(
			await readFile(
				path.join( repositoryRoot, `blocks/${ block }/block.json` ),
				'utf8'
			)
		);
		if ( metadata.version !== VERSION ) {
			throw new Error( `${ block } block version is not synchronized.` );
		}
	}
}

function createZip( entries ) {
	const localParts = [];
	const centralParts = [];
	let localOffset = 0;

	for ( const entry of entries ) {
		const name = Buffer.from( entry.archivePath, 'utf8' );
		const compressed = deflateRawSync( entry.contents, { level: 9 } );
		const crc = crc32( entry.contents );
		const local = Buffer.alloc( 30 );
		local.writeUInt32LE( ZIP_LOCAL_HEADER, 0 );
		local.writeUInt16LE( 20, 4 );
		local.writeUInt16LE( ZIP_FLAGS, 6 );
		local.writeUInt16LE( ZIP_METHOD, 8 );
		local.writeUInt16LE( ZIP_TIME, 10 );
		local.writeUInt16LE( ZIP_DATE, 12 );
		local.writeUInt32LE( crc, 14 );
		local.writeUInt32LE( compressed.length, 18 );
		local.writeUInt32LE( entry.contents.length, 22 );
		local.writeUInt16LE( name.length, 26 );
		local.writeUInt16LE( 0, 28 );
		localParts.push( local, name, compressed );

		const central = Buffer.alloc( 46 );
		central.writeUInt32LE( ZIP_CENTRAL_HEADER, 0 );
		central.writeUInt16LE( 0x0314, 4 );
		central.writeUInt16LE( 20, 6 );
		central.writeUInt16LE( ZIP_FLAGS, 8 );
		central.writeUInt16LE( ZIP_METHOD, 10 );
		central.writeUInt16LE( ZIP_TIME, 12 );
		central.writeUInt16LE( ZIP_DATE, 14 );
		central.writeUInt32LE( crc, 16 );
		central.writeUInt32LE( compressed.length, 20 );
		central.writeUInt32LE( entry.contents.length, 24 );
		central.writeUInt16LE( name.length, 28 );
		central.writeUInt16LE( 0, 30 );
		central.writeUInt16LE( 0, 32 );
		central.writeUInt16LE( 0, 34 );
		central.writeUInt16LE( 0, 36 );
		central.writeUInt32LE( 0x81a40000, 38 );
		central.writeUInt32LE( localOffset, 42 );
		centralParts.push( central, name );
		localOffset += local.length + name.length + compressed.length;
	}

	const centralDirectory = Buffer.concat( centralParts );
	const end = Buffer.alloc( 22 );
	end.writeUInt32LE( ZIP_END, 0 );
	end.writeUInt16LE( 0, 4 );
	end.writeUInt16LE( 0, 6 );
	end.writeUInt16LE( entries.length, 8 );
	end.writeUInt16LE( entries.length, 10 );
	end.writeUInt32LE( centralDirectory.length, 12 );
	end.writeUInt32LE( localOffset, 16 );
	end.writeUInt16LE( 0, 20 );

	return Buffer.concat( [ ...localParts, centralDirectory, end ] );
}

function inspectZip( archive ) {
	let endOffset = -1;
	for ( let offset = archive.length - 22; offset >= 0; offset-- ) {
		if ( archive.readUInt32LE( offset ) === ZIP_END ) {
			endOffset = offset;
			break;
		}
	}
	if ( endOffset < 0 ) {
		throw new Error(
			'Release ZIP has no end-of-central-directory record.'
		);
	}

	const count = archive.readUInt16LE( endOffset + 10 );
	let offset = archive.readUInt32LE( endOffset + 16 );
	const entries = [];
	for ( let index = 0; index < count; index++ ) {
		if ( archive.readUInt32LE( offset ) !== ZIP_CENTRAL_HEADER ) {
			throw new Error( 'Release ZIP central directory is malformed.' );
		}
		const compressedSize = archive.readUInt32LE( offset + 20 );
		const size = archive.readUInt32LE( offset + 24 );
		const nameLength = archive.readUInt16LE( offset + 28 );
		const extraLength = archive.readUInt16LE( offset + 30 );
		const commentLength = archive.readUInt16LE( offset + 32 );
		const localHeaderOffset = archive.readUInt32LE( offset + 42 );
		const name = archive
			.subarray( offset + 46, offset + 46 + nameLength )
			.toString( 'utf8' );
		if ( archive.readUInt32LE( localHeaderOffset ) !== ZIP_LOCAL_HEADER ) {
			throw new Error(
				`Release ZIP local header is missing for ${ name }.`
			);
		}
		const localNameLength = archive.readUInt16LE( localHeaderOffset + 26 );
		const localExtraLength = archive.readUInt16LE( localHeaderOffset + 28 );
		const dataOffset =
			localHeaderOffset + 30 + localNameLength + localExtraLength;
		const contents = inflateRawSync(
			archive.subarray( dataOffset, dataOffset + compressedSize )
		);
		if (
			contents.length !== size ||
			crc32( contents ) !== archive.readUInt32LE( offset + 16 )
		) {
			throw new Error(
				`Release ZIP integrity check failed for ${ name }.`
			);
		}
		entries.push( { archivePath: name, contents } );
		offset += 46 + nameLength + extraLength + commentLength;
	}
	return entries;
}

function assertPackagedMarkdownLinks( contentsByPath ) {
	const markdownLink = /\]\(([^)\s]+\.md)(?:#[^)]+)?\)/g;

	for ( const [ sourcePath, contents ] of contentsByPath ) {
		if ( ! sourcePath.endsWith( '.md' ) ) {
			continue;
		}

		const source = contents.toString( 'utf8' );
		for ( const match of source.matchAll( markdownLink ) ) {
			const target = match[ 1 ];
			if ( /^(?:https?:)?\/\//.test( target ) ) {
				continue;
			}

			const resolvedTarget = path.posix.normalize(
				path.posix.join( path.posix.dirname( sourcePath ), target )
			);
			if (
				! resolvedTarget.startsWith( `${ PLUGIN_DIRECTORY }/` ) ||
				! contentsByPath.has( resolvedTarget )
			) {
				throw new Error(
					`Packaged Markdown link does not resolve: ${ sourcePath } -> ${ target }.`
				);
			}
		}
	}
}

function assertReleasePolicyMetadata( entries ) {
	const contentsByPath = new Map(
		entries.map( ( entry ) => [ entry.archivePath, entry.contents ] )
	);
	assertPackagedMarkdownLinks( contentsByPath );
	const readmePath = `${ PLUGIN_DIRECTORY }/readme.txt`;
	const noticesPath = `${ PLUGIN_DIRECTORY }/docs/third-party-notices.md`;
	const securityPath = `${ PLUGIN_DIRECTORY }/SECURITY.md`;
	const changelogPath = `${ PLUGIN_DIRECTORY }/CHANGELOG.md`;
	const checklistPath = `${ PLUGIN_DIRECTORY }/docs/release-checklist.md`;
	const readme = contentsByPath.get( readmePath )?.toString( 'utf8' );
	const notices = contentsByPath.get( noticesPath )?.toString( 'utf8' );
	const security = contentsByPath.get( securityPath )?.toString( 'utf8' );
	const changelog = contentsByPath.get( changelogPath )?.toString( 'utf8' );
	const checklist = contentsByPath.get( checklistPath )?.toString( 'utf8' );

	if (
		! readme?.includes( SOURCE_REPOSITORY_URL ) ||
		! readme.includes( 'docs/tooling.md' ) ||
		! readme.includes( 'docs/third-party-notices.md' ) ||
		! readme.includes( 'SECURITY.md' )
	) {
		throw new Error(
			'Release readme must link public source, build instructions, third-party notices, and the security policy.'
		);
	}

	if (
		! security?.includes(
			'https://github.com/aaroncampbell/presenter/security/advisories/new'
		) ||
		! security.includes( 'https://aarondcampbell.com/contact/' ) ||
		! changelog?.includes( `## ${ VERSION }` ) ||
		! checklist?.includes( 'explicit maintainer authorization' ) ||
		! checklist.includes( 'plugin-check:release' )
	) {
		throw new Error(
			'Release security, changelog, or authorization policy is incomplete.'
		);
	}

	for ( const [ component, version, license ] of THIRD_PARTY_COMPONENTS ) {
		const record = `| ${ component } | ${ version } | ${ license } |`;
		if ( ! notices?.includes( record ) ) {
			throw new Error(
				`Third-party inventory is missing ${ component } ${ version } (${ license }).`
			);
		}
	}

	for ( const relativePath of REQUIRED_LICENSE_FILES ) {
		if (
			! contentsByPath.has( `${ PLUGIN_DIRECTORY }/${ relativePath }` )
		) {
			throw new Error(
				`Release package is missing license metadata: ${ relativePath }.`
			);
		}
	}

	for ( const relativePath of REQUIRED_POLICY_FILES ) {
		if (
			! contentsByPath.has( `${ PLUGIN_DIRECTORY }/${ relativePath }` )
		) {
			throw new Error(
				`Release package is missing project policy: ${ relativePath }.`
			);
		}
	}

	for ( const entry of entries ) {
		if ( ! /\.(?:js|php)$/.test( entry.archivePath ) ) {
			continue;
		}

		const contents = entry.contents.toString( 'utf8' ).toLowerCase();
		for ( const remoteDefault of FORBIDDEN_REMOTE_MATH_DEFAULTS ) {
			if ( contents.includes( remoteDefault ) ) {
				throw new Error(
					`Release package contains a remote math runtime default: ${ entry.archivePath }.`
				);
			}
		}
	}
}

async function sourceEntries() {
	const entries = [];
	for ( const relativePath of await expectedFiles() ) {
		assertSafeEntry( relativePath );
		const absolutePath = path.join( repositoryRoot, relativePath );
		const resolvedPath = await realpath( absolutePath );
		assertInside( repositoryRoot, resolvedPath, 'Release source' );
		entries.push( {
			relativePath,
			archivePath: `${ PLUGIN_DIRECTORY }/${ relativePath }`,
			contents: await readFile( resolvedPath ),
		} );
	}
	return entries;
}

function releaseManifest( entries, archive ) {
	return {
		schemaVersion: 1,
		plugin: PLUGIN_DIRECTORY,
		version: VERSION,
		archive: path.basename( archivePath ),
		archiveBytes: archive.length,
		archiveSha256: sha256( archive ),
		fileCount: entries.length,
		uncompressedBytes: entries.reduce(
			( total, entry ) => total + entry.contents.length,
			0
		),
		files: entries.map( ( entry ) => ( {
			path: entry.archivePath,
			bytes: entry.contents.length,
			sha256: sha256( entry.contents ),
		} ) ),
	};
}

async function verify( archive, manifest ) {
	const inspected = inspectZip( archive );
	assertReleasePolicyMetadata( inspected );
	const expectedPaths = manifest.files.map( ( file ) => file.path );
	const inspectedPaths = inspected.map( ( entry ) => entry.archivePath );
	if (
		JSON.stringify( inspectedPaths ) !== JSON.stringify( expectedPaths )
	) {
		throw new Error( 'Release ZIP entry set does not match its manifest.' );
	}
	if (
		manifest.version !== VERSION ||
		manifest.archiveSha256 !== sha256( archive ) ||
		manifest.archiveBytes !== archive.length ||
		manifest.fileCount !== inspected.length
	) {
		throw new Error( 'Release manifest summary is invalid.' );
	}
	for ( let index = 0; index < inspected.length; index++ ) {
		const entry = inspected[ index ];
		const record = manifest.files[ index ];
		if (
			record.bytes !== entry.contents.length ||
			record.sha256 !== sha256( entry.contents ) ||
			! entry.archivePath.startsWith( `${ PLUGIN_DIRECTORY }/` )
		) {
			throw new Error(
				`Release manifest mismatch: ${ entry.archivePath }`
			);
		}
		assertSafeEntry(
			entry.archivePath.slice( `${ PLUGIN_DIRECTORY }/`.length )
		);
	}
}

async function build() {
	await assertVersions();
	assertInside( repositoryRoot, releaseRoot, 'Release output' );
	await rm( releaseRoot, { force: true, recursive: true } );
	await mkdir( stagedPluginRoot, { recursive: true } );

	const entries = await sourceEntries();
	for ( const entry of entries ) {
		const destination = path.join( stagedPluginRoot, entry.relativePath );
		assertInside( releaseRoot, destination, 'Staged release file' );
		await mkdir( path.dirname( destination ), { recursive: true } );
		await copyFile(
			path.join( repositoryRoot, entry.relativePath ),
			destination
		);
	}

	const archive = createZip( entries );
	const repeat = createZip( entries );
	if ( ! archive.equals( repeat ) ) {
		throw new Error( 'Release ZIP construction is nondeterministic.' );
	}
	const manifest = releaseManifest( entries, archive );
	await writeFile( archivePath, archive );
	await writeFile(
		manifestPath,
		`${ JSON.stringify( manifest, null, 2 ) }\n`
	);
	await writeFile(
		checksumPath,
		`${ manifest.archiveSha256 }  ${ path.basename( archivePath ) }\n`
	);
	await verify( archive, manifest );
	return manifest;
}

async function verifyExisting() {
	const archive = await readFile( archivePath );
	const manifest = JSON.parse( await readFile( manifestPath, 'utf8' ) );
	await verify( archive, manifest );
	return manifest;
}

const manifest = process.argv.includes( '--verify' )
	? await verifyExisting()
	: await build();

process.stdout.write(
	`Verified ${ manifest.archive } (${ manifest.fileCount } files, ${ manifest.archiveSha256 }).\n`
);
