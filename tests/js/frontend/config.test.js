import {
	DEFAULT_PLUGIN_IDS,
	DEFAULT_REVEAL_CONFIG,
	parsePresenterRevealConfig,
	readPresenterRevealConfig,
} from '../../../src/frontend/config';

describe( 'Presenter Reveal configuration', () => {
	it( 'combines server settings with Presenter defaults', () => {
		const config = parsePresenterRevealConfig(
			JSON.stringify( {
				reveal: { controls: false, width: 1920 },
				plugins: [ 'notes', 'notes', 'zoom' ],
			} )
		);

		expect( config.reveal ).toEqual( {
			...DEFAULT_REVEAL_CONFIG,
			controls: false,
			width: 1920,
		} );
		expect( config.plugins ).toEqual( [ 'notes', 'zoom' ] );
	} );

	it( 'uses defaults when no configuration element is rendered', () => {
		document.body.innerHTML = '';

		expect( readPresenterRevealConfig( document ) ).toEqual( {
			reveal: DEFAULT_REVEAL_CONFIG,
			plugins: DEFAULT_PLUGIN_IDS,
		} );
		expect( DEFAULT_PLUGIN_IDS ).not.toContain( 'math' );
	} );

	it( 'reads JSON from a non-executable script element', () => {
		document.body.innerHTML = `
			<script type="application/json" data-presenter-reveal-config>
				{ "reveal": { "height": 1080 }, "plugins": [ "notes" ] }
			</script>
		`;

		const config = readPresenterRevealConfig( document );

		expect( config.reveal.height ).toBe( 1080 );
		expect( config.plugins ).toEqual( [ 'notes' ] );
	} );

	it( 'rejects invalid JSON rather than evaluating JavaScript', () => {
		expect( () =>
			parsePresenterRevealConfig(
				'{ "reveal": window.location = "https://example.com" }'
			)
		).toThrow( 'not valid JSON' );
	} );

	it( 'rejects prototype-sensitive properties at every depth', () => {
		expect( () =>
			parsePresenterRevealConfig(
				'{ "reveal": { "nested": { "__proto__": {} } } }'
			)
		).toThrow( 'unsupported property' );
	} );

	it( 'requires a string-only plugin ID list', () => {
		expect( () =>
			parsePresenterRevealConfig(
				'{ "reveal": {}, "plugins": [ "notes", 3 ] }'
			)
		).toThrow( 'non-empty string ID' );
	} );
} );
