import { createHash } from 'node:crypto';
import { lstat, readFile, realpath } from 'node:fs/promises';
import path from 'node:path';

import { SNAPSHOT_SOURCE_SHA256 } from './source-identity.mjs';

const DIGEST_PATTERN = /^[a-f0-9]{64}$/u;
const ALLOWED_MIME_TYPES = new Set( [
	'image/avif',
	'image/jpeg',
	'image/png',
	'text/css',
	'video/mp4',
	'video/ogg',
	'video/webm',
] );
const ENTRY_KINDS = new Set( [
	'same-origin-capture-substitute',
	'same-origin-missing',
	'staging-origin-archive',
] );
const MIME_TYPES_BY_RESOURCE_TYPE = Object.freeze( {
	image: new Set( [ 'image/avif', 'image/jpeg', 'image/png' ] ),
	media: new Set( [ 'video/mp4', 'video/ogg', 'video/webm' ] ),
	stylesheet: new Set( [ 'text/css' ] ),
} );
const MAX_MANIFEST_BYTES = 256 * 1024;
const MAX_ENTRIES = 64;
const MAX_ARTIFACT_BYTES = Object.freeze( {
	'image/avif': 25 * 1024 * 1024,
	'image/jpeg': 25 * 1024 * 1024,
	'image/png': 25 * 1024 * 1024,
	'text/css': 256 * 1024,
	'video/mp4': 25 * 1024 * 1024,
	'video/ogg': 25 * 1024 * 1024,
	'video/webm': 25 * 1024 * 1024,
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
const STAGING_HOST = 'aarondcampbell.mystagingwebsite.com';
const REWRITABLE_BACKGROUND_ATTRIBUTES = new Set( [
	'data-background',
	'data-background-image',
	'data-background-video',
] );
const RAW_TEXT_ELEMENTS = new Set( [ 'script', 'style', 'textarea', 'title' ] );

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
	if ( mimeType === 'image/jpeg' ) {
		if (
			body.byteLength < 3 ||
			body[ 0 ] !== 0xff ||
			body[ 1 ] !== 0xd8 ||
			body[ 2 ] !== 0xff
		) {
			fail( 'asset-substitution-artifact-type' );
		}
		return;
	}
	if ( mimeType === 'image/png' ) {
		if (
			body.byteLength < 8 ||
			! body
				.subarray( 0, 8 )
				.equals(
					Buffer.from( [
						0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a,
					] )
				)
		) {
			fail( 'asset-substitution-artifact-type' );
		}
		return;
	}
	if ( mimeType === 'video/mp4' ) {
		if (
			body.byteLength < 12 ||
			body.subarray( 4, 8 ).toString( 'ascii' ) !== 'ftyp'
		) {
			fail( 'asset-substitution-artifact-type' );
		}
		return;
	}
	if ( mimeType === 'video/webm' ) {
		if (
			body.byteLength < 4 ||
			! body
				.subarray( 0, 4 )
				.equals( Buffer.from( [ 0x1a, 0x45, 0xdf, 0xa3 ] ) )
		) {
			fail( 'asset-substitution-artifact-type' );
		}
		return;
	}
	if ( mimeType === 'video/ogg' ) {
		if (
			body.byteLength < 4 ||
			body.subarray( 0, 4 ).toString( 'ascii' ) !== 'OggS'
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

const validateStagingSourceToken = ( sourceToken ) => {
	if (
		typeof sourceToken !== 'string' ||
		! sourceToken.startsWith( '//' ) ||
		/[\\\0%]/u.test( sourceToken )
	) {
		fail( 'asset-substitution-source-url' );
	}
	let parsed;
	try {
		parsed = new URL( `http:${ sourceToken }` );
	} catch {
		fail( 'asset-substitution-source-url' );
	}
	if (
		parsed.hostname !== STAGING_HOST ||
		parsed.host !== STAGING_HOST ||
		parsed.username !== '' ||
		parsed.password !== '' ||
		parsed.search !== '' ||
		parsed.hash !== '' ||
		! parsed.pathname.startsWith( '/wp-content/uploads/' ) ||
		`//${ parsed.host }${ parsed.pathname }` !== sourceToken
	) {
		fail( 'asset-substitution-source-url' );
	}
	return Object.freeze( {
		pathname: parsed.pathname,
		sourceToken,
	} );
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

const copyResolvedEntry = ( entry ) =>
	Object.freeze( { ...entry, body: Buffer.from( entry.body ) } );

const rewriteBackgroundValue = ( attributeName, value, rewriteSources ) => {
	const rewrites = [];
	const rewriteToken = ( token ) => {
		const rewrite = rewriteSources.get( token );
		if ( ! rewrite ) {
			return token;
		}
		rewrites.push( rewrite.entryDigest );
		return rewrite.targetUrl;
	};
	const rewrittenValue =
		attributeName === 'data-background-video'
			? value
					.split( ',' )
					.map( ( part ) => {
						const leading = part.match( /^\s*/u )[ 0 ];
						const trailing = part.match( /\s*$/u )[ 0 ];
						const token = part.slice(
							leading.length,
							part.length - trailing.length
						);
						return `${ leading }${ rewriteToken(
							token
						) }${ trailing }`;
					} )
					.join( ',' )
			: rewriteToken( value );
	return { rewrittenValue, rewrites };
};

const rewriteSectionTag = ( tag, rewriteSources, substitutionCounts ) => {
	const sectionStart = tag.match( /^<section(?=\s|\/?>)/iu );
	if ( ! sectionStart || ! tag.endsWith( '>' ) ) {
		return tag;
	}
	const replacements = [];
	const pendingCounts = new Map();
	let cursor = sectionStart[ 0 ].length;
	while ( cursor < tag.length ) {
		while ( /\s/u.test( tag[ cursor ] ) ) {
			cursor++;
		}
		if ( tag[ cursor ] === '>' ) {
			cursor++;
			break;
		}
		if ( tag[ cursor ] === '/' && tag[ cursor + 1 ] === '>' ) {
			cursor += 2;
			break;
		}
		const nameStart = cursor;
		while ( cursor < tag.length && ! /[\s=<>/'"]/u.test( tag[ cursor ] ) ) {
			cursor++;
		}
		if ( cursor === nameStart ) {
			return tag;
		}
		const attributeName = tag.slice( nameStart, cursor ).toLowerCase();
		while ( /\s/u.test( tag[ cursor ] ) ) {
			cursor++;
		}
		if ( tag[ cursor ] !== '=' ) {
			continue;
		}
		cursor++;
		while ( /\s/u.test( tag[ cursor ] ) ) {
			cursor++;
		}
		const quote = tag[ cursor ];
		if ( quote !== '"' && quote !== "'" ) {
			while ( cursor < tag.length && ! /[\s>]/u.test( tag[ cursor ] ) ) {
				if ( /[<'"]/u.test( tag[ cursor ] ) ) {
					return tag;
				}
				cursor++;
			}
			continue;
		}
		const valueStart = cursor + 1;
		const valueEnd = tag.indexOf( quote, valueStart );
		if ( valueEnd === -1 ) {
			return tag;
		}
		cursor = valueEnd + 1;
		if ( ! REWRITABLE_BACKGROUND_ATTRIBUTES.has( attributeName ) ) {
			continue;
		}
		const value = tag.slice( valueStart, valueEnd );
		const { rewrittenValue, rewrites } = rewriteBackgroundValue(
			attributeName,
			value,
			rewriteSources
		);
		if ( rewrittenValue !== value ) {
			replacements.push( {
				end: valueEnd,
				start: valueStart,
				rewrittenValue,
			} );
			for ( const entryDigest of rewrites ) {
				pendingCounts.set(
					entryDigest,
					( pendingCounts.get( entryDigest ) ?? 0 ) + 1
				);
			}
		}
	}
	if ( cursor !== tag.length ) {
		return tag;
	}
	for ( const [ entryDigest, count ] of pendingCounts ) {
		substitutionCounts.set(
			entryDigest,
			( substitutionCounts.get( entryDigest ) ?? 0 ) + count
		);
	}
	let rewrittenTag = tag;
	for ( const replacement of replacements.reverse() ) {
		rewrittenTag = `${ rewrittenTag.slice( 0, replacement.start ) }${
			replacement.rewrittenValue
		}${ rewrittenTag.slice( replacement.end ) }`;
	}
	return rewrittenTag;
};

const rewriteDocumentHtml = ( html, rewriteSources ) => {
	const substitutionCounts = new Map();
	let rewritten = '';
	let cursor = 0;
	while ( cursor < html.length ) {
		const tagStart = html.indexOf( '<', cursor );
		if ( tagStart === -1 ) {
			rewritten += html.slice( cursor );
			break;
		}
		rewritten += html.slice( cursor, tagStart );
		if ( html.startsWith( '<!--', tagStart ) ) {
			const commentEnd = html.indexOf( '-->', tagStart + 4 );
			const end = commentEnd === -1 ? html.length : commentEnd + 3;
			rewritten += html.slice( tagStart, end );
			cursor = end;
			continue;
		}
		let quote = null;
		let tagEnd = tagStart + 1;
		for ( ; tagEnd < html.length; tagEnd++ ) {
			const character = html[ tagEnd ];
			if ( quote ) {
				if ( character === quote ) {
					quote = null;
				}
			} else if ( character === '"' || character === "'" ) {
				quote = character;
			} else if ( character === '>' ) {
				tagEnd++;
				break;
			}
		}
		const tag = html.slice( tagStart, tagEnd );
		const rawTextStart = tag.match( /^<([a-z][a-z0-9-]*)(?:\s|>)/iu );
		const rawTextName = rawTextStart?.[ 1 ].toLowerCase();
		if ( RAW_TEXT_ELEMENTS.has( rawTextName ) ) {
			const closingPattern = new RegExp(
				`<\\/${ rawTextName }\\s*>`,
				'giu'
			);
			closingPattern.lastIndex = tagEnd;
			const closing = closingPattern.exec( html );
			const end = closing
				? closing.index + closing[ 0 ].length
				: html.length;
			rewritten += html.slice( tagStart, end );
			cursor = end;
			continue;
		}
		rewritten += rewriteSectionTag(
			tag,
			rewriteSources,
			substitutionCounts
		);
		cursor = tagEnd;
	}
	const substitutions = [ ...substitutionCounts ]
		.sort( ( [ left ], [ right ] ) => left.localeCompare( right ) )
		.map( ( [ entryDigest, count ] ) =>
			Object.freeze( { entryDigest, count } )
		);
	return Object.freeze( {
		html: rewritten,
		substitutions: Object.freeze( substitutions ),
	} );
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
		manifest.schemaVersion !== 3 ||
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
	const rewriteSources = new Map();
	const rewrittenTargets = new Map();
	const claimedNetworkUrls = new Set();
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
				entry.sourceUrls.length !==
					CAPTURE_STYLESHEET_SEARCHES.size ) ||
			( entry.kind === 'staging-origin-archive' &&
				entry.sourceUrls.length !== 1 )
		) {
			fail( 'asset-substitution-entry-schema' );
		}
		const stagingSource =
			entry.kind === 'staging-origin-archive'
				? validateStagingSourceToken( entry.sourceUrls[ 0 ] )
				: null;
		const sourceUrls = stagingSource
			? [ stagingSource.sourceToken ]
			: entry.sourceUrls.map( ( sourceUrl ) =>
					validateSourceUrl( sourceUrl, expectedOrigin, entry.kind )
			  );
		const rewrittenTarget = stagingSource
			? `${ expectedOrigin }${ stagingSource.pathname }`
			: null;
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
			( entry.kind === 'staging-origin-archive' &&
				( artifactPath !== stagingSource.pathname.slice( 1 ) ||
					artifactPath.startsWith( 'capture-assets/' ) ||
					! [
						'image/jpeg',
						'image/png',
						'video/mp4',
						'video/ogg',
						'video/webm',
					].includes( entry.mimeType ) ) ) ||
			sourceUrls.some( ( sourceUrl ) =>
				claimedNetworkUrls.has( sourceUrl )
			) ||
			( rewrittenTarget !== null &&
				claimedNetworkUrls.has( rewrittenTarget ) )
		) {
			fail( 'asset-substitution-entry-schema' );
		}
		for ( const sourceUrl of sourceUrls ) {
			claimedNetworkUrls.add( sourceUrl );
		}
		if ( rewrittenTarget !== null ) {
			claimedNetworkUrls.add( rewrittenTarget );
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

		let basisUrl = sourceUrls[ 0 ];
		if ( entry.kind === 'staging-origin-archive' ) {
			basisUrl = `${ sourceUrls[ 0 ] }\0${ rewrittenTarget }`;
		} else if ( entry.kind === 'same-origin-capture-substitute' ) {
			basisUrl = `${ new URL( sourceUrls[ 0 ] ).origin }${
				new URL( sourceUrls[ 0 ] ).pathname
			}`;
		}
		const resolvedEntry = Object.freeze( {
			body,
			entryDigest: digest(
				`${ entry.kind }\0${ basisUrl }\0${ entry.sha256 }\0${ entry.mimeType }\0${ entry.resourceType }`
			),
			mimeType: entry.mimeType,
			resourceType: entry.resourceType,
		} );
		if ( stagingSource ) {
			const rewriteEntry = Object.freeze( {
				entryDigest: resolvedEntry.entryDigest,
				targetUrl: rewrittenTarget,
			} );
			rewriteSources.set( stagingSource.sourceToken, rewriteEntry );
			rewrittenTargets.set( rewrittenTarget, resolvedEntry );
		} else {
			for ( const sourceUrl of sourceUrls ) {
				entries.set( sourceUrl, resolvedEntry );
			}
		}
	}
	const rewrittenMediaTargetUrls = Object.freeze(
		[ ...rewrittenTargets ]
			.filter( ( [ , entry ] ) => entry.resourceType === 'media' )
			.map( ( [ targetUrl ] ) => targetUrl )
			.sort()
	);

	return Object.freeze( {
		entryCount: entries.size + rewriteSources.size,
		manifestDigest: digest( rawManifest ),
		resolve: ( requestUrl ) => {
			const entry = entries.get( requestUrl );
			return entry ? copyResolvedEntry( entry ) : null;
		},
		resolveRewrittenTarget: ( requestUrl ) => {
			const entry = rewrittenTargets.get( requestUrl );
			return entry ? copyResolvedEntry( entry ) : null;
		},
		rewrittenMediaTargets: () =>
			Object.freeze( [ ...rewrittenMediaTargetUrls ] ),
		rewriteDocument: ( html ) => {
			if ( typeof html !== 'string' ) {
				fail( 'asset-substitution-document' );
			}
			return rewriteDocumentHtml( html, rewriteSources );
		},
	} );
};
