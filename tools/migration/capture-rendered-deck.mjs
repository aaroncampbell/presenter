import { mkdir, realpath } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { chromium } from '@playwright/test';

const REPOSITORY_ROOT = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'../..'
);
const PRIVATE_REPOSITORY_ROOT = path.join( REPOSITORY_ROOT, 'local' );
const DEFAULT_ORIGIN = 'http://localhost:8890';
const ALLOWED_ORIGINS = new Set( [
	'http://localhost:8888',
	'http://localhost:8890',
] );
const DEFAULT_VIEWPORT = Object.freeze( { height: 720, width: 1280 } );

export const CAPTURE_ASSET_STATES = Object.freeze( {
	BLOCKED_NONLOCAL: 'blocked-nonlocal',
	CLEAN: 'clean',
	INCOMPLETE_IMAGE: 'incomplete-image',
	LOCAL_HTTP_ERROR: 'local-http-error',
	LOCAL_REQUEST_FAILED: 'local-request-failed',
	CONSOLE_ERROR: 'console-error',
	PAGE_ERROR: 'page-error',
} );

export class RenderedDeckCaptureError extends Error {
	/**
	 * Create an error whose message is safe to put in a durable report.
	 *
	 * @param {string} code Stable failure code.
	 */
	constructor( code ) {
		super( code );
		this.name = 'RenderedDeckCaptureError';
		this.code = code;
	}
}

const isInside = ( root, candidate ) => {
	const relative = path.relative( root, candidate );
	return (
		relative === '' ||
		( ! relative.startsWith( '..' ) && ! path.isAbsolute( relative ) )
	);
};

/**
 * Validate and create a caller-selected private artifact directory.
 *
 * Repository paths are accepted only below the ignored local/ directory. A
 * path outside the repository is also valid. There is deliberately no default.
 *
 * @param {string} candidate Caller-selected root.
 * @return {Promise<string>} Canonical absolute root.
 */
export const validatePrivateCaptureRoot = async ( candidate ) => {
	if ( typeof candidate !== 'string' || ! path.isAbsolute( candidate ) ) {
		throw new RenderedDeckCaptureError( 'invalid-private-root' );
	}

	const resolved = path.resolve( candidate );
	const parsed = path.parse( resolved );
	if ( resolved === parsed.root ) {
		throw new RenderedDeckCaptureError( 'invalid-private-root' );
	}

	if (
		isInside( REPOSITORY_ROOT, resolved ) &&
		! isInside( PRIVATE_REPOSITORY_ROOT, resolved )
	) {
		throw new RenderedDeckCaptureError( 'private-root-not-ignored' );
	}

	try {
		await mkdir( resolved, { recursive: true } );
		const canonical = await realpath( resolved );
		if (
			isInside( REPOSITORY_ROOT, canonical ) &&
			! isInside( PRIVATE_REPOSITORY_ROOT, canonical )
		) {
			throw new RenderedDeckCaptureError( 'private-root-not-ignored' );
		}
		return canonical;
	} catch ( error ) {
		if ( error instanceof RenderedDeckCaptureError ) {
			throw error;
		}
		throw new RenderedDeckCaptureError( 'private-root-unavailable' );
	}
};

const validateExpectedOrigin = ( candidate ) => {
	if ( ! ALLOWED_ORIGINS.has( candidate ) ) {
		throw new RenderedDeckCaptureError( 'invalid-expected-origin' );
	}
	return candidate;
};

const validateDeckUrl = ( candidate, expectedOrigin ) => {
	let target;
	try {
		target = new URL( candidate );
	} catch {
		throw new RenderedDeckCaptureError( 'invalid-deck-url' );
	}

	if (
		target.origin !== expectedOrigin ||
		target.username !== '' ||
		target.password !== '' ||
		target.hash !== ''
	) {
		throw new RenderedDeckCaptureError( 'nonlocal-deck-url' );
	}

	return target.href;
};

const validateOrdinal = ( value, code ) => {
	if ( ! Number.isSafeInteger( value ) || value < 1 || value > 999999 ) {
		throw new RenderedDeckCaptureError( code );
	}
	return String( value ).padStart( 6, '0' );
};

const requestIsAllowed = ( requestUrl, expectedOrigin ) => {
	let target;
	try {
		target = new URL( requestUrl );
	} catch {
		return false;
	}

	if ( target.origin === expectedOrigin ) {
		return true;
	}
	if ( [ 'about:', 'data:' ].includes( target.protocol ) ) {
		return true;
	}
	return (
		target.protocol === 'blob:' &&
		target.href.startsWith( `blob:${ expectedOrigin }/` )
	);
};

const isSnapshotRequest = ( requestUrl, expectedOrigin ) => {
	try {
		return new URL( requestUrl ).origin === expectedOrigin;
	} catch {
		return false;
	}
};

export const shouldCountLocalRequestFailure = ( request, expectedOrigin ) =>
	isSnapshotRequest( request.url(), expectedOrigin );

export const isVerifiedRewrittenMediaAbort = ( request, resolver ) => {
	if (
		request.resourceType() !== 'media' ||
		request.failure()?.errorText !== 'net::ERR_ABORTED' ||
		request.method() !== 'GET' ||
		request.postData() !== null
	) {
		return false;
	}
	const substitution = resolver?.resolveRewrittenTarget?.( request.url() );
	return Boolean(
		substitution &&
			Buffer.isBuffer( substitution.body ) &&
			substitution.body.byteLength > 0 &&
			/^[a-f0-9]{64}$/u.test( substitution.entryDigest ) &&
			substitution.resourceType === 'media' &&
			rewrittenTargetMimeTypes.media.has( substitution.mimeType )
	);
};

export const countUnstabilizedMediaAborts = (
	abortedTargets,
	stabilizedTargets
) =>
	abortedTargets.filter(
		( targetUrl ) => ! stabilizedTargets.has( targetUrl )
	).length;

