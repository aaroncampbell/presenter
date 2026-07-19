/**
 * Client-chained, page-bounded migration preparation.
 */

import { __, sprintf } from '@wordpress/i18n';

let activeOperation = null;

/** Freeze every migration mutation control once a page-level queue starts. */
function disableMutationControls() {
	for ( const control of document.querySelectorAll(
		'[data-presenter-prepare-select], [data-presenter-apply-select], [data-presenter-prepare-select-all], [data-presenter-apply-select-all], [data-presenter-apply-confirm], [data-presenter-prepare-start], [data-presenter-apply-start], [data-presenter-prepare-form] button, [data-presenter-prepare-form] input[type="submit"], [data-presenter-apply-form] button, [data-presenter-apply-form] input[type="submit"], [data-presenter-restore-form] button, [data-presenter-restore-form] input[type="submit"], form input[name="presenter_confirm"]'
	) ) {
		control.disabled = true;
	}
}

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
 * Freeze an attempt-bound Apply queue from server-rendered forms.
 *
 * @param {HTMLFormElement[]} forms Candidate current-page forms.
 * @return {{postId: string, nonce: string}[]} Frozen queue, capped at 20.
 */
export function freezeApplyQueue( forms ) {
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
 * Submit one attempt-bound Apply item and return a fixed local result.
 *
 * @param {Object}   item     Frozen per-deck request.
 * @param {string}   endpoint Authenticated AJAX endpoint.
 * @param {string}   action   Batch transport action.
 * @param {Function} request  Fetch-compatible request function.
 * @return {Promise<string>} Fixed local result.
 */
export async function submitApplyItem( item, endpoint, action, request ) {
	const body = new FormData();
	body.set( 'action', action );
	body.set( 'post_id', item.postId );
	body.set( '_wpnonce', item.nonce );
	body.set( 'presenter_confirm', 'apply' );

	let response;
	try {
		response = await request( endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
			body,
		} );
	} catch {
		return 'transport-error';
	}
	if ( ! response.ok ) {
		return 'transport-error';
	}

	let payload;
	try {
		payload = await response.json();
	} catch {
		return 'transport-error';
	}
	const allowed = [
		'applied',
		'applied-warning',
		'stopped',
		'review-required',
	];
	if (
		! payload ||
		Object.keys( payload ).sort().join( ',' ) !==
			'operation,result,schemaVersion' ||
		payload.schemaVersion !== 1 ||
		payload.operation !== 'apply' ||
		! allowed.includes( payload.result )
	) {
		return 'transport-error';
	}

	return payload.result;
}

/**
 * Run an Apply queue serially without retrying uncertain outcomes.
 *
 * @param {Object}            options            Runner options.
 * @param {HTMLFormElement[]} options.forms      Frozen, ordered form queue.
 * @param {string}            options.endpoint   Authenticated AJAX endpoint.
 * @param {string}            options.action     Batch transport action.
 * @param {Function}          options.request    Fetch-compatible request.
 * @param {Function}          options.shouldStop Whether to stop before next item.
 * @param {Function}          options.onProgress Progress callback.
 * @return {Promise<Object>} Aggregate content-free result.
 */
