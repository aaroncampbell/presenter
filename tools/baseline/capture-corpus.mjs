import { createHash, createHmac, randomBytes } from 'node:crypto';
import {
	chmod,
	mkdir,
	readFile,
	rename,
	stat,
	writeFile,
} from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import { chromium } from '@playwright/test';

const repositoryRoot = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'../..'
);
const corpusDirectory = path.join(
	repositoryRoot,
	'local',
	'acceptance-corpus'
);
const baselineDirectory = path.join( corpusDirectory, 'baseline' );
const corpusFile = path.join( corpusDirectory, 'corpus.json' );
const keyFile = path.join( corpusDirectory, '.hmac-key' );
const outputFile = path.join( baselineDirectory, 'manifest.json' );
const snapshotEnvironment = path.join(
	repositoryRoot,
	'tools',
	'snapshot',
	'env'
);
const snapshotOrigin = new URL( 'http://localhost:8890' );

const assertLocalPath = ( candidate ) => {
	const relative = path.relative(
		corpusDirectory,
		path.resolve( candidate )
	);
	if ( relative.startsWith( '..' ) || path.isAbsolute( relative ) ) {
		throw new Error(
			'Refusing to write outside the ignored acceptance corpus.'
		);
	}
};

const getKey = async () => {
	await mkdir( corpusDirectory, { recursive: true } );
	try {
		const value = ( await readFile( keyFile, 'utf8' ) ).trim();
		if ( ! /^[a-f0-9]{64}$/u.test( value ) ) {
			throw new Error(
				'The ignored acceptance-corpus HMAC key is invalid.'
			);
		}
		return Buffer.from( value, 'hex' );
	} catch ( error ) {
		if ( error.code !== 'ENOENT' ) {
			throw error;
		}

		const value = randomBytes( 32 );
		await writeFile( keyFile, `${ value.toString( 'hex' ) }\n`, {
			encoding: 'utf8',
			flag: 'wx',
			mode: 0o600,
		} );
		await chmod( keyFile, 0o600 ).catch( () => {} );
		return value;
	}
};

const collectWordPressMetadata = () => {
	const wpEnvExecutable = path.join(
		repositoryRoot,
		'node_modules',
		'@wordpress',
		'env',
		'bin',
		'wp-env'
	);
	const result = spawnSync(
		process.execPath,
		[
			wpEnvExecutable,
			'run',
			'cli',
			'--env-cwd=wp-content/plugins/presenter',
			'--',
			'wp',
			'eval-file',
			'tools/baseline/collect-corpus.php',
		],
		{
			cwd: snapshotEnvironment,
			encoding: 'utf8',
			maxBuffer: 16 * 1024 * 1024,
		}
	);

	if ( result.status !== 0 ) {
		throw new Error(
			'The local snapshot metadata collector failed. Confirm port 8890 is running.'
		);
	}

	const marker = 'PRESENTER_CORPUS_JSON:';
	const line = result.stdout
		.split( /\r?\n/u )
		.find( ( candidate ) => candidate.startsWith( marker ) );
	if ( ! line ) {
		throw new Error(
			'The local snapshot returned no safe corpus manifest.'
		);
	}

	return JSON.parse( line.slice( marker.length ) );
};

const hmac = ( key, value ) =>
	createHmac( 'sha256', key ).update( value, 'utf8' ).digest( 'hex' );

