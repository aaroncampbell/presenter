import {
	BACKGROUND_POSITION_OPTIONS,
	BACKGROUND_REPEAT_OPTIONS,
	BACKGROUND_SIZE_OPTIONS,
	normalizeAutoAnimateId,
	normalizeBackgroundOpacity,
	normalizeControlledOption,
} from '../../../src/blocks/slide/advanced-settings';

describe( 'advanced Slide settings', () => {
	it( 'accepts only background opacity values from zero through one', () => {
		expect( normalizeBackgroundOpacity( '' ) ).toBe( '' );
		expect( normalizeBackgroundOpacity( '0' ) ).toBe( 0 );
		expect( normalizeBackgroundOpacity( 0.45 ) ).toBe( 0.45 );
		expect( normalizeBackgroundOpacity( '1' ) ).toBe( 1 );
		expect( normalizeBackgroundOpacity( -0.01 ) ).toBeUndefined();
		expect( normalizeBackgroundOpacity( 1.01 ) ).toBeUndefined();
		expect( normalizeBackgroundOpacity( 'opaque' ) ).toBeUndefined();
	} );

	it( 'validates optional bounded auto-animate group identifiers', () => {
		expect( normalizeAutoAnimateId( '' ) ).toBe( '' );
		expect( normalizeAutoAnimateId( ' product-tour_2 ' ) ).toBe(
			'product-tour_2'
		);
		expect( normalizeAutoAnimateId( '-invalid' ) ).toBeUndefined();
		expect( normalizeAutoAnimateId( 'contains spaces' ) ).toBeUndefined();
		expect( normalizeAutoAnimateId( 'a'.repeat( 65 ) ) ).toBeUndefined();
	} );

	it.each( [
		[ 'size', 'contain', BACKGROUND_SIZE_OPTIONS ],
		[ 'position', 'bottom right', BACKGROUND_POSITION_OPTIONS ],
		[ 'repeat', 'repeat-x', BACKGROUND_REPEAT_OPTIONS ],
	] )( 'accepts a controlled background %s', ( label, value, options ) => {
		expect( normalizeControlledOption( value, options ) ).toBe( value );
		expect(
			normalizeControlledOption( `${ value } invalid`, options )
		).toBeUndefined();
	} );
} );
