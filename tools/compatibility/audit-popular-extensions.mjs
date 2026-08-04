/* eslint-disable no-console -- This opt-in audit emits a content-free compatibility report. */
/**
 * Audit popular WordPress.org themes and plugins against one native deck.
 */

import { spawnSync } from 'node:child_process';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

import { chromium } from '@playwright/test';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

const repositoryRoot = dirname(
	dirname( dirname( fileURLToPath( import.meta.url ) ) )
);
const matrix = JSON.parse(
	await readFile(
		join( repositoryRoot, 'tools/compatibility/popular-extensions.json' ),
		'utf8'
	)
);
const baseUrl =
	process.env.PRESENTER_POPULAR_COMPAT_URL ?? 'http://localhost:8889';
const origin = new URL( baseUrl );
const timestamp = new Date().toISOString().replace( /[:.]/g, '-' );
const artifactDirectory = join(
	repositoryRoot,
	'local/popular-extension-compatibility',
	timestamp
);
const targetUrl = new URL(
	'/?slideshow=presenter-popular-extension-compatibility',
	origin
).href;
const wpEnvScript = join(
	repositoryRoot,
	'node_modules/@wordpress/env/bin/wp-env'
);
const requiredPlugins = new Set( [
	'presenter',
	'aarondcampbell-presenter-themes',
] );

if (
	'http:' !== origin.protocol ||
	! [ 'localhost', '127.0.0.1' ].includes( origin.hostname )
) {
	throw new Error(
		'The popular-extension audit is restricted to local HTTP.'
	);
}

await mkdir( artifactDirectory, { recursive: true } );

const runWp = ( args ) => {
	const result = spawnSync(
		process.execPath,
		[ wpEnvScript, 'run', 'tests-cli', '--', 'wp', ...args ],
		{
			cwd: repositoryRoot,
			encoding: 'utf8',
			maxBuffer: 20 * 1024 * 1024,
		}
	);

	if ( 0 !== result.status ) {
		throw new Error(
			`WP-CLI failed (${ args.join( ' ' ) }): ${
				result.error?.message ||
				result.stderr?.trim() ||
				result.stdout?.trim() ||
				'unknown process failure'
			}`
		);
	}

	return result.stdout.trim();
};

const installedItems = ( type ) =>
	new Map(
		JSON.parse(
			runWp( [
				type,
				'list',
				'--fields=name,version,status',
				'--format=json',
			] )
		).map( ( item ) => [ item.name, item ] )
	);

const installMatrix = () => {
	const plugins = installedItems( 'plugin' );
	const themes = installedItems( 'theme' );

	for ( const plugin of matrix.plugins ) {
		const installed = plugins.get( plugin.slug );
		if ( installed && installed.version !== plugin.version ) {
			throw new Error(
				`${ plugin.slug } ${ installed.version } is installed; the audit pins ${ plugin.version }.`
			);
		}
		if ( ! installed ) {
			runWp( [
				'plugin',
				'install',
				`https://downloads.wordpress.org/plugin/${ plugin.slug }.${ plugin.version }.zip`,
			] );
		}
	}

	for ( const theme of matrix.themes ) {
		const installed = themes.get( theme.slug );
		if ( installed && installed.version !== theme.version ) {
			throw new Error(
				`${ theme.slug } ${ installed.version } is installed; the audit pins ${ theme.version }.`
			);
		}
		if ( ! installed ) {
			runWp( [
				'theme',
				'install',
				`https://downloads.wordpress.org/theme/${ theme.slug }.${ theme.version }.zip`,
			] );
		}
	}
};

const activePluginNames = () =>
	JSON.parse(
		runWp( [
			'plugin',
			'list',
			'--status=active',
			'--fields=name',
			'--format=json',
		] )
	).map( ( item ) => item.name );

const activeThemeName = () =>
	JSON.parse(
		runWp( [
			'theme',
			'list',
			'--status=active',
			'--fields=name',
			'--format=json',
		] )
	)[ 0 ]?.name;

let activePluginsState;
let activeThemeState;

