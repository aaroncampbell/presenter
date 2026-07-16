export const ASPECT_RATIO_DIMENSIONS = Object.freeze( {
	'16:9': Object.freeze( { width: 1280, height: 720 } ),
	'4:3': Object.freeze( { width: 960, height: 720 } ),
} );

/**
 * Convert a dimension control value into a positive integer.
 *
 * @param {string|number} value Dimension control value.
 * @return {number|undefined} A valid dimension, if supplied.
 */
export function parseDimension( value ) {
	const dimension = Number( value );

	return Number.isInteger( dimension ) && dimension > 0
		? dimension
		: undefined;
}

/**
 * Build the attributes changed by an aspect-ratio selection.
 *
 * Presets always use their canonical dimensions. Switching to custom keeps the
 * dimensions currently shown in the editor.
 *
 * @param {string} ratio Selected aspect ratio.
 * @return {Object} Attributes to update.
 */
export function getAspectRatioAttributes( ratio ) {
	if ( Object.hasOwn( ASPECT_RATIO_DIMENSIONS, ratio ) ) {
		return {
			aspectRatio: ratio,
			...ASPECT_RATIO_DIMENSIONS[ ratio ],
		};
	}

	return { aspectRatio: 'custom' };
}

/**
 * Build an accessible navigation settings update.
 *
 * Reveal presentations must retain at least one visible or keyboard-operated
 * navigation method. If an author disables the last enabled method, enable the
 * other one in the same block update.
 *
 * @param {'controls'|'keyboard'} setting    Navigation setting being changed.
 * @param {boolean}               value      New setting value.
 * @param {Object}                attributes Current Deck attributes.
 * @return {Object} Attributes to update.
 */
export function getNavigationAttributes( setting, value, attributes ) {
	const updates = { [ setting ]: value };
	const otherSetting = 'controls' === setting ? 'keyboard' : 'controls';

	if ( ! value && ! attributes[ otherSetting ] ) {
		updates[ otherSetting ] = true;
	}

	return updates;
}
