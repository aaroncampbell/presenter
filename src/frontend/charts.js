import Chart from 'chart.js/auto';

import { createChartConfiguration } from '../charts/config';

const instances = new WeakMap();

function renderChart( figure ) {
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
	if ( root.matches?.( '[data-presenter-chart]' ) ) {
		renderChart( root );
	}
	root.querySelectorAll?.( '[data-presenter-chart]' ).forEach( renderChart );
}
