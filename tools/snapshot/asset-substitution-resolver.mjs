import { createHash } from 'node:crypto';
import { lstat, readFile, realpath } from 'node:fs/promises';
import path from 'node:path';

import { SNAPSHOT_SOURCE_SHA256 } from './source-identity.mjs';

const DIGEST_PATTERN = /^[a-f0-9]{64}$/u;
const ALLOWED_MIME_TYPES = new Set( [ 'image/avif', 'text/css' ] );
const ENTRY_KINDS = new Set( [
	'same-origin-capture-substitute',
	'same-origin-missing',
] );
const MIME_TYPES_BY_RESOURCE_TYPE = Object.freeze( {
	image: new Set( [ 'image/avif' ] ),
	stylesheet: new Set( [ 'text/css' ] ),
} );
const MAX_MANIFEST_BYTES = 256 * 1024;
const MAX_ENTRIES = 64;
const MAX_ARTIFACT_BYTES = Object.freeze( {
	'image/avif': 25 * 1024 * 1024,
	'text/css': 256 * 1024,
} );
const MAX_TOTAL_ARTIFACT_BYTES = 100 * 1024 * 1024;
const GOOGLE_FONTS_IMPORT =
	'@import url(https://fonts.googleapis.com/css?family=Open+Sans:400italic,700italic,400,300,700);';
const OFFLINE_FONT_COMMENT =
	'/* Open Sans unavailable in the authoritative offline snapshot; using fallback. */';
const CAPTURE_STYLESHEET_SEARCHES = new Set( [
	'?ver=2.0.0-dev',
	'?ver=7.0.1',
] );

export class AssetSubstitutionError extends Error {
	constructor( code ) {
		super( code );
		this.name = 'AssetSubstitutionError';
		this.code = code;
	}
}

const fail = ( code ) => {
	throw new AssetSubstitutionError( code );
};

const exactKeys = ( value, keys, code ) => {
	if (
		value === null ||
		typeof value !== 'object' ||
		Array.isArray( value ) ||
		JSON.stringify( Object.keys( value ) ) !== JSON.stringify( keys )
	) {
		fail( code );
	}
};

const isInside = ( root, candidate ) => {
	const relative = path.relative( root, candidate );
	return (
		relative === '' ||
		( ! relative.startsWith( '..' ) && ! path.isAbsolute( relative ) )
	);
};

const digest = ( contents ) =>
	createHash( 'sha256' ).update( contents ).digest( 'hex' );

const validateArtifactBytes = ( body, mimeType ) => {
	if ( mimeType === 'image/avif' ) {
		if (
			body.byteLength < 16 ||
			body.subarray( 4, 8 ).toString( 'ascii' ) !== 'ftyp' ||
			! [ 'avif', 'avis' ].includes(
				body.subarray( 8, 12 ).toString( 'ascii' )
			)
		) {
			fail( 'asset-substitution-artifact-type' );
		}
		return;
	}
	try {
		const text = new TextDecoder( 'utf-8', { fatal: true } ).decode( body );
		if (
			text.includes( '\0' ) ||
			text.includes( 'fonts.googleapis.com' )
		) {
			fail( 'asset-substitution-artifact-type' );
		}
	} catch ( error ) {
		if ( error instanceof AssetSubstitutionError ) {
			throw error;
		}
		fail( 'asset-substitution-artifact-type' );
	}
};

const validateSourceUrl = ( sourceUrl, expectedOrigin, kind ) => {
	let parsed;
	try {
		parsed = new URL( sourceUrl );
	} catch {
		fail( 'asset-substitution-source-url' );
	}
	if (
		parsed.href !== sourceUrl ||
		parsed.username !== '' ||
		parsed.password !== '' ||
		parsed.hash !== '' ||
		/[\\\0]/u.test( parsed.pathname ) ||
		/%(?:00|2f|5c)/iu.test( parsed.pathname )
	) {
		fail( 'asset-substitution-source-url' );
	}
	if ( parsed.origin !== expectedOrigin ) {
		fail( 'asset-substitution-source-url' );
	}
	if (
		kind === 'same-origin-missing' &&
		( parsed.search !== '' ||
			! parsed.pathname.startsWith( '/wp-content/uploads/' ) ||
			! /\.(?:jpe?g|png)$/iu.test( parsed.pathname ) )
	) {
		fail( 'asset-substitution-source-url' );
	}
	if (
		kind === 'same-origin-capture-substitute' &&
		( parsed.pathname !==
			'/wp-content/plugins/aarondcampbell-presenter-themes/aaron-purple/aaron-purple.css' ||
			! CAPTURE_STYLESHEET_SEARCHES.has( parsed.search ) )
	) {
		fail( 'asset-substitution-source-url' );
	}
	return parsed.href;
};

