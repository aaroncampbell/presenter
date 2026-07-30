jest.mock( 'reveal.js', () => ( {
	__esModule: true,
	default: jest.fn( ( revealRoot, config ) => ( {
		config,
		initialize: jest.fn( () => Promise.resolve() ),
		layout: jest.fn(),
		on: jest.fn(),
		revealRoot,
	} ) ),
} ) );

describe( 'Presenter Reveal front-end entry point', () => {
	it( 'installs the public API idempotently', () => {
		const { installPresenterRevealApi } = require( '../../../src/frontend/api' );
		const target = {};
		const api = { getInstance: jest.fn(), registerPlugin: jest.fn() };

		expect( installPresenterRevealApi( target, api ) ).toBe( true );
		expect( installPresenterRevealApi( target, api ) ).toBe( false );
		expect( target.presenterReveal ).toBe( api );
	} );

	it( 'keeps plugin registration open through interactive deferred scripts', async () => {
		Object.defineProperty( document, 'readyState', {
			configurable: true,
			value: 'interactive',
		} );
		document.body.innerHTML = `
			<script type="application/json" data-presenter-reveal-config>
				{ "plugins": [ "dependent-extension" ] }
			</script>
			<div data-presenter-reveal-root><div class="slides"></div></div>
		`;

		const ready = new Promise( ( resolve ) => {
			document.addEventListener( 'presenter:reveal:ready', resolve, {
				once: true,
			} );
		} );
		const RevealClass = require( 'reveal.js' ).default;

		require( '../../../src/frontend' );

		expect( RevealClass ).not.toHaveBeenCalled();

		const extension = { id: 'dependent-extension' };
		window.presenterReveal.registerPlugin(
			'dependent-extension',
			extension
		);
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		await ready;

		expect( RevealClass ).toHaveBeenCalledTimes( 1 );
		expect( RevealClass.mock.calls[ 0 ][ 1 ].plugins ).toEqual( [
			extension,
		] );
	} );
} );
