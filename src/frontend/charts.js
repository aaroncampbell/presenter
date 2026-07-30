import { createChartConfiguration } from '../charts/config';

const instances = new WeakMap();
let chartConstructor;
let chartConstructorPromise;

function loadChartConstructor() {
	chartConstructorPromise ??= import(
		/* webpackChunkName: "chart" */ 'chart.js'
	).then(
		( {
			BarController,
			BarElement,
			CategoryScale,
			Chart,
			Legend,
			LinearScale,
			LineController,
			LineElement,
			PointElement,
			Title,
			Tooltip,
		} ) => {
			Chart.register(
				BarController,
				BarElement,
				CategoryScale,
				Legend,
				LinearScale,
				LineController,
				LineElement,
				PointElement,
				Title,
				Tooltip
			);

			chartConstructor = Chart;

			return chartConstructor;
		}
	);

	return chartConstructorPromise;
}

function renderChart( Chart, figure ) {
	const canvas = figure.querySelector( 'canvas' );
	if ( ! canvas || instances.has( figure ) ) {
		return;
	}
	if ( 0 === figure.clientWidth || 0 === figure.clientHeight ) {
		return;
	}

	let config;
	try {
		config = JSON.parse( figure.dataset.presenterChart );
	} catch {
		return;
	}
	if (
		! Array.isArray( config.columns ) ||
		! Array.isArray( config.rows ) ||
		config.columns.length < 2
	) {
		return;
	}

	instances.set(
		figure,
		new Chart( canvas, createChartConfiguration( figure, config ) )
	);
}

export function initializeCharts( root = document ) {
	const figures = [];
	if ( root.matches?.( '[data-presenter-chart]' ) ) {
		figures.push( root );
	}
	root.querySelectorAll?.( '[data-presenter-chart]' ).forEach( ( figure ) =>
		figures.push( figure )
	);

	if ( 0 === figures.length ) {
		return Promise.resolve();
	}

	if ( chartConstructor ) {
		figures.forEach( ( figure ) =>
			renderChart( chartConstructor, figure )
		);

		return Promise.resolve();
	}

	return loadChartConstructor().then( ( Chart ) =>
		figures.forEach( ( figure ) => renderChart( Chart, figure ) )
	);
}