const deactivatePlugin = ( slug ) => {
	if ( activePluginsState.has( slug ) ) {
		runWp( [ `--skip-plugins=${ slug }`, 'plugin', 'deactivate', slug ] );
		activePluginsState.delete( slug );
	}
};

const activatePlugin = ( slug ) => {
	if ( ! activePluginsState.has( slug ) ) {
		runWp( [ 'plugin', 'activate', slug ] );
		activePluginsState.add( slug );
	}
};

const activateTheme = ( slug ) => {
	if ( activeThemeState !== slug ) {
		runWp( [ 'theme', 'activate', slug ] );
		activeThemeState = slug;
	}
};

const normalizeUrl = ( value ) => {
	const url = new URL( value, origin );

	return `${ url.origin }${ url.pathname }`;
};

const capture = async ( browser, name ) => {
	const context = await browser.newContext( {
		colorScheme: 'light',
		reducedMotion: 'reduce',
		viewport: { height: 720, width: 1280 },
	} );
	const page = await context.newPage();
	const consoleErrors = [];
	const pageErrors = [];
	let navigationError = null;
	let readinessTimedOut = false;
	let response = null;

	page.on( 'console', ( message ) => {
		if ( 'error' === message.type() ) {
			consoleErrors.push( message.text() );
		}
	} );
	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );

	try {
		try {
			response = await page.goto( targetUrl, {
				timeout: 30_000,
				waitUntil: 'domcontentloaded',
			} );
		} catch ( error ) {
			navigationError = error.message;
		}
		try {
			await page.waitForFunction(
				() => window.presenterReveal?.getInstance()?.isReady() === true,
				undefined,
				{ timeout: 20_000 }
			);
		} catch {
			readinessTimedOut = true;
		}
		await page.waitForTimeout( 250 );

		const state = await page.evaluate( () => {
			const rectangle = ( element ) => {
				const bounds = element?.getBoundingClientRect();

				return bounds
					? {
							bottom: bounds.bottom,
							height: bounds.height,
							left: bounds.left,
							right: bounds.right,
							top: bounds.top,
							width: bounds.width,
					  }
					: null;
			};
			const describeElement = ( element ) => ( {
				classes: [ ...element.classList ].slice( 0, 6 ),
				id: element.id || null,
				tag: element.tagName.toLowerCase(),
			} );
			const heading = document.querySelector( '.slides > section h1' );
			const headingStyle = heading
				? window.getComputedStyle( heading )
				: null;

			return {
				adminBarCount:
					document.querySelectorAll( '#wpadminbar' ).length,
				bodyChildren: [ ...document.body.children ].map(
					describeElement
				),
				bodyHasAdminBarClass:
					document.body.classList.contains( 'admin-bar' ),
				heading: rectangle( heading ),
				headingStyle: headingStyle
					? {
							color: headingStyle.color,
							fontFamily: headingStyle.fontFamily,
							fontSize: headingStyle.fontSize,
							fontWeight: headingStyle.fontWeight,
					  }
					: null,
				inlineScripts: [ ...document.scripts ]
					.filter( ( script ) => ! script.src )
					.map( ( script ) => ( {
						id: script.id || null,
						length: script.textContent.length,
						type: script.type || null,
					} ) ),
				inlineStyles: [ ...document.querySelectorAll( 'style' ) ].map(
					( style ) => ( {
						id: style.id || null,
						length: style.textContent.length,
					} )
				),
				presentation: rectangle(
					document.querySelector( '#presenter-presentation' )
				),
				resourceUrls: [
					...document.querySelectorAll(
						'link[rel="stylesheet"][href], script[src]'
					),
				].map( ( element ) => element.href || element.src ),
				reveal: rectangle(
					document.querySelector( '[data-presenter-reveal-root]' )
				),
				revealReady:
					window.presenterReveal?.getInstance()?.isReady() === true,
				slideCount:
					document.querySelectorAll( '.slides > section' ).length,
				viewport: {
					height: window.innerHeight,
					width: window.innerWidth,
				},
			};
		} );
		const screenshot = await page.screenshot();
		const screenshotPath = join( artifactDirectory, `${ name }.png` );
		await writeFile( screenshotPath, screenshot );

		return {
			...state,
			consoleErrors,
			httpStatus: response?.status() ?? null,
			navigationError,
			pageErrors,
			readinessTimedOut,
			resourceUrls: [
				...new Set( state.resourceUrls.map( normalizeUrl ) ),
			].sort(),
			screenshot,
			screenshotPath: relative(
				repositoryRoot,
				screenshotPath
			).replaceAll( '\\', '/' ),
		};
	} finally {
		await context.close();
	}
};

