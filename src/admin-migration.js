/**
 * Client-chained, page-bounded migration preparation.
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * Submit one existing authenticated Prepare form through the batch transport.
 *
 * @param {Object}   item     Frozen per-deck request.
 * @param {string}   endpoint Authenticated AJAX endpoint.
 * @param {string}   action   Batch transport action.
 * @param {Function} request  Fetch-compatible request function.
 * @return {Promise<boolean>} Whether the exact clean result was returned.
 */
export async function submitPrepareItem( item, endpoint, action, request ) {
	const body = new FormData();
	body.set( 'action', action );
	body.set( 'post_id', item.postId );
	body.set( '_wpnonce', item.nonce );

	let response;
	try {
		response = await request( endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
			body,
		} );
	} catch {
		return false;
	}

	if ( ! response.ok ) {
		return false;
	}

	let payload;
	try {
		payload = await response.json();
	} catch {
		return false;
	}

	return (
		payload?.schemaVersion === 1 &&
		payload?.operation === 'prepare' &&
		payload?.result === 'prepared'
	);
}

/**
 * Freeze a canonical, deduplicated queue from server-rendered Prepare forms.
 *
 * @param {HTMLFormElement[]} forms Candidate current-page forms.
 * @return {{postId: string, nonce: string}[]} Frozen queue, capped at 20.
 */
export function freezePrepareQueue( forms ) {
	const seenPostIds = new Set();
	const queue = [];
	for ( const form of forms ) {
		const fields = new FormData( form );
		const postId = fields.get( 'post_id' );
		const nonce = fields.get( '_wpnonce' );
		if (
			typeof postId !== 'string' ||
			! /^[1-9][0-9]*$/.test( postId ) ||
			typeof nonce !== 'string' ||
			nonce.length < 1 ||
			seenPostIds.has( postId )
		) {
			continue;
		}

		seenPostIds.add( postId );
		queue.push( Object.freeze( { postId, nonce } ) );
		if ( queue.length === 20 ) {
			break;
		}
	}

	return Object.freeze( queue );
}

/**
 * Run a frozen queue sequentially and stop on the first non-clean result.
 *
 * @param {Object}            options            Runner options.
 * @param {HTMLFormElement[]} options.forms      Frozen, ordered form queue.
 * @param {string}            options.endpoint   Authenticated AJAX endpoint.
 * @param {string}            options.action     Batch transport action.
 * @param {Function}          options.request    Fetch-compatible request.
 * @param {Function}          options.shouldStop Whether to stop before next item.
 * @param {Function}          options.onProgress Progress callback.
 * @return {Promise<{completed: number, total: number, result: string}>} Result.
 */
export async function runPrepareQueue( {
	forms,
	endpoint,
	action,
	request,
	shouldStop = () => false,
	onProgress = () => {},
} ) {
	const queue = freezePrepareQueue( forms );
	let completed = 0;

	for ( const item of queue ) {
		if ( shouldStop() ) {
			return { completed, total: queue.length, result: 'cancelled' };
		}

		onProgress( { completed, total: queue.length, state: 'running' } );
		const prepared = await submitPrepareItem(
			item,
			endpoint,
			action,
			request
		);
		if ( ! prepared ) {
			return { completed, total: queue.length, result: 'stopped' };
		}

		completed += 1;
		onProgress( { completed, total: queue.length, state: 'prepared' } );
	}

	return { completed, total: queue.length, result: 'complete' };
}

/** Initialize the progressive-enhancement controls on the migration screen. */
export function initializePrepareBatch() {
	const root = document.querySelector( '[data-presenter-prepare-batch]' );
	if ( ! root || typeof window.fetch !== 'function' ) {
		return;
	}

	const selectAll = root.querySelector(
		'[data-presenter-prepare-select-all]'
	);
	const start = root.querySelector( '[data-presenter-prepare-start]' );
	const stop = root.querySelector( '[data-presenter-prepare-stop]' );
	const progress = root.querySelector( '[data-presenter-prepare-progress]' );
	if ( ! selectAll || ! start || ! stop || ! progress ) {
		return;
	}
	root.hidden = false;
	const selections = Array.from(
		document.querySelectorAll( '[data-presenter-prepare-select]' )
	);
	for ( const checkbox of selections ) {
		checkbox.hidden = false;
	}

	let running = false;
	let stopRequested = false;
	const warnBeforeUnload = ( event ) => {
		if ( running ) {
			event.preventDefault();
			event.returnValue = '';
		}
	};
	window.addEventListener( 'beforeunload', warnBeforeUnload );
	const updateSelection = () => {
		const selected = selections.filter( ( checkbox ) => checkbox.checked );
		start.disabled = running || selected.length < 1;
		selectAll.checked =
			selections.length > 0 && selected.length === selections.length;
		selectAll.indeterminate =
			selected.length > 0 && selected.length < selections.length;
	};

	selectAll.addEventListener( 'change', () => {
		for ( const checkbox of selections ) {
			checkbox.checked = selectAll.checked;
		}
		updateSelection();
	} );
	for ( const checkbox of selections ) {
		checkbox.addEventListener( 'change', updateSelection );
	}

	stop.addEventListener( 'click', () => {
		stopRequested = true;
		stop.disabled = true;
		progress.textContent = __(
			'Stop requested. The current deck will finish first.',
			'presenter'
		);
	} );

	start.addEventListener( 'click', async () => {
		if ( running ) {
			return;
		}

		const forms = selections
			.filter( ( checkbox ) => checkbox.checked )
			.map( ( checkbox ) =>
				document.getElementById( checkbox.dataset.presenterPrepareForm )
			)
			.filter( ( form ) => form instanceof window.HTMLFormElement );
		if ( forms.length < 1 ) {
			updateSelection();
			return;
		}

		running = true;
		stopRequested = false;
		for ( const checkbox of selections ) {
			checkbox.disabled = true;
		}
		selectAll.disabled = true;
		start.disabled = true;
		stop.hidden = false;
		stop.disabled = false;

		const result = await runPrepareQueue( {
			forms,
			endpoint: root.dataset.endpoint,
			action: root.dataset.action,
			request: window.fetch.bind( window ),
			shouldStop: () => stopRequested,
			onProgress: ( state ) => {
				progress.textContent = sprintf(
					/* translators: 1: completed deck count, 2: total deck count. */
					__( 'Prepared %1$d of %2$d selected decks.', 'presenter' ),
					state.completed,
					state.total
				);
			},
		} );

		running = false;
		stop.hidden = true;
		if ( result.result === 'complete' ) {
			progress.textContent = sprintf(
				/* translators: %d: prepared deck count. */
				__(
					'Prepared %d selected decks. Reload this page to review their current status.',
					'presenter'
				),
				result.completed
			);
		} else if ( result.result === 'cancelled' ) {
			progress.textContent = sprintf(
				/* translators: 1: completed deck count, 2: total deck count. */
				__(
					'Stopped after %1$d of %2$d selected decks. Reload this page before resuming.',
					'presenter'
				),
				result.completed,
				result.total
			);
		} else {
			progress.textContent = sprintf(
				/* translators: 1: completed deck count, 2: total deck count. */
				__(
					'Preparation stopped after %1$d of %2$d selected decks. Reload and review the current statuses before retrying.',
					'presenter'
				),
				result.completed,
				result.total
			);
		}
	} );

	updateSelection();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initializePrepareBatch );
} else {
	initializePrepareBatch();
}
