import Reveal from 'reveal.js';

import { readPresenterRevealConfig } from './config';
import {
	finishLegacyMarkdownNotes,
	prepareLegacyMarkdownNotes,
} from './legacy-markdown-notes';
import { resolvePresenterRevealPlugins } from './plugins';

export const REVEAL_ROOT_SELECTOR = '[data-presenter-reveal-root]';

let revealInstance = null;
let initializationPromise = null;

/**
 * Recalculate centered slide geometry after fragments change document flow.
 *
 * Reveal 4 decks can use fragment classes to swap elements with `display`,
 * which changes a slide's height after Reveal has laid it out. Coalescing the
 * fragment events into the next animation frame preserves that behavior in
 * the native Reveal runtime without performing duplicate layouts for a
 * fragment group.
 *
 * @param {Object}   instance               Initialized Reveal instance.
 * @param {Document} documentObject         Document containing the deck.
 * @param {Function} [requestFrameOverride] Optional test scheduling seam.
 */
export function synchronizeFragmentLayout(
	instance,
	documentObject,
	requestFrameOverride
) {
	const requestFrame =
		requestFrameOverride ||
		documentObject.defaultView.requestAnimationFrame.bind(
			documentObject.defaultView
		);
	let layoutFramePending = false;

	const scheduleLayout = () => {
		if ( layoutFramePending ) {
			return;
		}

		layoutFramePending = true;
		requestFrame( () => {
			layoutFramePending = false;
			instance.layout();
		} );
	};

	instance.on( 'fragmentshown', scheduleLayout );
	instance.on( 'fragmenthidden', scheduleLayout );
}

/**
 * Initialize the single Presenter-owned Reveal instance.
 *
 * @param {Object}   options                Optional test/integration seams.
 * @param {Document} options.documentObject Document containing the deck.
 * @param {Function} options.RevealClass    Reveal constructor.
 * @return {Promise<Object>} Initialized Reveal instance.
 */
export function initializePresenterReveal( {
	documentObject = document,
	RevealClass = Reveal,
} = {} ) {
	if ( initializationPromise ) {
		return initializationPromise;
	}

	if ( ! documentObject ) {
		return Promise.reject(
			new Error( 'Presenter Reveal requires a browser document.' )
		);
	}

	const revealRoot = documentObject.querySelector( REVEAL_ROOT_SELECTOR );

	if ( ! revealRoot ) {
		return Promise.reject(
			new Error( 'Presenter could not find its Reveal root element.' )
		);
	}

	const presenterConfig = readPresenterRevealConfig( documentObject );
	prepareLegacyMarkdownNotes( revealRoot );

	initializationPromise = resolvePresenterRevealPlugins(
		presenterConfig.plugins
	).then( ( resolvedPlugins ) => {
		const revealConfig = {
			...presenterConfig.reveal,
			plugins: resolvedPlugins,
		};

		revealInstance = new RevealClass( revealRoot, revealConfig );
		synchronizeFragmentLayout( revealInstance, documentObject );

		return Promise.resolve( revealInstance.initialize() ).then( () => {
			finishLegacyMarkdownNotes( revealRoot );

			return revealInstance;
		} );
	} );

	return initializationPromise;
}

/**
 * Return the Presenter-owned Reveal instance, when initialization has begun.
 *
 * @return {?Object} Reveal instance or null before initialization.
 */
export function getPresenterRevealInstance() {
	return revealInstance;
}
