import { InnerBlocks } from '@wordpress/block-editor';

/**
 * Persist the slide's inner blocks.
 *
 * Because presenter/slide is a dynamic block (see render.php), the surrounding
 * <section> element and all reveal.js data attributes are added on the server.
 * Here we only store the inner blocks so the server can wrap them at render
 * time. Storing bare InnerBlocks.Content also means migrated/legacy content can
 * never trigger a block validation error.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#save
 *
 * @return {WPElement} Element to save.
 */
export default function save() {
	return <InnerBlocks.Content />;
}
