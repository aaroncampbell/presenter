( function ( global ) {
	'use strict';

	/*
	 * Snapshot-only compatibility renderer for the small Google Charts API subset
	 * used by three historical Presenter decks. This is independently authored
	 * code. It is not Google Charts code and does not promise pixel parity.
	 */
	const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
	const DEFAULT_WIDTH = 800;
	const DEFAULT_HEIGHT = 400;
	const callbackQueue = [];
	let callbackTimer = null;

	const svgElement = ( name, attributes = {} ) => {
		const element = global.document.createElementNS( SVG_NAMESPACE, name );
		Object.entries( attributes ).forEach( ( [ key, value ] ) => {
			element.setAttribute( key, String( value ) );
		} );
		return element;
	};

	const appendText = ( parent, value, attributes ) => {
		const text = svgElement( 'text', attributes );
		text.textContent = value;
		parent.appendChild( text );
		return text;
	};

	const finiteNumber = ( value ) => {
		if ( value instanceof Date ) {
			return value.getTime();
		}
		return typeof value === 'number' && Number.isFinite( value )
			? value
			: null;
	};

	const dimension = ( element, name, fallback ) => {
		const clientValue =
			name === 'width' ? element.clientWidth : element.clientHeight;
		if ( Number.isFinite( clientValue ) && clientValue > 0 ) {
			return clientValue;
		}

		const bounds = element.getBoundingClientRect?.();
		if ( Number.isFinite( bounds?.[ name ] ) && bounds[ name ] > 0 ) {
			return bounds[ name ];
		}

		const styledValue = Number.parseFloat( element.style?.[ name ] );
		return Number.isFinite( styledValue ) && styledValue > 0
			? styledValue
			: fallback;
	};

	const niceStep = ( maximum, targetIntervals = 6 ) => {
		if ( maximum <= 0 ) {
			return 1;
		}
		const roughStep = maximum / targetIntervals;
		const magnitude = 10 ** Math.floor( Math.log10( roughStep ) );
		const normalized = roughStep / magnitude;
		let multiplier = 10;
		if ( normalized <= 1 ) {
			multiplier = 1;
		} else if ( normalized <= 2 ) {
			multiplier = 2;
		} else if ( normalized <= 5 ) {
			multiplier = 5;
		}
		return multiplier * magnitude;
	};

	const verticalTicks = ( maximum ) => {
		const step = niceStep( maximum );
		const ceiling = Math.max( step, Math.ceil( maximum / step ) * step );
		const ticks = [];
		for ( let value = 0; value <= ceiling; value += step ) {
			ticks.push( value );
		}
		return { ceiling, ticks };
	};

	const formatNumber = ( value ) =>
		new Intl.NumberFormat( 'en-US', {
			maximumFractionDigits: Number.isInteger( value ) ? 0 : 2,
		} ).format( value );

	const rowsFromData = ( data ) => {
		if ( data?.__presenterGoogleChartRows ) {
			return data.__presenterGoogleChartRows;
		}
		return Array.isArray( data ) ? data : [];
	};

	const arrayToDataTable = ( rows ) => {
		if ( ! Array.isArray( rows ) ) {
			throw new TypeError( 'arrayToDataTable expects an array.' );
		}
		return Object.freeze( {
			__presenterGoogleChartRows: rows.map( ( row ) =>
				Array.isArray( row ) ? [ ...row ] : row
			),
		} );
	};

	class LineChart {
		constructor( element ) {
			if ( ! element ) {
				throw new TypeError(
					'LineChart requires a container element.'
				);
			}
			this.element = element;
		}

		draw( data, options = {} ) {
			const table = rowsFromData( data );
			const headers = Array.isArray( table[ 0 ] ) ? table[ 0 ] : [];
			const points = table
				.slice( 1 )
				.map( ( row ) => ( {
					x: finiteNumber( row?.[ 0 ] ),
					y: finiteNumber( row?.[ 1 ] ),
				} ) )
				.filter( ( point ) => point.x !== null && point.y !== null );

			const width = dimension( this.element, 'width', DEFAULT_WIDTH );
			const height = dimension( this.element, 'height', DEFAULT_HEIGHT );
			const margin = {
				top: 20,
				right: 20,
				bottom: 90,
				left: 90,
			};
			const plotWidth = Math.max( 1, width - margin.left - margin.right );
			const plotHeight = Math.max(
				1,
				height - margin.top - margin.bottom
			);
			const xValues = points.map( ( point ) => point.x );
			const xMinimum = Math.min( ...xValues );
			const xMaximum = Math.max( ...xValues );
			const xSpan = Math.max( 1, xMaximum - xMinimum );
			const yMaximum = Math.max(
				0,
				...points.map( ( point ) => point.y )
			);
			const yTicks = verticalTicks( yMaximum );
			const xPosition = ( value ) =>
				margin.left + ( ( value - xMinimum ) / xSpan ) * plotWidth;
			const yPosition = ( value ) =>
				margin.top +
				plotHeight -
				( value / yTicks.ceiling ) * plotHeight;

			const svg = svgElement( 'svg', {
				'aria-label': `${ headers[ 1 ] || 'Value' } by ${
					headers[ 0 ] || 'category'
				}`,
				height,
				role: 'img',
				viewBox: `0 0 ${ width } ${ height }`,
				width,
			} );
			svg.style.maxWidth = '100%';
			svg.style.height = 'auto';

			const background = options.backgroundColor;
			const backgroundColor =
				typeof background === 'string' ? background : background?.fill;
			if ( backgroundColor && backgroundColor !== 'transparent' ) {
				svg.appendChild(
					svgElement( 'rect', {
						fill: backgroundColor,
						height,
						width,
						x: 0,
						y: 0,
					} )
				);
			}

			const verticalGridColor =
				options.hAxis?.gridlines?.color || '#cccccc';
			const horizontalGridColor =
				options.vAxis?.gridlines?.color || '#cccccc';
			const axisColor =
				options.vAxis?.baselineColor ||
				options.hAxis?.baselineColor ||
				'#666666';
			const xTextColor = options.hAxis?.textStyle?.color || '#444444';
			const yTextColor = options.vAxis?.textStyle?.color || '#444444';
			const xTicks = Array.isArray( options.hAxis?.ticks )
				? options.hAxis.ticks
				: [];

			yTicks.ticks.forEach( ( tick ) => {
				const y = yPosition( tick );
				svg.appendChild(
					svgElement( 'line', {
						stroke: horizontalGridColor,
						x1: margin.left,
						x2: margin.left + plotWidth,
						y1: y,
						y2: y,
					} )
				);
				appendText( svg, formatNumber( tick ), {
					fill: yTextColor,
					'font-size': 14,
					'font-weight': options.vAxis?.textStyle?.bold
						? '700'
						: '400',
					'text-anchor': 'end',
					x: margin.left - 12,
					y: y + 5,
				} );
			} );

			xTicks.forEach( ( tick ) => {
				const value = finiteNumber( tick?.v ?? tick );
				if ( value === null ) {
					return;
				}
				const x = xPosition( value );
				svg.appendChild(
					svgElement( 'line', {
						stroke: verticalGridColor,
						x1: x,
						x2: x,
						y1: margin.top,
						y2: margin.top + plotHeight,
					} )
				);
				const label = tick?.f ?? String( tick?.v ?? tick );
				const labelElement = appendText( svg, label, {
					fill: xTextColor,
					'font-size': 14,
					'font-weight': options.hAxis?.textStyle?.bold
						? '700'
						: '400',
					'text-anchor': 'end',
					x,
					y: margin.top + plotHeight + 22,
				} );
				if ( options.hAxis?.slantedText ) {
					const angle =
						Number( options.hAxis.slantedTextAngle ) || 45;
					labelElement.setAttribute(
						'transform',
						`rotate(-${ angle } ${ x } ${
							margin.top + plotHeight + 22
						})`
					);
				}
			} );

			svg.appendChild(
				svgElement( 'line', {
					stroke: axisColor,
					x1: margin.left,
					x2: margin.left + plotWidth,
					y1: margin.top + plotHeight,
					y2: margin.top + plotHeight,
				} )
			);

			if ( points.length > 0 ) {
				const pathData = points
					.map(
						( point, index ) =>
							`${ index === 0 ? 'M' : 'L' } ${ xPosition(
								point.x
							) } ${ yPosition( point.y ) }`
					)
					.join( ' ' );
				svg.appendChild(
					svgElement( 'path', {
						d: pathData,
						fill: 'none',
						stroke: options.colors?.[ 0 ] || '#3366cc',
						'stroke-linecap': 'round',
						'stroke-linejoin': 'round',
						'stroke-width': 2,
					} )
				);
			}

			this.element.replaceChildren( svg );
		}
	}

	const flushCallbacks = () => {
		callbackTimer = null;
		callbackQueue.splice( 0 ).forEach( ( callback ) => {
			try {
				callback();
			} catch ( error ) {
				global.setTimeout( () => {
					throw error;
				}, 0 );
			}
		} );
	};

	const setOnLoadCallback = ( callback ) => {
		if ( typeof callback !== 'function' ) {
			throw new TypeError( 'setOnLoadCallback expects a function.' );
		}
		callbackQueue.push( callback );
		if ( callbackTimer === null ) {
			callbackTimer = global.setTimeout( flushCallbacks, 0 );
		}
	};

	global.google = global.google || {};
	global.google.charts = {
		load() {},
		setOnLoadCallback,
	};
	global.google.visualization = {
		LineChart,
		arrayToDataTable,
	};
} )( window );
