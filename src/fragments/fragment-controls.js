import {
	InspectorControls,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	getDisabledFragmentAttributes,
	isFragmentEligibleBlock,
} from './attributes';
import {
	normalizeCustomFragmentClasses,
	normalizeFragmentIndex,
} from './validation';

export const FRAGMENT_EFFECT_OPTIONS = [
	{ label: __( 'Default', 'presenter' ), value: '' },
	{ label: __( 'Zoom in', 'presenter' ), value: 'zoom-in' },
	{ label: __( 'Fade out', 'presenter' ), value: 'fade-out' },
	{ label: __( 'Fade up', 'presenter' ), value: 'fade-up' },
	{ label: __( 'Fade down', 'presenter' ), value: 'fade-down' },
	{ label: __( 'Fade left', 'presenter' ), value: 'fade-left' },
	{ label: __( 'Fade right', 'presenter' ), value: 'fade-right' },
	{
		label: __( 'Fade in, then out', 'presenter' ),
		value: 'fade-in-then-out',
	},
	{
		label: __( 'Fade in, then dim', 'presenter' ),
		value: 'fade-in-then-semi-out',
	},
	{ label: __( 'Grow', 'presenter' ), value: 'grow' },
	{ label: __( 'Shrink', 'presenter' ), value: 'shrink' },
	{ label: __( 'Strike through', 'presenter' ), value: 'strike' },
	{ label: __( 'Highlight red', 'presenter' ), value: 'highlight-red' },
	{
		label: __( 'Highlight green', 'presenter' ),
		value: 'highlight-green',
	},
	{
		label: __( 'Highlight blue', 'presenter' ),
		value: 'highlight-blue',
	},
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
	{
		label: __( 'Current fragment only', 'presenter' ),
		value: 'current-visible',
	},
	{ label: __( 'Dim after display', 'presenter' ), value: 'semi-fade-out' },
	{ label: __( 'Custom classes', 'presenter' ), value: 'custom' },
];

/**
 * Whether the Presenter editor settings were supplied for this screen.
 *
 * @return {boolean} Whether this is the Presenter slideshow editor.
 */
export function isPresenterEditor() {
	return (
		'undefined' !== typeof window &&
		undefined !== window.presenterEditorSettings
	);
}

/**
 * Fragment controls for a block known to be eligible by name.
 *
 * @param {Object}   props               Component properties.
 * @param {Object}   props.attributes    Block attributes.
 * @param {string}   props.clientId      Block client identifier.
 * @param {Function} props.setAttributes Attribute update callback.
 * @return {Element|null} Fragment Inspector controls.
 */
export default function FragmentControls( {
	attributes,
	clientId,
	setAttributes,
} ) {
	const isInsideSlide = useSelect(
		( select ) =>
			select( blockEditorStore ).getBlockParentsByBlockName(
				clientId,
				'presenter/slide'
			).length > 0,
		[ clientId ]
	);
	const {
		presenterFragment,
		presenterFragmentCustomClasses = '',
		presenterFragmentEffect = '',
		presenterFragmentIndex,
	} = attributes;
	const [ customClassesInput, setCustomClassesInput ] = useState(
		presenterFragmentCustomClasses
	);
	const [ indexInput, setIndexInput ] = useState(
		undefined === presenterFragmentIndex
			? ''
			: String( presenterFragmentIndex )
	);
	const normalizedCustomClasses =
		normalizeCustomFragmentClasses( customClassesInput );
	const normalizedIndex = normalizeFragmentIndex( indexInput );
	const hasInvalidCustomClasses = undefined === normalizedCustomClasses;
	const hasInvalidIndex = '' !== indexInput && undefined === normalizedIndex;

	useEffect( () => {
		setCustomClassesInput( presenterFragmentCustomClasses );
	}, [ presenterFragmentCustomClasses ] );

	useEffect( () => {
		setIndexInput(
			undefined === presenterFragmentIndex
				? ''
				: String( presenterFragmentIndex )
		);
	}, [ presenterFragmentIndex ] );

	if ( ! isInsideSlide ) {
		return null;
	}

	const disableFragment = () => {
		setCustomClassesInput( '' );
		setIndexInput( '' );
		setAttributes( getDisabledFragmentAttributes() );
	};

	return (
		<InspectorControls>
			<PanelBody title={ __( 'Fragment', 'presenter' ) }>
				<ToggleControl
					label={ __(
						'Reveal this block incrementally',
						'presenter'
					) }
					checked={ Boolean( presenterFragment ) }
					onChange={ ( enabled ) =>
						enabled
							? setAttributes( { presenterFragment: true } )
							: disableFragment()
					}
				/>
				{ presenterFragment && (
					<>
						<SelectControl
							label={ __( 'Effect', 'presenter' ) }
							value={ presenterFragmentEffect }
							options={ FRAGMENT_EFFECT_OPTIONS }
							onChange={ ( effect ) => {
								setAttributes( {
									presenterFragmentEffect: effect,
									...( 'custom' === effect
										? {}
										: {
												presenterFragmentCustomClasses:
													'',
										  } ),
								} );
								if ( 'custom' !== effect ) {
									setCustomClassesInput( '' );
								}
							} }
						/>
						{ 'custom' === presenterFragmentEffect && (
							<TextControl
								label={ __( 'Custom classes', 'presenter' ) }
								value={ customClassesInput }
								onChange={ ( value ) => {
									setCustomClassesInput( value );
									const normalized =
										normalizeCustomFragmentClasses( value );
									if ( undefined !== normalized ) {
										setAttributes( {
											presenterFragmentCustomClasses:
												normalized,
										} );
									}
								} }
								onBlur={ () => {
									if ( hasInvalidCustomClasses ) {
										setCustomClassesInput(
											presenterFragmentCustomClasses
										);
									}
								} }
								help={
									hasInvalidCustomClasses
										? __(
												'Use up to ten safe class names; Reveal state classes are reserved.',
												'presenter'
										  )
										: __(
												'Add theme-defined fragment classes separated by spaces.',
												'presenter'
										  )
								}
								aria-invalid={ hasInvalidCustomClasses }
							/>
						) }
						<TextControl
							label={ __( 'Order', 'presenter' ) }
							type="number"
							min={ 0 }
							max={ 9999 }
							step={ 1 }
							value={ indexInput }
							onChange={ ( value ) => {
								setIndexInput( value );
								const normalized =
									normalizeFragmentIndex( value );
								if (
									'' === value ||
									undefined !== normalized
								) {
									setAttributes( {
										presenterFragmentIndex: normalized,
									} );
								}
							} }
							onBlur={ () => {
								if ( hasInvalidIndex ) {
									setIndexInput(
										undefined === presenterFragmentIndex
											? ''
											: String( presenterFragmentIndex )
									);
								}
							} }
							help={
								hasInvalidIndex
									? __(
											'Enter a whole number from 0 to 9999.',
											'presenter'
									  )
									: __(
											'Optional. Blocks with the same order are revealed together.',
											'presenter'
									  )
							}
							aria-invalid={ hasInvalidIndex }
						/>
					</>
				) }
			</PanelBody>
		</InspectorControls>
	);
}

/**
 * Determine whether fragment controls can be mounted for block edit props.
 *
 * @param {string} name Block name.
 * @return {boolean} Whether controls may be mounted.
 */
export function canMountFragmentControls( name ) {
	return isPresenterEditor() && isFragmentEligibleBlock( name );
}
