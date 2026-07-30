jest.mock( 'chart.js', () => {
	const Chart = jest.fn();
	Chart.register = jest.fn();

	return {
		BarController: {},
		BarElement: {},
		CategoryScale: {},
		Chart,
		Legend: {},
		LinearScale: {},
		LineController: {},
		LineElement: {},
		PointElement: {},
		Title: {},
		Tooltip: {},
	};
} );

import { Chart } from 'chart.js';
import { initializeCharts } from '../../../src/frontend/charts';

describe( 'Presenter front-end charts', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
		Chart.mockClear();
	} );

	it( 'does not construct Chart.js without chart markup', async () => {
		await initializeCharts();

		expect( Chart ).not.toHaveBeenCalled();
	} );

	it( 'renders each visible chart only once', async () => {
		document.body.innerHTML = `
			<figure data-presenter-chart='{"chartType":"bar","columns":["Year","Value"],"rows":[["2026",42]]}'>
				<canvas></canvas>
			</figure>
		`;
		const figure = document.querySelector( '[data-presenter-chart]' );
		Object.defineProperties( figure, {
			clientHeight: { configurable: true, value: 400 },
			clientWidth: { configurable: true, value: 800 },
		} );

		await initializeCharts( figure );
		await initializeCharts( figure );

		expect( Chart ).toHaveBeenCalledTimes( 1 );
		expect( Chart.mock.calls[ 0 ][ 0 ] ).toBe(
			figure.querySelector( 'canvas' )
		);
		expect( Chart.mock.calls[ 0 ][ 1 ] ).toEqual(
			expect.objectContaining( { type: 'bar' } )
		);
	} );
} );
