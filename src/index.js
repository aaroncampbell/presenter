// SCSS Files to process & build
import './index.scss'

import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import edit from './edit';
import save from './save';
import metadata from '../block.json'

registerBlockType( metadata, {
	/**
	 * @see ./edit.js
	 */
	 edit: edit,

	/**
	 * @see ./save.js
	 */
	 save: save,

});
