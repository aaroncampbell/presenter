/* eslint-disable no-console -- This command emits a content-free verification result. */
/**
 * Exercise the bounded Prepare-selected workflow through real wp-admin requests.
 */

import { chromium } from '@playwright/test';

const baseUrl = process.env.WP_BASE_URL ?? 'http://localhost:8888';
const username = process.env.WP_ADMIN_USER ?? 'admin';
const password = process.env.WP_ADMIN_PASSWORD ?? 'password';
const fixtures = [
	{
		slug: 'presenter-admin-batch-one',
		title: 'Presenter Admin Batch One',
	},
	{
		slug: 'presenter-admin-batch-two',
		title: 'Presenter Admin Batch Two',
	},
	{
		slug: 'presenter-admin-batch-three',
		title: 'Presenter Admin Batch Three',
	},
	{
		slug: 'presenter-admin-batch-neighbor',
		title: 'Presenter Admin Batch Neighbor',
	},
	{
		slug: 'presenter-admin-apply-neighbor',
		title: 'Presenter Admin Apply Neighbor',
	},
];
const selected = fixtures.slice( 0, 3 );
const neighbor = fixtures[ 3 ];
const applyNeighbor = fixtures[ 4 ];
const browser = await chromium.launch( { headless: true } );
const context = await browser.newContext();
const page = await context.newPage();
const pageErrors = [];
const consoleErrors = [];
let activePrepareRequests = 0;
let maximumPrepareRequests = 0;
let prepareRequestCount = 0;
let injectedFailure = false;
let interceptedPrepareCount = 0;
let activeApplyRequests = 0;
let maximumApplyRequests = 0;
let applyRequestCount = 0;
let interceptedApplyCount = 0;
let injectedApplyFailure = false;

const isPrepareRequest = ( request ) =>
	request.url().includes( '/wp-admin/admin-ajax.php' ) &&
	( request.postData() ?? '' ).includes(
		'presenter_migration_batch_prepare_item'
	);
const isApplyRequest = ( request ) =>
	request.url().includes( '/wp-admin/admin-ajax.php' ) &&
	( request.postData() ?? '' ).includes(
		'presenter_migration_batch_apply_item'
	);

page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
page.on( 'console', ( message ) => {
	if ( message.type() === 'error' ) {
		consoleErrors.push( message.text() );
	}
} );
page.on( 'request', ( request ) => {
	if ( isPrepareRequest( request ) ) {
		activePrepareRequests += 1;
		prepareRequestCount += 1;
		maximumPrepareRequests = Math.max(
			maximumPrepareRequests,
			activePrepareRequests
		);
	}
	if ( isApplyRequest( request ) ) {
		activeApplyRequests += 1;
		applyRequestCount += 1;
		maximumApplyRequests = Math.max(
			maximumApplyRequests,
			activeApplyRequests
		);
	}
} );
const finishRequest = ( request ) => {
	if ( isPrepareRequest( request ) ) {
		activePrepareRequests -= 1;
	}
	if ( isApplyRequest( request ) ) {
		activeApplyRequests -= 1;
	}
};
page.on( 'requestfinished', finishRequest );
page.on( 'requestfailed', finishRequest );

const assert = ( condition, message ) => {
	if ( ! condition ) {
		throw new Error( message );
	}
};

