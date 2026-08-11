import { store as blockEditorStore } from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Expose Gutenberg's block inserter at a Slide content boundary.
 *
 * Core renders its hover-line inserter only between existing blocks. Slides
 * have meaningful empty space before their first block and after their last,
 * so provide matching boundary targets without adding placeholder blocks.
 *
 * @param {Object}  props              Component properties.
 * @param {boolean} props.isAppender   Whether this is the final boundary.
 * @param {string}  props.rootClientId Slide client identifier.
 * @return {Element} Boundary insertion control.
 */
export default function BoundaryInserter( {
	isAppender = false,
	rootClientId,
} ) {
	const position = isAppender ? 'after' : 'before';
	const label = isAppender
		? __( 'Show block inserter after slide content', 'presenter' )
		: __( 'Show block inserter before slide content', 'presenter' );
	const insertionIndex = useSelect(
		( select ) =>
			isAppender
				? select( blockEditorStore ).getBlockCount( rootClientId )
				: 0,
		[ isAppender, rootClientId ]
	);
	const { showInsertionPoint } = useDispatch( blockEditorStore );
	const showBoundaryInserter = useCallback(
		( event ) => {
			event.stopPropagation();
			showInsertionPoint( rootClientId, insertionIndex, {
				__unstableWithInserter: true,
			} );
		},
		[ insertionIndex, rootClientId, showInsertionPoint ]
	);

	return (
		<div
			aria-label={ label }
			className={ `presenter-slide-boundary-inserter is-${ position }` }
			onFocus={ showBoundaryInserter }
			onMouseEnter={ showBoundaryInserter }
			onMouseMoveCapture={ showBoundaryInserter }
			role="button"
			tabIndex={ 0 }
		/>
	);
}