export const installMediaCapturePolicy = async (
	page,
	rewrittenMediaTargets
) => {
	if (
		! Array.isArray( rewrittenMediaTargets ) ||
		rewrittenMediaTargets.length > 64 ||
		rewrittenMediaTargets.some(
			( target, index ) =>
				typeof target !== 'string' ||
				target === '' ||
				( index > 0 && target <= rewrittenMediaTargets[ index - 1 ] )
		)
	) {
		throw new RenderedDeckCaptureError( 'invalid-media-capture-policy' );
	}
	await page.addInitScript( ( targets ) => {
		const managedTargets = new Set( targets );
		const nativePlay = window.HTMLMediaElement.prototype.play;
		window.HTMLMediaElement.prototype.play = function ( ...args ) {
			const result = nativePlay.apply( this, args );
			if ( ! result || typeof result.catch !== 'function' ) {
				return result;
			}
			const source =
				this.currentSrc ||
				this.querySelector( 'source[src]' )?.src ||
				this.src;
			if (
				this.closest( '.slide-background' ) &&
				managedTargets.has( source )
			) {
				result.catch( ( error ) => {
					if ( error?.name !== 'AbortError' ) {
						throw error;
					}
				} );
			}
			return result;
		};
	}, rewrittenMediaTargets );
};

const substitutionMimeTypes = Object.freeze( {
	image: 'image/avif',
	stylesheet: 'text/css',
} );

/**
 * Fulfill one exact verified snapshot substitution, if eligible.
 *
 * @param {import('@playwright/test').Route} route    Playwright request route.
 * @param {Object|null}                      resolver Verified resolver.
 * @param {Map<string, number>}              applied  Applied-entry request counts.
 * @return {Promise<boolean>} Whether the route was fulfilled.
 */
export const fulfillAssetSubstitution = async ( route, resolver, applied ) => {
	const request = route.request();
	const substitution = resolver?.resolve( request.url() );
	if ( ! substitution ) {
		return false;
	}
	if (
		JSON.stringify( Object.keys( substitution ).sort() ) !==
			JSON.stringify(
				[ 'body', 'entryDigest', 'mimeType', 'resourceType' ].sort()
			) ||
		! Buffer.isBuffer( substitution.body ) ||
		! /^[a-f0-9]{64}$/u.test( substitution.entryDigest ) ||
		substitutionMimeTypes[ substitution.resourceType ] !==
			substitution.mimeType ||
		substitution.resourceType !== request.resourceType() ||
		request.method() !== 'GET' ||
		request.postData() !== null
	) {
		return false;
	}
	applied.set(
		substitution.entryDigest,
		( applied.get( substitution.entryDigest ) ?? 0 ) + 1
	);
	await route.fulfill( {
		body: substitution.body,
		contentType: substitution.mimeType,
		headers: {
			'cache-control': 'no-store',
			'x-content-type-options': 'nosniff',
		},
		status: 200,
	} );
	return true;
};

const rewrittenTargetMimeTypes = Object.freeze( {
	image: new Set( [ 'image/jpeg', 'image/png' ] ),
	media: new Set( [ 'video/mp4', 'video/ogg', 'video/webm' ] ),
} );

const parseSingleByteRange = ( value, byteLength ) => {
	if ( value === undefined ) {
		return null;
	}
	if ( typeof value !== 'string' || value.length > 128 ) {
		return false;
	}
	const match = value.match( /^bytes=(\d*)-(\d*)$/u );
	if ( ! match || ( match[ 1 ] === '' && match[ 2 ] === '' ) ) {
		return false;
	}
	const first = match[ 1 ] === '' ? null : Number( match[ 1 ] );
	const second = match[ 2 ] === '' ? null : Number( match[ 2 ] );
	if (
		( first !== null && ! Number.isSafeInteger( first ) ) ||
		( second !== null && ! Number.isSafeInteger( second ) )
	) {
		return false;
	}
	if ( first === null ) {
		if ( second < 1 ) {
			return false;
		}
		return {
			end: byteLength - 1,
			start: Math.max( byteLength - second, 0 ),
		};
	}
	if ( first >= byteLength ) {
		return false;
	}
	const end = second === null ? byteLength - 1 : second;
	if ( end < first ) {
		return false;
	}
	return { end: Math.min( end, byteLength - 1 ), start: first };
};

/**
 * Serve one exact resolver-verified archive target. Range requests are bounded
 * to a single byte interval and do not alter logical substitution evidence.
 *
 * @param {import('@playwright/test').Route} route    Playwright request route.
 * @param {Object|null}                      resolver Verified resolver.
 * @return {Promise<boolean>} Whether the route was fulfilled.
 */
export const fulfillRewrittenTarget = async ( route, resolver ) => {
	const request = route.request();
	const substitution = resolver?.resolveRewrittenTarget?.( request.url() );
	if ( ! substitution ) {
		return false;
	}
	if (
		JSON.stringify( Object.keys( substitution ).sort() ) !==
			JSON.stringify(
				[ 'body', 'entryDigest', 'mimeType', 'resourceType' ].sort()
			) ||
		! Buffer.isBuffer( substitution.body ) ||
		substitution.body.byteLength < 1 ||
		! /^[a-f0-9]{64}$/u.test( substitution.entryDigest ) ||
		! rewrittenTargetMimeTypes[ substitution.resourceType ]?.has(
			substitution.mimeType
		) ||
		substitution.resourceType !== request.resourceType() ||
		request.method() !== 'GET' ||
		request.postData() !== null
	) {
		throw new RenderedDeckCaptureError(
			'invalid-rewritten-target-request'
		);
	}

	const range = parseSingleByteRange(
		request.headers().range,
		substitution.body.byteLength
	);
	const commonHeaders = {
		'accept-ranges': 'bytes',
		'cache-control': 'no-store',
		'x-content-type-options': 'nosniff',
	};
	if ( range === false ) {
		await route.fulfill( {
			body: Buffer.alloc( 0 ),
			contentType: substitution.mimeType,
			headers: {
				...commonHeaders,
				'content-length': '0',
				'content-range': `bytes */${ substitution.body.byteLength }`,
			},
			status: 416,
		} );
		return true;
	}
	if ( range === null ) {
		await route.fulfill( {
			body: substitution.body,
			contentType: substitution.mimeType,
			headers: {
				...commonHeaders,
				'content-length': String( substitution.body.byteLength ),
			},
			status: 200,
		} );
		return true;
	}
	const body = substitution.body.subarray( range.start, range.end + 1 );
	await route.fulfill( {
		body,
		contentType: substitution.mimeType,
		headers: {
			...commonHeaders,
			'content-length': String( body.byteLength ),
			'content-range': `bytes ${ range.start }-${ range.end }/${ substitution.body.byteLength }`,
		},
		status: 206,
	} );
	return true;
};

