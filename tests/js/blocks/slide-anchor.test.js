import { normalizeSlideAnchor } from '../../../src/blocks/slide/anchor';

describe( 'Presenter Slide anchors', () => {
	it( 'normalizes authored text to the value rendered by WordPress', () => {
		expect( normalizeSlideAnchor( '  My Opening Slide!  ' ) ).toBe(
			'my-opening-slide'
		);
	} );

	it( 'keeps an existing stable generated anchor unchanged', () => {
		expect( normalizeSlideAnchor( 'slide-a1b2-c3d4' ) ).toBe(
			'slide-a1b2-c3d4'
		);
	} );
} );