const validateArtifactPath = ( artifactPath ) => {
	if (
		typeof artifactPath !== 'string' ||
		artifactPath === '' ||
		path.posix.isAbsolute( artifactPath ) ||
		artifactPath.includes( '\\' ) ||
		artifactPath.includes( '\0' ) ||
		artifactPath
			.split( '/' )
			.some( ( segment ) => [ '', '.', '..' ].includes( segment ) )
	) {
		fail( 'asset-substitution-artifact-path' );
	}
	return artifactPath;
};

/**
 * Load a private, snapshot-bound map of exact missing upload substitutions.
 *
 * The returned resolver is immutable and contains only verified bytes. It does
 * not infer fallbacks at request time, and an unknown URL resolves to null.
 *
 * @param {Object} options                      Loader options.
 * @param {string} options.artifactRoot         Private snapshot artifact directory.
 * @param {string} options.expectedOrigin       Exact loopback capture origin.
 * @param {string} options.manifestPath         Private manifest path.
 * @param {string} options.stylesheetSourcePath Private companion-theme stylesheet.
 * @return {Promise<Object>} Verified resolver.
 */
export const loadAssetSubstitutionResolver = async ( {
	artifactRoot,
	expectedOrigin,
	manifestPath,
	stylesheetSourcePath = null,
} ) => {
	if (
		typeof manifestPath !== 'string' ||
		! path.isAbsolute( manifestPath ) ||
		typeof artifactRoot !== 'string' ||
		! path.isAbsolute( artifactRoot )
	) {
		fail( 'asset-substitution-path' );
	}

	let rawManifest;
	let canonicalRoot;
	let manifestDetails;
	try {
		[ rawManifest, canonicalRoot, manifestDetails ] = await Promise.all( [
			readFile( manifestPath ),
			realpath( artifactRoot ),
			lstat( manifestPath ),
		] );
	} catch {
		fail( 'asset-substitution-unavailable' );
	}
	if (
		! manifestDetails.isFile() ||
		manifestDetails.isSymbolicLink() ||
		rawManifest.byteLength > MAX_MANIFEST_BYTES
	) {
		fail( 'asset-substitution-manifest-file' );
	}

	let manifest;
	try {
		manifest = JSON.parse( rawManifest.toString( 'utf8' ) );
	} catch {
		fail( 'asset-substitution-manifest-json' );
	}
	exactKeys(
		manifest,
		[ 'schemaVersion', 'snapshotSha256', 'entries' ],
		'asset-substitution-manifest-schema'
	);
	exactKeys(
		manifest.snapshotSha256,
		[ 'database', 'wpContent' ],
		'asset-substitution-source-identity'
	);
	if (
		manifest.schemaVersion !== 2 ||
		manifest.snapshotSha256.database !== SNAPSHOT_SOURCE_SHA256.database ||
		manifest.snapshotSha256.wpContent !==
			SNAPSHOT_SOURCE_SHA256.wpContent ||
		! Array.isArray( manifest.entries ) ||
		manifest.entries.length < 1 ||
		manifest.entries.length > MAX_ENTRIES
	) {
		fail( 'asset-substitution-source-identity' );
	}

	const entries = new Map();
	let totalArtifactBytes = 0;
	let stylesheetSource = null;
	for ( const entry of manifest.entries ) {
		exactKeys(
			entry,
			[
				'kind',
				'sourceUrls',
				'artifactPath',
				'byteLength',
				'sha256',
				'mimeType',
				'resourceType',
			],
			'asset-substitution-entry-schema'
		);
		if ( ! ENTRY_KINDS.has( entry.kind ) ) {
			fail( 'asset-substitution-entry-schema' );
		}
		if (
			! Array.isArray( entry.sourceUrls ) ||
			entry.sourceUrls.length < 1 ||
			new Set( entry.sourceUrls ).size !== entry.sourceUrls.length ||
			JSON.stringify( entry.sourceUrls ) !==
				JSON.stringify( [ ...entry.sourceUrls ].sort() ) ||
			( entry.kind === 'same-origin-missing' &&
				entry.sourceUrls.length !== 1 ) ||
			( entry.kind === 'same-origin-capture-substitute' &&
				entry.sourceUrls.length !== CAPTURE_STYLESHEET_SEARCHES.size )
		) {
			fail( 'asset-substitution-entry-schema' );
		}
		const sourceUrls = entry.sourceUrls.map( ( sourceUrl ) =>
			validateSourceUrl( sourceUrl, expectedOrigin, entry.kind )
		);
		if (
			entry.kind === 'same-origin-capture-substitute' &&
			new Set(
				sourceUrls.map( ( sourceUrl ) => new URL( sourceUrl ).search )
			).size !== CAPTURE_STYLESHEET_SEARCHES.size
		) {
			fail( 'asset-substitution-entry-schema' );
		}
		const artifactPath = validateArtifactPath( entry.artifactPath );
		if (
			! Number.isSafeInteger( entry.byteLength ) ||
			entry.byteLength < 1 ||
			typeof entry.sha256 !== 'string' ||
			! DIGEST_PATTERN.test( entry.sha256 ) ||
			! ALLOWED_MIME_TYPES.has( entry.mimeType ) ||
			! MIME_TYPES_BY_RESOURCE_TYPE[ entry.resourceType ]?.has(
				entry.mimeType
			) ||
			( entry.kind === 'same-origin-missing' &&
				entry.resourceType !== 'image' ) ||
			( entry.kind === 'same-origin-capture-substitute' &&
				( entry.resourceType !== 'stylesheet' ||
					! artifactPath.startsWith( 'capture-assets/' ) ) ) ||
			sourceUrls.some( ( sourceUrl ) => entries.has( sourceUrl ) )
		) {
			fail( 'asset-substitution-entry-schema' );
		}
		if (
			entry.byteLength > MAX_ARTIFACT_BYTES[ entry.mimeType ] ||
			( totalArtifactBytes += entry.byteLength ) >
				MAX_TOTAL_ARTIFACT_BYTES
		) {
			fail( 'asset-substitution-artifact-size' );
		}

		const candidate = path.resolve(
			canonicalRoot,
			...artifactPath.split( '/' )
		);
		if ( entry.kind === 'same-origin-missing' ) {
			const source = new URL( sourceUrls[ 0 ] );
			const sourceRelative = source.pathname.replace(
				'/wp-content/uploads/',
				'wp-content/uploads/'
			);
			const expectedArtifact = sourceRelative.replace(
				/\.(?:jpe?g|png)$/iu,
				'.avif'
			);
			if (
				artifactPath !== expectedArtifact ||
				entry.mimeType !== 'image/avif'
			) {
				fail( 'asset-substitution-counterpart' );
			}
			try {
				await lstat(
					path.resolve(
						canonicalRoot,
						...sourceRelative.split( '/' )
					)
				);
				fail( 'asset-substitution-source-present' );
			} catch ( error ) {
				if (
					error instanceof AssetSubstitutionError ||
					error?.code !== 'ENOENT'
				) {
					throw error;
				}
			}
		}
		let details;
		let canonicalArtifact;
		let body;
		try {
			[ details, canonicalArtifact, body ] = await Promise.all( [
				lstat( candidate ),
				realpath( candidate ),
				readFile( candidate ),
			] );
		} catch {
			fail( 'asset-substitution-artifact-unavailable' );
		}
		if (
			! details.isFile() ||
			details.isSymbolicLink() ||
			! isInside( canonicalRoot, canonicalArtifact )
		) {
			fail( 'asset-substitution-artifact-path' );
		}
		if (
			body.byteLength !== entry.byteLength ||
			digest( body ) !== entry.sha256
		) {
			fail( 'asset-substitution-artifact-identity' );
		}
		validateArtifactBytes( body, entry.mimeType );
		if ( entry.kind === 'same-origin-capture-substitute' ) {
			if (
				typeof stylesheetSourcePath !== 'string' ||
				! path.isAbsolute( stylesheetSourcePath )
			) {
				fail( 'asset-substitution-stylesheet-source' );
			}
			if ( stylesheetSource === null ) {
				let sourceDetails;
				try {
					[ stylesheetSource, sourceDetails ] = await Promise.all( [
						readFile( stylesheetSourcePath, 'utf8' ),
						lstat( stylesheetSourcePath ),
					] );
				} catch {
					fail( 'asset-substitution-stylesheet-source' );
				}
				if (
					! sourceDetails.isFile() ||
					sourceDetails.isSymbolicLink() ||
					Buffer.byteLength( stylesheetSource ) >
						MAX_ARTIFACT_BYTES[ 'text/css' ]
				) {
					fail( 'asset-substitution-stylesheet-source' );
				}
			}
			if (
				stylesheetSource.split( GOOGLE_FONTS_IMPORT ).length !== 2 ||
				body.toString( 'utf8' ) !==
					stylesheetSource.replace(
						GOOGLE_FONTS_IMPORT,
						OFFLINE_FONT_COMMENT
					)
			) {
				fail( 'asset-substitution-stylesheet-transform' );
			}
		}

		const basisUrl =
			entry.kind === 'same-origin-capture-substitute'
				? `${ new URL( sourceUrls[ 0 ] ).origin }${
						new URL( sourceUrls[ 0 ] ).pathname
				  }`
				: sourceUrls[ 0 ];
		const resolvedEntry = Object.freeze( {
			body,
			entryDigest: digest(
				`${ entry.kind }\0${ basisUrl }\0${ entry.sha256 }\0${ entry.mimeType }\0${ entry.resourceType }`
			),
			mimeType: entry.mimeType,
			resourceType: entry.resourceType,
		} );
		for ( const sourceUrl of sourceUrls ) {
			entries.set( sourceUrl, resolvedEntry );
		}
	}

	return Object.freeze( {
		entryCount: entries.size,
		manifestDigest: digest( rawManifest ),
		resolve: ( requestUrl ) => {
			const entry = entries.get( requestUrl );
			return entry
				? Object.freeze( { ...entry, body: Buffer.from( entry.body ) } )
				: null;
		},
	} );
};