/**
 * Rewrite only the selected localhost deck document using a verified private
 * resolver. The response headers, including CSP, remain unchanged.
 *
 * @param {import('@playwright/test').Route} route    Playwright request route.
 * @param {Object|null}                      resolver Verified resolver.
 * @param {string}                           deckUrl  Exact selected deck URL.
 * @param {Map<string, number>}              applied  Logical substitution counts.
 * @return {Promise<boolean>} Whether the document route was fulfilled.
 */
export const rewriteDeckDocument = async (
	route,
	resolver,
	deckUrl,
	applied
) => {
	const request = route.request();
	if (
		typeof resolver?.rewriteDocument !== 'function' ||
		request.url() !== deckUrl ||
		request.resourceType() !== 'document' ||
		request.method() !== 'GET' ||
		request.postData() !== null
	) {
		return false;
	}

	const response = await route.fetch( { maxRedirects: 0 } );
	if ( response.status() >= 300 && response.status() < 400 ) {
		throw new RenderedDeckCaptureError( 'deck-document-redirect' );
	}
	const rewritten = resolver.rewriteDocument( await response.text() );
	if (
		JSON.stringify( Object.keys( rewritten ).sort() ) !==
			JSON.stringify( [ 'html', 'substitutions' ] ) ||
		typeof rewritten.html !== 'string' ||
		! Array.isArray( rewritten.substitutions )
	) {
		throw new RenderedDeckCaptureError( 'invalid-document-rewrite' );
	}
	let previousDigest = '';
	for ( const substitution of rewritten.substitutions ) {
		if (
			JSON.stringify( Object.keys( substitution ) ) !==
				JSON.stringify( [ 'entryDigest', 'count' ] ) ||
			! /^[a-f0-9]{64}$/u.test( substitution.entryDigest ) ||
			substitution.entryDigest <= previousDigest ||
			! Number.isSafeInteger( substitution.count ) ||
			substitution.count < 1
		) {
			throw new RenderedDeckCaptureError( 'invalid-document-rewrite' );
		}
		previousDigest = substitution.entryDigest;
		applied.set(
			substitution.entryDigest,
			( applied.get( substitution.entryDigest ) ?? 0 ) +
				substitution.count
		);
	}
	await route.fulfill( { body: rewritten.html, response } );
	return true;
};

export const resolveDeckNavigationTarget = async (
	requestContext,
	deckUrl,
	expectedOrigin,
	timeout
) => {
	let response = await requestContext.get( deckUrl, {
		failOnStatusCode: false,
		maxRedirects: 0,
		timeout,
	} );
	let target = deckUrl;
	try {
		if ( response.status() >= 300 && response.status() < 400 ) {
			let redirectTarget;
			try {
				redirectTarget = new URL(
					response.headers().location,
					deckUrl
				);
			} catch {
				throw new RenderedDeckCaptureError( 'deck-document-redirect' );
			}
			if (
				redirectTarget.origin !== expectedOrigin ||
				redirectTarget.username !== '' ||
				redirectTarget.password !== '' ||
				redirectTarget.hash !== ''
			) {
				throw new RenderedDeckCaptureError( 'deck-document-redirect' );
			}
			target = redirectTarget.href;
		}
	} finally {
		await response.dispose();
	}
	if ( target === deckUrl ) {
		return target;
	}
	response = await requestContext.get( target, {
		failOnStatusCode: false,
		maxRedirects: 0,
		timeout,
	} );
	try {
		if ( response.status() >= 300 && response.status() < 400 ) {
			throw new RenderedDeckCaptureError( 'deck-document-redirect' );
		}
	} finally {
		await response.dispose();
	}
	return target;
};

const deriveAssetState = ( failures ) => {
	if ( failures.pageError > 0 ) {
		return CAPTURE_ASSET_STATES.PAGE_ERROR;
	}
	if ( failures.consoleError > 0 ) {
		return CAPTURE_ASSET_STATES.CONSOLE_ERROR;
	}
	if ( failures.localRequestFailed > 0 ) {
		return CAPTURE_ASSET_STATES.LOCAL_REQUEST_FAILED;
	}
	if ( failures.localHttpError > 0 ) {
		return CAPTURE_ASSET_STATES.LOCAL_HTTP_ERROR;
	}
	if ( failures.incompleteImage > 0 ) {
		return CAPTURE_ASSET_STATES.INCOMPLETE_IMAGE;
	}
	if ( failures.blockedNonlocal > 0 ) {
		return CAPTURE_ASSET_STATES.BLOCKED_NONLOCAL;
	}
	return CAPTURE_ASSET_STATES.CLEAN;
};

const deriveAssetStates = ( failures ) => {
	const states = [];
	if ( failures.consoleError > 0 ) {
		states.push( CAPTURE_ASSET_STATES.CONSOLE_ERROR );
	}
	if ( failures.pageError > 0 ) {
		states.push( CAPTURE_ASSET_STATES.PAGE_ERROR );
	}
	if ( failures.blockedNonlocal > 0 ) {
		states.push( CAPTURE_ASSET_STATES.BLOCKED_NONLOCAL );
	}
	if ( failures.incompleteImage > 0 ) {
		states.push( CAPTURE_ASSET_STATES.INCOMPLETE_IMAGE );
	}
	if ( failures.localHttpError > 0 ) {
		states.push( CAPTURE_ASSET_STATES.LOCAL_HTTP_ERROR );
	}
	if ( failures.localRequestFailed > 0 ) {
		states.push( CAPTURE_ASSET_STATES.LOCAL_REQUEST_FAILED );
	}
	return states.length > 0 ? states : [ CAPTURE_ASSET_STATES.CLEAN ];
};

const waitForImages = async ( page, timeout ) => {
	await page
		.waitForFunction(
			() =>
				Array.from( document.images ).every(
					( image ) => image.complete
				),
			undefined,
			{ timeout }
		)
		.catch( () => false );
	return page.evaluate( async () => {
		const images = Array.from( document.images );
		await Promise.allSettled(
			images
				.filter( ( image ) => image.complete )
				.map( ( image ) =>
					typeof image.decode === 'function'
						? Promise.race( [
								image.decode(),
								new Promise( ( resolve ) =>
									setTimeout( resolve, 1000 )
								),
						  ] )
						: Promise.resolve()
				)
		);
		return images.filter(
			( image ) => ! image.complete || image.naturalWidth === 0
		).length;
	} );
};

