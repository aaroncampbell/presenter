import {
	BlockControls,
	InnerBlocks,
	InspectorControls,
	store as blockEditorStore,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextareaControl,
	TextControl,
	ToolbarButton,
	ToolbarGroup,
	ToggleControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { convertLegacyHtmlToBlocks } from '../../conversion/legacy-html-to-blocks';
import LegacySlidePreview from '../../preview/legacy-slide-preview';
import { getGlobalThemeSettings, resolveTheme } from '../deck/theme-settings';
import {
	BACKGROUND_POSITION_OPTIONS,
	BACKGROUND_REPEAT_OPTIONS,
	BACKGROUND_SIZE_OPTIONS,
	normalizeAutoAnimateId,
	normalizeBackgroundOpacity,
} from './advanced-settings';
import { normalizeSlideAnchor } from './anchor';
import RevealDataControls from './reveal-data-controls';
import { isValidSlideClassName } from './reveal-data';
import {
	getPreviewBackgroundImageUrl,
	normalizeBackgroundImageUrl,
	normalizeHexColor,
} from './settings';

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
 * @param {Object}   props.context       Deck block context.
 * @param {Function} props.setAttributes Update slide attributes.
 * @return {Element} Slide editor.
 */
export default function Edit( {
	attributes,
	clientId,
	context = {},
	setAttributes,
} ) {
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
		className,
		revealDataAttributes,
	} = attributes;
	const previewBackgroundImageUrl =
		getPreviewBackgroundImageUrl( attributes );
	const hasLegacyBackgroundImageShorthand =
		! backgroundImageUrl &&
		Boolean( previewBackgroundImageUrl ) &&
		revealDataAttributes.some(
			( attribute ) => 'data-background' === attribute.name
		);
	const [ classNameInput, setClassNameInput ] = useState( className );
	const [ backgroundColorInput, setBackgroundColorInput ] =
		useState( backgroundColor );
	const [ backgroundImageUrlInput, setBackgroundImageUrlInput ] = useState(
		previewBackgroundImageUrl
	);
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
	let backgroundImageHelp = __( 'HTTP or HTTPS image URL.', 'presenter' );
	if ( hasInvalidBackgroundImageUrl ) {
		backgroundImageHelp = __(
			'Enter a valid HTTP or HTTPS URL.',
			'presenter'
		);
	} else if ( hasLegacyBackgroundImageShorthand ) {
		backgroundImageHelp = __(
			'This migrated Reveal background will become a native Slide setting when this field is changed or left.',
			'presenter'
		);
	}
	const normalizedBackgroundOpacity = normalizeBackgroundOpacity(
		backgroundOpacityInput
	);
	const normalizedAutoAnimateId =
		normalizeAutoAnimateId( autoAnimateIdInput );
	const hasInvalidBackgroundOpacity =
		undefined === normalizedBackgroundOpacity;
	const hasInvalidAutoAnimateId = undefined === normalizedAutoAnimateId;
	const backgroundSizeOptions = [
		{ label: __( 'Default', 'presenter' ), value: '' },
		{ label: __( 'Cover', 'presenter' ), value: 'cover' },
		{ label: __( 'Contain', 'presenter' ), value: 'contain' },
		{ label: __( 'Automatic', 'presenter' ), value: 'auto' },
	];
	if (
		backgroundSize &&
		! BACKGROUND_SIZE_OPTIONS.includes( backgroundSize )
	) {
		backgroundSizeOptions.push( {
			label: backgroundSize,
			value: backgroundSize,
		} );
	}
	const hasInvalidClassName = ! isValidSlideClassName( classNameInput );
	const legacyPreview = useSelect(
		( select ) => {
			const editor = select( blockEditorStore );
			const children = editor.getBlocks( clientId );
			const block =
				1 === children.length && 'core/html' === children[ 0 ].name
					? children[ 0 ]
					: null;

			return {
				block,
				isEditing: block
					? editor.getSelectedBlockClientId() === block.clientId
					: false,
				isPreviewMode: Boolean( editor.getSettings().isPreviewMode ),
			};
		},
		[ clientId ]
	);
	const { replaceInnerBlocks, selectBlock } = useDispatch( blockEditorStore );
	const themeSettings = getGlobalThemeSettings();
	const selectedTheme = resolveTheme(
		context[ 'presenter/theme' ] ?? '',
		themeSettings
	);
	const previewFooterHtml =
		'string' === typeof themeSettings.previewFooterHtml
			? themeSettings.previewFooterHtml
			: '';
	const showLegacyPreview = legacyPreview.block && ! legacyPreview.isEditing;

	useEffect( () => {
		if ( ! anchor ) {
			setAttributes( { anchor: `slide-${ clientId }` } );
		}
	}, [ anchor, clientId, setAttributes ] );

	useEffect( () => {
		setBackgroundColorInput( backgroundColor );
	}, [ backgroundColor ] );

	useEffect( () => {
		setBackgroundImageUrlInput( previewBackgroundImageUrl );
	}, [ previewBackgroundImageUrl ] );

	useEffect( () => {
		setBackgroundOpacityInput( backgroundOpacity ?? '' );
	}, [ backgroundOpacity ] );

	useEffect( () => {
		setAutoAnimateIdInput( autoAnimateId );
	}, [ autoAnimateId ] );

	useEffect( () => {
		setClassNameInput( className );
	}, [ className ] );

	const blockProps = useBlockProps( {
		className: [
			'presenter-slide-editor',
			hidden ? 'is-presenter-slide-hidden' : '',
			showLegacyPreview ? 'is-presenter-legacy-preview' : '',
		]
			.filter( Boolean )
			.join( ' ' ),
		style: {
			backgroundColor: backgroundColor || undefined,
			backgroundImage: previewBackgroundImageUrl
				? `url("${ previewBackgroundImageUrl
						.replaceAll( '\\', '\\\\' )
						.replaceAll( '"', '\\"' ) }")`
				: undefined,
			backgroundPosition: previewBackgroundImageUrl
				? backgroundPosition || 'center'
				: undefined,
			backgroundRepeat: previewBackgroundImageUrl
				? backgroundRepeat || 'no-repeat'
				: undefined,
			backgroundSize: previewBackgroundImageUrl
				? backgroundSize || 'cover'
				: undefined,
		},
	} );
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		template: attributes.legacyNotesProcessing ? undefined : TEMPLATE,
		templateInsertUpdatesSelection: false,
		renderAppender: InnerBlocks.ButtonBlockAppender,
	} );

	return (
		<>
			{ showLegacyPreview && ! legacyPreview.isPreviewMode && (
				<BlockControls>
					<ToolbarGroup>
						<ToolbarButton
							onClick={ () => {
								const conversion = convertLegacyHtmlToBlocks(
									legacyPreview.block.attributes.content
								);
								replaceInnerBlocks(
									clientId,
									conversion.blocks,
									true
								);
								setAttributes( {
									legacyAutoParagraph: false,
									legacyNotesProcessing: true,
								} );
							} }
						>
							{ __( 'Convert to blocks', 'presenter' ) }
						</ToolbarButton>
						<ToolbarButton
							onClick={ () =>
								selectBlock( legacyPreview.block.clientId )
							}
						>
							{ __( 'Edit legacy HTML', 'presenter' ) }
						</ToolbarButton>
					</ToolbarGroup>
				</BlockControls>
			) }
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
						help={ backgroundImageHelp }
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
									previewBackgroundImageUrl
								);
								return;
							}

							const updates = {
								backgroundImageUrl:
									normalizedBackgroundImageUrl,
							};
							if ( hasLegacyBackgroundImageShorthand ) {
								updates.revealDataAttributes =
									revealDataAttributes.filter(
										( attribute ) =>
											'data-background' !== attribute.name
									);
							}
							setAttributes( updates );
						} }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Image size', 'presenter' ) }
						value={ backgroundSize }
						options={ backgroundSizeOptions }
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
				<PanelBody
					title={ __( 'Advanced Reveal attributes', 'presenter' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Slide CSS classes', 'presenter' ) }
						help={
							hasInvalidClassName
								? __(
										'Use unique CSS class names separated by whitespace.',
										'presenter'
								  )
								: __(
										'Classes are added to the rendered Reveal slide.',
										'presenter'
								  )
						}
						value={ classNameInput }
						onChange={ setClassNameInput }
						onBlur={ () => {
							if ( hasInvalidClassName ) {
								setClassNameInput( className );
								return;
							}
							setAttributes( { className: classNameInput } );
						} }
						aria-invalid={ hasInvalidClassName }
						__nextHasNoMarginBottom
					/>
					<RevealDataControls
						value={ revealDataAttributes }
						onChange={ ( value ) =>
							setAttributes( { revealDataAttributes: value } )
						}
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
							{
								label: __( 'Limited HTML', 'presenter' ),
								value: 'html',
							},
							{
								label: __(
									'Markdown with limited HTML',
									'presenter'
								),
								value: 'markdown-html',
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
						help={
							[ 'html', 'markdown-html' ].includes( notesFormat )
								? __(
										'HTML is limited to safe text-formatting and structural elements. Unsupported markup is removed when rendered.',
										'presenter'
								  )
								: undefined
						}
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
				{ showLegacyPreview ? (
					<LegacySlidePreview
						attributes={ attributes }
						center={ context[ 'presenter/center' ] ?? true }
						footerHtml={ previewFooterHtml }
						height={ context[ 'presenter/height' ] ?? 720 }
						html={ legacyPreview.block.attributes.content }
						theme={ selectedTheme }
						width={ context[ 'presenter/width' ] ?? 1280 }
					/>
				) : (
					innerBlocksProps.children
				) }
			</section>
		</>
	);
}
