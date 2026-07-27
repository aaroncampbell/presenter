function color( element, variable, fallback ) {
	return (
		window
			.getComputedStyle( element )
			.getPropertyValue( variable )
			.trim() || fallback
	);
}

/**
 * Build the shared Chart.js configuration for editor and front-end rendering.
 *
 * @param {Element} element Chart wrapper used to resolve semantic theme colors.
 * @param {Object}  config  Canonical Presenter chart data.
 * @return {Object} Chart.js configuration.
 */
export function createChartConfiguration( element, config ) {
	const styles = window.getComputedStyle( element );
	const textColor = color(
		element,
		'--presenter-chart-text-color',
		styles.color
	);
	const gridColor = color(
		element,
		'--presenter-chart-grid-color',
		'rgba(127, 127, 127, 0.25)'
	);
	const seriesColors = [
		color( element, '--presenter-chart-series-1', '#666666' ),
		color( element, '--presenter-chart-series-2', '#8377d1' ),
		color( element, '--presenter-chart-series-3', '#2f80ed' ),
	];
	const legacy = config.options || {};
	const datasets = config.columns.slice( 1 ).map( ( label, index ) => ( {
		label,
		data: config.rows.map( ( row ) => row[ index + 1 ] ),
		borderColor: seriesColors[ index % seriesColors.length ],
		backgroundColor: seriesColors[ index % seriesColors.length ],
		borderWidth: 2,
		pointRadius: 0,
		tension: 0,
	} ) );

	return {
		type: config.chartType || 'line',
		data: {
			labels: config.rows.map( ( row ) => row[ 0 ] ),
			datasets,
		},
		options: {
			animation: false,
			maintainAspectRatio: false,
			responsive: true,
			plugins: {
				legend: {
					display: 'none' !== legacy?.legend?.position,
					labels: { color: textColor },
				},
				title: {
					display: Boolean( legacy.title ),
					text: legacy.title || '',
					color: textColor,
				},
			},
			scales: {
				x: {
					title: {
						display: Boolean( legacy?.hAxis?.title ),
						text: legacy?.hAxis?.title || '',
						color: textColor,
					},
					ticks: { color: textColor },
					grid: { color: gridColor },
				},
				y: {
					min: legacy?.vAxis?.minValue,
					max: legacy?.vAxis?.maxValue,
					title: {
						display: Boolean( legacy?.vAxis?.title ),
						text: legacy?.vAxis?.title || '',
						color: textColor,
					},
					ticks: { color: textColor },
					grid: { color: gridColor },
				},
			},
		},
	};
}
