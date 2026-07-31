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
		schemaVersion: 3,
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

const stagingFixture = async () => {
	const value = await fixture();
	const assets = [
		{
			name: 'unknown-user.png',
			body: Buffer.concat( [
				Buffer.from( [
					0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a,
				] ),
				Buffer.from( 'png' ),
			] ),
			mimeType: 'image/png',
			resourceType: 'image',
		},
		...Array.from( { length: 3 }, ( unused, index ) => ( {
			name: `photo-${ index + 1 }.jpg`,
			body: Buffer.from( [ 0xff, 0xd8, 0xff, index ] ),
			mimeType: 'image/jpeg',
			resourceType: 'image',
		} ) ),
		{
			name: 'movie.mp4',
			body: Buffer.concat( [
				Buffer.from( [ 0, 0, 0, 12 ] ),
				Buffer.from( 'ftypisom' ),
			] ),
			mimeType: 'video/mp4',
			resourceType: 'media',
		},
		{
			name: 'movie.webm',
			body: Buffer.from( [ 0x1a, 0x45, 0xdf, 0xa3, 0 ] ),
			mimeType: 'video/webm',
			resourceType: 'media',
		},
		{
			name: 'movie.ogv',
			body: Buffer.from( 'OggSfixture' ),
			mimeType: 'video/ogg',
			resourceType: 'media',
		},
	];
	value.manifest.entries = [];
	await mkdir( path.join( value.artifacts, 'wp-content/uploads/2024/01' ), {
		recursive: true,
	} );
	for ( const asset of assets ) {
		const artifactPath = `wp-content/uploads/2024/01/${ asset.name }`;
		await writeFile(
			path.join( value.artifacts, artifactPath ),
			asset.body
		);
		value.manifest.entries.push( {
			kind: 'staging-origin-archive',
			sourceUrls: [
				`//aarondcampbell.mystagingwebsite.com/${ artifactPath }`,
			],
			artifactPath,
			byteLength: asset.body.byteLength,
			sha256: sha256( asset.body ),
			mimeType: asset.mimeType,
			resourceType: asset.resourceType,
		} );
	}
	await value.writeManifest();
	return { ...value, assets };
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

test( 'loads an exact import-free capture stylesheet substitute', async () => {
	const value = await fixture();
	const source = 'body { color: rebeccapurple; }';
	await mkdir( path.join( value.artifacts, 'capture-assets' ), {
		recursive: true,
	} );
	await writeFile(
		path.join( value.artifacts, 'capture-assets/theme.css' ),
		source
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
		byteLength: Buffer.byteLength( source ),
		sha256: sha256( source ),
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
	await writeFile(
		sourcePath,
		'@import url(https://example.com/theme.css);'
	);
	await rejectsCode(
		loadAssetSubstitutionResolver( {
			artifactRoot: value.artifacts,
			expectedOrigin: 'http://localhost:8890',
			manifestPath: value.manifestPath,
			stylesheetSourcePath: sourcePath,
		} ),
		'asset-substitution-stylesheet-transform'
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

test( 'rewrites only exact Reveal background attributes for archived staging assets', async () => {
	const value = await stagingFixture();
	const resolver = await loadAssetSubstitutionResolver( {
		artifactRoot: value.artifacts,
		expectedOrigin: 'http://localhost:8890',
		manifestPath: value.manifestPath,
	} );
	const [ png, firstJpeg, , , mp4, webm, ogg ] = value.manifest.entries.map(
		( entry ) => entry.sourceUrls[ 0 ]
	);
	const input = `<p>${ firstJpeg }</p>
<!-- <section data-background="${ firstJpeg }"> -->
<script>const asset = '<section data-background="${ firstJpeg }">';</script>
<a href="${ firstJpeg }">link</a>
<div data-background="${ firstJpeg }"></div>
<section data-other="${ firstJpeg }" data-background="${ firstJpeg }"></section>
<section data-background-image='${ png }'></section>
<section data-background-video="${ mp4 }, ${ webm },\t${ ogg }"></section>`;
	const result = resolver.rewriteDocument( input );
	const target = ( source ) =>
		`http://localhost:8890${ new URL( `http:${ source }` ).pathname }`;
	assert.deepEqual( Object.keys( result ), [ 'html', 'substitutions' ] );
	assert.equal( resolver.entryCount, 7 );
	assert.equal( resolver.resolve( firstJpeg ), null );
	const mediaTargets = resolver.rewrittenMediaTargets();
	const expectedMediaTargets = [
		target( mp4 ),
		target( webm ),
		target( ogg ),
	].sort();
	assert.deepEqual( mediaTargets, expectedMediaTargets );
	assert.ok( Object.isFrozen( mediaTargets ) );
	assert.ok(
		mediaTargets.every(
			( mediaTarget ) =>
				typeof mediaTarget === 'string' &&
				! mediaTarget.includes( 'mystagingwebsite.com' )
		)
	);
	assert.throws( () => mediaTargets.push( 'http://localhost:8890/leak' ) );
	assert.throws( () => {
		mediaTargets[ 0 ] = 'http://localhost:8890/leak';
	} );
	const freshMediaTargets = resolver.rewrittenMediaTargets();
	assert.notStrictEqual( freshMediaTargets, mediaTargets );
	assert.deepEqual( freshMediaTargets, expectedMediaTargets );
	assert.ok( Object.isFrozen( freshMediaTargets ) );
	assert.match(
		result.html,
		new RegExp( `data-background="${ target( firstJpeg ) }"`, 'u' )
	);
	assert.match(
		result.html,
		new RegExp( `data-background-image='${ target( png ) }'`, 'u' )
	);
	assert.match(
		result.html,
		new RegExp(
			`data-background-video="${ target( mp4 ) }, ${ target(
				webm
			) },\\t${ target( ogg ) }"`,
			'u'
		)
	);
	assert.ok( result.html.includes( `<p>${ firstJpeg }</p>` ) );
	assert.ok( result.html.includes( `<a href="${ firstJpeg }">` ) );
	assert.ok(
		result.html.includes(
			`<script>const asset = '<section data-background="${ firstJpeg }">';</script>`
		)
	);
	assert.ok(
		result.html.includes( `<div data-background="${ firstJpeg }">` )
	);
	assert.ok( result.html.includes( `data-other="${ firstJpeg }"` ) );
	assert.ok(
		result.html.includes(
			`<!-- <section data-background="${ firstJpeg }"> -->`
		)
	);
	assert.equal( result.substitutions.length, 5 );
	assert.deepEqual(
		result.substitutions,
		[ ...result.substitutions ].sort( ( left, right ) =>
			left.entryDigest.localeCompare( right.entryDigest )
		)
	);
	for ( const substitution of result.substitutions ) {
		assert.deepEqual( Object.keys( substitution ), [
			'entryDigest',
			'count',
		] );
		assert.equal( substitution.count, 1 );
		assert.ok( Object.isFrozen( substitution ) );
	}
	assert.ok( Object.isFrozen( result ) );
	assert.ok( Object.isFrozen( result.substitutions ) );
	assert.deepEqual( resolver.rewriteDocument( result.html ), {
		html: result.html,
		substitutions: [],
	} );

	const resolved = resolver.resolveRewrittenTarget( target( png ) );
	assert.deepEqual( Object.keys( resolved ), [
		'body',
		'entryDigest',
		'mimeType',
		'resourceType',
	] );
	assert.equal( resolved.mimeType, 'image/png' );
	assert.equal( resolved.resourceType, 'image' );
	resolved.body[ 0 ] = 0;
	assert.equal(
		resolver.resolveRewrittenTarget( target( png ) ).body[ 0 ],
		0x89
	);
	assert.equal(
		resolver.resolveRewrittenTarget( 'http://localhost:8890/unknown' ),
		null
	);
	assert.throws(
		() => resolver.rewriteDocument( Buffer.from( input ) ),
		( error ) =>
			error instanceof AssetSubstitutionError &&
			error.code === 'asset-substitution-document'
	);
} );

test( 'does not parse background-like strings nested inside other attributes', async () => {
	const value = await stagingFixture();
	const resolver = await loadAssetSubstitutionResolver( {
		artifactRoot: value.artifacts,
		expectedOrigin: 'http://localhost:8890',
		manifestPath: value.manifestPath,
	} );
	const png = value.manifest.entries[ 0 ].sourceUrls[ 0 ];
	const jpeg = value.manifest.entries[ 1 ].sourceUrls[ 0 ];
	const target = `http://localhost:8890${
		new URL( `http:${ jpeg }` ).pathname
	}`;
	const input = `<section aria-label='data-background="${ jpeg }"' title="data-background-image='${ png }'" data-background="${ jpeg }"></section>`;
	const result = resolver.rewriteDocument( input );
	assert.equal(
		result.html,
		`<section aria-label='data-background="${ jpeg }"' title="data-background-image='${ png }'" data-background="${ target }"></section>`
	);
	assert.equal( result.substitutions.length, 1 );
	assert.equal( result.substitutions[ 0 ].count, 1 );
} );

test( 'leaves malformed section tags unchanged without recording substitutions', async () => {
	const value = await stagingFixture();
	const resolver = await loadAssetSubstitutionResolver( {
		artifactRoot: value.artifacts,
		expectedOrigin: 'http://localhost:8890',
		manifestPath: value.manifestPath,
	} );
	const jpeg = value.manifest.entries[ 1 ].sourceUrls[ 0 ];
	const malformedTags = [
		`<section aria-label="unterminated data-background='${ jpeg }'>`,
		`<section ="broken" data-background="${ jpeg }"></section>`,
	];
	for ( const malformedTag of malformedTags ) {
		assert.deepEqual( resolver.rewriteDocument( malformedTag ), {
			html: malformedTag,
			substitutions: [],
		} );
	}
} );

test( 'rejects non-exact staging source tokens and mismatched archive paths', async () => {
	const invalidSources = [
		'http://aarondcampbell.mystagingwebsite.com/wp-content/uploads/2024/01/photo-1.jpg',
		'//evil.example/wp-content/uploads/2024/01/photo-1.jpg',
		'//aarondcampbell.mystagingwebsite.com:80/wp-content/uploads/2024/01/photo-1.jpg',
		'//aarondcampbell.mystagingwebsite.com/wp-content/uploads/2024/01/photo-1.jpg?x=1',
		'//aarondcampbell.mystagingwebsite.com/wp-content/uploads/2024/01/photo-1.jpg#x',
		'//aarondcampbell.mystagingwebsite.com/wp-content/uploads/2024/01/%2fphoto.jpg',
		'//aarondcampbell.mystagingwebsite.com/wp-content\\uploads/photo.jpg',
		'//aarondcampbell.mystagingwebsite.com/not-uploads/photo.jpg',
	];
	for ( const sourceUrl of invalidSources ) {
		const value = await stagingFixture();
		value.manifest.entries = [ value.manifest.entries[ 1 ] ];
		value.manifest.entries[ 0 ].sourceUrls = [ sourceUrl ];
		await value.writeManifest();
		await rejectsCode(
			loadAssetSubstitutionResolver( {
				artifactRoot: value.artifacts,
				expectedOrigin: 'http://localhost:8890',
				manifestPath: value.manifestPath,
			} ),
			'asset-substitution-source-url'
		);
	}

	const mismatch = await stagingFixture();
	mismatch.manifest.entries = [ mismatch.manifest.entries[ 1 ] ];
	mismatch.manifest.entries[ 0 ].artifactPath =
		'wp-content/uploads/2024/01/photo-2.jpg';
	await mismatch.writeManifest();
	await rejectsCode(
		loadAssetSubstitutionResolver( {
			artifactRoot: mismatch.artifacts,
			expectedOrigin: 'http://localhost:8890',
			manifestPath: mismatch.manifestPath,
		} ),
		'asset-substitution-entry-schema'
	);
} );

test( 'rejects spoofed staging archive signatures for every allowed type', async () => {
	for ( const index of [ 0, 1, 4, 5, 6 ] ) {
		const value = await stagingFixture();
		const entry = value.manifest.entries[ index ];
		value.manifest.entries = [ entry ];
		const invalid = Buffer.from( 'invalid artifact bytes' );
		await writeFile(
			path.join( value.artifacts, entry.artifactPath ),
			invalid
		);
		entry.byteLength = invalid.byteLength;
		entry.sha256 = sha256( invalid );
		await value.writeManifest();
		await rejectsCode(
			loadAssetSubstitutionResolver( {
				artifactRoot: value.artifacts,
				expectedOrigin: 'http://localhost:8890',
				manifestPath: value.manifestPath,
			} ),
			'asset-substitution-artifact-type'
		);
	}
} );
