import { InspectorControls } from '@wordpress/block-editor';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import {
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

const FRAGMENT_EFFECTS = [
	{ label: __( 'Default fade in', 'presenter' ), value: '' },
	{ label: __( 'Fade out', 'presenter' ), value: 'fade-out' },
	{ label: __( 'Fade up', 'presenter' ), value: 'fade-up' },
	{ label: __( 'Fade down', 'presenter' ), value: 'fade-down' },
	{ label: __( 'Fade left', 'presenter' ), value: 'fade-left' },
	{ label: __( 'Fade right', 'presenter' ), value: 'fade-right' },
	{
		label: __( 'Fade in, then out', 'presenter' ),
		value: 'fade-in-then-out',
	},
	{ label: __( 'Current visible', 'presenter' ), value: 'current-visible' },
	{
		label: __( 'Fade in, then semi out', 'presenter' ),
		value: 'fade-in-then-semi-out',
	},
	{ label: __( 'Grow', 'presenter' ), value: 'grow' },
	{ label: __( 'Semi fade out', 'presenter' ), value: 'semi-fade-out' },
	{ label: __( 'Shrink', 'presenter' ), value: 'shrink' },
	{ label: __( 'Strike', 'presenter' ), value: 'strike' },
	{ label: __( 'Highlight red', 'presenter' ), value: 'highlight-red' },
	{ label: __( 'Highlight green', 'presenter' ), value: 'highlight-green' },
	{ label: __( 'Highlight blue', 'presenter' ), value: 'highlight-blue' },
	{
		label: __( 'Highlight current red', 'presenter' ),
		value: 'highlight-current-red',
	},
	{
		label: __( 'Highlight current green', 'presenter' ),
		value: 'highlight-current-green',
	},
	{
		label: __( 'Highlight current blue', 'presenter' ),
		value: 'highlight-current-blue',
	},
	{ label: __( 'Custom classes', 'presenter' ), value: 'custom' },
];

const addFragmentAttributes = ( settings, name ) => {
	if ( name === 'presenter/slide' ) {
		return settings;
	}

	return {
		...settings,
		attributes: {
			...settings.attributes,
			presenterFragment: {
				type: 'boolean',
				default: false,
			},
			presenterFragmentEffect: {
				type: 'string',
				default: '',
			},
			presenterFragmentCustomEffect: {
				type: 'string',
				default: '',
			},
			presenterFragmentIndex: {
				type: 'number',
			},
		},
	};
};

const withFragmentControls = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => {
		const { attributes, clientId, name, setAttributes } = props;
		const {
			presenterFragment,
			presenterFragmentCustomEffect,
			presenterFragmentEffect,
			presenterFragmentIndex,
		} = attributes;

		const isSlideChild = useSelect(
			( select ) => {
				if ( name === 'presenter/slide' ) {
					return false;
				}

				const blockEditor = select( 'core/block-editor' );
				return (
					blockEditor.getBlockParentsByBlockName(
						clientId,
						'presenter/slide'
					).length > 0
				);
			},
			[ clientId, name ]
		);

		const updateFragmentIndex = ( value ) => {
			const nextIndex = parseInt( value, 10 );
			setAttributes( {
				presenterFragmentIndex:
					value === '' || Number.isNaN( nextIndex )
						? undefined
						: Math.max( 0, nextIndex ),
			} );
		};

		return (
			<>
				<BlockEdit { ...props } />
				{ isSlideChild && (
					<InspectorControls>
						<PanelBody
							title={ __( 'Fragment', 'presenter' ) }
							initialOpen={ true }
						>
							<ToggleControl
								label={ __(
									'Reveal incrementally',
									'presenter'
								) }
								help={ __(
									'Step through this block before advancing to the next slide.',
									'presenter'
								) }
								checked={ !! presenterFragment }
								onChange={ ( value ) =>
									setAttributes( {
										presenterFragment: value,
										presenterFragmentEffect: value
											? presenterFragmentEffect || ''
											: '',
										presenterFragmentCustomEffect: value
											? presenterFragmentCustomEffect ||
											  ''
											: '',
										presenterFragmentIndex: value
											? presenterFragmentIndex
											: undefined,
									} )
								}
							/>
							{ !! presenterFragment && (
								<>
									<SelectControl
										label={ __( 'Effect', 'presenter' ) }
										value={ presenterFragmentEffect || '' }
										options={ FRAGMENT_EFFECTS }
										onChange={ ( value ) =>
											setAttributes( {
												presenterFragmentEffect: value,
											} )
										}
									/>
									{ presenterFragmentEffect === 'custom' && (
										<TextControl
											label={ __(
												'Custom effect classes',
												'presenter'
											) }
											help={ __(
												'Use space-separated reveal.js fragment classes, such as custom blur.',
												'presenter'
											) }
											value={
												presenterFragmentCustomEffect ||
												''
											}
											onChange={ ( value ) =>
												setAttributes( {
													presenterFragmentCustomEffect:
														value,
												} )
											}
										/>
									) }
									<TextControl
										label={ __( 'Order', 'presenter' ) }
										type="number"
										min="0"
										help={ __(
											'Optional. Blocks with the same number appear together.',
											'presenter'
										) }
										value={
											Number.isInteger(
												presenterFragmentIndex
											)
												? presenterFragmentIndex
												: ''
										}
										onChange={ updateFragmentIndex }
									/>
								</>
							) }
						</PanelBody>
					</InspectorControls>
				) }
			</>
		);
	},
	'withFragmentControls'
);

addFilter(
	'blocks.registerBlockType',
	'presenter/fragment-attributes',
	addFragmentAttributes
);
addFilter(
	'editor.BlockEdit',
	'presenter/fragment-controls',
	withFragmentControls
);
