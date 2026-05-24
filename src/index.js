/**
 * Registers the presenter/slide block.
 *
 * The block is dynamic (server rendered via render.php), so `save` only needs
 * to persist the InnerBlocks content. All of the reveal.js section markup and
 * data attributes are produced on the server.
 */

// SCSS files to process & build.
import './index.scss';

import { registerBlockType } from '@wordpress/blocks';

import edit from './edit';
import save from './save';
import metadata from '../block.json';

registerBlockType( metadata.name, {
	/**
	 * @see ./edit.js
	 */
	edit,

	/**
	 * @see ./save.js
	 */
	save,
} );
