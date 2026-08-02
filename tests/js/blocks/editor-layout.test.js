import { getEditorScale } from '../../../src/blocks/deck/editor-layout';

describe( 'Presenter Deck editor layout', () => {
	it( 'scales logical slide dimensions to the available editor width', () => {
		expect( getEditorScale( 1280, 1280 ) ).toBe( 1 );
		expect( getEditorScale( 640, 1280 ) ).toBe( 0.5 );
		expect( getEditorScale( 1440, 960 ) ).toBe( 1.5 );
	} );

	it( 'uses a stable rounded value for CSS', () => {
		expect( getEditorScale( 1000, 1366 ) ).toBe( 0.732064 );
	} );

	it( 'falls back safely for unavailable or invalid dimensions', () => {
		expect( getEditorScale( 0, 1280 ) ).toBe( 1 );
		expect( getEditorScale( 1280, 0 ) ).toBe( 1 );
		expect( getEditorScale( Number.NaN, 1280 ) ).toBe( 1 );
		expect( getEditorScale( 1280, Number.POSITIVE_INFINITY ) ).toBe( 1 );
	} );
} );
