import {
	MAX_FRAGMENT_INDEX,
	normalizeCustomFragmentClasses,
	normalizeFragmentEffect,
	normalizeFragmentIndex,
} from '../../../src/fragments/validation';

describe( 'fragment validation', () => {
	it( 'allows supported effects and rejects untrusted values', () => {
		expect( normalizeFragmentEffect( 'zoom-in' ) ).toBe( 'zoom-in' );
		expect( normalizeFragmentEffect( 'custom' ) ).toBe( 'custom' );
		expect( normalizeFragmentEffect( 'spin-around' ) ).toBeUndefined();
	} );

	it( 'accepts an empty or bounded whole-number order', () => {
		expect( normalizeFragmentIndex( '' ) ).toBeUndefined();
		expect( normalizeFragmentIndex( '0' ) ).toBe( 0 );
		expect( normalizeFragmentIndex( MAX_FRAGMENT_INDEX ) ).toBe(
			MAX_FRAGMENT_INDEX
		);
	} );

	it.each( [ -1, '01', '1.5', 1.5, MAX_FRAGMENT_INDEX + 1, 'nope' ] )(
		'rejects invalid order %p',
		( value ) => {
			expect( normalizeFragmentIndex( value ) ).toBeUndefined();
		}
	);

	it( 'normalizes safe custom classes and removes duplicates', () => {
		expect( normalizeCustomFragmentClasses( '  sparkle   slow sparkle ' ) ).toBe(
			'sparkle slow'
		);
		expect( normalizeCustomFragmentClasses( '' ) ).toBe( '' );
	} );

	it.each( [
		'fragment',
		'visible',
		'current-fragment',
		'disabled',
		'has.dot',
		'9starts-with-number',
		'a b c d e f g h i j k',
	] )( 'rejects unsafe or reserved custom classes: %s', ( value ) => {
		expect( normalizeCustomFragmentClasses( value ) ).toBeUndefined();
	} );
} );
