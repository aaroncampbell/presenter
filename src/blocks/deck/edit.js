import {
	InspectorControls,
	store as blockEditorStore,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import {
	Button,
	Notice,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { convertLegacyDeckBlocks } from '../../conversion/legacy-html-to-blocks';
import { getEditorBlockTree } from './block-tree';
import { getEditorScale, getNestedEditorScale } from './editor-layout';
import {
	getAspectRatioAttributes,
	getNavigationAttributes,
	parseDimension,
} from './settings';
import { fetchThemePreview } from './theme-preview';
import {
	getGlobalThemeSettings,
	getThemeOptions,
	resolveTheme,
} from './theme-settings';

const ALLOWED_BLOCKS = [ 'presenter/slide', 'presenter/stack' ];
const TEMPLATE = [ [ 'presenter/slide' ] ];
const TRANSITION_OPTIONS = [
	{ label: __( 'None', 'presenter' ), value: 'none' },
	{ label: __( 'Fade', 'presenter' ), value: 'fade' },
	{ label: __( 'Slide', 'presenter' ), value: 'slide' },
	{ label: __( 'Convex', 'presenter' ), value: 'convex' },
	{ label: __( 'Concave', 'presenter' ), value: 'concave' },
	{ label: __( 'Zoom', 'presenter' ), value: 'zoom' },
];

/**
 * Edit a Presenter deck.
 *
 * @param {Object}   props               Block edit properties.
 * @param {Object}   props.attributes    Deck attributes.
 * @param {string}   props.clientId      Block editor client identifier.
 * @param {Function} props.setAttributes Update deck attributes.
 * @return {Element} Deck editor.
 */
export default function Edit( { attributes, clientId, setAttributes } ) {
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
	const themeSettings = getGlobalThemeSettings();
	const selectedTheme = resolveTheme( theme, themeSettings );
	const themeOptions = useMemo(
		() => getThemeOptions( themeSettings, __ ),
		[ themeSettings ]
	);
	const [ previewCss, setPreviewCss ] = useState( '' );
	const [ previewError, setPreviewError ] = useState( false );
	const [ editorScale, setEditorScale ] = useState( 1 );
	const [ nestedEditorScale, setNestedEditorScale ] = useState( 1 );
	const slidesRef = useRef( null );
	const deckBlocks = useSelect(
		( select ) =>
			getEditorBlockTree( select( blockEditorStore ), clientId ),
		[ clientId ]
	);
	const legacySlides = deckBlocks.filter(
		( slide ) =>
			'presenter/slide' === slide.name &&
			true === slide.attributes.legacyAutoParagraph
	);
	const { replaceInnerBlocks } = useDispatch( blockEditorStore );

	useEffect( () => {
		const slidesElement = slidesRef.current;

		if ( ! slidesElement || 'undefined' === typeof window.ResizeObserver ) {
			return undefined;
		}

		// Keep Gutenberg's editable DOM while laying native slides out in the same
		// logical coordinate system Reveal uses on the front end.
		const updateScale = ( availableWidth ) => {
			const nextScale = getEditorScale( availableWidth, width );
			const viewportElement = slidesElement.closest( '.reveal-viewport' );
			const canvasGutter = viewportElement
				? Number.parseFloat(
						window.getComputedStyle( viewportElement )
							.paddingInlineStart
				  )
				: 0;
			const nextNestedScale = getNestedEditorScale(
				availableWidth,
				width,
				canvasGutter
			);

			setEditorScale( ( currentScale ) =>
				0.000_001 > Math.abs( currentScale - nextScale )
					? currentScale
					: nextScale
			);
			setNestedEditorScale( ( currentScale ) =>
				0.000_001 > Math.abs( currentScale - nextNestedScale )
					? currentScale
					: nextNestedScale
			);
		};
		const observer = new window.ResizeObserver( ( entries ) => {
			const entry = entries[ 0 ];

			if ( entry ) {
				updateScale( entry.contentRect.width );
			}
		} );

		updateScale( slidesElement.clientWidth );
		observer.observe( slidesElement );

		return () => observer.disconnect();
	}, [ width ] );

	useEffect( () => {
		let isCurrent = true;

		setPreviewCss( '' );
		setPreviewError( false );

		if ( ! selectedTheme?.stylesheetUrl ) {
			setPreviewError( true );
			return () => {
				isCurrent = false;
			};
		}

		fetchThemePreview( selectedTheme.stylesheetUrl )
			.then( ( css ) => {
				if ( isCurrent ) {
					setPreviewCss( css );
				}
			} )
			.catch( () => {
				if ( isCurrent ) {
					setPreviewError( true );
				}
			} );

		return () => {
			isCurrent = false;
		};
	}, [ selectedTheme?.stylesheetUrl ] );

	const blockProps = useBlockProps( {
		className: 'presenter-deck-editor presenter-theme-preview',
		style: {
			'--presenter-slide-aspect-ratio': `${ width } / ${ height }`,
			'--presenter-slide-width': `${ width }px`,
			'--presenter-slide-height': `${ height }px`,
			'--presenter-editor-scale': editorScale,
			'--presenter-editor-scale-inverse': 1 / editorScale,
			'--presenter-rendered-slide-height': `${ height * editorScale }px`,
			'--presenter-nested-editor-scale': nestedEditorScale,
			'--presenter-nested-editor-scale-inverse': 1 / nestedEditorScale,
		},
	} );
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'slides', ref: slidesRef },
		{
			allowedBlocks: ALLOWED_BLOCKS,
			template: TEMPLATE,
			templateLock: false,
			templateInsertUpdatesSelection: false,
			renderAppender: false,
		}
	);

	return (
		<>
			<InspectorControls>
				{ 0 < legacySlides.length && (
					<PanelBody title={ __( 'Legacy content', 'presenter' ) }>
						<p>
							{ __(
								'Convert supported legacy HTML into editable WordPress blocks. Unsupported markup remains in the smallest safe Custom HTML fallback.',
								'presenter'
							) }
						</p>
						<Button
							variant="primary"
							onClick={ () => {
								replaceInnerBlocks(
									clientId,
									convertLegacyDeckBlocks( deckBlocks ),
									false
								);
							} }
						>
							{ sprintf(
								/* translators: %d is the number of slides. */
								__(
									'Convert %d legacy slides to blocks',
									'presenter'
								),
								legacySlides.length
							) }
						</Button>
					</PanelBody>
				) }
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
						options={ themeOptions }
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
			<div { ...blockProps }>
				{ previewCss && (
					<style data-presenter-theme-preview>{ previewCss }</style>
				) }
				{ previewError && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Theme preview is unavailable. The selected theme will still be used in the presentation.',
							'presenter'
						) }
					</Notice>
				) }
				<div className="reveal-viewport">
					<div className="reveal">
						<div { ...innerBlocksProps } />
					</div>
				</div>
			</div>
		</>
	);
}
