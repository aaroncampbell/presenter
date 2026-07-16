import { InnerBlocks } from '@wordpress/block-editor';

/**
 * Persist native blocks while leaving slide section markup to PHP.
 *
 * @return {Element} Serialized native slide content.
 */
export default function save() {
	return <InnerBlocks.Content />;
}
