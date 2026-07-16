import { InnerBlocks } from '@wordpress/block-editor';

/**
 * Persist only the deck's slides. The server owns presentation markup.
 *
 * @return {Element} Serialized slide blocks.
 */
export default function save() {
	return <InnerBlocks.Content />;
}
