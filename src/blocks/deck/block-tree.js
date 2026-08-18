/**
 * Read a complete descendant tree from the block-editor store.
 *
 * WordPress's getBlock() selector recursively returns the block's descendants,
 * except across an inner-block controller boundary. Presenter blocks are not
 * inner-block controllers, so the Deck's children are already fully hydrated.
 *
 * @param {Object} editor       Block-editor store selector.
 * @param {string} rootClientId Parent block client ID.
 * @return {Object[]} Complete child block tree.
 */
export function getEditorBlockTree( editor, rootClientId ) {
	return editor.getBlock( rootClientId )?.innerBlocks ?? [];
}
