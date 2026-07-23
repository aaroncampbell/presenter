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

const activateFragmentState = async ( page, slide, state, timeout ) => {
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
			instance.sync();
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
		stage = 'context';
		await context.route( '**/*', async ( route ) => {
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
			if ( isSnapshotRequest( request.url(), origin ) ) {
				failures.localRequestFailed++;
			}
		} );

		stage = 'navigation';
		const response = await page.goto( target, {
			timeout,
			waitUntil: 'domcontentloaded',
		} );
		if ( response?.status() !== 200 ) {
			throw new RenderedDeckCaptureError( 'deck-http-not-200' );
		}

		stage = 'stabilization';
		const metadata = await stabilizeDeck( page, timeout );
		stage = 'images';
		failures.incompleteImage = await waitForImages( page, timeout );
		stage = 'structure';
		const slides = await captureCanonicalStructure( page );
		if ( slides.length === 0 ) {
			throw new RenderedDeckCaptureError( 'deck-has-no-leaf-slides' );
		}

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
