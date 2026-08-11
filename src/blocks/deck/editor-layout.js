/**
 * Calculate the editor scale for one logical slide width.
 *
 * @param {number} availableWidth Available editor width in CSS pixels.
 * @param {number} logicalWidth   Configured presentation width.
 * @return {number} A positive unitless CSS zoom value.
 */
export function getEditorScale( availableWidth, logicalWidth ) {
	if (
		! Number.isFinite( availableWidth ) ||
		! Number.isFinite( logicalWidth ) ||
		0 >= availableWidth ||
		0 >= logicalWidth
	) {
		return 1;
	}

	return (
		Math.round( ( availableWidth / logicalWidth ) * 1_000_000 ) / 1_000_000
	);
}

/**
 * Calculate the editor scale for a continuation slide inset by one canvas
 * gutter while keeping its Reveal logical dimensions unchanged.
 *
 * @param {number} availableWidth Available editor width in CSS pixels.
 * @param {number} logicalWidth   Configured presentation width.
 * @param {number} gutterWidth    Nested-slide gutter in CSS pixels.
 * @return {number} A positive unitless CSS zoom value.
 */
export function getNestedEditorScale(
	availableWidth,
	logicalWidth,
	gutterWidth
) {
	if ( ! Number.isFinite( gutterWidth ) || 0 >= gutterWidth ) {
		return getEditorScale( availableWidth, logicalWidth );
	}

	return getEditorScale(
		Math.max( availableWidth - gutterWidth, 1 ),
		logicalWidth
	);
}
