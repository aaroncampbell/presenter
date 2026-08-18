import { autop } from '@wordpress/autop';
import { createBlock, getBlockContent, rawHandler } from '@wordpress/blocks';

import {
	convertLegacyDeckBlocks,
	convertLegacyHtmlToBlocks,
	convertLegacyHtmlToNestedSlides,
	convertLegacyHtmlToSingleSlide,
	getLegacyHtmlBlockContent,
	isLegacyHtmlNestedSlides,
} from '../../../src/conversion/legacy-html-to-blocks';

jest.mock( '@wordpress/autop', () => ( {
	autop: jest.fn( ( html ) => html ),
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	cloneBlock: jest.fn( ( block, attributes, innerBlocks ) => ( {
		...block,
		attributes,
		innerBlocks,
	} ) ),
	createBlock: jest.fn( ( name, attributes, innerBlocks ) => ( {
		name,
		attributes,
		innerBlocks,
	} ) ),
	getBlockContent: jest.fn(),
	rawHandler: jest.fn(),
} ) );

describe( 'legacy HTML block conversion', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		autop.mockImplementation( ( html ) => html );
		getBlockContent.mockImplementation(
			( block ) =>
				block.innerContent?.join( '' ) ??
				block.attributes?.content ??
				''
		);
	} );

	it( 'paragraphizes legacy source before using the canonical raw handler', () => {
		autop.mockReturnValue( '<p>Paragraphized</p>' );
		rawHandler.mockReturnValue( [
			{ name: 'core/paragraph', attributes: {}, innerBlocks: [] },
		] );

		const result = convertLegacyHtmlToBlocks( 'Paragraphized' );

		expect( autop ).toHaveBeenCalledWith( 'Paragraphized' );
		expect( rawHandler ).toHaveBeenCalledWith( {
			HTML: '<p>Paragraphized</p>',
		} );
		expect( result.outcome ).toBe( 'native' );
	} );

	it( 'reads Custom HTML through the canonical block serializer', () => {
		const block = {
			name: 'core/html',
			attributes: {},
			innerContent: [ '<h2>Saved HTML</h2>' ],
			innerBlocks: [],
		};

		expect( getLegacyHtmlBlockContent( block ) ).toBe(
			'<h2>Saved HTML</h2>'
		);
		expect( getBlockContent ).toHaveBeenCalledWith( block );
		expect(
			getLegacyHtmlBlockContent( {
				name: 'core/paragraph',
				attributes: { content: 'Not Custom HTML' },
			} )
		).toBe( '' );
		expect( getBlockContent ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'converts multiple sibling Slides into one complete Deck child list', () => {
		rawHandler.mockImplementation( ( { HTML } ) => [
			{
				name: 'core/paragraph',
				attributes: { content: HTML },
				innerBlocks: [],
			},
		] );
		const createLegacySlide = ( clientId, content, isLocal = false ) => ( {
			clientId,
			name: 'presenter/slide',
			attributes: {
				anchor: clientId,
				legacyAutoParagraph: true,
			},
			innerBlocks: [
				{
					name: 'core/html',
					attributes: isLocal ? { content } : {},
					innerContent: isLocal ? undefined : [ content ],
					innerBlocks: [],
				},
			],
		} );
		const unchanged = {
			clientId: 'native',
			name: 'presenter/slide',
			attributes: {},
			innerBlocks: [],
		};

		const converted = convertLegacyDeckBlocks( [
			createLegacySlide(
				'first',
				'<section id="first-section"><p>First slide</p></section>'
			),
			createLegacySlide(
				'second',
				'<section id="second-section"><p>Second slide</p></section>',
				true
			),
			unchanged,
		] );

		expect( converted ).toHaveLength( 3 );
		expect( converted.map( ( block ) => block.name ) ).toEqual( [
			'presenter/slide',
			'presenter/slide',
			'presenter/slide',
		] );
		expect( converted[ 0 ].innerBlocks[ 0 ].attributes.content ).toContain(
			'First slide'
		);
		expect( converted[ 1 ].innerBlocks[ 0 ].attributes.content ).toContain(
			'Second slide'
		);
		expect( converted[ 0 ].attributes ).toMatchObject( {
			anchor: 'first-section',
			legacyAutoParagraph: false,
			legacyNotesProcessing: true,
		} );
		expect( converted[ 1 ].attributes ).toMatchObject( {
			anchor: 'second-section',
			legacyAutoParagraph: false,
			legacyNotesProcessing: true,
		} );
		expect( converted[ 2 ] ).toBe( unchanged );
	} );

	it( 'flattens a single section wrapper without creating Nested Slides', () => {
		rawHandler.mockReturnValue( [
			{
				name: 'core/heading',
				attributes: { content: 'Wrapped title' },
				innerBlocks: [],
			},
		] );
		const html =
			'<section id="wrapped" class="topic"><h2>Wrapped title</h2></section>';

		const slide = convertLegacyHtmlToSingleSlide( html, {
			anchor: 'slide-4',
			className: 'slide-4',
			label: 'Wrapped slide',
			legacyAutoParagraph: true,
		} );

		expect( isLegacyHtmlNestedSlides( html, {} ) ).toBe( false );
		expect( convertLegacyHtmlToNestedSlides( html, {} ) ).toBeNull();
		expect( slide ).toEqual( {
			attributes: expect.objectContaining( {
				anchor: 'wrapped',
				className: 'slide-4 topic',
				label: 'Wrapped slide',
				legacyAutoParagraph: false,
				legacyNotesProcessing: true,
			} ),
			blocks: [
				expect.objectContaining( {
					name: 'core/heading',
					attributes: { content: 'Wrapped title' },
				} ),
			],
		} );
	} );

	it( 'maps Reveal fragment classes and ordering into Presenter attributes', () => {
		rawHandler.mockReturnValue( [
			{
				name: 'core/heading',
				attributes: { className: 'fragment fade-up speaker-emphasis' },
				innerBlocks: [],
			},
		] );

		const result = convertLegacyHtmlToBlocks(
			'<h2 class="fragment fade-up speaker-emphasis" data-fragment-index="3">Next</h2>'
		);

		expect( result.blocks[ 0 ].attributes ).toEqual( {
			className: 'speaker-emphasis',
			presenterFragment: true,
			presenterFragmentCustomClasses: 'speaker-emphasis',
			presenterFragmentEffect: 'fade-up',
			presenterFragmentIndex: 3,
		} );
	} );

	it( 'unwraps only behavior-free legacy containers and quote footers', () => {
		rawHandler.mockReturnValue( [
			{ name: 'core/heading', attributes: {}, innerBlocks: [] },
			{ name: 'core/image', attributes: {}, innerBlocks: [] },
			{ name: 'core/quote', attributes: {}, innerBlocks: [] },
		] );

		convertLegacyHtmlToBlocks(
			'<header><h2>Title</h2></header><div><img src="image.jpg"></div><blockquote><footer><cite>Source</cite></footer></blockquote>'
		);

		expect( rawHandler ).toHaveBeenCalledWith( {
			HTML: '<h2>Title</h2><img src="image.jpg"><blockquote><cite>Source</cite></blockquote>',
		} );
	} );

	it( 'promotes a plain quote cite from Custom HTML to native citation', () => {
		rawHandler.mockReturnValue( [
			{
				name: 'core/quote',
				attributes: {
					citation: { toString: () => '' },
					value: '<p>Quoted text</p>',
				},
				innerBlocks: [
					{
						name: 'core/paragraph',
						attributes: { content: 'Quoted text' },
						innerBlocks: [],
					},
					{
						name: 'core/html',
						attributes: {},
						innerContent: [
							'<cite>Source &amp; Author</cite>',
						],
						innerBlocks: [],
					},
				],
			},
		] );

		const result = convertLegacyHtmlToBlocks(
			'<blockquote><cite>Source &amp; Author</cite></blockquote>'
		);

		expect( result.outcome ).toBe( 'native' );
		expect( result.blocks[ 0 ].attributes.citation ).toBe(
			'Source &amp; Author'
		);
		expect( result.blocks[ 0 ].innerBlocks ).toEqual( [
			expect.objectContaining( { name: 'core/paragraph' } ),
		] );
	} );

	it( 'promotes exact legacy title panels into styled Core Groups', () => {
		for ( const margin of [ '', ' margin-top: 16em;' ] ) {
			rawHandler
				.mockReturnValueOnce( [
					{
						name: 'core/html',
						attributes: {},
						innerContent: [
							`<div style="background-color: rgba(0, 0, 0, 0.7); padding: 20px;${ margin }"><h2>Panel title</h2></div>`,
						],
						innerBlocks: [],
					},
				] )
				.mockReturnValueOnce( [
					{
						name: 'core/heading',
						attributes: { content: 'Panel title', level: 2 },
						innerBlocks: [],
					},
				] );

			const result = convertLegacyHtmlToBlocks( 'Legacy panel' );

			expect( result.outcome ).toBe( 'native' );
			expect( createBlock ).toHaveBeenLastCalledWith(
				'core/group',
				{
					style: {
						color: { background: 'rgba(0, 0, 0, 0.7)' },
						spacing: {
							padding: {
								top: '20px',
								right: '20px',
								bottom: '20px',
								left: '20px',
							},
							...( margin ? { margin: { top: '16em' } } : {} ),
						},
					},
					layout: { type: 'default' },
				},
				expect.arrayContaining( [
					expect.objectContaining( { name: 'core/heading' } ),
				] )
			);
		}
	} );

	it( 'retains near-match title panels as Custom HTML', () => {
		for ( const content of [
			'<div class="panel" style="background-color: rgba(0, 0, 0, 0.7); padding: 20px;"><h2>Title</h2></div>',
			'<div style="background-color: rgba(0, 0, 0, 0.7); padding: 21px;"><h2>Title</h2></div>',
			'<div style="background-color: rgba(0, 0, 0, 0.7); padding: 20px;"><h2 class="fragment">Title</h2></div>',
		] ) {
			rawHandler.mockReturnValueOnce( [
				{
					name: 'core/html',
					attributes: {},
					innerContent: [ content ],
					innerBlocks: [],
				},
			] );

			const result = convertLegacyHtmlToBlocks( content );

			expect( result.outcome ).toBe( 'custom-html' );
			expect( getLegacyHtmlBlockContent( result.blocks[ 0 ] ) ).toBe(
				content
			);
		}
	} );

	it( 'promotes class-only layout wrappers into Core Groups', () => {
		const content =
			'<div class="box fragment light"><h2>Callout</h2><h3><a href="https://example.com">Source</a></h3></div>';
		rawHandler
			.mockReturnValueOnce( [
				{
					name: 'core/html',
					attributes: {},
					innerContent: [ content ],
					innerBlocks: [],
				},
			] )
			.mockReturnValueOnce( [
				{
					name: 'core/heading',
					attributes: { content: 'Callout', level: 2 },
					innerBlocks: [],
				},
				{
					name: 'core/heading',
					attributes: {
						content: '<a href="https://example.com">Source</a>',
						level: 3,
					},
					innerBlocks: [],
				},
			] );

		const result = convertLegacyHtmlToBlocks( content );

		expect( result.outcome ).toBe( 'native' );
		expect( result.blocks[ 0 ] ).toEqual(
			expect.objectContaining( {
				name: 'core/group',
				attributes: {
					className: 'box light',
					layout: { type: 'default' },
					presenterFragment: true,
					presenterFragmentCustomClasses: 'box light',
					presenterFragmentEffect: 'custom',
				},
				innerBlocks: [
					expect.objectContaining( { name: 'core/heading' } ),
					expect.objectContaining( { name: 'core/heading' } ),
				],
			} )
		);
	} );

	it( 'retains classed wrappers whose children still need Custom HTML', () => {
		const content = '<div class="layout"><script>run()</script></div>';
		rawHandler
			.mockReturnValueOnce( [
				{
					name: 'core/html',
					attributes: {},
					innerContent: [ content ],
					innerBlocks: [],
				},
			] )
			.mockReturnValueOnce( [
				{
					name: 'core/html',
					attributes: {},
					innerContent: [ '<script>run()</script>' ],
					innerBlocks: [],
				},
			] );

		const result = convertLegacyHtmlToBlocks( content );

		expect( result.outcome ).toBe( 'custom-html' );
		expect( getLegacyHtmlBlockContent( result.blocks[ 0 ] ) ).toBe( content );
	} );

	it( 'retains formatted or attributed quote cites as Custom HTML', () => {
		for ( const content of [
			'<cite class="source">Author</cite>',
			'<cite><em>Author</em></cite>',
		] ) {
			rawHandler.mockReturnValueOnce( [
				{
					name: 'core/quote',
					attributes: { citation: '' },
					innerBlocks: [
						{
							name: 'core/html',
							attributes: {},
							innerContent: [ content ],
							innerBlocks: [],
						},
					],
				},
			] );

			const result = convertLegacyHtmlToBlocks( content );

			expect( result.outcome ).toBe( 'mixed' );
			expect(
				getLegacyHtmlBlockContent( result.blocks[ 0 ].innerBlocks[ 0 ] )
			).toBe( content );
		}
	} );

	it( 'retains section stacks and attributed layout containers', () => {
		rawHandler.mockReturnValue( [
			{ name: 'core/html', attributes: {}, innerBlocks: [] },
		] );
		const html =
			'<section><h2>Vertical</h2></section><div class="r-stack"><p>Layered</p></div>';

		convertLegacyHtmlToBlocks( html );

		expect( rawHandler ).toHaveBeenCalledWith( { HTML: html } );
	} );

	it( 'converts an exact section stack into native Nested Slides', () => {
		rawHandler
			.mockReturnValueOnce( [
				{
					name: 'core/heading',
					attributes: { content: 'First' },
					innerBlocks: [],
				},
			] )
			.mockReturnValueOnce( [
				{
					name: 'core/paragraph',
					attributes: { content: 'Second' },
					innerBlocks: [],
				},
			] );
		const html =
			'<section id="overview" class="topic" data-transition="fade"><h2>First</h2></section>' +
			'<section data-background-color="#abcdef"><p>Second</p></section>';

		const stack = convertLegacyHtmlToNestedSlides(
			html,
			{
				anchor: 'case-study',
				label: 'Case study',
				className: 'slide-4',
			},
			'outer-slide'
		);

		expect( stack ).toEqual(
			expect.objectContaining( {
				name: 'presenter/stack',
				attributes: {
					anchor: 'case-study',
					label: 'Case study',
					className: 'slide-4',
				},
			} )
		);
		expect( stack.innerBlocks ).toHaveLength( 2 );
		expect( stack.innerBlocks[ 0 ].attributes ).toEqual( {
			anchor: 'overview',
			className: 'topic',
			transition: 'fade',
		} );
		expect( stack.innerBlocks[ 1 ].attributes ).toEqual( {
			backgroundColor: '#abcdef',
		} );
		expect( rawHandler ).toHaveBeenNthCalledWith( 1, {
			HTML: '<h2>First</h2>',
		} );
		expect( rawHandler ).toHaveBeenNthCalledWith( 2, {
			HTML: '<p>Second</p>',
		} );
	} );

	it( 'preserves duplicate and absent child anchors exactly', () => {
		rawHandler.mockReturnValue( [] );
		const stack = convertLegacyHtmlToNestedSlides(
			'<section id="group"></section><section id="group"></section><section></section>',
			{ anchor: 'group' },
			'outer-slide'
		);

		expect(
			stack.innerBlocks.map( ( slide ) => slide.attributes.anchor )
		).toEqual( [ 'group', 'group', undefined ] );
		expect( stack.attributes.anchor ).toBe( 'group' );
	} );

	it( 'retains ambiguous stacks and outer Slide behavior unchanged', () => {
		const canonical =
			'<section id="one"></section><section id="two"></section>';

		expect( isLegacyHtmlNestedSlides( canonical, {} ) ).toBe( true );
		expect(
			isLegacyHtmlNestedSlides( canonical, { notes: 'Outer notes' } )
		).toBe( false );
		expect(
			isLegacyHtmlNestedSlides( canonical, {
				className: 'unsafe" onclick=alert(1)',
			} )
		).toBe( false );
		expect(
			isLegacyHtmlNestedSlides(
				'<section title="unsupported"></section>',
				{}
			)
		).toBe( false );
		expect(
			convertLegacyHtmlToNestedSlides(
				'<section title="unsupported"></section>',
				{},
				'outer-slide'
			)
		).toBeNull();
		expect(
			convertLegacyHtmlToNestedSlides(
				'<section><section></section></section>',
				{ transition: 'fade' },
				'outer-slide'
			)
		).toBeNull();
		expect( rawHandler ).not.toHaveBeenCalled();
	} );

	it( 'reports mixed and custom HTML fallbacks without discarding them', () => {
		rawHandler.mockReturnValueOnce( [
			{ name: 'core/paragraph', attributes: {}, innerBlocks: [] },
			{ name: 'core/html', attributes: {}, innerBlocks: [] },
		] );
		const mixed = convertLegacyHtmlToBlocks( '<p>Safe</p><script>run()</script>' );

		rawHandler.mockReturnValueOnce( [
			{ name: 'core/html', attributes: {}, innerBlocks: [] },
		] );
		const fallback = convertLegacyHtmlToBlocks( '<script>run()</script>' );

		expect( mixed.outcome ).toBe( 'mixed' );
		expect( fallback.outcome ).toBe( 'custom-html' );
	} );

	it( 'returns no blocks for an empty slide', () => {
		expect( convertLegacyHtmlToBlocks( '  ' ) ).toEqual( {
			blocks: [],
			outcome: 'empty',
		} );
		expect( rawHandler ).not.toHaveBeenCalled();
	} );
} );
