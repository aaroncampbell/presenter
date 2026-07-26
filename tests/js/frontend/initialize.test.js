import {
	getPresenterRevealInstance,
	initializePresenterReveal,
	synchronizeFragmentLayout,
} from '../../../src/frontend/initialize';

describe( 'Presenter Reveal initialization', () => {
	it( 'constructs and initializes only one Reveal instance', async () => {
		document.body.innerHTML = `
			<script type="application/json" data-presenter-reveal-config>
				{ "reveal": { "controls": false }, "plugins": [] }
			</script>
			<div data-presenter-reveal-root><div class="slides"></div></div>
		`;

		const lifecycle = [];
		const initialize = jest.fn( () => {
			lifecycle.push( 'initialize' );
			return Promise.resolve();
		} );
		const on = jest.fn( ( eventName ) => lifecycle.push( eventName ) );
		const RevealClass = jest.fn( () => ( { initialize, on } ) );

		const firstInitialization = initializePresenterReveal( { RevealClass } );
		const secondInitialization = initializePresenterReveal( { RevealClass } );
		const [ firstInstance, secondInstance ] = await Promise.all( [
			firstInitialization,
			secondInitialization,
		] );

		expect( RevealClass ).toHaveBeenCalledTimes( 1 );
		expect( initialize ).toHaveBeenCalledTimes( 1 );
		expect( lifecycle ).toEqual( [
			'fragmentshown',
			'fragmenthidden',
			'initialize',
		] );
		expect( on ).toHaveBeenCalledWith(
			'fragmentshown',
			expect.any( Function )
		);
		expect( on ).toHaveBeenCalledWith(
			'fragmenthidden',
			expect.any( Function )
		);
		expect( firstInstance ).toBe( secondInstance );
		expect( getPresenterRevealInstance() ).toBe( firstInstance );
		expect( RevealClass.mock.calls[ 0 ][ 1 ] ).toMatchObject( {
			controls: false,
			plugins: [],
			width: 1280,
			height: 720,
		} );
	} );

	it( 'coalesces fragment changes into one layout per animation frame', () => {
		const handlers = {};
		const instance = {
			layout: jest.fn(),
			on: jest.fn( ( eventName, handler ) => {
				handlers[ eventName ] = handler;
			} ),
		};
		const scheduledFrames = [];
		const requestFrame = jest.fn( ( callback ) => {
			scheduledFrames.push( callback );
		} );

		synchronizeFragmentLayout( instance, document, requestFrame );

		handlers.fragmentshown();
		handlers.fragmenthidden();

		expect( requestFrame ).toHaveBeenCalledTimes( 1 );
		expect( instance.layout ).not.toHaveBeenCalled();

		scheduledFrames.shift()();
		expect( instance.layout ).toHaveBeenCalledTimes( 1 );

		handlers.fragmenthidden();
		expect( requestFrame ).toHaveBeenCalledTimes( 2 );
	} );
} );
