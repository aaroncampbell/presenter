import {
	getPresenterRevealInstance,
	initializePresenterReveal,
} from './initialize';
import { initializeCharts } from './charts';
import { registerPresenterRevealPlugin } from './plugins';

const presenterRevealApi = Object.freeze( {
	getInstance: getPresenterRevealInstance,
	registerPlugin: registerPresenterRevealPlugin,
} );

function initializeChartsSafely( root = document ) {
	return initializeCharts( root ).catch( ( error ) => {
		window.console.error( 'Presenter could not initialize charts.', error );
	} );
}

Object.defineProperty( window, 'presenterReveal', {
	configurable: false,
	enumerable: true,
	value: presenterRevealApi,
	writable: false,
} );

/**
 * Start Presenter after dependency scripts have had an opportunity to register
 * their Reveal plugins.
 */
function startPresenterReveal() {
	Promise.resolve()
		.then( () => initializePresenterReveal() )
		.then( async ( revealInstance ) => {
			revealInstance.on( 'slidechanged', ( event ) => {
				initializeChartsSafely( event.currentSlide );
			} );
			revealInstance.on( 'fragmentshown', ( event ) => {
				window.requestAnimationFrame( () => {
					const slide =
						event.fragment?.closest?.( 'section' ) ||
						( typeof revealInstance.getCurrentSlide === 'function'
							? revealInstance.getCurrentSlide()
							: null );
					initializeChartsSafely( slide || document );
				} );
			} );
			await initializeChartsSafely();
			document.dispatchEvent(
				new CustomEvent( 'presenter:reveal:ready', {
					detail: { reveal: revealInstance },
				} )
			);
		} )
		.catch( ( error ) => {
			window.console.error(
				'Presenter could not initialize Reveal.',
				error
			);
		} );
}

if ( [ 'loading', 'interactive' ].includes( document.readyState ) ) {
	document.addEventListener( 'DOMContentLoaded', startPresenterReveal, {
		once: true,
	} );
} else {
	window.queueMicrotask( startPresenterReveal );
}

export {
	getPresenterRevealInstance,
	initializePresenterReveal,
	registerPresenterRevealPlugin,
};
window.addEventListener( 'beforeprint', () => initializeChartsSafely() );
