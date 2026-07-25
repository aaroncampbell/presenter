/* eslint-disable no-console -- This command emits a compact verification result. */
/**
 * Verify the companion Chart plugin through native and legacy WordPress routes.
 */

import assert from 'node:assert/strict';

import { chromium } from '@playwright/test';

const routes = [
	{
		mode: 'native',
		url:
			process.env.PRESENTER_CHART_NATIVE_URL ??
			'http://localhost:8888/?slideshow=presenter-chart-native-smoke',
	},
	{
		mode: 'legacy',
		url:
			process.env.PRESENTER_CHART_LEGACY_URL ??
			'http://localhost:8888/?slideshow=presenter-chart-legacy-smoke',
	},
];

/**
 * Install deterministic chart values before any page script runs.
 */
function installChartFixture() {
	window.presenterChartFixture = {
		data: { datasets: [] },
		update() {
			window.__presenterChartUpdateCounts.push(
				this.data.datasets.length
			);
		},
	};
	window.presenterChartDatasetFixture = {
		label: 'Headless Chart dataset',
	};
	window.__presenterChartUpdateCounts = [];
	window.__presenterGetChartRevealInstance = ( mode ) =>
		mode === 'native'
			? window.presenterReveal?.getInstance()
			: window.Reveal;
}

/**
 * Verify one real WordPress slideshow route.
 *
 * @param {import('@playwright/test').Browser}       browser Browser instance.
 * @param {{ mode: 'native'|'legacy', url: string }} route   Route definition.
 * @return {Promise<Object>} Compact route result.
 */
async function verifyRoute( browser, route ) {
	const context = await browser.newContext();
	const page = await context.newPage();
	const consoleErrors = [];
	const pageErrors = [];

	page.on( 'console', ( message ) => {
		if ( message.type() === 'error' ) {
			consoleErrors.push( message.text() );
		}
	} );
	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
	await page.addInitScript( installChartFixture );

	try {
		const response = await page.goto( route.url, {
			waitUntil: 'networkidle',
		} );
		assert.equal( response?.status(), 200 );
		await page.waitForFunction(
			( mode ) =>
				window.__presenterGetChartRevealInstance( mode )?.isReady() ===
				true,
			route.mode
		);

		const initial = await page.evaluate( ( mode ) => {
			const reveal = window.__presenterGetChartRevealInstance( mode );
			const plugin = reveal.getPlugin( 'chartjs' );
			const fragment = document.querySelector(
				'#presenter-chart-fragment'
			);

			return {
				datasetCount: window.presenterChartFixture.data.datasets.length,
				fragmentAttributes:
					fragment?.dataset.fragmentGraph ===
						'presenterChartFixture' &&
					fragment?.dataset.fragmentGraphDataset ===
						'presenterChartDatasetFixture',
				legacyPluginGlobal: window.RevealChartjs === plugin,
				nativeApi: Boolean( window.presenterReveal ),
				nativeBody: document.body.classList.contains(
					'presenter-presentation-native'
				),
				pluginId: plugin?.id,
				runtimeVersion: reveal.VERSION,
				updateCounts: [ ...window.__presenterChartUpdateCounts ],
			};
		}, route.mode );

		assert.equal( initial.datasetCount, 0 );
		assert.deepEqual( initial.updateCounts, [] );
		assert.equal( initial.fragmentAttributes, true );
		assert.equal( initial.legacyPluginGlobal, true );
		assert.equal( initial.pluginId, 'chartjs' );

		if ( route.mode === 'native' ) {
			assert.equal( initial.nativeApi, true );
			assert.equal( initial.nativeBody, true );
			assert.match( initial.runtimeVersion, /^6\./ );
		} else {
			assert.equal( initial.nativeApi, false );
			assert.equal( initial.nativeBody, false );
			assert.match( initial.runtimeVersion, /^4\./ );
		}

		for ( const expected of [
			{ datasets: 1, updates: [ 1 ], action: 'nextFragment' },
			{ datasets: 0, updates: [ 1, 0 ], action: 'prevFragment' },
			{
				datasets: 1,
				updates: [ 1, 0, 1 ],
				action: 'nextFragment',
			},
			{
				datasets: 0,
				updates: [ 1, 0, 1, 0 ],
				action: 'prevFragment',
			},
		] ) {
			await page.evaluate(
				( { action, mode } ) =>
					window
						.__presenterGetChartRevealInstance( mode )
						[ action ](),
				{ action: expected.action, mode: route.mode }
			);
			await page.waitForFunction(
				( { datasets, mode, updateCount } ) =>
					window.__presenterGetChartRevealInstance( mode ) &&
					window.presenterChartFixture.data.datasets.length ===
						datasets &&
					window.__presenterChartUpdateCounts.length === updateCount,
				{
					datasets: expected.datasets,
					mode: route.mode,
					updateCount: expected.updates.length,
				}
			);
			assert.deepEqual(
				await page.evaluate( () => [
					...window.__presenterChartUpdateCounts,
				] ),
				expected.updates
			);
		}

		assert.deepEqual( pageErrors, [] );
		assert.deepEqual( consoleErrors, [] );

		return {
			mode: route.mode,
			pluginDiscoverable: true,
			runtimeVersion: initial.runtimeVersion,
			updateCounts: [ 1, 0, 1, 0 ],
			url: route.url,
		};
	} finally {
		await context.close();
	}
}

const browser = await chromium.launch( { headless: true } );

try {
	const results = [];
	for ( const route of routes ) {
		results.push( await verifyRoute( browser, route ) );
	}

	console.log(
		JSON.stringify(
			{
				mousedownRequired: false,
				passed: true,
				routes: results,
			},
			null,
			2
		)
	);
} finally {
	await browser.close();
}
