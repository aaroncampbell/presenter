import {
	cloneSlideForDuplication,
	getDropTargetIndex,
	getSlideTitle,
} from '../../../src/navigator/slide-utils';

describe( 'slide navigator utilities', () => {
	describe( 'getSlideTitle', () => {
		it( 'prefers and normalizes an explicitly authored label', () => {
			const slide = {
				attributes: { label: '  Audience &amp; goals  ' },
				innerBlocks: [
					{
						name: 'core/heading',
						attributes: { content: 'Ignored heading' },
						innerBlocks: [],
					},
				],
			};

			expect( getSlideTitle( slide, 1 ) ).toBe( 'Audience & goals' );
		} );

		it( 'finds a nested heading before earlier paragraph content', () => {
			const slide = {
				attributes: { label: '' },
				innerBlocks: [
					{
						name: 'core/paragraph',
						attributes: { content: 'Introductory copy' },
						innerBlocks: [],
					},
					{
						name: 'core/group',
						attributes: {},
						innerBlocks: [
							{
								name: 'core/heading',
								attributes: {
									content:
										'<strong>Roadmap</strong>&nbsp;&#8212; 2026',
								},
								innerBlocks: [],
							},
						],
					},
				],
			};

			expect( getSlideTitle( slide, 2 ) ).toBe( 'Roadmap — 2026' );
		} );

		it( 'uses the first other meaningful text when no heading exists', () => {
			const slide = {
				attributes: {},
				innerBlocks: [
					{
						name: 'core/image',
						attributes: { caption: '<em>Architecture diagram</em>' },
						innerBlocks: [],
					},
				],
			};

			expect( getSlideTitle( slide, 3 ) ).toBe(
				'Architecture diagram'
			);
		} );

		it( 'reads stringifiable RichTextData attributes from the editor', () => {
			const richTextContent = {
				toString: () => '<strong>Live editor heading</strong>',
			};
			const slide = {
				attributes: {},
				innerBlocks: [
					{
						name: 'core/heading',
						attributes: { content: richTextContent },
						innerBlocks: [],
					},
				],
			};

			expect( getSlideTitle( slide, 4 ) ).toBe(
				'Live editor heading'
			);
		} );

		it( 'falls back to the localized numbered slide label', () => {
			expect(
				getSlideTitle( { attributes: {}, innerBlocks: [] }, 4 )
			).toBe( 'Slide 4' );
		} );
	} );

	describe( 'cloneSlideForDuplication', () => {
		it( 'sets a unique slide anchor and recursively clones block client IDs', () => {
			const slide = {
				name: 'presenter/slide',
				clientId: 'slide-original',
				attributes: {
					anchor: 'opening',
					label: 'Opening',
					hidden: true,
				},
				innerBlocks: [
					{
						name: 'core/group',
						clientId: 'group-original',
						attributes: { anchor: 'content-group' },
						innerBlocks: [
							{
								name: 'core/paragraph',
								clientId: 'paragraph-original',
								attributes: { content: 'Hello' },
								innerBlocks: [],
							},
						],
					},
				],
			};

			const duplicate = cloneSlideForDuplication( slide );

			expect( duplicate.clientId ).not.toBe( slide.clientId );
			expect( duplicate.attributes ).toEqual( {
				anchor: `slide-${ duplicate.clientId }`,
				label: 'Opening',
				hidden: true,
			} );
			expect( duplicate.innerBlocks[ 0 ].clientId ).not.toBe(
				slide.innerBlocks[ 0 ].clientId
			);
			expect( duplicate.innerBlocks[ 0 ].attributes.anchor ).toBe(
				'content-group'
			);
			expect( duplicate.innerBlocks[ 0 ].innerBlocks[ 0 ].clientId ).not.toBe(
				slide.innerBlocks[ 0 ].innerBlocks[ 0 ].clientId
			);
			expect( slide.attributes.anchor ).toBe( 'opening' );
		} );
	} );

	describe( 'getDropTargetIndex', () => {
		it.each( [
			[ 'moves upward to the requested boundary', 4, 1, 6, 1 ],
			[ 'adjusts a downward boundary after removal', 1, 5, 6, 4 ],
			[ 'keeps an adjacent downward drop in place', 2, 3, 6, 2 ],
			[ 'clamps the first boundary', 3, 0, 6, 0 ],
			[ 'clamps the boundary after the final slide', 1, 6, 6, 5 ],
		] )(
			'%s',
			( description, sourceIndex, boundaryIndex, slideCount, expected ) => {
				expect(
					getDropTargetIndex(
						sourceIndex,
						boundaryIndex,
						slideCount
					)
				).toBe( expected );
			}
		);
	} );
} );
