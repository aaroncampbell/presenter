import { InnerBlocks } from '@wordpress/block-editor';

/**
 * Save only the nested Slide blocks; PHP supplies the canonical outer section.
 *
 * @return {Element} Serialized nested Slides.
 */
export default function save() {
	return <InnerBlocks.Content />;
}
