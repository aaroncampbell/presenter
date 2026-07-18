import {
	areValidRevealDataAttributes,
	isValidSlideClassName,
} from '../../../src/blocks/slide/reveal-data';

describe( 'Presenter Slide legacy representation', () => {
	it( 'accepts exact, ordered class tokens', () => {
		expect( isValidSlideClassName( 'title-slide custom_theme-2' ) ).toBe(
			true
		);
		expect( isValidSlideClassName( 'duplicate duplicate' ) ).toBe( false );
		expect( isValidSlideClassName( 'unsafe\" onclick=' ) ).toBe( false );
		expect( isValidSlideClassName( ' leading' ) ).toBe( false );
	} );

	it( 'accepts ordered custom data without typed or internal collisions', () => {
		expect(
			areValidRevealDataAttributes( [
				{ name: 'data-chart', value: '{\"series\":[1,2]}' },
				{ name: 'data-background-video-loop', value: '' },
			] )
		).toBe( true );
		expect(
			areValidRevealDataAttributes( [
				{
					name: 'data-background-video',
					value: '/wp-content/uploads/presentation/video.mp4',
				},
			] )
		).toBe( true );
		expect(
			areValidRevealDataAttributes( [
				{ name: 'data-chart', value: 'one' },
				{ name: 'data-chart', value: 'two' },
			] )
		).toBe( false );
		expect(
			areValidRevealDataAttributes( [
				{ name: 'data-transition', value: 'zoom' },
			] )
		).toBe( false );
		expect(
			areValidRevealDataAttributes( [
				{ name: 'data-presenter-private', value: 'no' },
			] )
		).toBe( false );
	} );

	it( 'rejects malformed records and unsafe resource URLs', () => {
		expect(
			areValidRevealDataAttributes( [
				{ name: 'DATA-chart', value: 'one' },
			] )
		).toBe( false );
		expect(
			areValidRevealDataAttributes( [
				{ name: 'data-chart', value: 2 },
			] )
		).toBe( false );
		expect(
			areValidRevealDataAttributes( [
				{
					name: 'data-background-iframe',
					value: 'javascript:alert(1)',
				},
			] )
		).toBe( false );
		expect(
			areValidRevealDataAttributes( [
				{
					name: 'data-background-video',
					value: 'https://example.test/a.mp4, https://example.test/a.webm',
				},
			] )
		).toBe( true );
	} );
} );
