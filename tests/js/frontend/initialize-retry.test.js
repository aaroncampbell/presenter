import {
	getPresenterRevealInstance,
	initializePresenterReveal,
} from '../../../src/frontend/initialize';

describe( 'Presenter Reveal initialization recovery', () => {
	it( 'allows a clean retry after initialization rejects', async () => {
		document.body.innerHTML = `
			<script type="application/json" data-presenter-reveal-config>
				{ "plugins": [] }
			</script>
			<div data-presenter-reveal-root><div class="slides"></div></div>
		`;
		const failedInitialize = jest.fn( () =>
			Promise.reject( new Error( 'transient' ) )
		);
		const failedReveal = jest.fn( () => ( {
			initialize: failedInitialize,
			on: jest.fn(),
		} ) );

		await expect(
			initializePresenterReveal( { RevealClass: failedReveal } )
		).rejects.toThrow( 'transient' );
		expect( getPresenterRevealInstance() ).toBeNull();

		const successfulInstance = {
			initialize: jest.fn( () => Promise.resolve() ),
			on: jest.fn(),
		};
		const successfulReveal = jest.fn( () => successfulInstance );

		await expect(
			initializePresenterReveal( { RevealClass: successfulReveal } )
		).resolves.toBe( successfulInstance );
		expect( successfulReveal ).toHaveBeenCalledTimes( 1 );
	} );
} );
