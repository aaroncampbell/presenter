import {
	getAspectRatioAttributes,
	getNavigationAttributes,
	parseDimension,
} from '../../../src/blocks/deck/settings';

describe( 'Presenter Deck settings', () => {
	it( 'uses canonical dimensions for aspect-ratio presets', () => {
		expect( getAspectRatioAttributes( '16:9' ) ).toEqual( {
			aspectRatio: '16:9',
			width: 1280,
			height: 720,
		} );
		expect( getAspectRatioAttributes( '4:3' ) ).toEqual( {
			aspectRatio: '4:3',
			width: 960,
			height: 720,
		} );
	} );

	it( 'keeps dimensions unchanged for a custom ratio', () => {
		expect( getAspectRatioAttributes( 'custom' ) ).toEqual( {
			aspectRatio: 'custom',
		} );
	} );

	it( 'accepts only positive integer dimensions', () => {
		expect( parseDimension( '1920' ) ).toBe( 1920 );
		expect( parseDimension( '0' ) ).toBeUndefined();
		expect( parseDimension( '12.5' ) ).toBeUndefined();
		expect( parseDimension( '12px' ) ).toBeUndefined();
		expect( parseDimension( 'not a number' ) ).toBeUndefined();
	} );

	it( 'keeps keyboard navigation available when controls are disabled', () => {
		expect(
			getNavigationAttributes( 'controls', false, {
				controls: true,
				keyboard: false,
			} )
		).toEqual( { controls: false, keyboard: true } );
	} );

	it( 'keeps visible controls available when keyboard navigation is disabled', () => {
		expect(
			getNavigationAttributes( 'keyboard', false, {
				controls: false,
				keyboard: true,
			} )
		).toEqual( { keyboard: false, controls: true } );
	} );

	it( 'changes only the selected navigation setting when the other remains enabled', () => {
		expect(
			getNavigationAttributes( 'controls', false, {
				controls: true,
				keyboard: true,
			} )
		).toEqual( { controls: false } );
		expect(
			getNavigationAttributes( 'keyboard', true, {
				controls: false,
				keyboard: false,
			} )
		).toEqual( { keyboard: true } );
	} );
} );
