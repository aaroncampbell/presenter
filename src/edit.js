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
	Panel,
	PanelBody,
	RangeControl,
	ResponsiveWrapper,
	Spinner,
	TextareaControl,
	ToggleControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useEffect } from 'react';
import { useSelect } from '@wordpress/data';
import tinycolor from 'tinycolor2';

const ALLOWED_MEDIA_TYPES = ['image'];

/**
 * The edit function to describe the structure of the presenter/slide block in
 * the context of the editor.
 *
 * @see https://developer.wordpress.org/block-editor/developers/block-api/block-edit-save/#edit
 *
 * @param {Object}   props               Properties passed to the function.
 *
 * @return {WPElement} Element to render.
 */
export default function edit( props ) {
	const {
		attributes: { title, speakerNotes, hidden, bgColor, bgImageId },
		setAttributes,
	} = props;

	// Get the background image id there is one
	const { bgImage } = useSelect( ( select ) => {
		return {
			bgImage: bgImageId ? select( 'core' ).getMedia( bgImageId ) : null,
		};
	});

	const blockProps = useBlockProps();

	let slideStyles = {
		backgroundColor: bgColor || undefined,
		backgroundImage: ( bgImage && bgImage.source_url )? `url( ${ bgImage.source_url } )` : undefined,
		backgroundRepeat: ( bgImage && bgImage.source_url )? 'no-repeat' : undefined,
		backgroundPosition: ( bgImage && bgImage.source_url )? 'center' : undefined,
		backgroundSize: ( bgImage && bgImage.source_url )? 'cover' : undefined,
	};

	const instructions = (
		<p>
			{ __( 'To edit the background image, you need permission to upload media.', 'presenter' ) }
		</p>
	);

	const onChangeTitle = ( value ) => {
		setAttributes( { title: value } );
	};
	const onChangeSpeakerNotes = ( value ) => {
		setAttributes( { speakerNotes: value } );
	};
	const onChangeHidden = ( value ) => {
		setAttributes( { hidden: value } );
	};
	const onUpdateImage = ( image ) => {
		setAttributes( {
			bgImageId: image.id,
			bgImageUrl: image.url,
		} );
	};
	const onRemoveImage = () => {
		setAttributes( {
			bgImageId: undefined,
			bgImageUrl: undefined,
		} );
	};
	let tinyBgColor = tinycolor( bgColor );
	const [ bgColorHex, setBgColorHex ] = useState( tinyBgColor.isValid()? tinyBgColor.toHexString() : '' );
	const [ bgColorOpacity, setBgColorOpacity ] = useState( tinyBgColor.isValid()? tinyBgColor.getAlpha() * 100 : 100 );

	useEffect(() => {
		tinyBgColor = tinycolor( bgColorHex );
		tinyBgColor.setAlpha( bgColorOpacity / 100 );

		setAttributes( { bgColor: tinyBgColor.toHex8String() } );
	}, [ bgColorOpacity, bgColorHex ]);

	return (
		<div { ...blockProps }>
			<InspectorControls key="setting">
				<Panel>
					<PanelBody title={ __('Background Color', 'presenter') } icon='art' initialOpen={false}>
						<ColorPalette
							onChange={ ( value ) => setBgColorHex( value ) }
							value={ bgColorHex }
						/>
						<RangeControl
							label={ __( 'Background Opacity', 'presenter' ) }
							value={ bgColorOpacity }
							onChange={ ( value ) => setBgColorOpacity( value ) }
							min={ 0 }
							max={ 100 }
						/>
					</PanelBody>
				</Panel>
				<Panel>
					<PanelBody title={__('Background Image', 'presenter')} icon='format-image' initialOpen={false}>
						<div className="presenter-background-image-selector">
							<MediaUploadCheck fallback={instructions}>
								<MediaUpload
									title={ __('Background image', 'presenter') }
									onSelect={onUpdateImage}
									allowedTypes={ ALLOWED_MEDIA_TYPES }
									value={bgImageId}
									render={ ( { open  } ) => (
										<Button
											className={ ! bgImageId ? 'editor-post-featured-image__toggle' : 'editor-post-featured-image__preview' }
											onClick={ open }>
											{ ! bgImageId && ( __( 'Set background image', 'presenter' ) ) }
											{ !! bgImageId && ! bgImage && <Spinner /> }
											{ !! bgImageId && bgImage &&
												<ResponsiveWrapper
													naturalWidth={ bgImage.media_details.width }
													naturalHeight={ bgImage.media_details.height }
												>
													<img src={ bgImage.source_url } alt={ __( 'Background image', 'presenter' ) } />
												</ResponsiveWrapper>
											}
										</Button>
									)}
								/>
								{ !! bgImageId && bgImage &&
									<MediaUpload
										title={ __( 'Background image', 'image-selector-example' ) }
										onSelect={ onUpdateImage }
										allowedTypes={ ALLOWED_MEDIA_TYPES }
										value={ bgImageId }
										render={ ( { open } ) => (
											<Button onClick={ open } isSecondary>
												{ __( 'Replace Image', 'presenter' ) }
											</Button>
										) }
									/>
								}
								{ !! bgImageId &&
									<Button onClick={ onRemoveImage } isLink isDestructive>
										{ __( 'Remove background image', 'presenter' ) }
									</Button>
								}
							</MediaUploadCheck>
						</div>
					</PanelBody>
				</Panel>
				<Panel>
					<PanelBody title={ __('Slide Name', 'presenter') } initialOpen={false}>
						<TextControl
							label={ __( 'Slide name', 'presenter' ) }
							help={ __( 'This is sanitized and used as a hash in the URL.', 'presenter' ) }
							onChange={ onChangeTitle }
							value={ title }
						/>
					</PanelBody>
				</Panel>
				<Panel>
					<PanelBody title={ __('Visibility', 'presenter') } icon='visibility' initialOpen={false}>
						<ToggleControl
							label={ __('Hidden', 'presenter') }
							checked={ hidden }
							onChange={ onChangeHidden }
						/>
					</PanelBody>
				</Panel>
			</InspectorControls>
			<div className="reveal-viewport">
				<div className="reveal">
					<div className="slides">
						<section className="presenter-slide" style={ slideStyles }>
							<InnerBlocks />
						</section>
					</div>
				</div>
			</div>
			<TextareaControl
				label={ __( 'Speaker Notes', 'presenter' ) }
				value={ speakerNotes }
				onChange={ onChangeSpeakerNotes }
			/>
		</div>
	);
}