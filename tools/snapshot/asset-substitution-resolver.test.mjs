import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtemp, mkdir, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
	AssetSubstitutionError,
	loadAssetSubstitutionResolver,
} from './asset-substitution-resolver.mjs';
import { SNAPSHOT_SOURCE_SHA256 } from './source-identity.mjs';

const sha256 = ( value ) =>
	createHash( 'sha256' ).update( value ).digest( 'hex' );

const fixture = async () => {
	const root = await mkdtemp( path.join( tmpdir(), 'presenter-assets-' ) );
	const artifacts = root;
	const artifactPath = 'wp-content/uploads/2026/07/missing.avif';
	const body = Buffer.concat( [
		Buffer.from( [ 0, 0, 0, 16 ] ),
		Buffer.from( 'ftypavif' ),
		Buffer.alloc( 4 ),
	] );
	await mkdir( path.join( artifacts, 'wp-content/uploads/2026/07' ), {
		recursive: true,
	} );
	await writeFile( path.join( artifacts, artifactPath ), body );
	const manifest = {
		schemaVersion: 2,
		snapshotSha256: { ...SNAPSHOT_SOURCE_SHA256 },
		entries: [
			{
				kind: 'same-origin-missing',
				sourceUrls: [
					'http://localhost:8890/wp-content/uploads/2026/07/missing.jpg',
				],
				artifactPath,
				byteLength: body.byteLength,
				sha256: sha256( body ),
				mimeType: 'image/avif',
				resourceType: 'image',
			},
		],
	};
	const manifestPath = path.join( root, 'manifest.json' );
	const writeManifest = () =>
		writeFile( manifestPath, JSON.stringify( manifest ) );
	await writeManifest();
	return { artifacts, body, manifest, manifestPath, root, writeManifest };
};

const rejectsCode = async ( promise, code ) =>
	assert.rejects(
		promise,
		( error ) =>
			error instanceof AssetSubstitutionError && error.code === code
	);

test( 'loads only exact, source-bound, digest-verified substitutions', async () => {
	const value = await fixture();
	const resolver = await loadAssetSubstitutionResolver( {
		artifactRoot: value.artifacts,
		expectedOrigin: 'http://localhost:8890',
		manifestPath: value.manifestPath,
	} );
	const resolved = resolver.resolve(
		value.manifest.entries[ 0 ].sourceUrls[ 0 ]
	);
	assert.equal( resolver.entryCount, 1 );
	assert.deepEqual( resolved.body, value.body );
	assert.equal( resolved.mimeType, 'image/avif' );
	assert.equal( resolved.resourceType, 'image' );
	assert.match( resolved.entryDigest, /^[a-f0-9]{64}$/u );
	assert.equal(
		resolver.resolve(
			'http://localhost:8890/wp-content/uploads/2026/07/other.jpg'
		),
		null
	);
} );

test( 'rejects a manifest bound to a different source snapshot', async () => {
	const value = await fixture();
	value.manifest.snapshotSha256.database = '0'.repeat( 64 );
	await value.writeManifest();
	await rejectsCode(
		loadAssetSubstitutionResolver( {
			artifactRoot: value.artifacts,
			expectedOrigin: 'http://localhost:8890',
			manifestPath: value.manifestPath,
		} ),
		'asset-substitution-source-identity'
	);
} );

test( 'loads the exact same-origin capture stylesheet substitute', async () => {
	const value = await fixture();
	const source = `before
@import url(https://fonts.googleapis.com/css?family=Open+Sans:400italic,700italic,400,300,700);
after`;
	const body = source.replace(
		'@import url(https://fonts.googleapis.com/css?family=Open+Sans:400italic,700italic,400,300,700);',
		'/* Open Sans unavailable in the authoritative offline snapshot; using fallback. */'
	);
	await mkdir( path.join( value.artifacts, 'capture-assets' ), {
		recursive: true,
	} );
	await writeFile(
		path.join( value.artifacts, 'capture-assets/theme.css' ),
		body
	);
	const sourcePath = path.join( value.root, 'aaron-purple.css' );
	await writeFile( sourcePath, source );
	Object.assign( value.manifest.entries[ 0 ], {
		kind: 'same-origin-capture-substitute',
		sourceUrls: [
			'http://localhost:8890/wp-content/plugins/aarondcampbell-presenter-themes/aaron-purple/aaron-purple.css?ver=2.0.0-dev',
			'http://localhost:8890/wp-content/plugins/aarondcampbell-presenter-themes/aaron-purple/aaron-purple.css?ver=7.0.1',
		],
		artifactPath: 'capture-assets/theme.css',
		byteLength: Buffer.byteLength( body ),
		sha256: sha256( body ),
		mimeType: 'text/css',
		resourceType: 'stylesheet',
	} );
	await value.writeManifest();
	const resolver = await loadAssetSubstitutionResolver( {
		artifactRoot: value.artifacts,
		expectedOrigin: 'http://localhost:8890',
		manifestPath: value.manifestPath,
		stylesheetSourcePath: sourcePath,
	} );
	const [ native, legacy ] = value.manifest.entries[ 0 ].sourceUrls.map(
		( sourceUrl ) => resolver.resolve( sourceUrl )
	);
	assert.equal( native.resourceType, 'stylesheet' );
	assert.equal( legacy.resourceType, 'stylesheet' );
	assert.equal( native.entryDigest, legacy.entryDigest );
	assert.equal(
		resolver.resolve(
			'http://localhost:8890/wp-content/plugins/aarondcampbell-presenter-themes/aaron-purple/aaron-purple.css?ver=unknown'
		),
		null
	);
} );