const compareImages = ( baseline, candidate ) => {
	const expected = PNG.sync.read( baseline );
	const actual = PNG.sync.read( candidate );

	if (
		expected.width !== actual.width ||
		expected.height !== actual.height
	) {
		return expected.width * expected.height;
	}

	return pixelmatch(
		expected.data,
		actual.data,
		null,
		expected.width,
		expected.height,
		{
			includeAA: true,
			threshold: 0.1,
		}
	);
};

const difference = ( baseline, candidate ) =>
	candidate.filter( ( item ) => ! baseline.includes( item ) );

const classify = ( baseline, candidate ) => {
	const failures = [];
	const addedResources = difference(
		baseline.resourceUrls,
		candidate.resourceUrls
	);
	const addedBodyChildren = difference(
		baseline.bodyChildren.map( JSON.stringify ),
		candidate.bodyChildren.map( JSON.stringify )
	).map( JSON.parse );
	const addedInlineScripts = difference(
		baseline.inlineScripts.map( JSON.stringify ),
		candidate.inlineScripts.map( JSON.stringify )
	).map( JSON.parse );
	const addedInlineStyles = difference(
		baseline.inlineStyles.map( JSON.stringify ),
		candidate.inlineStyles.map( JSON.stringify )
	).map( JSON.parse );
	const visualDifferingPixels = compareImages(
		baseline.screenshot,
		candidate.screenshot
	);

	if ( null === candidate.httpStatus || candidate.httpStatus >= 400 ) {
		failures.push( 'http' );
	}
	if ( candidate.navigationError ) {
		failures.push( 'navigation' );
	}
	if ( ! candidate.revealReady || 1 !== candidate.slideCount ) {
		failures.push( 'reveal-runtime' );
	}
	if ( candidate.consoleErrors.length || candidate.pageErrors.length ) {
		failures.push( 'browser-error' );
	}
	if (
		! candidate.presentation ||
		! candidate.reveal ||
		Math.abs( candidate.presentation.bottom - candidate.viewport.height ) >
			1 ||
		Math.abs( candidate.reveal.bottom - candidate.viewport.height ) > 1
	) {
		failures.push( 'viewport-geometry' );
	}
	if ( candidate.adminBarCount || candidate.bodyHasAdminBarClass ) {
		failures.push( 'admin-bar' );
	}
	let status = 'pass';
	if ( failures.length ) {
		status = 'fail';
	} else if (
		visualDifferingPixels ||
		addedResources.length ||
		addedBodyChildren.length ||
		addedInlineScripts.length ||
		addedInlineStyles.length
	) {
		status = 'review';
	}

	return {
		addedBodyChildren,
		addedInlineScripts,
		addedInlineStyles,
		addedResources,
		failures,
		status,
		visualDifferingPixels,
	};
};

const originalPlugins = activePluginNames();
const originalTheme = activeThemeName();
activePluginsState = new Set( originalPlugins );
activeThemeState = originalTheme;
const results = [];
let browser;