export async function runApplyQueue( {
	forms,
	endpoint,
	action,
	request,
	shouldStop = () => false,
	onProgress = () => {},
} ) {
	const queue = freezeApplyQueue( forms );
	let applied = 0;
	let attempted = 0;
	for ( const item of queue ) {
		if ( shouldStop() ) {
			return {
				applied,
				attempted,
				total: queue.length,
				result: 'cancelled',
			};
		}
		onProgress( {
			applied,
			attempted,
			total: queue.length,
			state: 'running',
		} );
		const result = await submitApplyItem( item, endpoint, action, request );
		attempted += 1;
		if ( result === 'applied' ) {
			applied += 1;
			onProgress( {
				applied,
				attempted,
				total: queue.length,
				state: 'applied',
			} );
			continue;
		}
		if ( result === 'applied-warning' ) {
			applied += 1;
			return {
				applied,
				attempted,
				total: queue.length,
				result: 'warning',
			};
		}
		return { applied, attempted, total: queue.length, result };
	}
	return { applied, attempted, total: queue.length, result: 'complete' };
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
		start.disabled =
			running || activeOperation !== null || selected.length < 1;
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
		if ( running || activeOperation !== null ) {
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
		activeOperation = 'prepare';
		disableMutationControls();
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
		activeOperation = null;
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

/** Initialize the destructive Apply queue with explicit batch confirmation. */
export function initializeApplyBatch() {
	const root = document.querySelector( '[data-presenter-apply-batch]' );
	if ( ! root || typeof window.fetch !== 'function' ) {
		return;
	}
	const selectAll = root.querySelector( '[data-presenter-apply-select-all]' );
	const confirmation = root.querySelector( '[data-presenter-apply-confirm]' );
	const start = root.querySelector( '[data-presenter-apply-start]' );
	const stop = root.querySelector( '[data-presenter-apply-stop]' );
	const progress = root.querySelector( '[data-presenter-apply-progress]' );
	if ( ! selectAll || ! confirmation || ! start || ! stop || ! progress ) {
		return;
	}
	const selections = Array.from(
		document.querySelectorAll( '[data-presenter-apply-select]' )
	);
	root.hidden = false;
	for ( const checkbox of selections ) {
		checkbox.hidden = false;
	}

	let running = false;
	let stopRequested = false;
	window.addEventListener( 'beforeunload', ( event ) => {
		if ( running ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
	const updateSelection = () => {
		const selected = selections.filter( ( checkbox ) => checkbox.checked );
		start.disabled =
			running ||
			activeOperation !== null ||
			selected.length < 1 ||
			! confirmation.checked;
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
	confirmation.addEventListener( 'change', updateSelection );
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
		if ( running || activeOperation !== null || ! confirmation.checked ) {
			return;
		}
		const forms = selections
			.filter( ( checkbox ) => checkbox.checked )
			.map( ( checkbox ) =>
				document.getElementById( checkbox.dataset.presenterApplyForm )
			)
			.filter( ( form ) => form instanceof window.HTMLFormElement );
		if ( forms.length < 1 ) {
			updateSelection();
			return;
		}

		running = true;
		activeOperation = 'apply';
		disableMutationControls();
		stopRequested = false;
		for ( const control of [ ...selections, selectAll, confirmation ] ) {
			control.disabled = true;
		}
		start.disabled = true;
		stop.hidden = false;
		stop.disabled = false;
		const result = await runApplyQueue( {
			forms,
			endpoint: root.dataset.endpoint,
			action: root.dataset.action,
			request: window.fetch.bind( window ),
			shouldStop: () => stopRequested,
			onProgress: ( state ) => {
				progress.textContent = sprintf(
					/* translators: 1: applied deck count, 2: total deck count. */
					__( 'Applied %1$d of %2$d selected decks.', 'presenter' ),
					state.applied,
					state.total
				);
			},
		} );
		running = false;
		activeOperation = null;
		stop.hidden = true;
		if ( result.result === 'complete' ) {
			progress.textContent = sprintf(
				/* translators: %d: selected deck count. */
				__(
					'Applied all %d selected decks. Reload to review their current status.',
					'presenter'
				),
				result.total
			);
		} else if ( result.result === 'cancelled' ) {
			progress.textContent = sprintf(
				/* translators: 1: applied deck count, 2: selected deck count. */
				__(
					'Stopped after %1$d of %2$d selected decks. Reload before resuming.',
					'presenter'
				),
				result.applied,
				result.total
			);
		} else if ( result.result === 'warning' ) {
			progress.textContent = sprintf(
				/* translators: 1: applied deck count, 2: selected deck count. */
				__(
					'Applied %1$d of %2$d decks, but the last Apply reported a cleanup warning. Reload and review before continuing.',
					'presenter'
				),
				result.applied,
				result.total
			);
		} else if ( result.result === 'stopped' ) {
			progress.textContent = sprintf(
				/* translators: 1: applied deck count, 2: selected deck count. */
				__(
					'Apply stopped after %1$d of %2$d decks. The current deck did not cut over; reload and review before retrying.',
					'presenter'
				),
				result.applied,
				result.total
			);
		} else if ( result.result === 'review-required' ) {
			progress.textContent = sprintf(
				/* translators: 1: applied deck count, 2: selected deck count. */
				__(
					'Apply stopped after %1$d of %2$d decks because the current representation could not be classified conclusively. Do not retry or edit it until reviewed.',
					'presenter'
				),
				result.applied,
				result.total
			);
		} else {
			progress.textContent = sprintf(
				/* translators: %d: attempted deck count. */
				__(
					'Apply stopped after %d attempted deck. Its result is unknown; reload and review current statuses before continuing.',
					'presenter'
				),
				result.attempted
			);
		}
	} );
	updateSelection();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', () => {
		initializePrepareBatch();
		initializeApplyBatch();
	} );
} else {
	initializePrepareBatch();
	initializeApplyBatch();
}
