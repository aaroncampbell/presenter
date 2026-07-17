import {
	BlockPreview,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import { Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginSidebar } from '@wordpress/editor';
import { __, sprintf } from '@wordpress/i18n';

import {
	cloneSlideForDuplication,
	getDropTargetIndex,
	getSlideTitle,
} from './slide-utils';

const SLIDE_BLOCK_NAME = 'presenter/slide';

/**
 * Return a CSS background image declaration for a slide thumbnail.
 *
 * @param {string} url Background image URL.
 * @return {string|undefined} Safe inline declaration value.
 */
function getBackgroundImage( url ) {
	return url ? `url("${ url.replaceAll( '"', '\\"' ) }")` : undefined;
}

/**
 * Render a slide's live preview and useful title.
 *
 * Keeping this subscription at the item level ensures WordPress invalidates it
 * when that slide's asynchronously parsed or edited descendants change.
 *
 * @param {Object}   props             Component properties.
 * @param {Object}   props.deck        Parent Deck block.
 * @param {boolean}  props.isCurrent   Whether this is the selected slide.
 * @param {boolean}  props.isHidden    Whether this slide is hidden.
 * @param {Function} props.onSelect    Select-slide callback.
 * @param {Object}   props.slide       Slide block.
 * @param {number}   props.slideNumber One-based slide number.
 * @return {Element} Slide selection button.
 */
function SlideSelectButton( {
	deck,
	isCurrent,
	isHidden,
	onSelect,
	slide,
	slideNumber,
} ) {
	const hydratedSlide = useSelect(
		( select ) => select( blockEditorStore ).getBlock( slide.clientId ),
		[ slide.clientId ]
	);
	const previewSlide = hydratedSlide ?? slide;
	const title = getSlideTitle( previewSlide, slideNumber );
	const actionName = sprintf(
		/* translators: 1: Slide number. 2: Slide title. */
		__( 'Slide %1$d: %2$s', 'presenter' ),
		slideNumber,
		title
	);

	return (
		<div className="presenter-slide-navigator-preview-control">
			<div
				className="presenter-slide-navigator-thumbnail"
				aria-hidden="true"
				style={ {
					aspectRatio: `${ deck.attributes.width } / ${ deck.attributes.height }`,
					backgroundColor:
						slide.attributes.backgroundColor || undefined,
					backgroundImage: getBackgroundImage(
						slide.attributes.backgroundImageUrl
					),
				} }
			>
				<BlockPreview
					blocks={ previewSlide.innerBlocks }
					viewportWidth={ deck.attributes.width }
				/>
			</div>
			<div className="presenter-slide-navigator-title">
				<span>{ slideNumber }</span>
				<span>{ title }</span>
				{ isHidden && (
					<span className="presenter-slide-navigator-hidden">
						{ __( 'Hidden', 'presenter' ) }
					</span>
				) }
			</div>
			<Button
				className="presenter-slide-navigator-select"
				aria-label={ actionName }
				aria-current={ isCurrent ? 'true' : undefined }
				onClick={ onSelect }
			/>
		</div>
	);
}

/**
 * Render the supported slide-management workflow in a document sidebar.
 *
 * The sidebar intentionally uses the public PluginSidebar extension point. A
 * permanent editor rail would currently require private WordPress APIs.
 *
 * @return {Element|null} Slide navigator, or nothing outside a Presenter deck.
 */
export default function SlideNavigator() {
	const { deck, selectedSlideId, slides } = useSelect( ( select ) => {
		const editor = select( blockEditorStore );
		const nextDeck = editor
			.getBlocks()
			.find( ( block ) => 'presenter/deck' === block.name );

		if ( ! nextDeck ) {
			return { deck: null, selectedSlideId: '', slides: [] };
		}

		const nextSlides = editor
			.getBlocks( nextDeck.clientId )
			.filter( ( block ) => SLIDE_BLOCK_NAME === block.name );
		const selectedBlockId = editor.getSelectedBlockClientId();
		const selectedBlockParents = selectedBlockId
			? editor.getBlockParents( selectedBlockId )
			: [];
		const nextSelectedSlide = nextSlides.find(
			( slide ) =>
				slide.clientId === selectedBlockId ||
				selectedBlockParents.includes( slide.clientId )
		);

		return {
			deck: nextDeck,
			selectedSlideId: nextSelectedSlide?.clientId ?? '',
			slides: nextSlides,
		};
	}, [] );
	const {
		insertBlock,
		moveBlockToPosition,
		removeBlock,
		selectBlock,
		updateBlockAttributes,
	} = useDispatch( blockEditorStore );

	if ( ! deck ) {
		return null;
	}

	const addSlide = () => {
		const slide = createBlock( SLIDE_BLOCK_NAME );
		const anchoredSlide = {
			...slide,
			attributes: {
				...slide.attributes,
				anchor: `slide-${ slide.clientId }`,
			},
		};
		insertBlock( anchoredSlide, slides.length, deck.clientId, true );
	};
	const duplicateSlide = ( slide, index ) => {
		const duplicate = cloneSlideForDuplication( slide );
		insertBlock( duplicate, index + 1, deck.clientId, true );
	};
	const deleteSlide = ( slide, index ) => {
		const nextSelectedSlide = slides[ index + 1 ] ?? slides[ index - 1 ];
		removeBlock( slide.clientId, false );

		if ( nextSelectedSlide ) {
			selectBlock( nextSelectedSlide.clientId );
		}
	};
	const moveSlide = ( slide, index ) => {
		moveBlockToPosition(
			slide.clientId,
			deck.clientId,
			deck.clientId,
			index
		);
		selectBlock( slide.clientId );
	};
	const dropSlideAt = ( draggedSlideId, boundaryIndex ) => {
		const sourceIndex = slides.findIndex(
			( slide ) => slide.clientId === draggedSlideId
		);

		if ( -1 === sourceIndex ) {
			return;
		}

		const targetIndex = getDropTargetIndex(
			sourceIndex,
			boundaryIndex,
			slides.length
		);

		if ( targetIndex !== sourceIndex ) {
			moveSlide( slides[ sourceIndex ], targetIndex );
		}
	};
	const renderDropZone = ( index, asListItem = false ) => {
		const DropZone = asListItem ? 'li' : 'div';

		return (
			<DropZone
				aria-hidden="true"
				className="presenter-slide-navigator-drop-zone"
				data-presenter-drop-index={ index }
				role={ asListItem ? 'presentation' : undefined }
				onDragOver={ ( event ) => event.preventDefault() }
				onDrop={ ( event ) => {
					event.preventDefault();
					dropSlideAt(
						event.dataTransfer.getData( 'text/plain' ),
						index
					);
				} }
			/>
		);
	};

	return (
		<PluginSidebar
			name="slide-navigator"
			title={ __( 'Slides', 'presenter' ) }
			icon="slides"
			className="presenter-slide-navigator-sidebar"
		>
			<nav
				className="presenter-slide-navigator"
				aria-label={ __( 'Slide navigator', 'presenter' ) }
			>
				<div className="presenter-slide-navigator-header">
					<span>
						{ sprintf(
							/* translators: %d: Number of slides in the deck. */
							__( '%d slides', 'presenter' ),
							slides.length
						) }
					</span>
					<Button
						variant="primary"
						size="compact"
						onClick={ addSlide }
					>
						{ __( 'Add slide', 'presenter' ) }
					</Button>
				</div>
				<ol className="presenter-slide-navigator-list">
					{ renderDropZone( 0, true ) }
					{ slides.map( ( slide, index ) => {
						const slideNumber = index + 1;
						const isCurrent = selectedSlideId === slide.clientId;
						const isHidden = Boolean( slide.attributes.hidden );
						const visibilityActionName = isHidden
							? sprintf(
									/* translators: %d: Slide number. */
									__( 'Show slide %d', 'presenter' ),
									slideNumber
							  )
							: sprintf(
									/* translators: %d: Slide number. */
									__( 'Hide slide %d', 'presenter' ),
									slideNumber
							  );

						return (
							<li
								key={ slide.clientId }
								className={
									isCurrent ? 'is-current' : undefined
								}
								data-presenter-slide-id={ slide.clientId }
								draggable
								onDragStart={ ( event ) => {
									event.dataTransfer.effectAllowed = 'move';
									event.dataTransfer.setData(
										'text/plain',
										slide.clientId
									);
								} }
							>
								<SlideSelectButton
									deck={ deck }
									isCurrent={ isCurrent }
									isHidden={ isHidden }
									onSelect={ () =>
										selectBlock( slide.clientId )
									}
									slide={ slide }
									slideNumber={ slideNumber }
								/>
								<div className="presenter-slide-navigator-actions">
									<Button
										size="compact"
										disabled={ 0 === index }
										onClick={ () =>
											moveSlide( slide, index - 1 )
										}
										aria-label={ sprintf(
											/* translators: %d: Slide number. */
											__(
												'Move slide %d up',
												'presenter'
											),
											slideNumber
										) }
									>
										{ __( 'Up', 'presenter' ) }
									</Button>
									<Button
										size="compact"
										disabled={ slides.length - 1 === index }
										onClick={ () =>
											moveSlide( slide, index + 1 )
										}
										aria-label={ sprintf(
											/* translators: %d: Slide number. */
											__(
												'Move slide %d down',
												'presenter'
											),
											slideNumber
										) }
									>
										{ __( 'Down', 'presenter' ) }
									</Button>
									<Button
										size="compact"
										onClick={ () =>
											updateBlockAttributes(
												slide.clientId,
												{
													hidden: ! isHidden,
												}
											)
										}
										aria-label={ visibilityActionName }
									>
										{ isHidden
											? __( 'Show', 'presenter' )
											: __( 'Hide', 'presenter' ) }
									</Button>
									<Button
										size="compact"
										onClick={ () =>
											duplicateSlide( slide, index )
										}
										aria-label={ sprintf(
											/* translators: %d: Slide number. */
											__(
												'Duplicate slide %d',
												'presenter'
											),
											slideNumber
										) }
									>
										{ __( 'Duplicate', 'presenter' ) }
									</Button>
									<Button
										size="compact"
										isDestructive
										disabled={ 1 === slides.length }
										onClick={ () =>
											deleteSlide( slide, index )
										}
										aria-label={ sprintf(
											/* translators: %d: Slide number. */
											__(
												'Delete slide %d',
												'presenter'
											),
											slideNumber
										) }
									>
										{ __( 'Delete', 'presenter' ) }
									</Button>
								</div>
								{ renderDropZone( index + 1 ) }
							</li>
						);
					} ) }
				</ol>
			</nav>
		</PluginSidebar>
	);
}
