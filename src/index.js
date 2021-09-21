import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks } from '@wordpress/block-editor';
import {
	useBlockProps,
	ColorPalette,
	InspectorControls,
	MediaUpload,
	MediaUploadCheck
} from '@wordpress/block-editor';
import {
	Panel,
	PanelBody,
	TextareaControl,
	ToggleControl,
	TextControl,
	Button,
	Spinner,
	ResponsiveWrapper,
} from '@wordpress/components';
import { cleanForSlug } from '@wordpress/url';
import { useSelect } from '@wordpress/data';

const ALLOWED_MEDIA_TYPES = ['image'];

registerBlockType( 'presenter/slide', {
	apiVersion: 2,
	title: __('Slide', 'presenter'),
	description: __( 'With this block you can add a new slide to your presentation!' ),
	icon: 'slides',
	category: 'common',
	keywords: [ __( 'Presentation', 'presenter' ), __( 'Presenter', 'presenter' ) ],
	attributes: {
		title: {
			type: 'string'
		},
		speakerNotes: {
			type: 'string'
		},
		hidden: {
			type: 'boolean'
		},
		bgColor: {
			type: 'string'
		},
		bgImageId: {
			type: 'number',
		},
		bgImageUrl: {
			type: 'string',
		},
	},

	example: {
		attributes: {
			title: __( 'Slide Title', 'presenter' ),
		}
	},
	edit( props ) {
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
		const onChangeBGColor = ( value ) => {
			setAttributes( { bgColor: value } );
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
			} );
		};

		return (
			<div { ...blockProps }>
				<InspectorControls key="setting">
					<Panel>
						<PanelBody title={ __('Background Color', 'presenter') } icon='art' initialOpen={false}>
							<ColorPalette
								onChange={ onChangeBGColor }
								value={ bgColor }
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
												<Button onClick={ open } isDefault isLarge>
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
				<section className="presenter-slide" style={ slideStyles }>
					<InnerBlocks />
				</section>
				<TextareaControl
					label={ __( 'Speaker Notes', 'presenter' ) }
					value={ speakerNotes }
					onChange={ onChangeSpeakerNotes }
				/>
			</div>
		);
	},
	save( props ) {
		const {
			attributes: { title, speakerNotes, hidden, bgColor, bgImageUrl },
		} = props;

		const TagName = hidden ? 'div' : 'section';

		const blockProps = useBlockProps.save({
			// Clean the Title and use it for the ID - Reveal.js uses this in a URL fragment
			// If no title is specified use the block id to generate one - it is needed as an id for reveal.js
			id: cleanForSlug( title || '' ),
			style: {
				display: hidden ? 'none' : undefined,
			},
			'data-background-color': bgColor || undefined,
			'data-background-image': bgImageUrl || undefined,
		});

		return (
			<TagName {...blockProps}>
				<InnerBlocks.Content />
				<aside className="notes">{speakerNotes}</aside>
			</TagName>
		);
	},
});
