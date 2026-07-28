import { createChartConfiguration } from '../../../src/charts/config';

describe( 'Presenter chart configuration', () => {
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
