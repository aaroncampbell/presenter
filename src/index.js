import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks } from '@wordpress/block-editor';
import { useBlockProps, ColorPalette, InspectorControls } from '@wordpress/block-editor';
import {
	Panel,
	PanelBody,
	TextareaControl,
	ToggleControl,
	TextControl
} from '@wordpress/components';
import { cleanForSlug } from '@wordpress/url';

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
	},
	
	example: {
		attributes: {
			title: __( 'Slide Title', 'presenter' ),
		}
	},
	edit( props ) {
		const {
			attributes: { title, speakerNotes, hidden, bgColor },
			setAttributes,
		} = props;
		const blockProps = useBlockProps();
 
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

		return (
			<div { ...blockProps }>
				<InspectorControls key="setting">
					<Panel>
						<PanelBody title={ __('Background Color', 'presenter') } icon='art' initialOpen="false">
							<ColorPalette
								onChange={ onChangeBGColor } // onChange event callback
								value={ bgColor }
							/>
						</PanelBody>
					</Panel>
					<Panel>
						<PanelBody title={ __('Slide Name', 'presenter') } initialOpen="false">
							<TextControl
								label={ __( 'Slide name', 'presenter' ) }
								help={ __( 'This is sanitized and used as a hash in the URL.', 'presenter' ) }
								onChange={ onChangeTitle }
								value={ title }
							/>
						</PanelBody>
					</Panel>
					<Panel>
						<PanelBody title={ __('Visibility', 'presenter') } icon='visibility' initialOpen="false">
							<ToggleControl 
								label={ __('Hidden', 'presenter') }
								checked={ hidden }
								onChange={ onChangeHidden }
							/>
						</PanelBody>
					</Panel>
				</InspectorControls>
				<section className="presenter-slide" style={{backgroundColor: bgColor}}>
					<InnerBlocks />
				</section>
				<TextareaControl
					label={ __( 'Speaker Notes', 'presenter' ) }
					help="Enter some text"
					value={ speakerNotes }
					onChange={ onChangeSpeakerNotes }
				/>
			</div>
		);
	},
	save( props ) {
		const {
			attributes: { title, speakerNotes, hidden, bgColor },
		} = props;

		const TagName = hidden? 'div' : 'section';

		const blockProps = useBlockProps.save( {
			// Clean the Title and use it for the ID - Reveal.js uses this in a URL fragment
			// If no title is specified use the block id to generate one - it is needed as an id for reveal.js
			id: cleanForSlug( title || '' ),
			style: {
				display: hidden ? 'none' : undefined
			},
			'data-background-color': bgColor || undefined,
		} );

		return (
			<TagName { ...blockProps }>
				<InnerBlocks.Content />
				<aside className="notes">{ speakerNotes }</aside>
			</TagName>
		);
	},
} );
