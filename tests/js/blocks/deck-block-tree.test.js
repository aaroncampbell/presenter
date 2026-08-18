import { getEditorBlockTree } from '../../../src/blocks/deck/block-tree';

describe( 'deck block tree', () => {
	it( 'uses the recursively populated block returned by getBlock', () => {
		const deck = {
			clientId: 'deck',
			name: 'presenter/deck',
			attributes: {},
			innerBlocks: [
				{
					clientId: 'slide-1',
					name: 'presenter/slide',
					attributes: { legacyAutoParagraph: true },
					innerBlocks: [
						{
							clientId: 'html-1',
							name: 'core/html',
							attributes: {},
							innerContent: [
								'<h2>Authored content</h2>',
							],
							innerBlocks: [],
						},
					],
				},
			],
		};
		const editor = {
			getBlock: jest.fn( () => deck ),
		};

		const tree = getEditorBlockTree( editor, 'deck' );

		expect( tree[ 0 ].innerBlocks ).toEqual( [
			expect.objectContaining( {
				clientId: 'html-1',
				name: 'core/html',
				innerContent: [ '<h2>Authored content</h2>' ],
			} ),
		] );
		expect( editor.getBlock ).toHaveBeenCalledTimes( 1 );
		expect( editor.getBlock ).toHaveBeenCalledWith( 'deck' );
	} );

	it( 'returns an empty child list when the root block is unavailable', () => {
		const editor = {
			getBlock: jest.fn( () => null ),
		};

		expect( getEditorBlockTree( editor, 'missing-deck' ) ).toEqual( [] );
	} );
} );