test( 'rejects changed artifact bytes', async () => {
	const value = await fixture();
	await writeFile(
		path.join( value.artifacts, 'wp-content/uploads/2026/07/missing.avif' ),
		'changed image bytes'
	);
	await rejectsCode(
		loadAssetSubstitutionResolver( {
			artifactRoot: value.artifacts,
			expectedOrigin: 'http://localhost:8890',
			manifestPath: value.manifestPath,
		} ),
		'asset-substitution-artifact-identity'
	);
} );

test( 'rejects MIME spoofing, existing sources, and unrelated counterparts', async () => {
	const spoofed = await fixture();
	const invalid = Buffer.from( 'not an avif' );
	await writeFile(
		path.join(
			spoofed.artifacts,
			'wp-content/uploads/2026/07/missing.avif'
		),
		invalid
	);
	spoofed.manifest.entries[ 0 ].byteLength = invalid.byteLength;
	spoofed.manifest.entries[ 0 ].sha256 = sha256( invalid );
	await spoofed.writeManifest();
	await rejectsCode(
		loadAssetSubstitutionResolver( {
			artifactRoot: spoofed.artifacts,
			expectedOrigin: 'http://localhost:8890',
			manifestPath: spoofed.manifestPath,
		} ),
		'asset-substitution-artifact-type'
	);

	const present = await fixture();
	await writeFile(
		path.join(
			present.artifacts,
			'wp-content/uploads/2026/07/missing.jpg'
		),
		present.body
	);
	await rejectsCode(
		loadAssetSubstitutionResolver( {
			artifactRoot: present.artifacts,
			expectedOrigin: 'http://localhost:8890',
			manifestPath: present.manifestPath,
		} ),
		'asset-substitution-source-present'
	);

	const unrelated = await fixture();
	unrelated.manifest.entries[ 0 ].artifactPath =
		'wp-content/uploads/2026/07/unrelated.avif';
	await writeFile(
		path.join(
			unrelated.artifacts,
			unrelated.manifest.entries[ 0 ].artifactPath
		),
		unrelated.body
	);
	await unrelated.writeManifest();
	await rejectsCode(
		loadAssetSubstitutionResolver( {
			artifactRoot: unrelated.artifacts,
			expectedOrigin: 'http://localhost:8890',
			manifestPath: unrelated.manifestPath,
		} ),
		'asset-substitution-counterpart'
	);
} );

test( 'rejects duplicate URLs, URL variations, traversal, and symlinks', async () => {
	for ( const mutate of [
		( value ) => value.manifest.entries.push( value.manifest.entries[ 0 ] ),
		( value ) =>
			( value.manifest.entries[ 0 ].sourceUrls[ 0 ] += '?variation=1' ),
		( value ) => ( value.manifest.entries[ 0 ].artifactPath = '../escape' ),
		( value ) =>
			( value.manifest.entries[ 0 ].resourceType = 'stylesheet' ),
	] ) {
		const value = await fixture();
		mutate( value );
		await value.writeManifest();
		await assert.rejects(
			loadAssetSubstitutionResolver( {
				artifactRoot: value.artifacts,
				expectedOrigin: 'http://localhost:8890',
				manifestPath: value.manifestPath,
			} ),
			AssetSubstitutionError
		);
	}

	const value = await fixture();
	const target = path.join( value.root, 'outside.avif' );
	await writeFile( target, value.body );
	const linked = path.join(
		value.artifacts,
		'wp-content/uploads/2026/07/missing.avif'
	);
	await writeFile( linked, '' );
	await import( 'node:fs/promises' ).then( ( { unlink } ) =>
		unlink( linked )
	);
	await symlink( target, linked );
	await rejectsCode(
		loadAssetSubstitutionResolver( {
			artifactRoot: value.artifacts,
			expectedOrigin: 'http://localhost:8890',
			manifestPath: value.manifestPath,
		} ),
		'asset-substitution-artifact-path'
	);
} );