try {
	const publicBefore = new Map();
	for ( const fixture of fixtures ) {
		const response = await context.request.get(
			`${ baseUrl }/?slideshow=${ fixture.slug }`
		);
		const body = await response.text();
		assert( response.ok(), `Legacy route failed for ${ fixture.slug }.` );
		assert(
			body.includes( `Legacy route sentinel for ${ fixture.title }.` ),
			`Legacy route sentinel is missing for ${ fixture.slug }.`
		);
		publicBefore.set( fixture.slug, body.includes( 'reveal.min.js' ) );
	}

	await page.goto( `${ baseUrl }/wp-login.php`, {
		waitUntil: 'domcontentloaded',
	} );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/ );

	let fixturePageFound = false;
	for ( let pageNumber = 1; pageNumber <= 100; pageNumber += 1 ) {
		await page.goto(
			`${ baseUrl }/wp-admin/tools.php?page=presenter-migration&paged=${ pageNumber }`,
			{ waitUntil: 'domcontentloaded' }
		);
		const fixtureCounts = await Promise.all(
			fixtures.map( ( fixture ) =>
				page
					.getByRole( 'row' )
					.filter( { hasText: fixture.title } )
					.count()
			)
		);
		const foundCount = fixtureCounts.filter(
			( count ) => count > 0
		).length;
		if ( foundCount === fixtures.length ) {
			fixturePageFound = true;
			break;
		}
		assert(
			foundCount === 0,
			'The disposable batch fixtures crossed an inventory page boundary.'
		);
		if ( 0 === ( await page.locator( '.page-numbers.next' ).count() ) ) {
			break;
		}
	}
	assert( fixturePageFound, 'The disposable batch fixtures were not found.' );

	await page.route( '**/wp-admin/admin-ajax.php', async ( route ) => {
		if ( isApplyRequest( route.request() ) ) {
			interceptedApplyCount += 1;
			if ( ! injectedApplyFailure && interceptedApplyCount === 2 ) {
				injectedApplyFailure = true;
				await route.fulfill( {
					body: JSON.stringify( {
						schemaVersion: 1,
						operation: 'apply',
						result: 'stopped',
					} ),
					contentType: 'application/json',
					status: 200,
				} );
				return;
			}
			await route.continue();
			return;
		}
		if ( ! isPrepareRequest( route.request() ) ) {
			await route.continue();
			return;
		}
		interceptedPrepareCount += 1;
		if ( injectedFailure || interceptedPrepareCount !== 2 ) {
			await route.continue();
			return;
		}

		injectedFailure = true;
		await route.fulfill( {
			body: JSON.stringify( {
				schemaVersion: 1,
				operation: 'prepare',
				result: 'stopped',
			} ),
			contentType: 'application/json',
			status: 200,
		} );
	} );
	for ( const fixture of selected ) {
		await page
			.getByRole( 'checkbox', {
				name: `Select ${ fixture.title } for batch preparation`,
				exact: true,
			} )
			.check();
	}
	await page
		.getByRole( 'checkbox', {
			name: `Select ${ neighbor.title } for batch preparation`,
			exact: true,
		} )
		.isChecked()
		.then( ( checked ) =>
			assert( ! checked, 'The unselected neighbor entered the queue.' )
		);

	await page
		.getByRole( 'button', { name: 'Prepare selected decks', exact: true } )
		.click();
	await page
		.getByRole( 'status' )
		.filter( {
			hasText: 'Preparation stopped after 1 of 3 selected decks.',
		} )
		.waitFor();

	assert(
		2 === prepareRequestCount,
		'The stopped queue did not issue exactly two requests.'
	);
	assert( 1 === maximumPrepareRequests, 'Prepare requests overlapped.' );

	await page.reload( { waitUntil: 'domcontentloaded' } );
	const firstRow = page
		.getByRole( 'row' )
		.filter( { hasText: selected[ 0 ].title } );
	await firstRow.getByText( 'Prepared', { exact: true } ).waitFor();
	for ( const fixture of selected.slice( 1 ) ) {
		const row = page
			.getByRole( 'row' )
			.filter( { hasText: fixture.title } );
		await row.getByText( 'Not prepared', { exact: true } ).waitFor();
		await row
			.getByRole( 'checkbox', {
				name: `Select ${ fixture.title } for batch preparation`,
				exact: true,
			} )
			.check();
	}
	await page
		.getByRole( 'button', { name: 'Prepare selected decks', exact: true } )
		.click();
	await page
		.getByRole( 'status' )
		.filter( { hasText: 'Prepared 2 selected decks.' } )
		.waitFor();
	assert(
		4 === prepareRequestCount,
		'The resumed queue did not issue exactly two requests.'
	);

	for ( const fixture of fixtures ) {
		const response = await context.request.get(
			`${ baseUrl }/?slideshow=${ fixture.slug }`
		);
		const body = await response.text();
		assert(
			body.includes( `Legacy route sentinel for ${ fixture.title }.` ),
			`Prepare changed the public legacy content for ${ fixture.slug }.`
		);
		assert(
			publicBefore.get( fixture.slug ) ===
				body.includes( 'reveal.min.js' ),
			`Prepare changed the public runtime for ${ fixture.slug }.`
		);
	}

	await page.reload( { waitUntil: 'domcontentloaded' } );
	for ( const fixture of selected ) {
		const row = page
			.getByRole( 'row' )
			.filter( { hasText: fixture.title } );
		await row.getByText( 'Prepared', { exact: true } ).waitFor();
		assert(
			0 ===
				( await row
					.locator( '[data-presenter-prepare-select]' )
					.count() ),
			`Prepared deck ${ fixture.slug } remained batch eligible.`
		);
		await row
			.getByRole( 'button', {
				name: 'Apply native content',
				exact: true,
			} )
			.waitFor();
	}
	const neighborRow = page
		.getByRole( 'row' )
		.filter( { hasText: neighbor.title } );
	await neighborRow.getByText( 'Not prepared', { exact: true } ).waitFor();
	assert(
		1 ===
			( await neighborRow
				.locator( '[data-presenter-prepare-select]' )
				.count() ),
		'The unselected neighbor is not safely resumable.'
	);
	const applyNeighborRow = page
		.getByRole( 'row' )
		.filter( { hasText: applyNeighbor.title } );
	await applyNeighborRow
		.getByRole( 'button', { name: 'Prepare', exact: true } )
		.click();
	await page.waitForLoadState( 'domcontentloaded' );
	const preparedApplyNeighborRow = page
		.getByRole( 'row' )
		.filter( { hasText: applyNeighbor.title } );
	await preparedApplyNeighborRow
		.getByText( 'Prepared', { exact: true } )
		.waitFor();
	assert(
		! ( await preparedApplyNeighborRow
			.getByRole( 'checkbox', {
				name: `Select ${ applyNeighbor.title } for batch apply`,
				exact: true,
			} )
			.isChecked() ),
		'The unselected prepared Apply neighbor entered the queue.'
	);
	await page
		.getByRole( 'link', {
			name: 'View all legacy slideshows',
			exact: true,
		} )
		.click();
	await page.waitForLoadState( 'domcontentloaded' );

	for ( const fixture of selected ) {
		await page
			.getByRole( 'checkbox', {
				name: `Select ${ fixture.title } for batch apply`,
				exact: true,
			} )
			.check();
	}
	await page
		.getByRole( 'checkbox', {
			name: 'I understand that this changes the published slideshows.',
			exact: true,
		} )
		.check();
	await page
		.getByRole( 'button', {
			name: 'Apply native content to selected decks',
			exact: true,
		} )
		.click();
	await page
		.getByRole( 'status' )
		.filter( { hasText: 'Apply stopped after 1 of 3 decks.' } )
		.waitFor();
	assert(
		2 === applyRequestCount,
		'The stopped Apply queue did not issue exactly two requests.'
	);
	assert( 1 === maximumApplyRequests, 'Apply requests overlapped.' );

	await page.reload( { waitUntil: 'domcontentloaded' } );
	const appliedFirstRow = page
		.getByRole( 'row' )
		.filter( { hasText: selected[ 0 ].title } );
	await appliedFirstRow.getByText( 'Applied', { exact: true } ).waitFor();
	await appliedFirstRow
		.getByRole( 'button', { name: 'Restore legacy content', exact: true } )
		.waitFor();
	for ( const fixture of selected.slice( 1 ) ) {
		const row = page
			.getByRole( 'row' )
			.filter( { hasText: fixture.title } );
		await row.getByText( 'Prepared', { exact: true } ).waitFor();
		await row
			.getByRole( 'checkbox', {
				name: `Select ${ fixture.title } for batch apply`,
				exact: true,
			} )
			.check();
	}
	await page
		.getByRole( 'checkbox', {
			name: 'I understand that this changes the published slideshows.',
			exact: true,
		} )
		.check();
	await page
		.getByRole( 'button', {
			name: 'Apply native content to selected decks',
			exact: true,
		} )
		.click();
	await page
		.getByRole( 'status' )
		.filter( { hasText: 'Applied all 2 selected decks.' } )
		.waitFor();
	assert(
		4 === applyRequestCount,
		'The resumed Apply queue did not issue exactly two requests.'
	);

	await page.reload( { waitUntil: 'domcontentloaded' } );
	for ( const fixture of selected ) {
		const row = page
			.getByRole( 'row' )
			.filter( { hasText: fixture.title } );
		await row.getByText( 'Applied', { exact: true } ).waitFor();
		assert(
			0 ===
				( await row
					.locator( '[data-presenter-apply-select]' )
					.count() ),
			`Applied deck ${ fixture.slug } remained Apply selectable.`
		);
	}
	await neighborRow.getByText( 'Not prepared', { exact: true } ).waitFor();
	await preparedApplyNeighborRow
		.getByText( 'Prepared', { exact: true } )
		.waitFor();

	assert(
		0 === pageErrors.length,
		`Page errors: ${ pageErrors.join( ' | ' ) }`
	);
	assert(
		0 === consoleErrors.length,
		`Console errors: ${ consoleErrors.join( ' | ' ) }`
	);

	console.log(
		JSON.stringify( {
			appliedCount: selected.length,
			maximumApplyConcurrency: maximumApplyRequests,
			maximumPrepareConcurrency: maximumPrepareRequests,
			neighborUnchanged: true,
			passed: true,
			preparedCount: selected.length,
		} )
	);
} finally {
	await browser.close();
}