const stabilizeDeck = async ( page, timeout ) => {
	await page.waitForFunction(
		() => {
			const instance =
				window.presenterReveal?.getInstance?.() ?? window.Reveal;
			return (
				typeof instance?.isReady === 'function' && instance.isReady()
			);
		},
		undefined,
		{ timeout }
	);
	await page.waitForFunction(
		() => ! document.fonts || document.fonts.status === 'loaded',
		undefined,
		{ timeout }
	);
	const metadata = await captureDeckMetadata( page );
	await page.addStyleTag( {
		content: `
			*, *::before, *::after {
				animation-delay: 0s !important;
				animation-duration: 0s !important;
				caret-color: transparent !important;
				transition-delay: 0s !important;
				transition-duration: 0s !important;
			}
		`,
	} );
	return metadata;
};

export const captureCanonicalStructure = async ( page ) =>
	page.evaluate( () => {
		const ELEMENT_NODE = 1;
		const TEXT_NODE = 3;
		const runtimeSlideClasses = new Set( [
			'future',
			'has-dark-background',
			'has-light-background',
			'past',
			'present',
			'stack',
			'wp-block-presenter-slide',
		] );
		const runtimeFragmentClasses = new Set( [
			'current-fragment',
			'visible',
		] );
		const runtimeSlideAttributes = new Set( [
			'aria-label',
			'aria-hidden',
			'data-fragment',
			'data-index-h',
			'data-index-v',
			'data-previous-indexv',
			'hidden',
		] );
		const runtimeElementAttributes = new Set( [
			'data-auto-animate-target',
			'data-lazy-loaded',
			'data-paused-by-reveal',
		] );
		const normalizeClasses = ( element, isSlideRoot ) =>
			Array.from( element.classList )
				.filter(
					( name ) =>
						! ( isSlideRoot && runtimeSlideClasses.has( name ) ) &&
						! (
							element.classList.contains( 'fragment' ) &&
							runtimeFragmentClasses.has( name )
						)
				)
				.sort();
		const normalizeSlideStyle = ( element ) => {
			for ( const property of [ 'display', 'top' ] ) {
				element.style.removeProperty( property );
			}
			if ( element.getAttribute( 'style' ) === '' ) {
				element.removeAttribute( 'style' );
			}
		};
		const normalizeElement = ( source, slideRoot = false ) => {
			const clone = source.cloneNode( true );
			// WordPress's browser polyfill replaces authored emoji text after
			// rendering. Compare its exact, trusted markup as the original text;
			// authored images that merely use the `emoji` class remain untouched.
			for ( const image of clone.querySelectorAll( 'img.emoji' ) ) {
				const alt = image.getAttribute( 'alt' );
				const sourceUrl = image.getAttribute( 'src' ) ?? '';
				const attributeNames = Array.from( image.attributes )
					.map( ( attribute ) => attribute.name )
					.sort();
				if (
					JSON.stringify( attributeNames ) ===
						JSON.stringify( [
							'alt',
							'class',
							'draggable',
							'role',
							'src',
						] ) &&
					typeof alt === 'string' &&
					alt !== '' &&
					image.getAttribute( 'class' ) === 'emoji' &&
					image.getAttribute( 'draggable' ) === 'false' &&
					image.getAttribute( 'role' ) === 'img' &&
					/^https:\/\/s\.w\.org\/images\/core\/emoji\/[0-9.]+\/svg\/[0-9a-f-]+\.svg$/u.test(
						sourceUrl
					)
				) {
					image.replaceWith( document.createTextNode( alt ) );
				}
			}
			const blockElements = new Set( [
				'ADDRESS',
				'ASIDE',
				'BLOCKQUOTE',
				'DIV',
				'FIGURE',
				'H1',
				'H2',
				'H3',
				'H4',
				'H5',
				'H6',
				'HR',
				'OL',
				'P',
				'PRE',
				'SECTION',
				'TABLE',
				'UL',
			] );
			for ( const element of [
				clone,
				...clone.querySelectorAll( '*' ),
			] ) {
				const isSlideRoot = slideRoot && element === clone;
				if ( element.hasAttribute( 'class' ) ) {
					const classNames = normalizeClasses( element, isSlideRoot );
					if ( classNames.length > 0 ) {
						element.setAttribute( 'class', classNames.join( ' ' ) );
					} else {
						element.removeAttribute( 'class' );
					}
				}
				if ( isSlideRoot ) {
					for ( const attribute of runtimeSlideAttributes ) {
						element.removeAttribute( attribute );
					}
					normalizeSlideStyle( element );
				}
				for ( const attribute of runtimeElementAttributes ) {
					element.removeAttribute( attribute );
				}
				if ( element.matches( 'section, aside.notes' ) ) {
					for ( const child of Array.from( element.childNodes ) ) {
						if (
							child.nodeType !== TEXT_NODE ||
							child.textContent.trim() !== ''
						) {
							continue;
						}
						const previous = child.previousSibling;
						const next = child.nextSibling;
						if (
							! previous ||
							! next ||
							( previous.nodeType === ELEMENT_NODE &&
								next.nodeType === ELEMENT_NODE &&
								blockElements.has( previous.nodeName ) &&
								blockElements.has( next.nodeName ) )
						) {
							child.remove();
						}
					}
				}
				const attributes = Array.from( element.attributes ).sort(
					( a, b ) => a.name.localeCompare( b.name )
				);
				for ( const attribute of attributes ) {
					element.removeAttribute( attribute.name );
				}
				for ( const attribute of attributes ) {
					element.setAttribute( attribute.name, attribute.value );
				}
			}
			return slideRoot ? clone.innerHTML : clone.outerHTML;
		};
		const attributesOf = ( element, slideRoot = false ) => {
			const attributes = Object.fromEntries(
				Array.from( element.attributes )
					.filter(
						( attribute ) =>
							! runtimeElementAttributes.has( attribute.name ) &&
							! (
								slideRoot &&
								runtimeSlideAttributes.has( attribute.name )
							)
					)
					.map( ( attribute ) => [ attribute.name, attribute.value ] )
					.sort( ( a, b ) => a[ 0 ].localeCompare( b[ 0 ] ) )
			);
			if ( typeof attributes.class === 'string' ) {
				attributes.class = normalizeClasses( element, slideRoot ).join(
					' '
				);
				if ( attributes.class === '' ) {
					delete attributes.class;
				}
			}
			if ( slideRoot && typeof attributes.style === 'string' ) {
				const styleSource = element.cloneNode( false );
				normalizeSlideStyle( styleSource );
				const style = styleSource.getAttribute( 'style' );
				if ( style ) {
					attributes.style = style;
				} else {
					delete attributes.style;
				}
			}
			return attributes;
		};

		const horizontalSlides = Array.from(
			document.querySelectorAll( '.reveal > .slides > section' )
		);
		const leaves = [];
		horizontalSlides.forEach( ( horizontal, horizontalIndex ) => {
			const verticalSlides = Array.from(
				horizontal.querySelectorAll( ':scope > section' )
			);
			const slides =
				verticalSlides.length > 0 ? verticalSlides : [ horizontal ];
			slides.forEach( ( slide, verticalIndex ) => {
				const fragments = Array.from(
					slide.querySelectorAll( '.fragment' )
				).filter( ( fragment ) => ! fragment.closest( 'aside.notes' ) );
				leaves.push( {
					attributes: attributesOf( slide, true ),
					canonicalHtml: normalizeElement( slide, true ),
					finalFragmentIndex: fragments.length - 1,
					fragmentCount: fragments.length,
					fragments: fragments.map( ( fragment, fragmentIndex ) => ( {
						attributes: attributesOf( fragment ),
						canonicalHtml: normalizeElement( fragment ),
						fragmentOrdinal: fragmentIndex + 1,
					} ) ),
					horizontalIndex,
					notes: Array.from(
						slide.querySelectorAll( ':scope > aside.notes' )
					).map( ( note ) => ( {
						html: note.innerHTML,
						markdown: note.hasAttribute( 'data-markdown' ),
					} ) ),
					verticalIndex:
						verticalSlides.length > 0 ? verticalIndex : 0,
				} );
			} );
		} );
		return leaves;
	} );

