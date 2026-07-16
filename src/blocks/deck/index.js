import { registerBlockType } from '@wordpress/blocks';

import metadata from '../../../blocks/deck/block.json';
import Edit from './edit';
import save from './save';

registerBlockType( metadata.name, {
	edit: Edit,
	save,
} );
