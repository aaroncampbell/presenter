import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { normalizeSlideAnchor } from './anchor';

const TEMPLATE = [
	[ 'core/heading', { placeholder: __( 'Slide title', 'presenter' ) } ],
	[
		'core/paragraph',
		{ placeholder: __( 'Add slide content…', 'presenter' ) },
	],
];

/**
 * Edit an individual Presenter slide.
 *
 * @param {Object}   props               Block edit properties.
 * @param {Object}   props.attributes    Slide attributes.
 * @param {string}   props.clientId      Block editor client identifier.
 * @param {Function} props.setAttributes Update slide attributes.
 * @return {Element} Slide editor.
 */
export default function Edit( { attributes, clientId, setAttributes } ) {
	const { anchor, hidden, notes, notesFormat } = attributes;

	useEffect( () => {
		if ( ! anchor ) {
			setAttributes( { anchor: `slide-${ clientId }` } );
		}
	}, [ anchor, clientId, setAttributes ] );

	const blockProps = useBlockProps( {
		className: hidden ? 'is-presenter-slide-hidden' : undefined,
	} );
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		template: TEMPLATE,
		templateInsertUpdatesSelection: false,
		renderAppender: InnerBlocks.ButtonBlockAppender,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Slide settings', 'presenter' ) }>
					<TextControl
						label={ __( 'Anchor', 'presenter' ) }
						help={ __(
							'Optional URL-safe identifier for linking directly to this slide.',
							'presenter'
						) }
						value={ anchor }
						onChange={ ( value ) =>
							setAttributes( {
								anchor: normalizeSlideAnchor( value ),
							} )
						}
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __(
							'Hide slide from presentation',
							'presenter'
						) }
						checked={ hidden }
						onChange={ ( value ) =>
							setAttributes( { hidden: value } )
						}
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody title={ __( 'Speaker notes', 'presenter' ) }>
					<SelectControl
						label={ __( 'Format', 'presenter' ) }
						value={ notesFormat }
						options={ [
							{
								label: __( 'Plain text', 'presenter' ),
								value: 'plain',
							},
							{
								label: __( 'Markdown', 'presenter' ),
								value: 'markdown',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { notesFormat: value } )
						}
						__nextHasNoMarginBottom
					/>
					<TextareaControl
						label={ __( 'Notes', 'presenter' ) }
						value={ notes }
						onChange={ ( value ) =>
							setAttributes( { notes: value } )
						}
						rows={ 8 }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...innerBlocksProps } />
		</>
	);
}