export const captureDeckMetadata = async ( page ) =>
	page.evaluate( () => {
		const instance =
			window.presenterReveal?.getInstance?.() ?? window.Reveal;
		const config = instance.getConfig();
		const semanticKeys = [
			'backgroundTransition',
			'center',
			'controls',
			'keyboard',
			'margin',
			'progress',
			'transition',
		];
		const semanticConfig = Object.fromEntries(
			semanticKeys
				.filter( ( key ) =>
					[ 'boolean', 'number', 'string' ].includes(
						typeof config[ key ]
					)
				)
				.map( ( key ) => [ key, config[ key ] ] )
		);
		semanticConfig.urlNavigation = Boolean( config.hash || config.history );
		let slideOrdinal = 0;
		const horizontalSlides = Array.from(
			document.querySelectorAll( '.reveal > .slides > section' )
		);
		const hierarchy = horizontalSlides.map(
			( horizontal, horizontalIndex ) => {
				const verticalSlides = Array.from(
					horizontal.querySelectorAll( ':scope > section' )
				);
				const leaves =
					verticalSlides.length > 0 ? verticalSlides : [ horizontal ];
				return {
					horizontalIndex,
					kind: verticalSlides.length > 0 ? 'stack' : 'slide',
					leaves: leaves.map( ( _slide, verticalIndex ) => ( {
						slideOrdinal: ++slideOrdinal,
						verticalIndex:
							verticalSlides.length > 0 ? verticalIndex : 0,
					} ) ),
				};
			}
		);
		const themeStylesheet = Array.from(
			document.querySelectorAll( 'link[rel~="stylesheet"]' )
		).find( ( stylesheet ) =>
			[ 'reveal-theme', 'reveal-theme-css' ].includes( stylesheet.id )
		);
		const themeStylesheets = [];
		if ( themeStylesheet ) {
			const url = new URL( themeStylesheet.href, document.baseURI );
			const builtin = url.pathname.match(
				/\/(?:reveal\.js\/(?:css|dist)|build\/reveal)\/theme\/([a-z0-9-]+)\.css$/iu
			);
			themeStylesheets.push( {
				identity: builtin
					? `builtin:${ builtin[ 1 ].toLowerCase() }`
					: `stylesheet:${ url.origin }${ url.pathname }`,
				media: themeStylesheet.media || 'all',
			} );
		}

		return {
			dimensions: {
				height: config.height,
				width: config.width,
			},
			hierarchy,
			semanticConfig,
			themeStylesheets,
		};
	} );

export const activateFragmentState = async ( page, slide, state, timeout ) => {
	await page.evaluate(
		( { indices, requestedState } ) => {
			const instance =
				window.presenterReveal?.getInstance?.() ?? window.Reveal;
			let fragmentIndex;
			if ( requestedState === 'final' ) {
				fragmentIndex = Number.MAX_SAFE_INTEGER;
			} else if ( indices.fragmentCount > 0 ) {
				fragmentIndex = -1;
			}
			if ( typeof fragmentIndex === 'number' ) {
				instance.slide(
					indices.horizontalIndex,
					indices.verticalIndex,
					fragmentIndex
				);
			} else {
				instance.slide(
					indices.horizontalIndex,
					indices.verticalIndex
				);
			}
		},
		{ indices: slide, requestedState: state }
	);
	await page.waitForFunction(
		( { indices, requestedState } ) => {
			const instance =
				window.presenterReveal?.getInstance?.() ?? window.Reveal;
			const current = instance.getIndices();
			const fragments = Array.from(
				instance.getCurrentSlide()?.querySelectorAll( '.fragment' ) ??
					[]
			).filter( ( fragment ) => ! fragment.closest( 'aside.notes' ) );
			const fragmentsMatch =
				requestedState === 'final'
					? fragments.every( ( fragment ) =>
							fragment.classList.contains( 'visible' )
					  )
					: fragments.every(
							( fragment ) =>
								! fragment.classList.contains( 'visible' )
					  );
			return (
				current.h === indices.horizontalIndex &&
				current.v === indices.verticalIndex &&
				fragmentsMatch
			);
		},
		{ indices: slide, requestedState: state },
		{ timeout }
	);
	await page.evaluate( async () => {
		await new Promise( ( resolve ) =>
			window.requestAnimationFrame( () =>
				window.requestAnimationFrame( resolve )
			)
		);
	} );
};

