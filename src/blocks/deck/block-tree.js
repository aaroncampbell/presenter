/**
 * Read a complete descendant tree from the block-editor store.
 *
 * WordPress may return shallow block objects from getBlocks( parentClientId ).
 * Rebuilding each level explicitly prevents structural conversions from
 * treating registered grandchildren as empty innerBlocks.
 *
 * @param {Object} editor       Block-editor store selector.
 * @param {string} rootClientId Parent block client ID.
 * @return {Object[]} Complete child block tree.
 */
export function getEditorBlockTree( editor, rootClientId ) {
	return editor.getBlocks( rootClientId ).map( ( block ) => ( {
		...block,
		innerBlocks: getEditorBlockTree( editor, block.clientId ),
	} ) );
}