const normalizeDom = ( html ) =>
	html
		.replaceAll( snapshotOrigin.origin, '{LOCAL_ORIGIN}' )
		.replace( /\b(?:_wpnonce|nonce)=[a-zA-Z0-9_-]+/gu, 'nonce={NONCE}' )
		.replace(
			/\bwp-settings-time-\d+=[^;"'\s<]+/gu,
			'wp-settings-time={TIME}'
		)
		.replace( /\bver=[^&"'\s<]+/gu, 'ver={VERSION}' );

const captureDeck = async ( context, key, selection ) => {
	const page = await context.newPage();
	const events = {
		consoleErrors: 0,
		externalFailures: 0,
		internalFailures: 0,
		pageErrors: 0,
		responseErrors: 0,
	};
	const assets = new Map();

	page.on( 'console', ( message ) => {
		if ( message.type() === 'error' ) {
			events.consoleErrors += 1;
		}
	} );
	page.on( 'pageerror', () => {
		events.pageErrors += 1;
	} );
	page.on( 'requestfailed', ( request ) => {
		const requestUrl = new URL( request.url() );
		if ( requestUrl.origin === snapshotOrigin.origin ) {
			events.internalFailures += 1;
		} else {
			events.externalFailures += 1;
		}
	} );
	page.on( 'response', ( response ) => {
		const resourceType = response.request().resourceType();
		if ( ! [ 'script', 'stylesheet' ].includes( resourceType ) ) {
			return;
		}

		const assetUrl = new URL( response.url() );
		const location =
			assetUrl.origin === snapshotOrigin.origin
				? assetUrl.pathname
				: `{EXTERNAL_ORIGIN}${ assetUrl.pathname }`;
		assets.set( `${ resourceType }:${ location }`, {
			location,
			status: response.status(),
			type: resourceType,
		} );
		if ( response.status() >= 400 ) {
			events.responseErrors += 1;
		}
	} );

	const target = new URL( '/', snapshotOrigin );
	target.searchParams.set( 'post_type', 'slideshow' );
	target.searchParams.set( 'p', String( selection.postId ) );
	const response = await page.goto( target.href, {
		waitUntil: 'networkidle',
	} );
	await page.waitForTimeout( 750 );

	const readiness = await page.evaluate( () => ( {
		currentSlidePresent: Boolean(
			document.querySelector( '.reveal .slides section.present' )
		),
		nestedSectionCount: document.querySelectorAll(
			'.reveal .slides > section > section'
		).length,
		passwordRequired: Boolean(
			document.querySelector( 'form.post-password-form' )
		),
		revealAvailable: typeof window.Reveal !== 'undefined',
		revealReady:
			typeof window.Reveal !== 'undefined' &&
			typeof window.Reveal.isReady === 'function' &&
			window.Reveal.isReady(),
		topLevelSectionCount: document.querySelectorAll(
			'.reveal .slides > section'
		).length,
	} ) );
	const domDigest = hmac( key, normalizeDom( await page.content() ) );

	await page.close();
	return {
		postId: selection.postId,
		httpStatus: response?.status() ?? null,
		readiness,
		events,
		assets: [ ...assets.values() ].sort( ( left, right ) =>
			`${ left.type }:${ left.location }`.localeCompare(
				`${ right.type }:${ right.location }`
			)
		),
		domDigest,
	};
};

const key = await getKey();
const corpus = JSON.parse( await readFile( corpusFile, 'utf8' ) );
const corpusStat = await stat( corpusFile );

if ( ! Array.isArray( corpus.decks ) || corpus.decks.length === 0 ) {
	throw new Error( 'The ignored corpus manifest has no selected decks.' );
}

await mkdir( baselineDirectory, { recursive: true } );
assertLocalPath( outputFile );

const wordpress = collectWordPressMetadata();
const browser = await chromium.launch();
const context = await browser.newContext( {
	viewport: { height: 720, width: 1280 },
} );
await context.route( '**/*', async ( route ) => {
	const requestUrl = new URL( route.request().url() );
	if (
		requestUrl.origin === snapshotOrigin.origin ||
		[ 'about:', 'blob:', 'data:' ].includes( requestUrl.protocol )
	) {
		await route.continue();
		return;
	}

	await route.abort( 'blockedbyclient' );
} );
const browserResults = [];

try {
	if ( process.env.PRESENTER_SNAPSHOT_PASSWORD ) {
		const loginPage = await context.newPage();
		await loginPage.goto( new URL( '/wp-login.php', snapshotOrigin ).href, {
			waitUntil: 'domcontentloaded',
		} );
		await loginPage
			.locator( '#user_login' )
			.fill( process.env.PRESENTER_SNAPSHOT_USER || 'presenter-local' );
		await loginPage
			.locator( '#user_pass' )
			.fill( process.env.PRESENTER_SNAPSHOT_PASSWORD );
		await Promise.all( [
			loginPage.waitForNavigation( { waitUntil: 'networkidle' } ),
			loginPage.locator( '#wp-submit' ).click(),
		] );
		if ( new URL( loginPage.url() ).pathname === '/wp-login.php' ) {
			throw new Error( 'Local snapshot authentication failed.' );
		}
		await loginPage.close();
	}

	for ( const selection of corpus.decks ) {
		browserResults.push( await captureDeck( context, key, selection ) );
	}
} finally {
	await context.close();
	await browser.close();
}

const manifest = {
	schemaVersion: 1,
	snapshotSha256: corpus.snapshotSha256,
	access: process.env.PRESENTER_SNAPSHOT_PASSWORD
		? 'authenticated-local-snapshot'
		: 'anonymous-local-snapshot',
	corpusManifest: {
		deckCount: corpus.decks.length,
		digest: hmac( key, await readFile( corpusFile, 'utf8' ) ),
		modifiedAt: corpusStat.mtime.toISOString(),
	},
	keyId: createHash( 'sha256' ).update( key ).digest( 'hex' ).slice( 0, 16 ),
	wordpress,
	browser: browserResults,
};
const temporaryFile = `${ outputFile }.tmp`;
await writeFile(
	temporaryFile,
	`${ JSON.stringify( manifest, null, '\t' ) }\n`,
	'utf8'
);
await rename( temporaryFile, outputFile );

const readyCount = browserResults.filter(
	( result ) => result.readiness.revealReady
).length;
process.stdout.write(
	`Captured ${ wordpress.decks.length } content-free metadata records and ${ readyCount } ready browser baselines.`
);
process.stdout.write(
	'\nPrivate values were authenticated with the ignored local HMAC key.\n'
);
