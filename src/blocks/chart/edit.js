import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { createChartConfiguration } from '../../charts/config';
import Chart from '../../charts/runtime';

export default function Edit( { attributes, setAttributes } ) {
	const { caption, chartType, columns, height, options, rows, width } =
		attributes;
	const canvasRef = useRef();
	const [ dataText, setDataText ] = useState(
		JSON.stringify( [ columns, ...rows ], null, 2 )
	);
	const blockProps = useBlockProps( { className: 'presenter-chart-editor' } );

	useEffect( () => {
		const figure = canvasRef.current?.closest( '.presenter-chart-editor' );
		if (
			! canvasRef.current ||
			! figure ||
			columns.length < 2 ||
			0 === rows.length
		) {
			return undefined;
		}

		let chart;
		let renderFrame;
		const renderChart = () => {
			chart?.destroy();
			chart = new Chart(
				canvasRef.current,
				createChartConfiguration( figure, {
					chartType,
					columns,
					options,
					rows,
				} )
			);
		};
		const scheduleRender = () => {
			window.cancelAnimationFrame( renderFrame );
			renderFrame = window.requestAnimationFrame( renderChart );
		};
		const deck = figure.closest( '.presenter-deck-editor' );
		const observer = deck
			? new window.MutationObserver( ( mutations ) => {
					const themeChanged = mutations.some( ( mutation ) => {
						const target =
							mutation.target.nodeType ===
							window.Node.ELEMENT_NODE
								? mutation.target
								: mutation.target.parentElement;
						if (
							target?.closest?.(
								'style[data-presenter-theme-preview]'
							)
						) {
							return true;
						}

						return [ ...mutation.addedNodes ].some(
							( node ) =>
								node.nodeType === window.Node.ELEMENT_NODE &&
								( node.matches?.(
									'style[data-presenter-theme-preview]'
								) ||
									node.querySelector?.(
										'style[data-presenter-theme-preview]'
									) )
						);
					} );

					if ( themeChanged ) {
						scheduleRender();
					}
			  } )
			: null;

		renderChart();
		observer?.observe( deck, {
			characterData: true,
			childList: true,
			subtree: true,
		} );

		return () => {
			observer?.disconnect();
			window.cancelAnimationFrame( renderFrame );
			chart?.destroy();
		};
	}, [ chartType, columns, options, rows ] );

	const applyData = () => {
		try {
			const parsed = JSON.parse( dataText );
			if (
				Array.isArray( parsed ) &&
				parsed.length > 1 &&
				parsed.every( Array.isArray )
			) {
				setAttributes( {
					columns: parsed[ 0 ].map( String ),
					rows: parsed.slice( 1 ),
				} );
			}
		} catch {
			setDataText( JSON.stringify( [ columns, ...rows ], null, 2 ) );
		}
	};

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Chart settings', 'presenter' ) }>
					<SelectControl
						label={ __( 'Type', 'presenter' ) }
						value={ chartType }
						options={ [
							{ label: __( 'Line', 'presenter' ), value: 'line' },
							{ label: __( 'Bar', 'presenter' ), value: 'bar' },
						] }
						onChange={ ( value ) =>
							setAttributes( { chartType: value } )
						}
					/>
					<TextControl
						label={ __( 'Caption', 'presenter' ) }
						value={ caption }
						onChange={ ( value ) =>
							setAttributes( { caption: value } )
						}
					/>
					<TextControl
						label={ __( 'Width', 'presenter' ) }
						type="number"
						min="200"
						max="2000"
						value={ width }
						onChange={ ( value ) =>
							setAttributes( { width: Number( value ) } )
						}
					/>
					<TextControl
						label={ __( 'Height', 'presenter' ) }
						type="number"
						min="150"
						max="1200"
						value={ height }
						onChange={ ( value ) =>
							setAttributes( { height: Number( value ) } )
						}
					/>
					<TextareaControl
						label={ __( 'Chart data (JSON)', 'presenter' ) }
						help={ __(
							'The first row contains column labels.',
							'presenter'
						) }
						rows={ 12 }
						value={ dataText }
						onChange={ setDataText }
						onBlur={ applyData }
					/>
				</PanelBody>
			</InspectorControls>
			<figure
				{ ...blockProps }
				style={ { height: `${ height }px`, maxWidth: `${ width }px` } }
			>
				{ columns.length > 1 && rows.length > 0 ? (
					<canvas
						ref={ canvasRef }
						role="img"
						aria-label={
							caption || __( 'Presenter chart', 'presenter' )
						}
					/>
				) : (
					<p>
						{ __(
							'Add chart data in the block settings.',
							'presenter'
						) }
					</p>
				) }
			</figure>
		</>
	);
}