try {
	installMatrix();
	for ( const plugin of [ ...activePluginsState ] ) {
		if ( ! requiredPlugins.has( plugin ) ) {
			deactivatePlugin( plugin );
		}
	}
	for ( const plugin of requiredPlugins ) {
		activatePlugin( plugin );
	}
	activateTheme( 'twentytwentyfive' );
	runWp( [
		'eval-file',
		'wp-content/plugins/presenter/tools/compatibility/create-popular-extension-fixture.php',
	] );

	browser = await chromium.launch( { headless: true } );
	const baseline = await capture( browser, 'baseline' );
	if (
		200 !== baseline.httpStatus ||
		! baseline.revealReady ||
		1 !== baseline.slideCount
	) {
		throw new Error(
			'Presenter baseline did not produce one ready slide with HTTP 200.'
		);
	}
	const repeatedBaseline = await capture( browser, 'baseline-repeat' );
	const baselineDifference = compareImages(
		baseline.screenshot,
		repeatedBaseline.screenshot
	);
	if ( baselineDifference ) {
		throw new Error(
			`Baseline is not visually deterministic (${ baselineDifference } pixels).`
		);
	}

	let previousPlugin = null;
	for ( const plugin of matrix.plugins ) {
		if ( previousPlugin ) {
			deactivatePlugin( previousPlugin );
		}
		activatePlugin( plugin.slug );
		const captured = await capture( browser, `plugin-${ plugin.slug }` );
		results.push( {
			...classify( baseline, captured ),
			kind: 'plugin',
			screenshotPath: captured.screenshotPath,
			slug: plugin.slug,
			version: plugin.version,
		} );
		previousPlugin = plugin.slug;
	}
	if ( previousPlugin ) {
		deactivatePlugin( previousPlugin );
	}

	for ( const theme of matrix.themes ) {
		activateTheme( theme.slug );
		const captured = await capture( browser, `theme-${ theme.slug }` );
		results.push( {
			...classify( baseline, captured ),
			kind: 'theme',
			screenshotPath: captured.screenshotPath,
			slug: theme.slug,
			version: theme.version,
		} );
	}

	const report = {
		artifactSchemaVersion: 1,
		baseline: {
			bodyChildren: baseline.bodyChildren,
			heading: baseline.heading,
			headingStyle: baseline.headingStyle,
			inlineScripts: baseline.inlineScripts,
			inlineStyles: baseline.inlineStyles,
			resourceUrls: baseline.resourceUrls,
			screenshotPath: baseline.screenshotPath,
		},
		environment: {
			baseUrl,
			php:
				runWp( [ '--info' ] ).match(
					/PHP version:\s+([^\s]+)/
				)?.[ 1 ] ?? null,
			wordpress: runWp( [ 'core', 'version' ] ),
		},
		matrixObservedAt: matrix.observedAt,
		results,
	};
	const reportPath = join( artifactDirectory, 'report.json' );
	await writeFile( reportPath, `${ JSON.stringify( report, null, 2 ) }\n` );

	for ( const result of results ) {
		console.log(
			`${ result.status.toUpperCase() }\t${ result.kind }\t${
				result.slug
			}@${ result.version }\tassets:${
				result.addedResources.length
			}\tinline:${ result.addedInlineScripts.length }\tmarkup:${
				result.addedBodyChildren.length
			}\tstyles:${ result.addedInlineStyles.length }\tpixels:${
				result.visualDifferingPixels
			}${
				result.failures.length
					? `\tfailures:${ result.failures.join( ',' ) }`
					: ''
			}`
		);
	}
	console.log( `Report: ${ relative( repositoryRoot, reportPath ) }` );

	if ( results.some( ( result ) => 'fail' === result.status ) ) {
		process.exitCode = 1;
	}
} finally {
	if ( browser ) {
		await browser.close();
	}
	try {
		activePluginsState = new Set( activePluginNames() );
		for ( const plugin of [ ...activePluginsState ] ) {
			if ( ! originalPlugins.includes( plugin ) ) {
				deactivatePlugin( plugin );
			}
		}
		for ( const plugin of originalPlugins ) {
			activatePlugin( plugin );
		}
		if ( originalTheme ) {
			activateTheme( originalTheme );
		}
	} catch ( error ) {
		console.error(
			`Could not restore the tests environment: ${ error.message }`
		);
		process.exitCode = 1;
	}
}
