import {
	BlockPreview,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import { Button, Dropdown, Modal } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	createPortal,
	Fragment,
	useEffect,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import LegacySlidePreview from '../preview/legacy-slide-preview';
import {
	getGlobalThemeSettings,
	resolveTheme,
} from '../blocks/deck/theme-settings';
import {
	getAdjacentStack,
	getSelectedSlideEntry,
	getSlideEntries,
	insertSlideRelative,
	moveDeckItemBefore,
	moveSlideTo,
	removeSlide,
	SLIDE_BLOCK_NAME,
	STACK_BLOCK_NAME,
	wrapSlideInStack,
} from './slide-tree';
import {
	cloneSlideForDuplication,
	cloneStackForDuplication,
	getSlideTitle,
} from './slide-utils';

const THUMBNAIL_STYLES = [
	{
		css: '.presenter-deck-editor .reveal-viewport{padding:0}.presenter-deck-editor .slides{gap:0}.presenter-slide-editor{border:0;box-shadow:none}',
	},
];
const DRAG_DATA_TYPE = 'application/x-presenter-slide-tree';

/**
 * Track the edge of WordPress's optional Settings sidebar.
 *
 * The Presenter rail is an independent fixed panel so it can remain available
 * while WordPress switches between Slideshow and Block settings. Geometry is
 * measured rather than assuming a fixed core-sidebar width.
 *
 * @param {string} activeArea Active WordPress complementary-area identifier.
 * @return {{ bottom: number, right: number, top: number }} Rail offsets.
 */
function useRailGeometry( activeArea ) {
	const [ geometry, setGeometry ] = useState( {
		bottom: 0,
		right: 0,
		top: 60,
	} );

	useEffect( () => {
		let frame = 0;
		const update = () => {
			window.cancelAnimationFrame( frame );
			frame = window.requestAnimationFrame( () => {
				const sidebar = Array.from(
					document.querySelectorAll( '.editor-sidebar' )
				).find( ( element ) => {
					const rect = element.getBoundingClientRect();
					return 0 < rect.width && 0 < rect.height;
				} );
				const header = document.querySelector(
					'.editor-header, .edit-post-header'
				);
				const sidebarRect = sidebar?.getBoundingClientRect();
				const headerRect = header?.getBoundingClientRect();
				const next = {
					bottom: sidebarRect
						? Math.max( 0, window.innerHeight - sidebarRect.bottom )
						: 0,
					right: sidebarRect
						? Math.max( 0, window.innerWidth - sidebarRect.left )
						: 0,
					top: sidebarRect?.top ?? headerRect?.bottom ?? 60,
				};

				setGeometry( ( current ) =>
					current.bottom === next.bottom &&
					current.right === next.right &&
					current.top === next.top
						? current
						: next
				);
			} );
		};
		const resizeObserver = new window.ResizeObserver( update );
		const observed = [
			document.querySelector( '.interface-interface-skeleton' ),
			document.querySelector( '.editor-header, .edit-post-header' ),
			...document.querySelectorAll( '.editor-sidebar' ),
		].filter( Boolean );

		observed.forEach( ( element ) => resizeObserver.observe( element ) );
		window.addEventListener( 'resize', update );
		update();

		return () => {
			window.cancelAnimationFrame( frame );
			window.removeEventListener( 'resize', update );
			resizeObserver.disconnect();
		};
	}, [ activeArea ] );

	return geometry;
}

/**
 * Create a Slide with its stable anchor before insertion.
 *
 * @return {Object} New Presenter Slide block.
 */
function createAnchoredSlide() {
	const slide = createBlock( SLIDE_BLOCK_NAME );

	return {
		...slide,
		attributes: {
			...slide.attributes,
			anchor: `slide-${ slide.clientId }`,
		},
	};
}

/**
 * Read Presenter drag data without accepting unrelated editor drags.
 *
 * @param {DataTransfer} dataTransfer Browser drag data.
 * @return {Object|null} Parsed drag descriptor.
 */
function getDragData( dataTransfer ) {
	try {
		return JSON.parse(
			dataTransfer.getData( DRAG_DATA_TYPE ) ||
				dataTransfer.getData( 'text/plain' )
		);
	} catch ( error ) {
		return null;
	}
}

/**
 * Render a structural drop boundary.
 *
 * @param {Object}   props             Component properties.
 * @param {Object}   props.destination Structural destination descriptor.
 * @param {Function} props.onDrop      Drop callback.
 * @return {Element} Drop target.
 */
function DropZone( { destination, onDrop } ) {
	return (
		<li
			aria-hidden="true"
			className="presenter-slide-navigator-drop-zone"
			data-presenter-drop-before={ destination.beforeId ?? '' }
			data-presenter-drop-parent={ destination.stackId ?? '' }
			data-presenter-drop-type={ destination.type }
			role="presentation"
			onDragOver={ ( event ) => {
				event.preventDefault();
				event.dataTransfer.dropEffect = 'move';
			} }
			onDrop={ ( event ) => {
				event.preventDefault();
				onDrop( getDragData( event.dataTransfer ) );
			} }
		/>
	);
}

/**
 * Render a Slide's live preview and useful title.
 *
 * Keeping this subscription at the item level ensures WordPress invalidates it
 * when that Slide's asynchronously parsed or edited descendants change.
 *
 * @param {Object}   props               Component properties.
 * @param {Object}   props.deck          Parent Deck block.
 * @param {boolean}  props.isCurrent     Whether this is the selected Slide.
 * @param {boolean}  props.isHidden      Whether this Slide is hidden.
 * @param {Function} props.onSelect      Select-Slide callback.
 * @param {Object}   props.slide         Slide block.
 * @param {string}   props.slidePosition One-based Slide position.
 * @return {Element} Slide selection button.
 */
function SlideSelectButton( {
	deck,
	isCurrent,
	isHidden,
	onSelect,
	slide,
	slidePosition,
} ) {
	const hydratedSlide = useSelect(
		( select ) => select( blockEditorStore ).getBlock( slide.clientId ),
		[ slide.clientId ]
	);
	const previewSlide = hydratedSlide ?? slide;
	const legacyBlock =
		1 === previewSlide.innerBlocks.length &&
		'core/html' === previewSlide.innerBlocks[ 0 ].name
			? previewSlide.innerBlocks[ 0 ]
			: null;
	const themeSettings = getGlobalThemeSettings();
	const selectedTheme = resolveTheme( deck.attributes.theme, themeSettings );
	const previewFooterHtml =
		'string' === typeof themeSettings.previewFooterHtml
			? themeSettings.previewFooterHtml
			: '';
	const previewDeck = {
		...deck,
		innerBlocks: [ previewSlide ],
	};
	const title = getSlideTitle( previewSlide, slidePosition );
	const actionName = sprintf(
		/* translators: 1: Slide position. 2: Slide title. */
		__( 'Slide %1$s: %2$s', 'presenter' ),
		slidePosition,
		title
	);

	return (
		<div className="presenter-slide-navigator-preview-control">
			<div
				className="presenter-slide-navigator-thumbnail"
				aria-hidden="true"
				style={ {
					aspectRatio: `${ deck.attributes.width } / ${ deck.attributes.height }`,
				} }
			>
				{ legacyBlock ? (
					<LegacySlidePreview
						attributes={ previewSlide.attributes }
						center={ deck.attributes.center }
						footerHtml={ previewFooterHtml }
						height={ deck.attributes.height }
						html={ legacyBlock.attributes.content }
						theme={ selectedTheme }
						width={ deck.attributes.width }
					/>
				) : (
					<BlockPreview
						additionalStyles={ THUMBNAIL_STYLES }
						blocks={ [ previewDeck ] }
						minHeight={ deck.attributes.height }
						viewportWidth={ deck.attributes.width }
					/>
				) }
			</div>
			<div className="presenter-slide-navigator-title">
				<span>{ slidePosition }</span>
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
 * Render the supported Slide-management workflow in a persistent rail.
 *
 * @return {Element|null} Slide navigator, or nothing outside a Presenter Deck.
 */
export default function SlideNavigator() {
	const editorState = useSelect( ( select ) => {
		const editor = select( blockEditorStore );
		const rootDeck = editor
			.getBlocks()
			.find( ( block ) => 'presenter/deck' === block.name );

		if ( ! rootDeck ) {
			return {
				activeArea: '',
				rootDeck: null,
				selectedId: '',
				selectedParentIds: '',
			};
		}

		const selectedId = editor.getSelectedBlockClientId();

		return {
			activeArea:
				select( 'core/interface' ).getActiveComplementaryArea(
					'core'
				) ?? '',
			rootDeck,
			selectedId,
			selectedParentIds: selectedId
				? editor.getBlockParents( selectedId ).join( ',' )
				: '',
		};
	}, [] );
	const deck = editorState.rootDeck;
	const entries = getSlideEntries( deck );
	const selectedEntry =
		getSelectedSlideEntry(
			entries,
			editorState.selectedId,
			editorState.selectedParentIds
				? editorState.selectedParentIds.split( ',' )
				: []
		) ?? null;
	const [ deletingStack, setDeletingStack ] = useState( null );
	const [ isRailOpen, setIsRailOpen ] = useState( true );
	const railGeometry = useRailGeometry( editorState.activeArea );
	const selectedSlideId = selectedEntry?.slide.clientId ?? '';
	const {
		insertBlock,
		moveBlockToPosition,
		replaceInnerBlocks,
		selectBlock,
		updateBlockAttributes,
	} = useDispatch( blockEditorStore );

	useEffect( () => {
		if ( ! isRailOpen || ! selectedSlideId ) {
			return undefined;
		}

		const frame = window.requestAnimationFrame( () => {
			const item = document.querySelector(
				`.presenter-slides-rail [data-presenter-slide-id="${ selectedSlideId }"]`
			);
			item?.scrollIntoView( { block: 'nearest' } );

			const editorDocument =
				document.querySelector( 'iframe[name="editor-canvas"]' )
					?.contentDocument ?? document;
			const selectedSlide = editorDocument.querySelector(
				`[data-block="${ selectedSlideId }"]`
			);
			selectedSlide?.scrollIntoView( {
				block: 'center',
				inline: 'nearest',
			} );
		} );

		return () => window.cancelAnimationFrame( frame );
	}, [ isRailOpen, selectedSlideId ] );

	useEffect( () => {
		document.body.classList.add( 'presenter-slides-rail-active' );
		document.body.classList.toggle(
			'presenter-slides-rail-open',
			isRailOpen
		);

		return () => {
			document.body.classList.remove(
				'presenter-slides-rail-active',
				'presenter-slides-rail-open'
			);
		};
	}, [ isRailOpen ] );

	if ( ! deck ) {
		return null;
	}

	const replaceDeckItems = ( items, selectedId ) => {
		if ( ! items ) {
			return;
		}

		const materializedItems = items.map( ( item ) => {
			if ( STACK_BLOCK_NAME !== item.name ) {
				return item;
			}

			const currentStack = deck.innerBlocks.find(
				( currentItem ) => currentItem.clientId === item.clientId
			);

			if ( ! currentStack || currentStack === item ) {
				return item;
			}

			return createBlock(
				STACK_BLOCK_NAME,
				item.attributes,
				item.innerBlocks
			);
		} );

		replaceInnerBlocks( deck.clientId, materializedItems, true );

		if ( selectedId ) {
			selectBlock( selectedId );
		}
	};
	const addRelativeSlide = ( offset ) => {
		const added = createAnchoredSlide();

		if ( ! selectedEntry ) {
			insertBlock( added, deck.innerBlocks.length, deck.clientId, true );
			return;
		}

		if ( selectedEntry.isStackParent ) {
			const stackIndex = deck.innerBlocks.findIndex(
				( item ) => item.clientId === selectedEntry.stack.clientId
			);
			const nextItems = [ ...deck.innerBlocks ];
			nextItems.splice( stackIndex + offset, 0, added );
			replaceDeckItems( nextItems, added.clientId );
			return;
		}

		const nextItems = insertSlideRelative(
			deck.innerBlocks,
			selectedEntry.slide.clientId,
			added,
			offset
		);

		if ( selectedEntry.stack ) {
			const nextStack = nextItems.find(
				( item ) => item.clientId === selectedEntry.stack.clientId
			);
			replaceInnerBlocks(
				selectedEntry.stack.clientId,
				nextStack.innerBlocks,
				true
			);
			selectBlock( added.clientId );
			return;
		}

		replaceDeckItems( nextItems, added.clientId );
	};
	const addNestedSlide = () => {
		if ( ! selectedEntry || selectedEntry.stack ) {
			return;
		}

		const added = createAnchoredSlide();
		const stack = createBlock( STACK_BLOCK_NAME );
		const anchoredStack = {
			...stack,
			attributes: {
				...stack.attributes,
				anchor: `stack-${ stack.clientId }`,
			},
		};
		const items = wrapSlideInStack(
			deck.innerBlocks,
			selectedEntry.slide.clientId,
			anchoredStack,
			added
		);

		replaceDeckItems( items, added.clientId );
	};
	const moveSameParent = ( entry, index ) => {
		if ( entry.isStackParent ) {
			const sourceIndex = deck.innerBlocks.findIndex(
				( item ) => item.clientId === entry.stack.clientId
			);
			const beforeId =
				index < sourceIndex
					? deck.innerBlocks[ index ]?.clientId ?? null
					: deck.innerBlocks[ index + 1 ]?.clientId ?? null;
			replaceDeckItems(
				moveDeckItemBefore(
					deck.innerBlocks,
					entry.stack.clientId,
					beforeId
				),
				entry.slide.clientId
			);
			return;
		}

		moveBlockToPosition(
			entry.slide.clientId,
			entry.parentId,
			entry.parentId,
			index
		);
		selectBlock( entry.slide.clientId );
	};
	const duplicateSlide = ( entry ) => {
		const duplicate = cloneSlideForDuplication( entry.slide );

		if ( entry.isStackParent ) {
			const stackIndex = deck.innerBlocks.findIndex(
				( item ) => item.clientId === entry.stack.clientId
			);
			const nextItems = [ ...deck.innerBlocks ];
			nextItems.splice( stackIndex + 1, 0, duplicate );
			replaceDeckItems( nextItems, duplicate.clientId );
			return;
		}

		const nextItems = insertSlideRelative(
			deck.innerBlocks,
			entry.slide.clientId,
			duplicate,
			1
		);

		if ( entry.stack ) {
			const nextStack = nextItems.find(
				( item ) => item.clientId === entry.stack.clientId
			);
			replaceInnerBlocks(
				entry.stack.clientId,
				nextStack.innerBlocks,
				true
			);
			selectBlock( duplicate.clientId );
			return;
		}

		replaceDeckItems( nextItems, duplicate.clientId );
	};
	const deleteSlide = ( entry ) => {
		const currentIndex = entries.findIndex(
			( candidate ) => candidate.slide.clientId === entry.slide.clientId
		);
		const nextSelected =
			entries[ currentIndex + 1 ] ?? entries[ currentIndex - 1 ];

		replaceDeckItems(
			removeSlide( deck.innerBlocks, entry.slide.clientId ),
			nextSelected?.slide.clientId
		);
	};
	const duplicateStack = ( stack ) => {
		const duplicate = cloneStackForDuplication( stack );
		const index = deck.innerBlocks.findIndex(
			( item ) => item.clientId === stack.clientId
		);
		const nextItems = [ ...deck.innerBlocks ];
		nextItems.splice( index + 1, 0, duplicate );
		replaceDeckItems( nextItems, duplicate.innerBlocks[ 0 ]?.clientId );
	};
	const deleteStack = ( stack ) => {
		const stackEntries = entries.filter(
			( entry ) => entry.stack?.clientId === stack.clientId
		);

		if ( entries.length <= stackEntries.length ) {
			setDeletingStack( null );
			return;
		}

		const lastIndex = entries.findIndex(
			( entry ) =>
				entry.slide.clientId ===
				stackEntries[ stackEntries.length - 1 ]?.slide.clientId
		);
		const nextSelected =
			entries[ lastIndex + 1 ] ??
			entries[ lastIndex - stackEntries.length ];
		const nextItems = deck.innerBlocks.filter(
			( item ) => item.clientId !== stack.clientId
		);

		setDeletingStack( null );
		replaceDeckItems( nextItems, nextSelected?.slide.clientId );
	};
	const moveToTopLevel = ( entry ) => {
		const sourceItemIndex = deck.innerBlocks.findIndex(
			( item ) => item.clientId === entry.stack.clientId
		);
		const beforeId =
			deck.innerBlocks[ sourceItemIndex + 1 ]?.clientId ?? null;

		replaceDeckItems(
			moveSlideTo( deck.innerBlocks, entry.slide.clientId, {
				type: 'deck',
				beforeId,
			} ),
			entry.slide.clientId
		);
	};
	const moveIntoStack = ( entry, stack, atStart ) => {
		replaceDeckItems(
			moveSlideTo( deck.innerBlocks, entry.slide.clientId, {
				type: 'stack',
				stackId: stack.clientId,
				beforeId: atStart
					? stack.innerBlocks[ 0 ]?.clientId ?? null
					: null,
			} ),
			entry.slide.clientId
		);
	};
	const handleTopLevelDrop = ( data, beforeId ) => {
		if ( 'stack' === data?.type ) {
			replaceDeckItems(
				moveDeckItemBefore( deck.innerBlocks, data.id, beforeId ),
				data.id
			);
			return;
		}

		if ( 'slide' === data?.type ) {
			replaceDeckItems(
				moveSlideTo( deck.innerBlocks, data.id, {
					type: 'deck',
					beforeId,
				} ),
				data.id
			);
		}
	};
	const handleStackDrop = ( data, stackId, beforeId ) => {
		if ( 'slide' !== data?.type ) {
			return;
		}

		replaceDeckItems(
			moveSlideTo( deck.innerBlocks, data.id, {
				type: 'stack',
				stackId,
				beforeId,
			} ),
			data.id
		);
	};
	const setDragData = ( event, data ) => {
		const serialized = JSON.stringify( data );
		event.dataTransfer.effectAllowed = 'move';
		event.dataTransfer.setData( DRAG_DATA_TYPE, serialized );
		event.dataTransfer.setData( 'text/plain', serialized );
	};
	const renderSlide = (
		entry,
		{ className = '', dragData = null, stackActions = null } = {}
	) => {
		const { slide } = entry;
		const isCurrent = selectedEntry?.slide.clientId === slide.clientId;
		const isHidden = Boolean( slide.attributes.hidden );
		const siblings =
			entry.stack && ! entry.isStackParent
				? entry.stack.innerBlocks
				: deck.innerBlocks;
		const siblingIndex = entry.isStackParent
			? entry.horizontalIndex
			: entry.index;
		const previousStack = entry.stack
			? null
			: getAdjacentStack( deck.innerBlocks, slide.clientId, -1 );
		const nextStack = entry.stack
			? null
			: getAdjacentStack( deck.innerBlocks, slide.clientId, 1 );
		const moveUpName = sprintf(
			/* translators: %s: Slide position, such as 2 or 2.1. */
			__( 'Move Slide %s up', 'presenter' ),
			entry.position
		);
		const moveDownName = sprintf(
			/* translators: %s: Slide position, such as 2 or 2.1. */
			__( 'Move Slide %s down', 'presenter' ),
			entry.position
		);
		const visibilityName = isHidden
			? sprintf(
					/* translators: %s: Slide position, such as 2 or 2.1. */
					__( 'Show Slide %s', 'presenter' ),
					entry.position
			  )
			: sprintf(
					/* translators: %s: Slide position, such as 2 or 2.1. */
					__( 'Hide Slide %s', 'presenter' ),
					entry.position
			  );
		const duplicateName = stackActions
			? sprintf(
					/* translators: %d: Nested Slides group position. */
					__( 'Duplicate Nested Slides %d', 'presenter' ),
					entry.horizontalIndex + 1
			  )
			: sprintf(
					/* translators: %s: Slide position, such as 2 or 2.1. */
					__( 'Duplicate Slide %s', 'presenter' ),
					entry.position
			  );
		const deleteName = stackActions
			? sprintf(
					/* translators: %d: Nested Slides group position. */
					__( 'Delete Nested Slides %d', 'presenter' ),
					entry.horizontalIndex + 1
			  )
			: sprintf(
					/* translators: %s: Slide position, such as 2 or 2.1. */
					__( 'Delete Slide %s', 'presenter' ),
					entry.position
			  );

		return (
			<li
				key={ slide.clientId }
				className={ [ isCurrent ? 'is-current' : '', className ]
					.filter( Boolean )
					.join( ' ' ) }
				role="treeitem"
				aria-selected={ isCurrent }
				data-presenter-slide-id={ slide.clientId }
				draggable
				onDragStart={ ( event ) =>
					setDragData(
						event,
						dragData ?? { type: 'slide', id: slide.clientId }
					)
				}
			>
				<SlideSelectButton
					deck={ deck }
					isCurrent={ isCurrent }
					isHidden={ isHidden }
					onSelect={ () => selectBlock( slide.clientId ) }
					slide={ slide }
					slidePosition={ entry.position }
				/>
				<div className="presenter-slide-navigator-actions">
					<Button
						size="compact"
						disabled={ 0 === siblingIndex }
						aria-label={ moveUpName }
						onClick={ () =>
							moveSameParent( entry, siblingIndex - 1 )
						}
					>
						{ __( 'Up', 'presenter' ) }
					</Button>
					<Button
						size="compact"
						disabled={ siblings.length - 1 === siblingIndex }
						aria-label={ moveDownName }
						onClick={ () =>
							moveSameParent( entry, siblingIndex + 1 )
						}
					>
						{ __( 'Down', 'presenter' ) }
					</Button>
					{ entry.stack && ! entry.isStackParent && (
						<Button
							size="compact"
							aria-label={ sprintf(
								/* translators: %s: Nested Slide position. */
								__( 'Move Slide %s to top level', 'presenter' ),
								entry.position
							) }
							onClick={ () => moveToTopLevel( entry ) }
						>
							{ __( 'Move to top level', 'presenter' ) }
						</Button>
					) }
					{ previousStack && (
						<Button
							size="compact"
							aria-label={ sprintf(
								/* translators: %s: Slide position. */
								__(
									'Move Slide %s into Nested Slides before',
									'presenter'
								),
								entry.position
							) }
							onClick={ () =>
								moveIntoStack( entry, previousStack, false )
							}
						>
							{ __(
								'Move into Nested Slides before',
								'presenter'
							) }
						</Button>
					) }
					{ nextStack && (
						<Button
							size="compact"
							aria-label={ sprintf(
								/* translators: %s: Slide position. */
								__(
									'Move Slide %s into Nested Slides after',
									'presenter'
								),
								entry.position
							) }
							onClick={ () =>
								moveIntoStack( entry, nextStack, true )
							}
						>
							{ __(
								'Move into Nested Slides after',
								'presenter'
							) }
						</Button>
					) }
					<Button
						size="compact"
						aria-label={ visibilityName }
						onClick={ () =>
							updateBlockAttributes( slide.clientId, {
								hidden: ! isHidden,
							} )
						}
					>
						{ isHidden
							? __( 'Show', 'presenter' )
							: __( 'Hide', 'presenter' ) }
					</Button>
					<Button
						size="compact"
						aria-label={ duplicateName }
						onClick={ () =>
							stackActions
								? duplicateStack( stackActions )
								: duplicateSlide( entry )
						}
					>
						{ __( 'Duplicate', 'presenter' ) }
					</Button>
					<Button
						size="compact"
						isDestructive
						disabled={
							stackActions
								? entries.length <=
								  stackActions.innerBlocks.length
								: 1 === entries.length
						}
						aria-label={ deleteName }
						onClick={ () =>
							stackActions
								? setDeletingStack( stackActions )
								: deleteSlide( entry )
						}
					>
						{ __( 'Delete', 'presenter' ) }
					</Button>
				</div>
			</li>
		);
	};
	const renderTopLevelItem = ( item ) => {
		if ( SLIDE_BLOCK_NAME === item.name ) {
			return renderSlide(
				entries.find(
					( entry ) => entry.slide.clientId === item.clientId
				)
			);
		}

		const firstSlide = item.innerBlocks[ 0 ];
		const continuationSlides = item.innerBlocks.slice( 1 );
		const findEntry = ( slide ) =>
			entries.find(
				( entry ) => entry.slide.clientId === slide.clientId
			);

		return (
			<li
				key={ item.clientId }
				className="presenter-slide-navigator-stack"
				role="none"
			>
				<ol role="group">
					<DropZone
						destination={ {
							type: 'stack',
							stackId: item.clientId,
							beforeId: firstSlide?.clientId ?? null,
						} }
						onDrop={ ( data ) =>
							handleStackDrop(
								data,
								item.clientId,
								firstSlide?.clientId ?? null
							)
						}
					/>
					{ firstSlide &&
						renderSlide( findEntry( firstSlide ), {
							className: 'presenter-slide-navigator-stack-parent',
							dragData: {
								type: 'stack',
								id: item.clientId,
							},
							stackActions: item,
						} ) }
				</ol>
				{ 0 < continuationSlides.length && (
					<ol
						className="presenter-slide-navigator-stack-continuations"
						role="group"
					>
						<DropZone
							destination={ {
								type: 'stack',
								stackId: item.clientId,
								beforeId:
									continuationSlides[ 0 ]?.clientId ?? null,
							} }
							onDrop={ ( data ) =>
								handleStackDrop(
									data,
									item.clientId,
									continuationSlides[ 0 ]?.clientId ?? null
								)
							}
						/>
						{ continuationSlides.map( ( slide, slideIndex ) => (
							<Fragment key={ slide.clientId }>
								{ renderSlide( findEntry( slide ) ) }
								<DropZone
									destination={ {
										type: 'stack',
										stackId: item.clientId,
										beforeId:
											continuationSlides[ slideIndex + 1 ]
												?.clientId ?? null,
									} }
									onDrop={ ( data ) => {
										handleStackDrop(
											data,
											item.clientId,
											continuationSlides[ slideIndex + 1 ]
												?.clientId ?? null
										);
									} }
								/>
							</Fragment>
						) ) }
					</ol>
				) }
			</li>
		);
	};

	return (
		<>
			{ createPortal(
				<aside
					aria-label={ __( 'Slides rail', 'presenter' ) }
					className={ `presenter-slides-rail ${
						isRailOpen ? 'is-open' : 'is-collapsed'
					}` }
					style={ railGeometry }
				>
					<div className="presenter-slides-rail-tab">
						<Button
							aria-expanded={ isRailOpen }
							aria-label={
								isRailOpen
									? __( 'Collapse Slides rail', 'presenter' )
									: __( 'Open Slides rail', 'presenter' )
							}
							onClick={ () =>
								setIsRailOpen( ( current ) => ! current )
							}
						>
							<span
								aria-hidden="true"
								className="dashicons dashicons-slides"
							/>
							<span className="presenter-slides-rail-tab-label">
								{ __( 'Slides', 'presenter' ) }
							</span>
							<span aria-hidden="true">
								{ isRailOpen ? '›' : '‹' }
							</span>
						</Button>
					</div>
					{ isRailOpen && (
						<div className="presenter-slides-rail-panel">
							<h2>{ __( 'Slides', 'presenter' ) }</h2>
							<nav
								className="presenter-slide-navigator"
								aria-label={ __(
									'Slide navigator',
									'presenter'
								) }
							>
								<div className="presenter-slide-navigator-header">
									<span>
										{ sprintf(
											/* translators: %d: Number of Slides in the Deck. */
											__( '%d slides', 'presenter' ),
											entries.length
										) }
									</span>
									<div className="presenter-add-slide-split-button">
										<Button
											variant="primary"
											size="compact"
											onClick={ () =>
												addRelativeSlide( 1 )
											}
										>
											{ __( 'Add Slide', 'presenter' ) }
										</Button>
										<Dropdown
											popoverProps={ {
												placement: 'bottom-end',
											} }
											renderToggle={ ( {
												isOpen,
												onToggle,
											} ) => (
												<Button
													variant="primary"
													size="compact"
													aria-expanded={ isOpen }
													aria-label={ __(
														'Add Slide options',
														'presenter'
													) }
													onClick={ onToggle }
												>
													<span aria-hidden="true">
														▾
													</span>
												</Button>
											) }
											renderContent={ ( { onClose } ) => (
												<div className="presenter-add-slide-menu">
													<Button
														onClick={ () => {
															onClose();
															addRelativeSlide(
																1
															);
														} }
													>
														{ __(
															'Add after',
															'presenter'
														) }
													</Button>
													{ selectedEntry && (
														<Button
															onClick={ () => {
																onClose();
																addRelativeSlide(
																	0
																);
															} }
														>
															{ __(
																'Add before',
																'presenter'
															) }
														</Button>
													) }
													{ selectedEntry &&
														! selectedEntry.stack && (
															<Button
																onClick={ () => {
																	onClose();
																	addNestedSlide();
																} }
															>
																{ __(
																	'Add nested',
																	'presenter'
																) }
															</Button>
														) }
												</div>
											) }
										/>
									</div>
								</div>
								<ol
									className="presenter-slide-navigator-list"
									role="tree"
								>
									<DropZone
										destination={ {
											type: 'deck',
											beforeId:
												deck.innerBlocks[ 0 ]
													?.clientId ?? null,
										} }
										onDrop={ ( data ) =>
											handleTopLevelDrop(
												data,
												deck.innerBlocks[ 0 ]
													?.clientId ?? null
											)
										}
									/>
									{ deck.innerBlocks.map( ( item, index ) => (
										<Fragment key={ item.clientId }>
											{ renderTopLevelItem(
												item,
												index
											) }
											<DropZone
												destination={ {
													type: 'deck',
													beforeId:
														deck.innerBlocks[
															index + 1
														]?.clientId ?? null,
												} }
												onDrop={ ( data ) =>
													handleTopLevelDrop(
														data,
														deck.innerBlocks[
															index + 1
														]?.clientId ?? null
													)
												}
											/>
										</Fragment>
									) ) }
								</ol>
							</nav>
						</div>
					) }
				</aside>,
				document.body
			) }
			{ deletingStack && (
				<Modal
					title={ __( 'Delete Nested Slides?', 'presenter' ) }
					onRequestClose={ () => setDeletingStack( null ) }
				>
					<p>
						{ sprintf(
							/* translators: %d: Number of Slides that will be deleted. */
							__(
								'This will delete all %d Slides in this Nested Slides group.',
								'presenter'
							),
							deletingStack.innerBlocks.length
						) }
					</p>
					<div className="presenter-delete-stack-actions">
						<Button
							variant="tertiary"
							onClick={ () => setDeletingStack( null ) }
						>
							{ __( 'Cancel', 'presenter' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ () => deleteStack( deletingStack ) }
						>
							{ __( 'Delete Nested Slides', 'presenter' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
