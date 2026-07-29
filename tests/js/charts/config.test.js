import { createChartConfiguration } from '../../../src/charts/config';

describe( 'Presenter chart configuration', () => {
	test( 'uses the active Reveal theme palette by default', () => {
		const element = document.createElement( 'figure' );
		element.style.setProperty( '--r-main-color', '#111111' );
		element.style.setProperty( '--r-heading-color', '#8377d1' );
		element.style.setProperty( '--r-link-color', '#6254b8' );
		element.style.setProperty( '--r-link-color-hover', '#71e9f4' );
		document.body.appendChild( element );

		const configuration = createChartConfiguration( element, {
			chartType: 'line',
			columns: [ 'Year', 'First', 'Second', 'Third' ],
			rows: [ [ '2021', 1, 2, 3 ] ],
		} );

		expect( configuration.data.datasets ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( { borderColor: '#8377d1' } ),
				expect.objectContaining( { borderColor: '#6254b8' } ),
				expect.objectContaining( { borderColor: '#71e9f4' } ),
			] )
		);
		expect( configuration.options.plugins.legend.labels.color ).toBe(
			'#111111'
		);
	} );

	test( 'prefers explicit chart palette overrides', () => {
		const element = document.createElement( 'figure' );
		element.style.setProperty( '--r-heading-color', '#8377d1' );
		element.style.setProperty( '--presenter-chart-series-1', '#abcdef' );
		document.body.appendChild( element );

		const configuration = createChartConfiguration( element, {
			chartType: 'bar',
			columns: [ 'Year', 'Series' ],
			rows: [ [ '2021', 1 ] ],
		} );

		expect( configuration.data.datasets[ 0 ].borderColor ).toBe(
			'#abcdef'
		);
	} );

	test( 'renders a characterized value suffix through trusted callbacks', () => {
		const element = document.createElement( 'figure' );
		document.body.appendChild( element );
		const configuration = createChartConfiguration( element, {
			chartType: 'line',
			columns: [ 'Year', 'WordPress' ],
			rows: [ [ '2021', 42.4 ] ],
			options: { valueSuffix: '%' },
		} );

		expect( configuration.options.scales.y.ticks.callback( 42.4 ) ).toBe(
			'42.4%'
		);
		expect(
			configuration.options.plugins.tooltip.callbacks.label( {
				dataset: { label: 'WordPress' },
				formattedValue: '42.4',
			} )
		).toBe( 'WordPress: 42.4%' );
	} );
} );
