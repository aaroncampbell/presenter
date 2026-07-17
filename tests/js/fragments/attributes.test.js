import {
	addFragmentAttributes,
	getDisabledFragmentAttributes,
	isFragmentEligibleBlock,
} from '../../../src/fragments/attributes';

describe( 'fragment block attributes', () => {
	it( 'adds namespaced attributes without replacing block attributes', () => {
		const settings = { attributes: { content: { type: 'string' } } };
		const filtered = addFragmentAttributes( settings, 'core/paragraph' );

		expect( filtered ).not.toBe( settings );
		expect( filtered.attributes.content ).toBe( settings.attributes.content );
		expect( filtered.attributes.presenterFragment ).toEqual( {
			type: 'boolean',
			default: false,
		} );
		expect( filtered.attributes.presenterFragmentIndex ).toEqual( {
			type: 'number',
		} );
	} );

	it( 'keeps the Presenter contract canonical for its namespaced attributes', () => {
		const owned = { type: 'boolean', default: true };
		const filtered = addFragmentAttributes(
			{ attributes: { presenterFragment: owned } },
			'custom/dynamic'
		);

		expect( filtered.attributes.presenterFragment ).toEqual( {
			type: 'boolean',
			default: false,
		} );
	} );

	it.each( [ 'presenter/deck', 'presenter/slide' ] )(
		'excludes structural block %s',
		( name ) => {
			const settings = { attributes: {} };
			expect( addFragmentAttributes( settings, name ) ).toBe( settings );
			expect( isFragmentEligibleBlock( name ) ).toBe( false );
		}
	);

	it( 'accepts native and third-party content blocks', () => {
		expect( isFragmentEligibleBlock( 'core/latest-posts' ) ).toBe( true );
		expect( isFragmentEligibleBlock( 'custom/dynamic' ) ).toBe( true );
	} );

	it( 'clears every dormant value when fragment behavior is disabled', () => {
		expect( getDisabledFragmentAttributes() ).toEqual( {
			presenterFragment: false,
			presenterFragmentEffect: '',
			presenterFragmentCustomClasses: '',
			presenterFragmentIndex: undefined,
		} );
	} );
} );