const waitForCurrentSlideImages = async ( page, timeout ) => {
	await page
		.waitForFunction(
			() => {
				const instance =
					window.presenterReveal?.getInstance?.() ?? window.Reveal;
				const images = Array.from(
					instance
						.getCurrentSlide()
						?.querySelectorAll( 'img[src], img[data-src]' ) ?? []
				).filter( ( image ) => ! image.closest( 'aside.notes' ) );
				return images.every(
					( image ) => image.complete && image.naturalWidth > 0
				);
			},
			undefined,
			{ timeout: Math.min( timeout, 1000 ) }
		)
		.catch( () => false );
	await page.evaluate( async () => {
		const instance =
			window.presenterReveal?.getInstance?.() ?? window.Reveal;
		const images = Array.from(
			instance
				.getCurrentSlide()
				?.querySelectorAll( 'img[src], img[data-src]' ) ?? []
		).filter(
			( image ) =>
				! image.closest( 'aside.notes' ) &&
				image.complete &&
				image.naturalWidth > 0
		);
		await Promise.allSettled(
			images.map( ( image ) =>
				typeof image.decode === 'function'
					? Promise.race( [
							image.decode(),
							new Promise( ( resolve ) =>
								setTimeout( resolve, 1000 )
							),
					  ] )
					: Promise.resolve()
			)
		);
	} );
};

/**
 * Pause the selected Reveal background video on a decoded frame at time zero.
 * Streaming requests are intentionally not treated as network-idle work.
 *
 * @param {import('@playwright/test').Page} page    Playwright page.
 * @param {number}                          timeout Readiness timeout.
 * @return {Promise<string[]>} Exact current sources that passed the barrier.
 */
export const stabilizeCurrentSlideMedia = async ( page, timeout ) => {
	try {
		await page.waitForFunction(
			() => {
				const instance =
					window.presenterReveal?.getInstance?.() ?? window.Reveal;
				const currentSlide = instance.getCurrentSlide();
				const backgrounds = Array.from(
					document.querySelectorAll(
						'.slide-background.present video'
					)
				);
				if (
					currentSlide?.hasAttribute( 'data-background-video' ) &&
					backgrounds.length === 0
				) {
					return false;
				}
				const authored = Array.from(
					currentSlide?.querySelectorAll( 'video' ) ?? []
				).filter( ( video ) => ! video.closest( 'aside.notes' ) );
				return [ ...new Set( [ ...authored, ...backgrounds ] ) ].every(
					( video ) =>
						! video.error &&
						video.currentSrc !== '' &&
						video.readyState >=
							window.HTMLMediaElement.HAVE_CURRENT_DATA &&
						video.videoWidth > 0 &&
						video.videoHeight > 0
				);
			},
			undefined,
			{ timeout }
		);
	} catch {
		throw new RenderedDeckCaptureError( 'media-not-ready' );
	}

	try {
		await page.evaluate(
			async ( seekTimeout ) => {
				const instance =
					window.presenterReveal?.getInstance?.() ?? window.Reveal;
				const authored = Array.from(
					instance.getCurrentSlide()?.querySelectorAll( 'video' ) ??
						[]
				).filter( ( video ) => ! video.closest( 'aside.notes' ) );
				const backgrounds = Array.from(
					document.querySelectorAll(
						'.slide-background.present video'
					)
				);
				for ( const video of new Set( [
					...authored,
					...backgrounds,
				] ) ) {
					video.pause();
					if (
						video.seeking ||
						Math.abs( video.currentTime ) > 0.001
					) {
						await new Promise( ( resolve, reject ) => {
							const onSeeked = () => {
								clearTimeout( timer );
								resolve();
							};
							const timer = setTimeout( () => {
								video.removeEventListener( 'seeked', onSeeked );
								reject( new Error( 'media-seek-timeout' ) );
							}, seekTimeout );
							video.addEventListener( 'seeked', onSeeked, {
								once: true,
							} );
							if ( ! video.seeking ) {
								video.currentTime = 0;
							}
						} );
					}
					video.pause();
				}
				await new Promise( ( resolve ) =>
					window.requestAnimationFrame( () =>
						window.requestAnimationFrame( resolve )
					)
				);
			},
			Math.min( timeout, 2000 )
		);
		await page.waitForFunction(
			() => {
				const instance =
					window.presenterReveal?.getInstance?.() ?? window.Reveal;
				const currentSlide = instance.getCurrentSlide();
				const backgrounds = Array.from(
					document.querySelectorAll(
						'.slide-background.present video'
					)
				);
				if (
					currentSlide?.hasAttribute( 'data-background-video' ) &&
					backgrounds.length === 0
				) {
					return false;
				}
				const authored = Array.from(
					currentSlide?.querySelectorAll( 'video' ) ?? []
				).filter( ( video ) => ! video.closest( 'aside.notes' ) );
				return [ ...new Set( [ ...authored, ...backgrounds ] ) ].every(
					( video ) =>
						video.paused &&
						! video.seeking &&
						Math.abs( video.currentTime ) <= 0.001
				);
			},
			undefined,
			{ timeout }
		);
	} catch {
		throw new RenderedDeckCaptureError( 'media-seek-timeout' );
	}
	return page.evaluate( () => {
		const instance =
			window.presenterReveal?.getInstance?.() ?? window.Reveal;
		const authored = Array.from(
			instance.getCurrentSlide()?.querySelectorAll( 'video' ) ?? []
		).filter( ( video ) => ! video.closest( 'aside.notes' ) );
		const backgrounds = Array.from(
			document.querySelectorAll( '.slide-background.present video' )
		);
		return [ ...new Set( [ ...authored, ...backgrounds ] ) ]
			.map( ( video ) => video.currentSrc )
			.filter( ( source ) => source !== '' )
			.sort();
	} );
};

/**
 * Track static resources that may begin only after Reveal activates a slide.
 * Media requests are deliberately excluded because streams and range requests
 * need a separate deterministic capture policy.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {(timeout:number) => Promise<void>} Bounded static-resource barrier.
 */
