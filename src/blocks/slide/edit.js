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
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { normalizeSlideAnchor } from './anchor';
import { normalizeBackgroundImageUrl, normalizeHexColor } from './settings';

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
	const {
		anchor,
		backgroundColor,
		backgroundImageUrl,
		hidden,
		label,
		notes,
		notesFormat,
		transition,
	} = attributes;
	const [ backgroundColorInput, setBackgroundColorInput ] =
		useState( backgroundColor );
	const [ backgroundImageUrlInput, setBackgroundImageUrlInput ] =
		useState( backgroundImageUrl );
	const normalizedBackgroundColor = normalizeHexColor( backgroundColorInput );
	const normalizedBackgroundImageUrl = normalizeBackgroundImageUrl(
		backgroundImageUrlInput
	);
	const hasInvalidBackgroundColor = undefined === normalizedBackgroundColor;
	const hasInvalidBackgroundImageUrl =
		undefined === normalizedBackgroundImageUrl;

	useEffect( () => {
		if ( ! anchor ) {
			setAttributes( { anchor: `slide-${ clientId }` } );
		}
	}, [ anchor, clientId, setAttributes ] );

	useEffect( () => {
		setBackgroundColorInput( backgroundColor );
	}, [ backgroundColor ] );

	useEffect( () => {
		setBackgroundImageUrlInput( backgroundImageUrl );
	}, [ backgroundImageUrl ] );

	const blockProps = useBlockProps( {
		className: [
			'presenter-slide-editor',
			hidden ? 'is-presenter-slide-hidden' : '',
		]
			.filter( Boolean )
			.join( ' ' ),
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
						label={ __( 'Label', 'presenter' ) }
						help={ __(
							'A descriptive name for editor navigation.',
							'presenter'
						) }
						value={ label }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
						__nextHasNoMarginBottom
					/>
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
						help={ __(
							'The slide remains editable but is excluded from the presentation.',
							'presenter'
						) }
						onChange={ ( value ) =>
							setAttributes( { hidden: value } )
						}
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Transition', 'presenter' ) }
						value={ transition }
						options={ [
							{
								label: __( 'Inherit from deck', 'presenter' ),
								value: '',
							},
							{ label: __( 'None', 'presenter' ), value: 'none' },
							{ label: __( 'Fade', 'presenter' ), value: 'fade' },
							{
								label: __( 'Slide', 'presenter' ),
								value: 'slide',
							},
							{
								label: __( 'Convex', 'presenter' ),
								value: 'convex',
							},
							{
								label: __( 'Concave', 'presenter' ),
								value: 'concave',
							},
							{ label: __( 'Zoom', 'presenter' ), value: 'zoom' },
						] }
						onChange={ ( value ) =>
							setAttributes( { transition: value } )
						}
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Background', 'presenter' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Color', 'presenter' ) }
						aria-invalid={ hasInvalidBackgroundColor }
						help={
							hasInvalidBackgroundColor
								? __(
										'Enter a six-digit hexadecimal color.',
										'presenter'
								  )
								: __(
										'Hexadecimal color, for example #663399.',
										'presenter'
								  )
						}
						placeholder="#000000"
						className={
							hasInvalidBackgroundColor
								? 'presenter-color-control-has-error'
								: undefined
						}
						value={ backgroundColorInput }
						onChange={ setBackgroundColorInput }
						onBlur={ () => {
							if ( hasInvalidBackgroundColor ) {
								setBackgroundColorInput( backgroundColor );
								return;
							}

							setAttributes( {
								backgroundColor: normalizedBackgroundColor,
							} );
						} }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Image URL', 'presenter' ) }
						aria-invalid={ hasInvalidBackgroundImageUrl }
						help={
							hasInvalidBackgroundImageUrl
								? __(
										'Enter a valid HTTP or HTTPS URL.',
										'presenter'
								  )
								: __( 'HTTP or HTTPS image URL.', 'presenter' )
						}
						type="url"
						className={
							hasInvalidBackgroundImageUrl
								? 'presenter-url-control-has-error'
								: undefined
						}
						value={ backgroundImageUrlInput }
						onChange={ setBackgroundImageUrlInput }
						onBlur={ () => {
							if ( hasInvalidBackgroundImageUrl ) {
								setBackgroundImageUrlInput(
									backgroundImageUrl
								);
								return;
							}

							setAttributes( {
								backgroundImageUrl:
									normalizedBackgroundImageUrl,
							} );
						} }
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
			<div { ...innerBlocksProps }>
				{ hidden && (
					<div
						className="presenter-slide-hidden-status"
						role="status"
					>
						{ __( 'Hidden slide', 'presenter' ) }
					</div>
				) }
				{ innerBlocksProps.children }
			</div>
		</>
	);
}
