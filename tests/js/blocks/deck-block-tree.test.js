import { getEditorBlockTree } from '../../../src/blocks/deck/block-tree';

describe( 'deck block tree', () => {
	it( 'hydrates grandchildren when parent selectors return shallow blocks', () => {
		const blocks = new Map( [
			[
				'deck',
				[
					{
						clientId: 'slide-1',
						name: 'presenter/slide',
						attributes: { legacyAutoParagraph: true },
						innerBlocks: [],
					},
				],
			],
			[
				'slide-1',
				[
					{
						clientId: 'html-1',
						name: 'core/html',
						attributes: { content: '<h2>Authored content</h2>' },
						innerBlocks: [],
					},
				],
			],
			[ 'html-1', [] ],
		] );
		const editor = {
			getBlocks: jest.fn( ( clientId ) => blocks.get( clientId ) ?? [] ),
		};

		const tree = getEditorBlockTree( editor, 'deck' );

		expect( tree[ 0 ].innerBlocks ).toEqual( [
			expect.objectContaining( {
				clientId: 'html-1',
				name: 'core/html',
				attributes: { content: '<h2>Authored content</h2>' },
			} ),
		] );
		expect( editor.getBlocks ).toHaveBeenCalledWith( 'html-1' );
	} );
} );