export const createStaticResourceBarrier = ( page ) => {
	const pending = new Set();
	const staticTypes = new Set( [ 'font', 'image', 'stylesheet' ] );
	page.on( 'request', ( request ) => {
		if ( staticTypes.has( request.resourceType() ) ) {
			pending.add( request );
		}
	} );
	const finish = ( request ) => pending.delete( request );
	page.on( 'requestfinished', finish );
	page.on( 'requestfailed', finish );

	return async ( timeout ) => {
		const deadline = Date.now() + timeout;
		let idlePaints = 0;
		while ( Date.now() < deadline ) {
			await page.evaluate(
				() =>
					new Promise( ( resolve ) =>
						window.requestAnimationFrame( () =>
							window.requestAnimationFrame( resolve )
						)
					)
			);
			idlePaints = pending.size === 0 ? idlePaints + 1 : 0;
			if ( idlePaints >= 2 ) {
				return;
			}
			await new Promise( ( resolve ) => setTimeout( resolve, 10 ) );
		}
		throw new RenderedDeckCaptureError( 'static-resource-timeout' );
	};
};

/**
 * Activate every leaf slide so browser-native lazy loading starts before the
 * capture records asset failures or screenshots. Restore the exact Reveal
 * address afterward so capture does not change the deck's initial state.
 *
 * @param {import('@playwright/test').Page}          page                  Playwright page.
 * @param {Object[]}                                 slides                Canonical leaf slides.
 * @param {number}                                   timeout               Readiness timeout.
 * @param {((timeout:number) => Promise<void>)|null} staticResourceBarrier Static-resource barrier.
 * @param {(targets:string[]) => void}               onMediaStabilized     Successful-media observer.
 * @return {Promise<void>}
 */
export const prewarmSlideImages = async (
	page,
	slides,
	timeout,
	staticResourceBarrier = null,
	onMediaStabilized = () => {}
) => {
	if ( typeof onMediaStabilized !== 'function' ) {
		throw new RenderedDeckCaptureError( 'invalid-media-observer' );
	}
	const initialState = await page.evaluate( () => {
		const instance =
			window.presenterReveal?.getInstance?.() ?? window.Reveal;
		const indices = instance.getIndices();
		return {
			fragmentIndex: Number.isInteger( indices.f ) ? indices.f : -1,
			horizontalIndex: indices.h,
			verticalIndex: indices.v ?? 0,
		};
	} );

	for ( const slide of slides ) {
		await activateFragmentState( page, slide, 'initial', timeout );
		if ( staticResourceBarrier ) {
			await staticResourceBarrier( timeout );
		}
		await waitForCurrentSlideImages( page, timeout );
		onMediaStabilized( await stabilizeCurrentSlideMedia( page, timeout ) );
	}

	await page.evaluate( ( state ) => {
		const instance =
			window.presenterReveal?.getInstance?.() ?? window.Reveal;
		instance.slide(
			state.horizontalIndex,
			state.verticalIndex,
			state.fragmentIndex
		);
	}, initialState );
	if ( staticResourceBarrier ) {
		await staticResourceBarrier( timeout );
	}
	onMediaStabilized( await stabilizeCurrentSlideMedia( page, timeout ) );
	await page.waitForFunction(
		( state ) => {
			const instance =
				window.presenterReveal?.getInstance?.() ?? window.Reveal;
			const current = instance.getIndices();
			const currentFragmentIndex = Number.isInteger( current.f )
				? current.f
				: -1;
			return (
				current.h === state.horizontalIndex &&
				( current.v ?? 0 ) === state.verticalIndex &&
				currentFragmentIndex === state.fragmentIndex
			);
		},
		initialState,
		{ timeout }
	);
};

/**
 * Capture one locally rendered deck in a fresh browser context.
 *
 * Raw DOM values are returned to the caller and are never persisted by this
 * module. Only screenshots are written. The caller must digest or redact the
 * returned structure before writing a durable comparison report.
 *
 * @param {Object}                             options                                          Capture options.
 * @param {import('@playwright/test').Browser} [options.browser]                                Optional browser.
 * @param {Object}                             [options.assetSubstitutionResolver]              Verified snapshot-only resolver.
 * @param {boolean}                            [options.captureFrames=true]                     Whether to write visual frames.
 * @param {number}                             options.captureOrdinal                           Opaque capture ordinal.
 * @param {string}                             options.deckUrl                                  Local snapshot URL.
 * @param {string}                             [options.expectedOrigin='http://localhost:8890'] Expected local origin.
 * @param {string}                             options.privateRoot                              Private screenshot root.
 * @param {number}                             [options.timeout=15000]                          Readiness timeout in milliseconds.
 * @param {{height:number,width:number}}       [options.viewport]                               Fixed viewport.
 * @return {Promise<Object>} In-memory structure and private artifact references.
 */
