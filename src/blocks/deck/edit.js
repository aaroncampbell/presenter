import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import {
	getAspectRatioAttributes,
	getNavigationAttributes,
	parseDimension,
} from './settings';

const ALLOWED_BLOCKS = [ 'presenter/slide' ];
const TEMPLATE = [ [ 'presenter/slide' ] ];
const TRANSITION_OPTIONS = [
	{ label: __( 'None', 'presenter' ), value: 'none' },
	{ label: __( 'Fade', 'presenter' ), value: 'fade' },
	{ label: __( 'Slide', 'presenter' ), value: 'slide' },
	{ label: __( 'Convex', 'presenter' ), value: 'convex' },
	{ label: __( 'Concave', 'presenter' ), value: 'concave' },
	{ label: __( 'Zoom', 'presenter' ), value: 'zoom' },
];
const BUILT_IN_THEME_OPTIONS = [
	{ label: __( 'Site default', 'presenter' ), value: '' },
	{ label: __( 'Beige', 'presenter' ), value: 'beige' },
	{ label: __( 'Black', 'presenter' ), value: 'black' },
	{ label: __( 'Black Contrast', 'presenter' ), value: 'black-contrast' },
	{ label: __( 'Blood', 'presenter' ), value: 'blood' },
	{ label: __( 'Dracula', 'presenter' ), value: 'dracula' },
	{ label: __( 'League', 'presenter' ), value: 'league' },
	{ label: __( 'Moon', 'presenter' ), value: 'moon' },
	{ label: __( 'Night', 'presenter' ), value: 'night' },
	{ label: __( 'Serif', 'presenter' ), value: 'serif' },
	{ label: __( 'Simple', 'presenter' ), value: 'simple' },
	{ label: __( 'Sky', 'presenter' ), value: 'sky' },
	{ label: __( 'Solarized', 'presenter' ), value: 'solarized' },
	{ label: __( 'White', 'presenter' ), value: 'white' },
	{ label: __( 'White Contrast', 'presenter' ), value: 'white-contrast' },
];

/**
 * Edit a Presenter deck.
 *
 * @param {Object}   props               Block edit properties.
 * @param {Object}   props.attributes    Deck attributes.
 * @param {Function} props.setAttributes Update deck attributes.
 * @return {Element} Deck editor.
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		aspectRatio,
		backgroundTransition,
		center,
		controls,
		hash,
		height,
		keyboard,
		margin,
		progress,
		theme,
		transition,
		width,
	} = attributes;
	const blockProps = useBlockProps( {
		className: 'presenter-deck-editor',
		style: {
			'--presenter-slide-aspect-ratio': `${ width } / ${ height }`,
		},
	} );
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		allowedBlocks: ALLOWED_BLOCKS,
		template: TEMPLATE,
		templateLock: false,
		templateInsertUpdatesSelection: false,
		renderAppender: InnerBlocks.ButtonBlockAppender,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Slide size', 'presenter' ) }>
					<SelectControl
						label={ __( 'Aspect ratio', 'presenter' ) }
						value={ aspectRatio }
						options={ [
							{
								label: __( 'Widescreen (16:9)', 'presenter' ),
								value: '16:9',
							},
							{
								label: __( 'Standard (4:3)', 'presenter' ),
								value: '4:3',
							},
							{
								label: __( 'Custom', 'presenter' ),
								value: 'custom',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( getAspectRatioAttributes( value ) )
						}
						__nextHasNoMarginBottom
					/>
					{ 'custom' === aspectRatio && (
						<>
							<TextControl
								label={ __( 'Width', 'presenter' ) }
								type="number"
								min="1"
								step="1"
								value={ width }
								onChange={ ( value ) => {
									const nextWidth = parseDimension( value );
									if ( nextWidth ) {
										setAttributes( { width: nextWidth } );
									}
								} }
								__nextHasNoMarginBottom
							/>
							<TextControl
								label={ __( 'Height', 'presenter' ) }
								type="number"
								min="1"
								step="1"
								value={ height }
								onChange={ ( value ) => {
									const nextHeight = parseDimension( value );
									if ( nextHeight ) {
										setAttributes( { height: nextHeight } );
									}
								} }
								__nextHasNoMarginBottom
							/>
						</>
					) }
					<RangeControl
						label={ __( 'Margin', 'presenter' ) }
						help={ __(
							'Space around slide content.',
							'presenter'
						) }
						value={ margin }
						onChange={ ( value ) =>
							setAttributes( { margin: value } )
						}
						min={ 0 }
						max={ 0.5 }
						step={ 0.01 }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody title={ __( 'Presentation', 'presenter' ) }>
					<SelectControl
						label={ __( 'Theme', 'presenter' ) }
						value={ theme }
						options={ BUILT_IN_THEME_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { theme: value } )
						}
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Transition', 'presenter' ) }
						value={ transition }
						options={ TRANSITION_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { transition: value } )
						}
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Background transition', 'presenter' ) }
						value={ backgroundTransition }
						options={ TRANSITION_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { backgroundTransition: value } )
						}
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Navigation', 'presenter' ) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __( 'Show controls', 'presenter' ) }
						help={ __(
							'Presenter keeps either visible controls or keyboard navigation enabled.',
							'presenter'
						) }
						checked={ controls }
						onChange={ ( value ) =>
							setAttributes(
								getNavigationAttributes( 'controls', value, {
									controls,
									keyboard,
								} )
							)
						}
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Show progress', 'presenter' ) }
						checked={ progress }
						onChange={ ( value ) =>
							setAttributes( { progress: value } )
						}
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Update URL hash', 'presenter' ) }
						checked={ hash }
						onChange={ ( value ) =>
							setAttributes( { hash: value } )
						}
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __( 'Center slides vertically', 'presenter' ) }
						checked={ center }
						onChange={ ( value ) =>
							setAttributes( { center: value } )
						}
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __(
							'Enable keyboard navigation',
							'presenter'
						) }
						help={ __(
							'Presenter keeps either visible controls or keyboard navigation enabled.',
							'presenter'
						) }
						checked={ keyboard }
						onChange={ ( value ) =>
							setAttributes(
								getNavigationAttributes( 'keyboard', value, {
									controls,
									keyboard,
								} )
							)
						}
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...innerBlocksProps } />
		</>
	);
}
