import { buildLegacySlidePreviewDocument } from '../../../src/preview/legacy-slide-document';

describe( 'Presenter migrated HTML slide preview', () => {
	it( 'builds a centered script-disabled Reveal document', () => {
		const document = buildLegacySlidePreviewDocument( {
			attributes: {
				backgroundColor: '#d6d1f7',
				backgroundImageUrl:
					'https://example.test/image.png?size=large&slide=1',
				backgroundPosition: 'bottom right',
				backgroundRepeat: 'repeat-x',
				backgroundSize: 'contain',
				className: 'title',
			},
			center: true,
			footerHtml:
				'<p class="persistent-twitter-link">Preview footer</p>',
			html: '<h2>Legacy title</h2><script>window.ran=true</script>',
			themeCss:
				'.reveal h2{color:#8377d1}</style><script>window.cssRan=true</script>',
		} );

		expect( document ).toContain( 'script-src \'none\'' );
		expect( document ).toContain(
			'<div class="reveal"><div class="slides"><section class="title">'
		);
		expect( document ).toContain( 'background-position:bottom right' );
		expect( document ).toContain( 'background-repeat:repeat-x' );
		expect( document ).toContain( 'background-size:contain' );
		expect( document ).toContain( '&amp;slide=1' );
		expect( document ).toContain( '<\\/style>' );
		expect( document ).not.toContain( '</style><script>window.cssRan' );
		expect( document ).toContain(
			'</section></div><p class="persistent-twitter-link">Preview footer</p></div>'
		);
	} );

	it( 'marks a non-centered slide for top alignment', () => {
		const document = buildLegacySlidePreviewDocument( {
			attributes: {},
			center: false,
			footerHtml: '',
			html: '<p>Top aligned</p>',
			themeCss: '',
		} );

		expect( document ).toContain(
			'<section class="presenter-preview-top">'
		);
	} );

	it( 'previews historical background shorthand with typed precedence', () => {
		const legacy = buildLegacySlidePreviewDocument( {
			attributes: {
				revealDataAttributes: [
					{
						name: 'data-background',
						value: '//example.test/legacy.png',
					},
				],
			},
			center: true,
			footerHtml: '',
			html: '',
			themeCss: '',
		} );
		const typed = buildLegacySlidePreviewDocument( {
			attributes: {
				backgroundImageUrl: 'https://example.test/typed.png',
				revealDataAttributes: [
					{
						name: 'data-background',
						value: '//example.test/legacy.png',
					},
				],
			},
			center: true,
			footerHtml: '',
			html: '',
			themeCss: '',
		} );

		expect( legacy ).toContain(
			'background-image:url(&quot;//example.test/legacy.png&quot;)' 
		);
		expect( typed ).toContain(
			'background-image:url(&quot;https://example.test/typed.png&quot;)' 
		);
		expect( typed ).not.toContain( '//example.test/legacy.png' );
	} );
} );