export const captureRenderedDeck = async ( {
	assetSubstitutionResolver = null,
	browser: callerBrowser,
	captureFrames = true,
	captureOrdinal,
	deckUrl,
	expectedOrigin = DEFAULT_ORIGIN,
	privateRoot,
	timeout = 15000,
	viewport = DEFAULT_VIEWPORT,
} ) => {
	const origin = validateExpectedOrigin( expectedOrigin );
	const target = validateDeckUrl( deckUrl, origin );
	const root = await validatePrivateCaptureRoot( privateRoot );
	const ordinal = validateOrdinal(
		captureOrdinal,
		'invalid-capture-ordinal'
	);
	if (
		( assetSubstitutionResolver !== null &&
			( typeof assetSubstitutionResolver !== 'object' ||
				typeof assetSubstitutionResolver.resolve !== 'function' ) ) ||
		typeof captureFrames !== 'boolean' ||
		! Number.isSafeInteger( timeout ) ||
		timeout < 1000 ||
		timeout > 120000 ||
		! Number.isSafeInteger( viewport?.height ) ||
		! Number.isSafeInteger( viewport?.width ) ||
		viewport.height < 1 ||
		viewport.width < 1
	) {
		throw new RenderedDeckCaptureError( 'invalid-capture-options' );
	}

	const captureDirectory = path.join( root, `capture-${ ordinal }` );
	if ( ! isInside( root, captureDirectory ) ) {
		throw new RenderedDeckCaptureError( 'invalid-artifact-path' );
	}
	try {
		await mkdir( captureDirectory, { recursive: true } );
	} catch {
		throw new RenderedDeckCaptureError( 'artifact-directory-unavailable' );
	}
	let canonicalCaptureDirectory;
	try {
		canonicalCaptureDirectory = await realpath( captureDirectory );
	} catch {
		throw new RenderedDeckCaptureError( 'artifact-directory-unavailable' );
	}
	if ( ! isInside( root, canonicalCaptureDirectory ) ) {
		throw new RenderedDeckCaptureError( 'artifact-directory-escaped-root' );
	}

	let ownedBrowser;
	let context;
	let stage = 'browser';
	const failures = {
		blockedNonlocal: 0,
		consoleError: 0,
		incompleteImage: 0,
		localHttpError: 0,
		localRequestFailed: 0,
		pageError: 0,
	};
	const appliedSubstitutions = new Map();
	const rewrittenMediaTargets =
		assetSubstitutionResolver?.rewrittenMediaTargets?.() ?? [];
	const verifiedMediaTargetSet = new Set( rewrittenMediaTargets );
	const stabilizedMediaTargets = new Set();
	const verifiedMediaAborts = [];
	const recordStabilizedMedia = ( targets ) => {
		for ( const targetUrl of targets ) {
			if ( verifiedMediaTargetSet.has( targetUrl ) ) {
				stabilizedMediaTargets.add( targetUrl );
			}
		}
	};

	try {
		const browser =
			callerBrowser ?? ( ownedBrowser = await chromium.launch() );
		if ( ! browser?.isConnected() ) {
			throw new RenderedDeckCaptureError( 'browser-unavailable' );
		}
		context = await browser.newContext( {
			colorScheme: 'light',
			deviceScaleFactor: 1,
			locale: 'en-US',
			reducedMotion: 'reduce',
			serviceWorkers: 'block',
			timezoneId: 'UTC',
			viewport,
		} );
		stage = 'navigation-target';
		const navigationTarget = await resolveDeckNavigationTarget(
			context.request,
			target,
			origin,
			timeout
		);
		stage = 'context';
		await context.route( '**/*', async ( route ) => {
			if (
				await rewriteDeckDocument(
					route,
					assetSubstitutionResolver,
					navigationTarget,
					appliedSubstitutions
				)
			) {
				return;
			}
			if (
				await fulfillRewrittenTarget( route, assetSubstitutionResolver )
			) {
				return;
			}
			if (
				await fulfillAssetSubstitution(
					route,
					assetSubstitutionResolver,
					appliedSubstitutions
				)
			) {
				return;
			}
			if ( requestIsAllowed( route.request().url(), origin ) ) {
				await route.continue();
				return;
			}
			failures.blockedNonlocal++;
			await route.abort( 'blockedbyclient' );
		} );

		const page = await context.newPage();
		await installMediaCapturePolicy( page, rewrittenMediaTargets );
		const staticResourceBarrier = createStaticResourceBarrier( page );
		page.on( 'console', ( message ) => {
			if ( message.type() === 'error' ) {
				failures.consoleError++;
			}
		} );
		page.on( 'pageerror', () => {
			failures.pageError++;
		} );
		page.on( 'response', ( response ) => {
			if (
				isSnapshotRequest( response.url(), origin ) &&
				response.status() >= 400
			) {
				failures.localHttpError++;
			}
		} );
		page.on( 'requestfailed', ( request ) => {
			if (
				isVerifiedRewrittenMediaAbort(
					request,
					assetSubstitutionResolver
				)
			) {
				verifiedMediaAborts.push( request.url() );
			} else if ( shouldCountLocalRequestFailure( request, origin ) ) {
				failures.localRequestFailed++;
			}
		} );

		stage = 'navigation';
		const response = await page.goto( navigationTarget, {
			timeout,
			waitUntil: 'domcontentloaded',
		} );
		if ( response?.status() !== 200 ) {
			throw new RenderedDeckCaptureError( 'deck-http-not-200' );
		}

		stage = 'stabilization';
		const metadata = await stabilizeDeck( page, timeout );
		stage = 'prewarm-structure';
		const prewarmSlides = await captureCanonicalStructure( page );
		if ( prewarmSlides.length === 0 ) {
			throw new RenderedDeckCaptureError( 'deck-has-no-leaf-slides' );
		}
		stage = 'image-prewarm';
		await prewarmSlideImages(
			page,
			prewarmSlides,
			timeout,
			staticResourceBarrier,
			recordStabilizedMedia
		);
		stage = 'images';
		failures.incompleteImage = await waitForImages( page, timeout );
		stage = 'structure';
		const slides = prewarmSlides;

		const frames = [];
		let frameOrdinal = 0;
		for ( const [ index, slide ] of captureFrames
			? slides.entries()
			: [] ) {
			const states =
				slide.fragmentCount > 0
					? [ 'initial', 'final' ]
					: [ 'initial' ];
			for ( const state of states ) {
				stage = 'frame-state';
				await activateFragmentState( page, slide, state, timeout );
				recordStabilizedMedia(
					await stabilizeCurrentSlideMedia( page, timeout )
				);
				frameOrdinal++;
				const opaqueFrameOrdinal = String( frameOrdinal ).padStart(
					6,
					'0'
				);
				const filename = path.join(
					canonicalCaptureDirectory,
					`frame-${ opaqueFrameOrdinal }.png`
				);
				if ( ! isInside( root, filename ) ) {
					throw new RenderedDeckCaptureError(
						'invalid-artifact-path'
					);
				}
				stage = 'screenshot';
				await page.screenshot( {
					animations: 'disabled',
					path: filename,
				} );
				frames.push( {
					file: path
						.relative( root, filename )
						.replaceAll( '\\', '/' ),
					frameOrdinal,
					slideOrdinal: index + 1,
					state,
				} );
			}
		}

		await page.evaluate(
			() =>
				new Promise( ( resolve ) =>
					window.requestAnimationFrame( resolve )
				)
		);
		failures.localRequestFailed += countUnstabilizedMediaAborts(
			verifiedMediaAborts,
			stabilizedMediaTargets
		);

		return {
			assets: {
				...failures,
				substitutions: [ ...appliedSubstitutions.entries() ]
					.sort( ( first, second ) =>
						first[ 0 ].localeCompare( second[ 0 ] )
					)
					.map( ( [ entryDigest, count ] ) => ( {
						entryDigest,
						count,
					} ) ),
				state: deriveAssetState( failures ),
				states: deriveAssetStates( failures ),
			},
			schemaVersion: 1,
			frames,
			metadata,
			slideCount: slides.length,
			slides,
			viewport: { ...viewport },
		};
	} catch ( error ) {
		if ( error instanceof RenderedDeckCaptureError ) {
			throw error;
		}
		throw new RenderedDeckCaptureError( `capture-${ stage }-failed` );
	} finally {
		await context?.close().catch( () => {} );
		await ownedBrowser?.close().catch( () => {} );
	}
};
