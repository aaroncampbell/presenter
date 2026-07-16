import {
	getPresenterRevealInstance,
	initializePresenterReveal,
} from '../../../src/frontend/initialize';

describe( 'Presenter Reveal initialization', () => {
	it( 'constructs and initializes only one Reveal instance', async () => {
		document.body.innerHTML = `
			<script type="application/json" data-presenter-reveal-config>
				{ "reveal": { "controls": false }, "plugins": [] }
			</script>
			<div data-presenter-reveal-root><div class="slides"></div></div>
		`;

		const initialize = jest.fn( () => Promise.resolve() );
		const RevealClass = jest.fn( () => ( { initialize } ) );

		const firstInitialization = initializePresenterReveal( { RevealClass } );
		const secondInitialization = initializePresenterReveal( { RevealClass } );
		const [ firstInstance, secondInstance ] = await Promise.all( [
			firstInitialization,
			secondInitialization,
		] );

		expect( RevealClass ).toHaveBeenCalledTimes( 1 );
		expect( initialize ).toHaveBeenCalledTimes( 1 );
		expect( firstInstance ).toBe( secondInstance );
		expect( getPresenterRevealInstance() ).toBe( firstInstance );
		expect( RevealClass.mock.calls[ 0 ][ 1 ] ).toMatchObject( {
			controls: false,
			plugins: [],
			width: 1280,
			height: 720,
		} );
	} );
} );
