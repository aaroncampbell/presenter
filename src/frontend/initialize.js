import Reveal from 'reveal.js';

import { readPresenterRevealConfig } from './config';
import { resolvePresenterRevealPlugins } from './plugins';

export const REVEAL_ROOT_SELECTOR = '[data-presenter-reveal-root]';

let revealInstance = null;
let initializationPromise = null;

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

	initializationPromise = resolvePresenterRevealPlugins(
		presenterConfig.plugins
	).then( ( resolvedPlugins ) => {
		const revealConfig = {
			...presenterConfig.reveal,
			plugins: resolvedPlugins,
		};

		revealInstance = new RevealClass( revealRoot, revealConfig );

		return Promise.resolve( revealInstance.initialize() ).then(
			() => revealInstance
		);
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
