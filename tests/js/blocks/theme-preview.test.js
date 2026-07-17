jest.mock( '@wordpress/block-editor', () => ( {
	transformStyles: jest.fn( ( [ style ], selector ) => [
		`${ selector } ${ style.css } /* ${ style.baseURL } */`,
	] ),
} ) );

import {
	clearThemePreviewCache,
	fetchThemePreview,
} from '../../../src/blocks/deck/theme-preview';

describe( 'Presenter editor theme preview', () => {
	beforeEach( () => clearThemePreviewCache() );

	it( 'passes CSS and its base URL through the Presenter scope', async () => {
		const fetchStyles = jest.fn().mockResolvedValue( {
			ok: true,
			text: () =>
				Promise.resolve(
					':root{--r-main-color:#fff}.reveal{color:var(--r-main-color)}.reveal img{background:url("images/mark.svg")}'
				),
		} );
		const stylesheetUrl =
			'https://example.test/themes/aaron-purple/theme.css';

		const css = await fetchThemePreview( stylesheetUrl, fetchStyles );

		expect( css ).toContain( '.presenter-theme-preview' );
		expect( css ).toContain( ':root{--r-main-color:#fff}' );
		expect( css ).toContain( stylesheetUrl );
	} );

	it( 'caches successful stylesheet requests by URL', async () => {
		const fetchStyles = jest.fn().mockResolvedValue( {
			ok: true,
			text: () => Promise.resolve( '.reveal{color:#fff}' ),
		} );

		await fetchThemePreview( 'https://example.test/theme.css', fetchStyles );
		await fetchThemePreview( 'https://example.test/theme.css', fetchStyles );

		expect( fetchStyles ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'allows a failed request to be retried', async () => {
		const fetchStyles = jest
			.fn()
			.mockResolvedValueOnce( { ok: false, status: 503 } )
			.mockResolvedValueOnce( {
				ok: true,
				text: () => Promise.resolve( '.reveal{color:#fff}' ),
			} );
		const stylesheetUrl = 'https://example.test/theme.css';

		await expect(
			fetchThemePreview( stylesheetUrl, fetchStyles )
		).rejects.toThrow( 'HTTP 503' );
		await expect(
			fetchThemePreview( stylesheetUrl, fetchStyles )
		).resolves.toContain( '.presenter-theme-preview' );
		expect( fetchStyles ).toHaveBeenCalledTimes( 2 );
	} );
} );
