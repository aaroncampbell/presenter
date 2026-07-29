function color( styles, variables, fallback ) {
	for ( const variable of variables ) {
		const value = styles.getPropertyValue( variable ).trim();
		if ( value ) {
			return value;
		}
	}

	return fallback;
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
		styles,
		[ '--presenter-chart-text-color', '--r-main-color' ],
		styles.color
	);
	const gridColor = color(
		styles,
		[ '--presenter-chart-grid-color' ],
		'rgba(127, 127, 127, 0.25)'
	);
	const seriesColors = [
		color(
			styles,
			[
				'--presenter-chart-series-1',
				'--r-heading-color',
				'--r-link-color',
			],
			'#666666'
		),
		color(
			styles,
			[
				'--presenter-chart-series-2',
				'--r-link-color',
				'--r-heading-color',
			],
			'#8377d1'
		),
		color(
			styles,
			[
				'--presenter-chart-series-3',
				'--r-link-color-hover',
				'--r-link-color',
			],
			'#2f80ed'
		),
	];
	const legacy = config.options || {};
	const valueSuffix =
		typeof legacy.valueSuffix === 'string' ? legacy.valueSuffix : '';
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
				tooltip: valueSuffix
					? {
							callbacks: {
								label: ( context ) =>
									`${ context.dataset.label || '' }: ${
										context.formattedValue
									}${ valueSuffix }`.trim(),
							},
					  }
					: undefined,
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
					ticks: {
						color: textColor,
						callback: valueSuffix
							? ( value ) => `${ value }${ valueSuffix }`
							: undefined,
					},
					grid: { color: gridColor },
				},
			},
		},
	};
}
