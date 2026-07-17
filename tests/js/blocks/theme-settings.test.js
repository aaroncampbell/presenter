import {
	getThemeOptions,
	normalizeThemeSettings,
	resolveTheme,
} from '../../../src/blocks/deck/theme-settings';

const settings = {
	defaultTheme: {
		id: 'aaron-purple',
		label: 'Aaron Purple',
		stylesheetUrl: 'https://example.test/aaron-purple.css',
	},
	themes: [
		{
			id: 'black',
			label: 'Black',
			stylesheetUrl: 'https://example.test/black.css',
		},
		{
			id: 'aaron-purple',
			label: 'Aaron Purple',
			stylesheetUrl: 'https://example.test/aaron-purple.css',
		},
	],
};

describe( 'Presenter editor theme settings', () => {
	it( 'builds options from the filtered server registry', () => {
		expect( getThemeOptions( settings, ( value ) => value ) ).toEqual( [
			{ label: 'Site default (Aaron Purple)', value: '' },
			{ label: 'Black', value: 'black' },
			{ label: 'Aaron Purple', value: 'aaron-purple' },
		] );
	} );

	it( 'resolves the site default, an explicit theme, and an unknown ID', () => {
		expect( resolveTheme( '', settings )?.id ).toBe( 'aaron-purple' );
		expect( resolveTheme( 'black', settings )?.id ).toBe( 'black' );
		expect( resolveTheme( 'missing', settings )?.id ).toBe(
			'aaron-purple'
		);
	} );

	it( 'rejects malformed and non-HTTP theme descriptions', () => {
		expect(
			normalizeThemeSettings( {
				themes: [
					{ id: 'unsafe', label: 'Unsafe', stylesheetUrl: 'data:text/css' },
				],
			} )
		).toEqual( { defaultTheme: undefined, themes: [] } );
	} );
} );
