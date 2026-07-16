/* eslint-disable no-console -- This command emits content-free verification results. */
import assert from 'node:assert/strict';
import { createReadStream } from 'node:fs';
import { access, readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { dirname, extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

import { chromium } from '@playwright/test';

const toolDirectory = dirname( fileURLToPath( import.meta.url ) );
const pluginDirectory = normalize( join( toolDirectory, '..', '..' ) );
const buildDirectory = join( pluginDirectory, 'build' );
const fixtureSentinel = 'PRIVATE_FIXTURE_CONTENT_DO_NOT_LOG';

const contentTypes = new Map( [
	[ '.css', 'text/css; charset=utf-8' ],
	[ '.js', 'text/javascript; charset=utf-8' ],
] );

const validFixture = `<!doctype html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<link rel="stylesheet" href="/build/reveal/reveal.css">
		<script>
			window.__presenterReadyCount = 0;
			window.__presenterConfigExecuted = false;
			document.addEventListener( 'presenter:reveal:ready', () => {
				window.__presenterReadyCount += 1;
			} );
		</script>
	</head>
	<body>
		<div class="reveal" data-presenter-reveal-root>
			<div class="slides">
				<section><h1>${ fixtureSentinel }</h1><p class="fragment">Fragment</p></section>
				<section><h2>Second fixture slide</h2></section>
			</div>
		</div>
		<script type="application/json" data-presenter-reveal-config>{"reveal":{"probe":"\\u003cimg src=x onerror=window.__presenterConfigExecuted=true>"},"plugins":["search","zoom","notes"]}</script>
		<script src="/build/frontend.js"></script>
	</body>
</html>`;

const unsafeFixture = `<!doctype html>
<html lang="en">
	<head><meta charset="utf-8"></head>
	<body>
		<div class="reveal" data-presenter-reveal-root><div class="slides"><section>Unsafe configuration fixture</section></div></div>
		<script type="application/json" data-presenter-reveal-config>{"reveal":{"__proto__":{"presenterPolluted":true}},"plugins":[]}</script>
		<script src="/build/frontend.js"></script>
	</body>
</html>`;

/**
 * Start the origin-confined fixture server.
 *
 * @return {Promise<{ close: Function, origin: string, requests: string[] }>} Server controls.
 */
async function startFixtureServer() {
	const requests = [];
	const server = createServer( async ( request, response ) => {
		const requestUrl = new URL( request.url || '/', 'http://127.0.0.1' );
		requests.push( requestUrl.pathname + requestUrl.search );

		if ( requestUrl.pathname === '/' ) {
			response.writeHead( 200, {
				'Content-Type': 'text/html; charset=utf-8',
			} );
			response.end( validFixture );
			return;
		}

		if ( requestUrl.pathname === '/unsafe-config' ) {
			response.writeHead( 200, {
				'Content-Type': 'text/html; charset=utf-8',
			} );
			response.end( unsafeFixture );
			return;
		}

		if ( ! requestUrl.pathname.startsWith( '/build/' ) ) {
			response.writeHead( 404 );
			response.end();
			return;
		}

		const relativePath = normalize(
			requestUrl.pathname.slice( '/build/'.length )
		);
		const filePath = join( buildDirectory, relativePath );

		if ( ! filePath.startsWith( buildDirectory ) ) {
			response.writeHead( 403 );
			response.end();
			return;
		}

		try {
			await access( filePath );
			response.writeHead( 200, {
				'Content-Type':
					contentTypes.get( extname( filePath ) ) ||
					'application/octet-stream',
			} );
			createReadStream( filePath ).pipe( response );
		} catch {
			response.writeHead( 404 );
			response.end();
		}
	} );

	await new Promise( ( resolve, reject ) => {
		server.once( 'error', reject );
		server.listen( 0, '127.0.0.1', resolve );
	} );

	const address = server.address();
	assert( address && typeof address === 'object' );

	return {
		close: () =>
			new Promise( ( resolve, reject ) => {
				server.close( ( error ) =>
					error ? reject( error ) : resolve()
				);
			} ),
		origin: `http://127.0.0.1:${ address.port }`,
		requests,
	};
}

await Promise.all( [
	access( join( buildDirectory, 'frontend.js' ) ),
	access( join( buildDirectory, 'reveal', 'reveal.css' ) ),
] );

const server = await startFixtureServer();
const browser = await chromium.launch();

try {
	const externalRequests = [];
	const failedRequests = [];
	const pageErrors = [];
	const context = await browser.newContext( {
		viewport: { height: 720, width: 1280 },
	} );
	const page = await context.newPage();

	page.on( 'request', ( request ) => {
		if ( new URL( request.url() ).origin !== server.origin ) {
			externalRequests.push( request.url() );
		}
	} );
	page.on( 'requestfailed', ( request ) => {
		failedRequests.push( request.url() );
	} );
	page.on( 'pageerror', ( error ) => {
		pageErrors.push( error.message );
	} );

	const response = await page.goto( server.origin, {
		waitUntil: 'networkidle',
	} );
	assert.equal( response?.status(), 200 );
	await page.waitForFunction(
		() =>
			window.presenterReveal?.getInstance()?.isReady() === true &&
			window.__presenterReadyCount === 1
	);

	const initialState = await page.evaluate( () => {
		const instance = window.presenterReveal.getInstance();
		const configElement = document.querySelector(
			'[data-presenter-reveal-config]'
		);

		return {
			configElementType: configElement?.getAttribute( 'type' ),
			configExecuted: window.__presenterConfigExecuted,
			geometry: {
				height: instance.getConfig().height,
				width: instance.getConfig().width,
			},
			indices: instance.getIndices(),
			pluginIds: Object.keys( instance.getPlugins() ).sort(),
			readyCount: window.__presenterReadyCount,
			slideCount: instance.getTotalSlides(),
		};
	} );

	assert.equal( initialState.configElementType, 'application/json' );
	assert.equal( initialState.configExecuted, false );
	assert.deepEqual( initialState.geometry, { height: 720, width: 1280 } );
	assert.equal( initialState.readyCount, 1 );
	assert.equal( initialState.slideCount, 2 );
	assert.deepEqual( initialState.pluginIds, [ 'notes', 'search', 'zoom' ] );

	await page.keyboard.press( 'ArrowRight' );
	await page.waitForFunction(
		() =>
			document.querySelectorAll(
				'.reveal .slides section.present .fragment.visible'
			).length === 1
	);
	const fragmentIndices = await page.evaluate( () =>
		window.presenterReveal.getInstance().getIndices()
	);
	assert.equal( fragmentIndices.h, 0 );

	await page.keyboard.press( 'ArrowRight' );
	await page.waitForFunction(
		() => window.presenterReveal.getInstance().getIndices().h === 1
	);

	await page.evaluate( () => {
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
	} );
	await page.waitForTimeout( 100 );
	assert.equal(
		await page.evaluate( () => window.__presenterReadyCount ),
		1,
		'Reveal must initialize exactly once.'
	);

	assert.deepEqual( externalRequests, [] );
	assert.deepEqual( failedRequests, [] );
	assert.deepEqual( pageErrors, [] );

	const requestedChunkNames = server.requests
		.filter( ( requestPath ) =>
			/^\/build\/\d+\.js\?ver=/.test( requestPath )
		)
		.map(
			( requestPath ) => requestPath.split( '/' ).pop().split( '?' )[ 0 ]
		);
	assert.equal( new Set( requestedChunkNames ).size, 3 );
	assert( server.requests.includes( '/build/reveal/reveal.css' ) );
	assert(
		server.requests.every(
			( requestPath ) => ! requestPath.includes( fixtureSentinel )
		)
	);

	await context.close();

	const unsafeContext = await browser.newContext();
	const unsafePage = await unsafeContext.newPage();
	const unsafeConsoleMessages = [];
	const unsafePageErrors = [];
	unsafePage.on( 'console', ( message ) => {
		unsafeConsoleMessages.push( {
			text: message.text(),
			type: message.type(),
		} );
	} );
	unsafePage.on( 'pageerror', ( error ) => {
		unsafePageErrors.push( error.message );
	} );
	await unsafePage.goto( `${ server.origin }/unsafe-config`, {
		waitUntil: 'networkidle',
	} );
	await unsafePage.waitForFunction(
		() =>
			window.presenterReveal?.getInstance() === null &&
			! Object.prototype.presenterPolluted
	);
	await unsafePage.waitForTimeout( 100 );
	assert.equal(
		await unsafePage.evaluate(
			() => Object.prototype.presenterPolluted === undefined
		),
		true
	);
	assert.deepEqual( unsafePageErrors, [] );
	assert(
		unsafeConsoleMessages.some(
			( message ) =>
				message.type === 'error' &&
				message.text.includes( 'unsupported property' )
		),
		`Expected a configuration error; received ${ JSON.stringify( {
			unsafeConsoleMessages,
			unsafePageErrors,
		} ) }.`
	);
	await unsafeContext.close();

	const frontendSource = await readFile(
		join( buildDirectory, 'frontend.js' ),
		'utf8'
	);
	assert( ! frontendSource.includes( fixtureSentinel ) );

	console.log(
		JSON.stringify(
			{
				configuredPlugins: initialState.pluginIds.length,
				dynamicChunksLoaded: new Set( requestedChunkNames ).size,
				externalRequests: externalRequests.length,
				geometry: initialState.geometry,
				navigation: true,
				passed: true,
				safeJsonRejectedPrototypeKeys: true,
				singleInitialization: true,
			},
			null,
			2
		)
	);
} finally {
	await browser.close();
	await server.close();
}
