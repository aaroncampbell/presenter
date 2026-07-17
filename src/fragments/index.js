import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';

import { addFragmentAttributes } from './attributes';
import FragmentControls, {
	canMountFragmentControls,
} from './fragment-controls';

const withFragmentControls = createHigherOrderComponent(
	( BlockEdit ) =>
		function WithFragmentControls( props ) {
			return (
				<>
					<BlockEdit { ...props } />
					{ canMountFragmentControls( props.name ) && (
						<FragmentControls { ...props } />
					) }
				</>
			);
		},
	'withPresenterFragmentControls'
);

addFilter(
	'blocks.registerBlockType',
	'presenter/fragments/attributes',
	addFragmentAttributes
);
addFilter(
	'editor.BlockEdit',
	'presenter/fragments/controls',
	withFragmentControls
);
