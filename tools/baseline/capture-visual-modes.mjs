import { createHash } from 'node:crypto';
import { mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import path from 'node:path';
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
const corpusFile = path.join( corpusDirectory, 'corpus.json' );
const outputDirectory = path.join( corpusDirectory, 'visual-modes' );
const outputFile = path.join( outputDirectory, 'manifest.json' );
const snapshotOrigin = new URL( 'http://localhost:8890' );
const viewport = { height: 720, width: 1280 };

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

const sha256File = async ( filename ) =>
	createHash( 'sha256' )
		.update( await readFile( filename ) )
		.digest( 'hex' );

const deckUrl = ( postId, printMode = false ) => {
	const target = new URL( '/', snapshotOrigin );
	target.searchParams.set( 'post_type', 'slideshow' );
	target.searchParams.set( 'p', String( postId ) );
	if ( printMode ) {
		target.searchParams.set( 'print-pdf', '' );
	}
	return target.href;
};

const isReady = ( page ) =>
	page.evaluate(
		() =>
			typeof window.Reveal !== 'undefined' &&
			typeof window.Reveal.isReady === 'function' &&
			window.Reveal.isReady() &&
			Boolean(
				document.querySelector( '.reveal .slides section.present' )
			)
	);

const openDeck = async ( context, postId, printMode = false ) => {
	const page = await context.newPage();
	const response = await page.goto( deckUrl( postId, printMode ), {
		waitUntil: 'networkidle',
	} );
	await page.waitForTimeout( 750 );

	if ( response?.status() !== 200 || ! ( await isReady( page ) ) ) {
		await page.close();
		return null;
	}

	await page.evaluate( () => window.Reveal.slide( 0, 0, 0 ) );
	await page.waitForTimeout( 250 );
	return page;
};

const captureScreenshot = async ( page, filename ) => {
	assertLocalPath( filename );
	await page.screenshot( {
		animations: 'disabled',
		path: filename,
	} );
	return {
		file: path
			.relative( corpusDirectory, filename )
			.replaceAll( '\\', '/' ),
		sha256: await sha256File( filename ),
	};
};

const captureSpeakerView = async ( context, page, postId ) => {
	const speakerPagePromise = context
		.waitForEvent( 'page', { timeout: 5000 } )
		.catch( () => null );
	await page.keyboard.press( 's' );
	const speakerPage = await speakerPagePromise;

	if ( ! speakerPage ) {
		return {
			detected: false,
			connected: false,
			previewsReady: false,
			screenshot: null,
		};
	}

	try {
		await speakerPage.waitForLoadState( 'domcontentloaded' );
		const detected = await speakerPage
			.locator( '#speaker-controls' )
			.isVisible()
			.catch( () => false );
		const connected = await speakerPage
			.waitForFunction(
				() =>
					document.querySelector( '#connection-status' )?.style
						.display === 'none',
				undefined,
				{ timeout: 5000 }
			)
			.then( () => true )
			.catch( () => false );
		const previewsReady = connected
			? await speakerPage
					.waitForFunction(
						() => {
							const previews = Array.from(
								document.querySelectorAll(
									'#current-slide iframe, #upcoming-slide iframe'
								)
							);

							return (
								previews.length === 2 &&
								previews.every( ( preview ) =>
									Boolean(
										preview.contentDocument?.querySelector(
											'.reveal .slides section.present'
										)
									)
								)
							);
						},
						undefined,
						{ timeout: 5000 }
					)
					.then( () => true )
					.catch( () => false )
			: false;
		const screenshot = detected
			? await captureScreenshot(
					speakerPage,
					path.join( outputDirectory, `${ postId }-speaker.png` )
			  )
			: null;

		return { detected, connected, previewsReady, screenshot };
	} finally {
		await speakerPage.close();
	}
};

const captureDeck = async ( context, selection ) => {
	const page = await openDeck( context, selection.postId );
	if ( ! page ) {
		return null;
	}

	try {
		const standard = await captureScreenshot(
			page,
			path.join( outputDirectory, `${ selection.postId }-standard.png` )
		);
		const speaker = await captureSpeakerView(
			context,
			page,
			selection.postId
		);
		const printPage = await openDeck( context, selection.postId, true );
		let print = null;

		if ( printPage ) {
			try {
				const printModeDetected = await printPage.evaluate( () =>
					document.documentElement.classList.contains( 'print-pdf' )
				);
				print = {
					detected: printModeDetected,
					screenshot: await captureScreenshot(
						printPage,
						path.join(
							outputDirectory,
							`${ selection.postId }-print.png`
						)
					),
				};
			} finally {
				await printPage.close();
			}
		}

		return {
			postId: selection.postId,
			standard,
			print,
			speaker,
		};
	} finally {
		await page.close();
	}
};

assertLocalPath( outputDirectory );
assertLocalPath( outputFile );
await mkdir( outputDirectory, { recursive: true } );

const corpus = JSON.parse( await readFile( corpusFile, 'utf8' ) );
if ( ! Array.isArray( corpus.decks ) || corpus.decks.length === 0 ) {
	throw new Error( 'The ignored corpus manifest has no selected decks.' );
}

const browser = await chromium.launch();
const context = await browser.newContext( {
	reducedMotion: 'reduce',
	viewport,
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

const captures = [];
try {
	for ( const selection of corpus.decks ) {
		const result = await captureDeck( context, selection );
		if ( result ) {
			captures.push( result );
		}
	}
} finally {
	await context.close();
	await browser.close();
}

const manifest = {
	schemaVersion: 1,
	snapshotSha256: corpus.snapshotSha256,
	origin: snapshotOrigin.origin,
	viewport,
	captures,
};
const temporaryFile = `${ outputFile }.tmp`;
await writeFile(
	temporaryFile,
	`${ JSON.stringify( manifest, null, '\t' ) }\n`,
	'utf8'
);
await rename( temporaryFile, outputFile );

const printCount = captures.filter(
	( result ) => result.print?.detected
).length;
const speakerCount = captures.filter(
	( result ) => result.speaker.connected
).length;
const speakerPreviewCount = captures.filter(
	( result ) => result.speaker.previewsReady
).length;
process.stdout.write(
	`Captured ${ captures.length } public visual baselines; ${ printCount } print modes, ${ speakerCount } speaker shells connected, and ${ speakerPreviewCount } speaker previews rendered.\n`
);
process.stdout.write(
	'All artifacts are confined to ignored local storage.\n'
);
