import { autop } from '@wordpress/autop';
import { rawHandler } from '@wordpress/blocks';

import { convertLegacyHtmlToBlocks } from '../../../src/conversion/legacy-html-to-blocks';

jest.mock( '@wordpress/autop', () => ( {
	autop: jest.fn( ( html ) => html ),
} ) );

jest.mock( '@wordpress/blocks', () => ( {
	cloneBlock: jest.fn( ( block, attributes, innerBlocks ) => ( {
		...block,
		attributes,
		innerBlocks,
	} ) ),
	rawHandler: jest.fn(),
} ) );

describe( 'legacy HTML block conversion', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		autop.mockImplementation( ( html ) => html );
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
