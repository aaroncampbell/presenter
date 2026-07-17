const STRUCTURAL_BLOCKS = new Set( [ 'presenter/deck', 'presenter/slide' ] );

export const FRAGMENT_ATTRIBUTES = {
	presenterFragment: {
		type: 'boolean',
		default: false,
	},
	presenterFragmentEffect: {
		type: 'string',
		default: '',
	},
	presenterFragmentCustomClasses: {
		type: 'string',
		default: '',
	},
	presenterFragmentIndex: {
		type: 'number',
	},
};

/**
 * Return the complete reset used when fragment behavior is disabled.
 *
 * @return {Object} Dormant fragment attributes reset to defaults.
 */
export function getDisabledFragmentAttributes() {
	return {
		presenterFragment: false,
		presenterFragmentEffect: '',
		presenterFragmentCustomClasses: '',
		presenterFragmentIndex: undefined,
	};
}

/**
 * Whether a block can receive Presenter fragment attributes.
 *
 * @param {string} name Block name.
 * @return {boolean} Whether the block is eligible.
 */
export function isFragmentEligibleBlock( name ) {
	return 'string' === typeof name && ! STRUCTURAL_BLOCKS.has( name );
}

/**
 * Add namespaced fragment attributes without replacing block-owned settings.
 *
 * @param {Object} settings Registered block settings.
 * @param {string} name     Block name.
 * @return {Object} Filtered settings.
 */
export function addFragmentAttributes( settings, name ) {
	if ( ! isFragmentEligibleBlock( name ) ) {
		return settings;
	}

	return {
		...settings,
		attributes: {
			...( settings.attributes ?? {} ),
			...FRAGMENT_ATTRIBUTES,
		},
	};
}
