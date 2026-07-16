import {
	normalizeBackgroundImageUrl,
	normalizeHexColor,
} from '../../../src/blocks/slide/settings';

describe( 'Presenter Slide settings', () => {
	it.each( [
		[ '#663399', '#663399' ],
		[ '#AABBCC', '#aabbcc' ],
		[ '  #FFFFFF  ', '#ffffff' ],
		[ '', '' ],
	] )( 'normalizes valid hexadecimal color %s', ( authored, expected ) => {
		expect( normalizeHexColor( authored ) ).toBe( expected );
	} );

	it.each( [
		'663399',
		'#abc',
		'#12345',
		'#12345678',
		'#gggggg',
		'rgb(0, 0, 0)',
	] )(
		'rejects non-hexadecimal color %s',
		( color ) => {
			expect( normalizeHexColor( color ) ).toBeUndefined();
		}
	);

	it.each( [
		'https://images.example.test/slide.jpg',
		'http://localhost:8888/local.jpg',
	] )( 'accepts browser-safe background image URL %s', ( url ) => {
		expect( normalizeBackgroundImageUrl( `  ${ url }  ` ) ).toBe( url );
	} );

	it.each( [
		'javascript:alert(1)',
		'data:image/svg+xml,<svg></svg>',
		'/relative/image.jpg',
		'not a URL',
	] )( 'rejects unsafe background image URL %s', ( url ) => {
		expect( normalizeBackgroundImageUrl( url ) ).toBeUndefined();
	} );
} );
