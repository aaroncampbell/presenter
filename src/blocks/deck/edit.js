import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const ALLOWED_BLOCKS = [ 'presenter/slide' ];
const TEMPLATE = [ [ 'presenter/slide' ] ];

/**
 * Convert a dimension control value into a positive integer.
 *
 * @param {string} value Dimension control value.
 * @return {number|undefined} A valid dimension, if supplied.
 */
function parseDimension( value ) {
	const dimension = Number.parseInt( value, 10 );

	return Number.isInteger( dimension ) && dimension > 0
		? dimension
		: undefined;
}

/**
 * Edit a Presenter deck.
 *
 * @param {Object}   props               Block edit properties.
 * @param {Object}   props.attributes    Deck attributes.
 * @param {Function} props.setAttributes Update deck attributes.
 * @return {Element} Deck editor.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { width, height } = attributes;
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
				</PanelBody>
			</InspectorControls>
			<div { ...innerBlocksProps } />
		</>
	);
}
