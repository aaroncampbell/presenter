import {
	InnerBlocks,
	useBlockProps,
} from '@wordpress/block-editor';
import { cleanForSlug } from '@wordpress/url';

/**
 * The save function defines the final markup for the presenter/slide block.
 *
 * @see https://developer.wordpress.org/block-editor/developers/block-api/block-edit-save/#save
 *
 * @param {Object} props            Properties passed to the function.
 *
 * @return {WPElement} Element to render.
 */
 export default function save( props ) {
	const {
		attributes: { title, speakerNotes, hidden, bgColor, bgImageUrl },
	} = props;

	const TagName = hidden ? 'div' : 'section';

	const blockProps = useBlockProps.save({
		// Clean the Title and use it for the ID - Reveal.js uses this in a URL fragment
		// If no title is specified use the block id to generate one - it is needed as an id for reveal.js
		id: cleanForSlug( title || '' ),
		style: {
			display: hidden ? 'none' : undefined,
		},
		'data-background-color': bgColor || undefined,
		'data-background-image': bgImageUrl || undefined,
	});

	return (
		<TagName {...blockProps}>
			<InnerBlocks.Content />
			<aside className="notes">{speakerNotes}</aside>
		</TagName>
	);
}
