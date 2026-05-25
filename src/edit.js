import { __ } from '@wordpress/i18n';
import {
	ColorPalette,
	InnerBlocks,
	InspectorControls,
	MediaUpload,
	MediaUploadCheck,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Button,
	PanelBody,
	RangeControl,
	ResponsiveWrapper,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import tinycolor from 'tinycolor2';

const ALLOWED_MEDIA_TYPES = [ 'image' ];

// reveal.js built-in slide transitions.
const TRANSITIONS = [
	{ label: __( 'Default (deck setting)', 'presenter' ), value: '' },
	{ label: __( 'None', 'presenter' ), value: 'none' },
	{ label: __( 'Fade', 'presenter' ), value: 'fade' },
	{ label: __( 'Slide', 'presenter' ), value: 'slide' },
	{ label: __( 'Convex', 'presenter' ), value: 'convex' },
	{ label: __( 'Concave', 'presenter' ), value: 'concave' },
	{ label: __( 'Zoom', 'presenter' ), value: 'zoom' },
];

// A starter template so a freshly inserted slide isn't empty.
const SLIDE_TEMPLATE = [
	[ 'core/heading', { level: 2, placeholder: __( 'Slide title', 'presenter' ) } ],
	[ 'core/paragraph', { placeholder: __( 'Slide content…', 'presenter' ) } ],
];

/**
 * The edit function describes the structure of the presenter/slide block in
 * the editor.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#edit
 *
 * @param {Object} props Properties passed to the function.
 * @return {WPElement} Element to render.
 */
export default function edit( props ) {
	const {
		attributes: {
			title,
			speakerNotes,
			hidden,
			bgColor,
			bgImageId,
			bgVideoUrl,
			transition,
			autoAnimate,
		},
		setAttributes,
	} = props;

	// Resolve the selected background image, if any.
	const { bgImage } = useSelect(
		( select ) => ( {
			bgImage: bgImageId ? select( 'core' ).getMedia( bgImageId ) : null,
		} ),
		[ bgImageId ]
	);

	const blockProps = useBlockProps( {
		className: hidden ? 'is-slide-hidden' : undefined,
	} );

	const canvasStyle = {
		backgroundColor: bgColor || undefined,
		backgroundImage:
			bgImage && bgImage.source_url ? `url( ${ bgImage.source_url } )` : undefined,
	};

	const uploadInstructions = (
		<p>
			{ __(
				'To set a background image you need permission to upload media.',
				'presenter'
			) }
		</p>
	);

	const onUpdateImage = ( image ) =>
		setAttributes( { bgImageId: image.id, bgImageUrl: image.url } );
	const onRemoveImage = () =>
		setAttributes( { bgImageId: undefined, bgImageUrl: undefined } );

	// Background colour is stored as an 8-digit hex (colour + opacity).
	const parsedColor = tinycolor( bgColor );
	const [ bgColorHex, setBgColorHex ] = useState(
		parsedColor.isValid() ? parsedColor.toHexString() : ''
	);
	const [ bgColorOpacity, setBgColorOpacity ] = useState(
		parsedColor.isValid() ? parsedColor.getAlpha() * 100 : 100
	);

	useEffect( () => {
		if ( ! bgColorHex ) {
			if ( bgColor ) {
				setAttributes( { bgColor: undefined } );
			}
			return;
		}
		const next = tinycolor( bgColorHex );
		next.setAlpha( bgColorOpacity / 100 );
		setAttributes( { bgColor: next.toHex8String() } );
	}, [ bgColorOpacity, bgColorHex ] );

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Background', 'presenter' ) }
					initialOpen={ false }
				>
					<ColorPalette
						value={ bgColorHex }
						onChange={ ( value ) => setBgColorHex( value || '' ) }
					/>
					<RangeControl
						label={ __( 'Background opacity', 'presenter' ) }
						value={ bgColorOpacity }
						onChange={ ( value ) => setBgColorOpacity( value ) }
						min={ 0 }
						max={ 100 }
					/>
					<div className="presenter-background-image-selector">
						<MediaUploadCheck fallback={ uploadInstructions }>
							<MediaUpload
								title={ __( 'Background image', 'presenter' ) }
								onSelect={ onUpdateImage }
								allowedTypes={ ALLOWED_MEDIA_TYPES }
								value={ bgImageId }
								render={ ( { open } ) => (
									<Button
										className={
											! bgImageId
												? 'editor-post-featured-image__toggle'
												: 'editor-post-featured-image__preview'
										}
										onClick={ open }
									>
										{ ! bgImageId &&
											__( 'Set background image', 'presenter' ) }
										{ !! bgImageId && ! bgImage && <Spinner /> }
										{ !! bgImageId && bgImage && (
											<ResponsiveWrapper
												naturalWidth={
													bgImage.media_details.width
												}
												naturalHeight={
													bgImage.media_details.height
												}
											>
												<img
													src={ bgImage.source_url }
													alt={ __(
														'Background image',
														'presenter'
													) }
												/>
											</ResponsiveWrapper>
										) }
									</Button>
								) }
							/>
							{ !! bgImageId && (
								<Button
									onClick={ onRemoveImage }
									isLink
									isDestructive
								>
									{ __( 'Remove background image', 'presenter' ) }
								</Button>
							) }
						</MediaUploadCheck>
					</div>
					<TextControl
						label={ __( 'Background video URL', 'presenter' ) }
						help={ __(
							'Optional. A video file URL to play as the slide background.',
							'presenter'
						) }
						value={ bgVideoUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { bgVideoUrl: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Animation', 'presenter' ) }
					initialOpen={ false }
				>
					<SelectControl
						label={ __( 'Slide transition', 'presenter' ) }
						value={ transition || '' }
						options={ TRANSITIONS }
						onChange={ ( value ) =>
							setAttributes( { transition: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Auto-animate', 'presenter' ) }
						help={ __(
							'Smoothly animate matching elements between this slide and the next.',
							'presenter'
						) }
						checked={ !! autoAnimate }
						onChange={ ( value ) =>
							setAttributes( { autoAnimate: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Slide', 'presenter' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Slide name', 'presenter' ) }
						help={ __(
							'Used as the slide id in the URL. Leave blank to skip.',
							'presenter'
						) }
						value={ title || '' }
						onChange={ ( value ) => setAttributes( { title: value } ) }
					/>
					<ToggleControl
						label={ __( 'Hide this slide', 'presenter' ) }
						help={ __(
							'Hidden slides are skipped in the presentation.',
							'presenter'
						) }
						checked={ !! hidden }
						onChange={ ( value ) => setAttributes( { hidden: value } ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div className="presenter-slide-viewport reveal-viewport">
				<div className="reveal center">
					<div className="slides">
						<section
							className="present presenter-slide-canvas"
							style={ canvasStyle }
						>
							<InnerBlocks template={ SLIDE_TEMPLATE } />
						</section>
					</div>
				</div>
			</div>

			<TextareaControl
				label={ __( 'Speaker notes', 'presenter' ) }
				value={ speakerNotes || '' }
				onChange={ ( value ) =>
					setAttributes( { speakerNotes: value } )
				}
			/>
		</div>
	);
}
