import {
	freezeApplyQueue,
	freezePrepareQueue,
	initializeApplyBatch,
	initializePrepareBatch,
	runApplyQueue,
	runPrepareQueue,
	submitApplyItem,
	submitPrepareItem,
} from '../../src/admin-migration';

const createForm = ( postId ) => {
	const form = document.createElement( 'form' );
	form.innerHTML = `
		<input name="action" value="presenter_migration_prepare">
		<input name="post_id" value="${ postId }">
		<input name="_wpnonce" value="nonce-${ postId }">
	`;
	return form;
};

const createApplyForm = ( postId ) => {
	const form = document.createElement( 'form' );
	form.innerHTML = `
		<input name="action" value="presenter_migration_apply">
		<input name="post_id" value="${ postId }">
		<input name="_wpnonce" value="apply-nonce-${ postId }">
	`;
	return form;
};

const response = ( payload, ok = true ) => ( {
	ok,
	json: jest.fn( async () => payload ),
} );

describe( 'Presenter migration Apply queue', () => {
	const originalFetch = window.fetch;

	afterEach( () => {
		document.body.innerHTML = '';
		window.fetch = originalFetch;
	} );

	it( 'submits the exact attempt-bound Apply authority and validates the exact receipt', async () => {
		const item = freezeApplyQueue( [ createApplyForm( 7 ) ] )[ 0 ];
		const request = jest.fn( async ( endpoint, options ) => {
			expect( endpoint ).toBe( '/ajax' );
			expect( Array.from( options.body.keys() ).sort() ).toEqual( [
				'_wpnonce',
				'action',
				'post_id',
				'presenter_confirm',
			] );
			expect( options.body.get( 'action' ) ).toBe( 'batch-apply' );
			expect( options.body.get( 'presenter_confirm' ) ).toBe( 'apply' );
			return response( { schemaVersion: 1, operation: 'apply', result: 'applied' } );
		} );

		await expect( submitApplyItem( item, '/ajax', 'batch-apply', request ) ).resolves.toBe( 'applied' );
	} );

	it.each( [
		[ 'extra key', response( { schemaVersion: 1, operation: 'apply', result: 'applied', private: 'sentinel' } ) ],
		[ 'wrong operation', response( { schemaVersion: 1, operation: 'prepare', result: 'applied' } ) ],
		[ 'unknown result', response( { schemaVersion: 1, operation: 'apply', result: 'private-code' } ) ],
		[ 'HTTP failure', response( {}, false ) ],
		[ 'invalid JSON', { ok: true, json: jest.fn( async () => Promise.reject( new Error( 'invalid' ) ) ) } ],
	] )( 'treats %s as an unknown transport outcome', async ( _label, result ) => {
		await expect(
			submitApplyItem(
				freezeApplyQueue( [ createApplyForm( 1 ) ] )[ 0 ],
				'/ajax',
				'batch-apply',
				jest.fn( async () => result )
			)
		).resolves.toBe( 'transport-error' );
	} );

	it( 'freezes, deduplicates, caps, and runs with one request in flight', async () => {
		const forms = Array.from( { length: 21 }, ( unused, index ) => createApplyForm( index + 1 ) );
		forms.splice( 2, 0, createApplyForm( 2 ) );
		const frozen = freezeApplyQueue( forms );
		expect( frozen ).toHaveLength( 20 );
		forms[ 1 ].querySelector( '[name="_wpnonce"]' ).value = 'changed';
		let active = 0;
		let maximumActive = 0;
		const request = jest.fn( async () => {
			active += 1;
			maximumActive = Math.max( maximumActive, active );
			await Promise.resolve();
			active -= 1;
			return response( { schemaVersion: 1, operation: 'apply', result: 'applied' } );
		} );
		await expect( runApplyQueue( { forms, endpoint: '/ajax', action: 'batch-apply', request } ) ).resolves.toEqual( {
			applied: 20,
			attempted: 20,
			total: 20,
			result: 'complete',
		} );
		expect( maximumActive ).toBe( 1 );
	} );

	it.each( [
		[ 'applied-warning', 'warning', 2 ],
		[ 'stopped', 'stopped', 1 ],
		[ 'review-required', 'review-required', 1 ],
	] )( 'stops before the next deck on %s', async ( receipt, expected, applied ) => {
		const request = jest
			.fn()
			.mockResolvedValueOnce( response( { schemaVersion: 1, operation: 'apply', result: 'applied' } ) )
			.mockResolvedValueOnce( response( { schemaVersion: 1, operation: 'apply', result: receipt } ) );
		await expect(
			runApplyQueue( {
				forms: [ createApplyForm( 1 ), createApplyForm( 2 ), createApplyForm( 3 ) ],
				endpoint: '/ajax',
				action: 'batch-apply',
				request,
			} )
		).resolves.toEqual( { applied, attempted: 2, total: 3, result: expected } );
		expect( request ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'requires selection and explicit confirmation before starting', async () => {
		document.body.innerHTML = `
			<input type="checkbox" data-presenter-apply-select data-presenter-apply-form="apply-1" hidden>
			<form id="apply-1">${ createApplyForm( 1 ).innerHTML }</form>
			<section data-presenter-apply-batch data-endpoint="/ajax" data-action="batch-apply" hidden>
				<input type="checkbox" data-presenter-apply-select-all>
				<input type="checkbox" data-presenter-apply-confirm>
				<button data-presenter-apply-start disabled>Start</button>
				<button data-presenter-apply-stop hidden>Stop</button>
				<p data-presenter-apply-progress></p>
			</section>`;
		window.fetch = jest.fn( async () => response( { schemaVersion: 1, operation: 'apply', result: 'applied' } ) );
		initializeApplyBatch();
		const start = document.querySelector( '[data-presenter-apply-start]' );
		document.querySelector( '[data-presenter-apply-select]' ).click();
		expect( start.disabled ).toBe( true );
		document.querySelector( '[data-presenter-apply-confirm]' ).click();
		expect( start.disabled ).toBe( false );
		start.click();
		start.click();
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		expect( document.querySelector( '[data-presenter-apply-progress]' ).textContent ).toContain( 'Applied all 1' );
	} );
} );

describe( 'Presenter migration Prepare queue', () => {
	const originalFetch = window.fetch;

	afterEach( () => {
		document.body.innerHTML = '';
		window.fetch = originalFetch;
	} );

	it( 'uses the dedicated transport while preserving per-deck form authority', async () => {
		const form = createForm( 41 );
		const request = jest.fn( async ( endpoint, options ) => {
			expect( endpoint ).toBe( '/wp-admin/admin-ajax.php' );
			expect( options.method ).toBe( 'POST' );
			expect( options.credentials ).toBe( 'same-origin' );
			expect( options.body.get( 'action' ) ).toBe(
				'presenter_migration_batch_prepare_item'
			);
			expect( options.body.get( 'post_id' ) ).toBe( '41' );
			expect( options.body.get( '_wpnonce' ) ).toBe( 'nonce-41' );
			return response( {
				schemaVersion: 1,
				operation: 'prepare',
				result: 'prepared',
			} );
		} );

		await expect(
			submitPrepareItem(
				freezePrepareQueue( [ form ] )[ 0 ],
				'/wp-admin/admin-ajax.php',
				'presenter_migration_batch_prepare_item',
				request
			)
		).resolves.toBe( true );
		expect( request ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'runs a deduplicated frozen queue with at most one request in flight', async () => {
		const forms = [ createForm( 1 ), createForm( 2 ), createForm( 3 ) ];
		const duplicatePostForm = createForm( 2 );
		let active = 0;
		let maximumActive = 0;
		const seen = [];
		const request = jest.fn( async ( _endpoint, options ) => {
			active += 1;
			maximumActive = Math.max( maximumActive, active );
			seen.push( options.body.get( 'post_id' ) );
			await Promise.resolve();
			active -= 1;
			return response( {
				schemaVersion: 1,
				operation: 'prepare',
				result: 'prepared',
			} );
		} );

		await expect(
			runPrepareQueue( {
				forms: [
					forms[ 0 ],
					forms[ 1 ],
					duplicatePostForm,
					forms[ 2 ],
				],
				endpoint: '/ajax',
				action: 'batch-prepare',
				request,
			} )
		).resolves.toEqual( {
			completed: 3,
			total: 3,
			result: 'complete',
		} );
		expect( seen ).toEqual( [ '1', '2', '3' ] );
		expect( maximumActive ).toBe( 1 );
	} );

	it( 'freezes later request fields before the first request starts', async () => {
		const first = createForm( 1 );
		const second = createForm( 2 );
		const seen = [];
		const request = jest.fn( async ( _endpoint, options ) => {
			seen.push( {
				nonce: options.body.get( '_wpnonce' ),
				postId: options.body.get( 'post_id' ),
			} );
			if ( seen.length === 1 ) {
				second.querySelector( '[name="post_id"]' ).value = '999';
				second.querySelector( '[name="_wpnonce"]' ).value = 'changed';
			}
			return response( {
				schemaVersion: 1,
				operation: 'prepare',
				result: 'prepared',
			} );
		} );

		await runPrepareQueue( {
			forms: [ first, second ],
			endpoint: '/ajax',
			action: 'batch-prepare',
			request,
		} );

		expect( seen ).toEqual( [
			{ nonce: 'nonce-1', postId: '1' },
			{ nonce: 'nonce-2', postId: '2' },
		] );
	} );

	it( 'caps an otherwise valid frozen queue at the rendered page size', () => {
		const forms = Array.from( { length: 21 }, ( unused, index ) =>
			createForm( index + 1 )
		);

		expect( freezePrepareQueue( forms ) ).toHaveLength( 20 );
		expect( freezePrepareQueue( forms )[ 19 ] ).toEqual( {
			nonce: 'nonce-20',
			postId: '20',
		} );
	} );

	it.each( [
		[ 'fixed stop result', response( { schemaVersion: 1, operation: 'prepare', result: 'stopped' } ) ],
		[ 'wrong operation', response( { schemaVersion: 1, operation: 'apply', result: 'prepared' } ) ],
		[ 'HTTP failure', response( {}, false ) ],
		[ 'invalid JSON', { ok: true, json: jest.fn( async () => Promise.reject( new Error( 'invalid' ) ) ) } ],
		[ 'network failure', new Error( 'private-network-sentinel' ) ],
	] )( 'stops before later decks on %s', async ( _label, secondResult ) => {
		const forms = [ createForm( 1 ), createForm( 2 ), createForm( 3 ) ];
		const request = jest
			.fn()
			.mockResolvedValueOnce(
				response( {
					schemaVersion: 1,
					operation: 'prepare',
					result: 'prepared',
				} )
			);
		if ( secondResult instanceof Error ) {
			request.mockRejectedValueOnce( secondResult );
		} else {
			request.mockResolvedValueOnce( secondResult );
		}

		await expect(
			runPrepareQueue( {
				forms,
				endpoint: '/ajax',
				action: 'batch-prepare',
				request,
			} )
		).resolves.toEqual( {
			completed: 1,
			total: 3,
			result: 'stopped',
		} );
		expect( request ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'honors cancellation only between deck requests', async () => {
		const forms = [ createForm( 1 ), createForm( 2 ) ];
		let stop = false;
		const request = jest.fn( async () =>
			response( {
				schemaVersion: 1,
				operation: 'prepare',
				result: 'prepared',
			} )
		);

		await expect(
			runPrepareQueue( {
				forms,
				endpoint: '/ajax',
				action: 'batch-prepare',
				request,
				shouldStop: () => stop,
				onProgress: ( state ) => {
					if ( state.state === 'prepared' ) {
						stop = true;
					}
				},
			} )
		).resolves.toEqual( {
			completed: 1,
			total: 2,
			result: 'cancelled',
		} );
		expect( request ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps incomplete progressive controls hidden', () => {
		document.body.innerHTML = '<section data-presenter-prepare-batch hidden></section>';
		window.fetch = jest.fn();

		initializePrepareBatch();

		expect(
			document.querySelector( '[data-presenter-prepare-batch]' ).hidden
		).toBe( true );
	} );

	it( 'prevents double start and stops only after the active deck', async () => {
		document.body.innerHTML = `
			<input type="checkbox" data-presenter-prepare-select data-presenter-prepare-form="prepare-1" hidden>
			<input type="checkbox" data-presenter-prepare-select data-presenter-prepare-form="prepare-2" hidden>
			<form id="prepare-1">${ createForm( 1 ).innerHTML }</form>
			<form id="prepare-2">${ createForm( 2 ).innerHTML }</form>
			<section data-presenter-prepare-batch data-endpoint="/ajax" data-action="batch-prepare" hidden>
				<input type="checkbox" data-presenter-prepare-select-all>
				<button type="button" data-presenter-prepare-start disabled>Start</button>
				<button type="button" data-presenter-prepare-stop hidden>Stop</button>
				<p data-presenter-prepare-progress></p>
			</section>
		`;
		let resolveRequest;
		window.fetch = jest.fn(
			() =>
				new Promise( ( resolve ) => {
					resolveRequest = resolve;
				} )
		);
		initializePrepareBatch();
		const root = document.querySelector( '[data-presenter-prepare-batch]' );
		const selectAll = root.querySelector(
			'[data-presenter-prepare-select-all]'
		);
		const start = root.querySelector( '[data-presenter-prepare-start]' );
		const stop = root.querySelector( '[data-presenter-prepare-stop]' );
		const progress = root.querySelector(
			'[data-presenter-prepare-progress]'
		);

		expect( root.hidden ).toBe( false );
		expect(
			document.querySelector( '[data-presenter-prepare-select]' ).hidden
		).toBe( false );
		selectAll.click();
		start.click();
		start.click();
		await Promise.resolve();
		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		const unload = new Event( 'beforeunload', { cancelable: true } );
		window.dispatchEvent( unload );
		expect( unload.defaultPrevented ).toBe( true );

		stop.click();
		resolveRequest(
			response( {
				schemaVersion: 1,
				operation: 'prepare',
				result: 'prepared',
			} )
		);
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		expect( progress.textContent ).toContain( 'Stopped after 1 of 2' );
		expect( stop.hidden ).toBe( true );
	} );
} );
