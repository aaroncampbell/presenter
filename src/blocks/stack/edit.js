import {
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { normalizeSlideAnchor } from '../slide/anchor';

const ALLOWED_BLOCKS = [ 'presenter/slide' ];

/**
 * Edit the one supported level of Reveal nested Slides.
 *
 * @param {Object}   props               Block edit properties.
 * @param {Object}   props.attributes    Group attributes.
 * @param {string}   props.clientId      Block editor client identifier.
 * @param {Function} props.setAttributes Update group attributes.
 * @return {Element} Nested Slides editor.
 */
export default function Edit( { attributes, clientId, setAttributes } ) {
	const { anchor, label } = attributes;

	useEffect( () => {
		if ( ! anchor ) {
			setAttributes( { anchor: `stack-${ clientId }` } );
		}
	}, [ anchor, clientId, setAttributes ] );

	const blockProps = useBlockProps( {
		className: 'presenter-stack-editor',
	} );
	const innerBlocksProps = useInnerBlocksProps(
		{
			...blockProps,
			className: `${ blockProps.className } presenter-stack-editor-slides`,
		},
		{
			allowedBlocks: ALLOWED_BLOCKS,
			renderAppender: false,
		}
	);

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Nested Slides settings', 'presenter' ) }
				>
					<TextControl
						label={ __( 'Label', 'presenter' ) }
						help={ __(
							'A descriptive editor navigation label.',
							'presenter'
						) }
						value={ label }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Group anchor', 'presenter' ) }
						help={ __(
							'Optional URL-safe identifier for the nested group.',
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
				</PanelBody>
			</InspectorControls>
			<div { ...innerBlocksProps } />
		</>
	);
}
