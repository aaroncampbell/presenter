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

import {
	BACKGROUND_POSITION_OPTIONS,
	BACKGROUND_REPEAT_OPTIONS,
	BACKGROUND_SIZE_OPTIONS,
	normalizeAutoAnimateId,
	normalizeBackgroundOpacity,
} from './advanced-settings';
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
		autoAnimate,
		autoAnimateId,
		autoAnimateRestart,
		backgroundColor,
		backgroundImageUrl,
		backgroundOpacity,
		backgroundPosition,
		backgroundRepeat,
		backgroundSize,
		backgroundTransition,
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
	const [ backgroundOpacityInput, setBackgroundOpacityInput ] = useState(
		backgroundOpacity ?? ''
	);
	const [ autoAnimateIdInput, setAutoAnimateIdInput ] =
		useState( autoAnimateId );
	const normalizedBackgroundColor = normalizeHexColor( backgroundColorInput );
	const normalizedBackgroundImageUrl = normalizeBackgroundImageUrl(
		backgroundImageUrlInput
	);
	const hasInvalidBackgroundColor = undefined === normalizedBackgroundColor;
	const hasInvalidBackgroundImageUrl =
		undefined === normalizedBackgroundImageUrl;
	const normalizedBackgroundOpacity = normalizeBackgroundOpacity(
		backgroundOpacityInput
	);
	const normalizedAutoAnimateId =
		normalizeAutoAnimateId( autoAnimateIdInput );
	const hasInvalidBackgroundOpacity =
		undefined === normalizedBackgroundOpacity;
	const hasInvalidAutoAnimateId = undefined === normalizedAutoAnimateId;

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

	useEffect( () => {
		setBackgroundOpacityInput( backgroundOpacity ?? '' );
	}, [ backgroundOpacity ] );

	useEffect( () => {
		setAutoAnimateIdInput( autoAnimateId );
	}, [ autoAnimateId ] );

	const blockProps = useBlockProps( {
		className: [
			'presenter-slide-editor',
			hidden ? 'is-presenter-slide-hidden' : '',
		]
			.filter( Boolean )
			.join( ' ' ),
		style: {
			backgroundColor: backgroundColor || undefined,
			backgroundImage: backgroundImageUrl
				? `url("${ backgroundImageUrl.replaceAll( '"', '\\"' ) }")`
				: undefined,
			backgroundPosition: backgroundImageUrl
				? backgroundPosition || 'center'
				: undefined,
			backgroundRepeat: backgroundImageUrl
				? backgroundRepeat || 'no-repeat'
				: undefined,
			backgroundSize: backgroundImageUrl
				? backgroundSize || 'cover'
				: undefined,
		},
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
					<SelectControl
						label={ __( 'Image size', 'presenter' ) }
						value={ backgroundSize }
						options={ [
							{ label: __( 'Default', 'presenter' ), value: '' },
							{
								label: __( 'Cover', 'presenter' ),
								value: 'cover',
							},
							{
								label: __( 'Contain', 'presenter' ),
								value: 'contain',
							},
							{
								label: __( 'Automatic', 'presenter' ),
								value: 'auto',
							},
						] }
						onChange={ ( value ) =>
							BACKGROUND_SIZE_OPTIONS.includes( value ) &&
							setAttributes( { backgroundSize: value } )
						}
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Image position', 'presenter' ) }
						value={ backgroundPosition }
						options={ [
							{ label: __( 'Default', 'presenter' ), value: '' },
							{
								label: __( 'Center', 'presenter' ),
								value: 'center',
							},
							{ label: __( 'Top', 'presenter' ), value: 'top' },
							{
								label: __( 'Top right', 'presenter' ),
								value: 'top right',
							},
							{
								label: __( 'Right', 'presenter' ),
								value: 'right',
							},
							{
								label: __( 'Bottom right', 'presenter' ),
								value: 'bottom right',
							},
							{
								label: __( 'Bottom', 'presenter' ),
								value: 'bottom',
							},
							{
								label: __( 'Bottom left', 'presenter' ),
								value: 'bottom left',
							},
							{ label: __( 'Left', 'presenter' ), value: 'left' },
							{
								label: __( 'Top left', 'presenter' ),
								value: 'top left',
							},
						] }
						onChange={ ( value ) =>
							BACKGROUND_POSITION_OPTIONS.includes( value ) &&
							setAttributes( { backgroundPosition: value } )
						}
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Image repeat', 'presenter' ) }
						value={ backgroundRepeat }
						options={ [
							{ label: __( 'Default', 'presenter' ), value: '' },
							{
								label: __( 'No repeat', 'presenter' ),
								value: 'no-repeat',
							},
							{
								label: __( 'Repeat', 'presenter' ),
								value: 'repeat',
							},
							{
								label: __( 'Repeat horizontally', 'presenter' ),
								value: 'repeat-x',
							},
							{
								label: __( 'Repeat vertically', 'presenter' ),
								value: 'repeat-y',
							},
						] }
						onChange={ ( value ) =>
							BACKGROUND_REPEAT_OPTIONS.includes( value ) &&
							setAttributes( { backgroundRepeat: value } )
						}
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Background opacity', 'presenter' ) }
						type="number"
						min="0"
						max="1"
						step="0.05"
						aria-invalid={ hasInvalidBackgroundOpacity }
						help={
							hasInvalidBackgroundOpacity
								? __(
										'Enter a number from 0 through 1.',
										'presenter'
								  )
								: __(
										'Optional opacity from 0 through 1.',
										'presenter'
								  )
						}
						value={ backgroundOpacityInput }
						onChange={ setBackgroundOpacityInput }
						onBlur={ () => {
							if ( hasInvalidBackgroundOpacity ) {
								setBackgroundOpacityInput( backgroundOpacity );
								return;
							}

							setAttributes( {
								backgroundOpacity:
									'' === normalizedBackgroundOpacity
										? undefined
										: normalizedBackgroundOpacity,
							} );
						} }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Background transition', 'presenter' ) }
						value={ backgroundTransition }
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
							setAttributes( { backgroundTransition: value } )
						}
						__nextHasNoMarginBottom
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Auto-animate', 'presenter' ) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __(
							'Animate from the previous slide',
							'presenter'
						) }
						help={ __(
							'Reveal matches compatible elements between adjacent auto-animated slides.',
							'presenter'
						) }
						checked={ autoAnimate }
						onChange={ ( value ) =>
							setAttributes( {
								autoAnimate: value,
								autoAnimateId: value ? autoAnimateId : '',
								autoAnimateRestart: value
									? autoAnimateRestart
									: false,
							} )
						}
						__nextHasNoMarginBottom
					/>
					{ autoAnimate && (
						<>
							<TextControl
								label={ __( 'Group identifier', 'presenter' ) }
								help={
									hasInvalidAutoAnimateId
										? __(
												'Use up to 64 letters, numbers, hyphens, or underscores; begin with a letter or number.',
												'presenter'
										  )
										: __(
												'Optional. Adjacent slides animate only when their identifiers match.',
												'presenter'
										  )
								}
								aria-invalid={ hasInvalidAutoAnimateId }
								value={ autoAnimateIdInput }
								onChange={ setAutoAnimateIdInput }
								onBlur={ () => {
									if ( hasInvalidAutoAnimateId ) {
										setAutoAnimateIdInput( autoAnimateId );
										return;
									}

									setAttributes( {
										autoAnimateId: normalizedAutoAnimateId,
									} );
								} }
								__nextHasNoMarginBottom
							/>
							<ToggleControl
								label={ __(
									'Restart animation sequence',
									'presenter'
								) }
								help={ __(
									'Do not animate this slide from the preceding auto-animated slide.',
									'presenter'
								) }
								checked={ autoAnimateRestart }
								onChange={ ( value ) =>
									setAttributes( {
										autoAnimateRestart: value,
									} )
								}
								__nextHasNoMarginBottom
							/>
						</>
					) }
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
			<section { ...innerBlocksProps }>
				{ hidden && (
					<div
						className="presenter-slide-hidden-status"
						role="status"
					>
						{ __( 'Hidden slide', 'presenter' ) }
					</div>
				) }
				{ innerBlocksProps.children }
			</section>
		</>
	);
}
