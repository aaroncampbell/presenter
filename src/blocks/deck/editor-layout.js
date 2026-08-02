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
