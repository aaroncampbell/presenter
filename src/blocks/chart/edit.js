import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { caption, chartType, columns, height, rows, width } = attributes;
	const [ dataText, setDataText ] = useState(
		JSON.stringify( [ columns, ...rows ], null, 2 )
	);
	const blockProps = useBlockProps( { className: 'presenter-chart-editor' } );

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
			<div { ...blockProps }>
				<strong>
					{ caption || __( 'Presenter chart', 'presenter' ) }
				</strong>
				<p>{ columns.join( ' · ' ) }</p>
				<p>
					{ rows.length } { __( 'data rows', 'presenter' ) }
				</p>
			</div>
		</>
	);
}
