describe( 'Presenter Reveal plugin extensions', () => {
	beforeEach( () => {
		jest.resetModules();
	} );

	it( 'registers and resolves extension plugins in configured order', async () => {
		const {
			registerPresenterRevealPlugin,
			resolvePresenterRevealPlugins,
		} = require( '../../../src/frontend/plugins' );
		const firstPlugin = { id: 'first-extension' };
		const secondPlugin = { id: 'second-extension' };

		registerPresenterRevealPlugin( ' first-extension ', firstPlugin );
		registerPresenterRevealPlugin( 'second-extension', secondPlugin );

		await expect(
			resolvePresenterRevealPlugins( [
				'second-extension',
				'first-extension',
			] )
		).resolves.toEqual( [ secondPlugin, firstPlugin ] );
	} );

	it.each( [
		[ 'an empty ID', '', {}, 'requires a stable ID' ],
		[ 'a whitespace ID', '   ', {}, 'requires a stable ID' ],
		[ 'a missing plugin', 'extension', null, 'must be an object' ],
		[ 'a function plugin', 'extension', () => {}, 'must be an object' ],
	] )(
		'rejects %s',
		( label, id, plugin, expectedMessage ) => {
			const { registerPresenterRevealPlugin } = require( '../../../src/frontend/plugins' );

			expect( () =>
				registerPresenterRevealPlugin( id, plugin )
			).toThrow( expectedMessage );
		}
	);

	it( 'prevents extensions from replacing a registered plugin ID', () => {
		const { registerPresenterRevealPlugin } = require( '../../../src/frontend/plugins' );

		registerPresenterRevealPlugin( 'extension-plugin', { id: 'first' } );

		expect( () =>
			registerPresenterRevealPlugin( 'extension-plugin', { id: 'second' } )
		).toThrow( 'already registered as extension-plugin' );
		expect( () =>
			registerPresenterRevealPlugin( 'notes', { id: 'replacement' } )
		).toThrow( 'already registered as notes' );
	} );

	it( 'closes registration as soon as plugin resolution starts', async () => {
		const {
			registerPresenterRevealPlugin,
			resolvePresenterRevealPlugins,
		} = require( '../../../src/frontend/plugins' );

		await expect( resolvePresenterRevealPlugins( [] ) ).resolves.toEqual( [] );
		expect( () =>
			registerPresenterRevealPlugin( 'too-late', { id: 'too-late' } )
		).toThrow( 'must be registered before initialization' );
	} );

	it( 'rejects configured IDs that no script registered', async () => {
		const { resolvePresenterRevealPlugins } = require( '../../../src/frontend/plugins' );

		await expect(
			resolvePresenterRevealPlugins( [ 'missing-extension' ] )
		).rejects.toThrow(
			'Presenter Reveal plugin is not registered: missing-extension.'
		);
	} );
} );
