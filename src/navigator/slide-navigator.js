import {
	BlockPreview,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import {
	Button,
	Dropdown,
	DropdownMenu,
	MenuGroup,
	MenuItem,
	Modal,
} from '@wordpress/components';
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
	nestSlideUnder,
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
 * Create a Nested Slides Stack with its stable anchor before insertion.
 *
 * @return {Object} New Presenter Stack block.
 */
function createAnchoredStack() {
	const stack = createBlock( STACK_BLOCK_NAME );

	return {
		...stack,
		attributes: {
			...stack.attributes,
			anchor: `stack-${ stack.clientId }`,
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

/**
 * Build a stable UI key for one structural drag destination.
 *
 * @param {Object} destination Destination descriptor.
 * @return {string} Destination key.
 */
function getDropKey( destination ) {
	return [
		destination.type,
		destination.stackId ?? '',
		destination.beforeId ?? '',
		destination.targetSlideId ?? '',
	].join( ':' );
}

/**
 * Choose the source-card state for the current drag destination.
 *
 * @param {boolean} isDragging    Whether this item is the drag source.
 * @param {string}  activeDropKey Current destination key.
 * @return {string} Source-card class name.
 */
function getDragSourceClass( isDragging, activeDropKey ) {
	if ( ! isDragging ) {
		return '';
	}

	return activeDropKey
		? 'is-drag-source-collapsed'
		: 'is-drag-source-placeholder';
}

/**
 * Render a structural drop boundary.
 *
 * @param {Object}   props               Component properties.
 * @param {string}   props.activeDropKey Active destination key.
 * @param {Object}   props.dragState     Current Presenter drag, or null.
 * @param {Object}   props.destination   Destination descriptor.
 * @param {Function} props.onActivate    Set active destination.
 * @param {Function} props.onDrop        Drop callback.
 * @param {Function} props.onFinish      Clear drag state.
 * @return {Element} Drop target.
 */
function DropZone( {
	activeDropKey,
	dragState,
	destination,
	onActivate,
	onDrop,
	onFinish,
} ) {
	const dropKey = getDropKey( destination );
	const acceptsDragType = Boolean(
		dragState &&
			( 'deck' === destination.type || 'slide' === dragState.data.type )
	);
	const isOrigin = Boolean(
		acceptsDragType && dragState.noOpDropKeys?.includes( dropKey )
	);
	const acceptsDrag = acceptsDragType && ! isOrigin;
	const isActive = acceptsDrag && activeDropKey === dropKey;

	return (
		<li
			aria-hidden="true"
			className={ `presenter-slide-navigator-drop-zone ${
				acceptsDragType ? 'is-dragging' : ''
			} ${ isActive ? 'is-active' : '' }` }
			data-presenter-drop-before={ destination.beforeId ?? '' }
			data-presenter-drop-parent={ destination.stackId ?? '' }
			data-presenter-drop-type={ destination.type }
			role="presentation"
			style={
				isActive
					? {
							'--presenter-drag-placeholder-height': `${ dragState.height }px`,
					  }
					: undefined
			}
			onDragEnter={ ( event ) => {
				if ( ! acceptsDragType ) {
					return;
				}

				event.preventDefault();
				if ( isOrigin ) {
					onActivate( '' );
					return;
				}

				onActivate( dropKey );
			} }
			onDragOver={ ( event ) => {
				if ( ! acceptsDragType ) {
					return;
				}

				event.preventDefault();
				event.dataTransfer.dropEffect = 'move';
			} }
			onDrop={ ( event ) => {
				if ( ! acceptsDragType ) {
					return;
				}

				event.preventDefault();
				if ( ! isOrigin ) {
					onDrop( getDragData( event.dataTransfer ) );
				}
				onFinish();
			} }
		>
			{ isActive && (
				<span>{ __( 'Move Slide here', 'presenter' ) }</span>
			) }
		</li>
	);
}

/**
 * Render one native-style contextual menu for a Slide card.
 *
 * @param {Object}   props                Component properties.
 * @param {string}   props.label          Toggle accessibility label.
 * @param {Object[]} props.moveItems      Contextual move actions.
 * @param {Function} props.onDelete       Delete action.
 * @param {Function} props.onDuplicate    Duplicate action.
 * @param {Function} props.onVisibility   Hide/show action.
 * @param {boolean}  props.canDelete      Whether deletion is allowed.
 * @param {string}   props.visibilityText Hide/show menu text.
 * @return {Element} Slide action menu.
 */
function SlideActionMenu( {
	canDelete,
	label,
	moveItems,
	onDelete,
	onDuplicate,
	onVisibility,
	visibilityText,
} ) {
	const run = ( action, onClose ) => {
		onClose();
		action();
	};

	return (
		<DropdownMenu
			className="presenter-slide-navigator-menu"
			icon={
				<span aria-hidden="true" className="presenter-more-menu-icon">
					⋮
				</span>
			}
			label={ label }
			popoverProps={ { placement: 'bottom-end' } }
			toggleProps={ { size: 'compact' } }
		>
			{ ( { onClose } ) => (
				<>
					<MenuGroup label={ __( 'Move', 'presenter' ) }>
						{ moveItems.map( ( item ) => (
							<MenuItem
								key={ item.label }
								disabled={ item.disabled }
								onClick={ () => run( item.onClick, onClose ) }
							>
								{ item.label }
							</MenuItem>
						) ) }
					</MenuGroup>
					<MenuGroup>
						<MenuItem
							onClick={ () => run( onVisibility, onClose ) }
						>
							{ visibilityText }
						</MenuItem>
						<MenuItem onClick={ () => run( onDuplicate, onClose ) }>
							{ __( 'Duplicate', 'presenter' ) }
						</MenuItem>
					</MenuGroup>
					<MenuGroup>
						<MenuItem
							disabled={ ! canDelete }
							isDestructive
							onClick={ () => run( onDelete, onClose ) }
						>
							{ __( 'Delete', 'presenter' ) }
						</MenuItem>
					</MenuGroup>
				</>
			) }
		</DropdownMenu>
	);
}

/**
 * Render the right-edge target and resulting nested-position preview.
 *
 * @param {Object}      props               Component properties.
 * @param {string}      props.activeDropKey Active destination key.
 * @param {Object|null} props.dragState     Current Presenter drag.
 * @param {Object}      props.destination   Nest destination descriptor.
 * @param {string}      props.label         Visual destination label.
 * @param {Function}    props.onActivate    Set active destination.
 * @param {Function}    props.onDrop        Drop callback.
 * @param {Function}    props.onFinish      Clear drag state.
 * @return {Element|null} Nest target while dragging a Slide.
 */
function NestDropZone( {
	activeDropKey,
	dragState,
	destination,
	label,
	onActivate,
	onDrop,
	onFinish,
} ) {
	if (
		! dragState ||
		'slide' !== dragState.data.type ||
		dragState.data.id === destination.targetSlideId
	) {
		return null;
	}

	const dropKey = getDropKey( destination );
	const isActive = activeDropKey === dropKey;
	const previewHeight = Math.max( 96, Math.round( dragState.height * 0.75 ) );
	const activate = ( event ) => {
		event.preventDefault();
		event.stopPropagation();
		onActivate( dropKey );
	};
	const allowDrop = ( event ) => {
		event.preventDefault();
		event.stopPropagation();
		event.dataTransfer.dropEffect = 'move';
	};
	const completeDrop = ( event ) => {
		event.preventDefault();
		event.stopPropagation();
		onDrop( getDragData( event.dataTransfer ) );
		onFinish();
	};

	return (
		<>
			<div
				aria-hidden="true"
				className={ `presenter-slide-navigator-nest-zone ${
					isActive ? 'is-active' : ''
				}` }
				data-presenter-nest-target={ destination.targetSlideId }
				style={ {
					height: isActive
						? `calc(100% - ${ previewHeight + 8 }px)`
						: '100%',
				} }
				onDragEnter={ activate }
				onDragOver={ allowDrop }
				onDrop={ completeDrop }
			>
				<span>{ __( 'Nest', 'presenter' ) }</span>
			</div>
			{ isActive && (
				<div
					aria-hidden="true"
					className="presenter-slide-navigator-nest-preview"
					style={ {
						'--presenter-nest-placeholder-height': `${ previewHeight }px`,
					} }
					onDragEnter={ activate }
					onDragOver={ allowDrop }
					onDrop={ completeDrop }
				>
					<span>{ label }</span>
				</div>
			) }
		</>
	);
}

/**
 * Render an invisible left-edge target for moving a nested Slide to top level.
 *
 * @param {Object}      props               Component properties.
 * @param {string}      props.activeDropKey Active destination key.
 * @param {Object|null} props.dragState     Current Presenter drag.
 * @param {Function}    props.onActivate    Set active destination.
 * @param {Function}    props.onDrop        Drop callback.
 * @param {Function}    props.onFinish      Clear drag state.
 * @param {string}      props.stackId       Source Stack client ID.
 * @return {Element|null} Un-nest target for a Slide in this Stack.
 */
function UnnestDropZone( {
	activeDropKey,
	dragState,
	onActivate,
	onDrop,
	onFinish,
	stackId,
} ) {
	if (
		! dragState ||
		'slide' !== dragState.data.type ||
		dragState.data.sourceStackId !== stackId
	) {
		return null;
	}

	const destination = { type: 'unnest', stackId };
	const dropKey = getDropKey( destination );
	const isActive = activeDropKey === dropKey;

	return (
		<li
			aria-hidden="true"
			className={ `presenter-slide-navigator-unnest-zone ${
				isActive ? 'is-active' : ''
			}` }
			data-presenter-unnest-target={ stackId }
			role="presentation"
			onDragEnter={ ( event ) => {
				event.preventDefault();
				event.stopPropagation();
				onActivate( dropKey );
			} }
			onDragOver={ ( event ) => {
				event.preventDefault();
				event.stopPropagation();
				event.dataTransfer.dropEffect = 'move';
			} }
			onDrop={ ( event ) => {
				event.preventDefault();
				event.stopPropagation();
				onDrop( getDragData( event.dataTransfer ) );
				onFinish();
			} }
		>
			<span>{ __( 'Move to top level', 'presenter' ) }</span>
		</li>
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
	const [ activeDropKey, setActiveDropKey ] = useState( '' );
	const [ deletingStack, setDeletingStack ] = useState( null );
	const [ dragState, setDragState ] = useState( null );
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
		const anchoredStack = createAnchoredStack();
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
	const handleNestDrop = ( data, targetEntry ) => {
		if ( 'slide' !== data?.type ) {
			return;
		}

		if ( targetEntry.isStackParent ) {
			replaceDeckItems(
				moveSlideTo( deck.innerBlocks, data.id, {
					type: 'stack',
					stackId: targetEntry.stack.clientId,
					beforeId: null,
				} ),
				data.id
			);
			return;
		}

		replaceDeckItems(
			nestSlideUnder(
				deck.innerBlocks,
				data.id,
				targetEntry.slide.clientId,
				createAnchoredStack()
			),
			data.id
		);
	};
	const handleUnnestDrop = ( data, stackId ) => {
		if ( 'slide' !== data?.type || data.sourceStackId !== stackId ) {
			return;
		}

		const sourceItemIndex = deck.innerBlocks.findIndex(
			( item ) => item.clientId === stackId
		);
		const beforeId =
			deck.innerBlocks[ sourceItemIndex + 1 ]?.clientId ?? null;

		replaceDeckItems(
			moveSlideTo( deck.innerBlocks, data.id, {
				type: 'deck',
				beforeId,
			} ),
			data.id
		);
	};
	const finishDrag = () => {
		setActiveDropKey( '' );
		setDragState( null );
	};
	const startDrag = ( event, data, noOpDropKeys ) => {
		const serialized = JSON.stringify( data );
		const sourceElement =
			'stack' === data.type
				? event.currentTarget.closest(
						'.presenter-slide-navigator-stack'
				  )
				: event.currentTarget;
		const bounds = sourceElement.getBoundingClientRect();

		event.dataTransfer.effectAllowed = 'move';
		event.dataTransfer.setData( DRAG_DATA_TYPE, serialized );
		event.dataTransfer.setData( 'text/plain', serialized );
		setActiveDropKey( '' );
		setDragState( {
			data,
			height: Math.max( 72, Math.round( bounds.height ) ),
			noOpDropKeys,
		} );
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
		const dragDescriptor = dragData ?? {
			type: 'slide',
			id: slide.clientId,
			sourceStackId: entry.stack?.clientId ?? null,
		};
		const isDragging = Boolean(
			'stack' !== dragDescriptor.type &&
				dragState?.data.id === dragDescriptor.id
		);
		let originDestinations;

		if ( 'stack' === dragDescriptor.type ) {
			const sourceIndex = deck.innerBlocks.findIndex(
				( item ) => item.clientId === dragDescriptor.id
			);
			originDestinations = [
				{ type: 'deck', beforeId: dragDescriptor.id },
				{
					type: 'deck',
					beforeId:
						deck.innerBlocks[ sourceIndex + 1 ]?.clientId ?? null,
				},
			];
		} else if ( entry.stack ) {
			const sourceIndex = entry.stack.innerBlocks.findIndex(
				( candidate ) => candidate.clientId === slide.clientId
			);
			originDestinations = [
				{
					type: 'stack',
					stackId: entry.stack.clientId,
					beforeId: slide.clientId,
				},
				{
					type: 'stack',
					stackId: entry.stack.clientId,
					beforeId:
						entry.stack.innerBlocks[ sourceIndex + 1 ]?.clientId ??
						null,
				},
			];
		} else {
			const sourceIndex = deck.innerBlocks.findIndex(
				( item ) => item.clientId === slide.clientId
			);
			originDestinations = [
				{ type: 'deck', beforeId: slide.clientId },
				{
					type: 'deck',
					beforeId:
						deck.innerBlocks[ sourceIndex + 1 ]?.clientId ?? null,
				},
			];
		}

		const noOpDropKeys = originDestinations.map( getDropKey );
		const sourcePlaceholderClass = getDragSourceClass(
			isDragging,
			activeDropKey
		);
		const nestDestination = {
			type: 'nest',
			targetSlideId: slide.clientId,
			stackId: entry.isStackParent ? entry.stack.clientId : undefined,
		};
		const isNestTarget = activeDropKey === getDropKey( nestDestination );
		const moveItems = [
			{
				disabled: 0 === siblingIndex,
				label: __( 'Move up', 'presenter' ),
				onClick: () => moveSameParent( entry, siblingIndex - 1 ),
			},
			{
				disabled: siblings.length - 1 === siblingIndex,
				label: __( 'Move down', 'presenter' ),
				onClick: () => moveSameParent( entry, siblingIndex + 1 ),
			},
		];

		if ( entry.stack && ! entry.isStackParent ) {
			moveItems.push( {
				label: __( 'Move to top level', 'presenter' ),
				onClick: () => moveToTopLevel( entry ),
			} );
		}

		if ( previousStack ) {
			moveItems.push( {
				label: __( 'Move into Nested Slides before', 'presenter' ),
				onClick: () => moveIntoStack( entry, previousStack, false ),
			} );
		}

		if ( nextStack ) {
			moveItems.push( {
				label: __( 'Move into Nested Slides after', 'presenter' ),
				onClick: () => moveIntoStack( entry, nextStack, true ),
			} );
		}

		const menuLabel = stackActions
			? sprintf(
					/* translators: %d: Nested Slides group position. */
					__( 'Options for Nested Slides %d', 'presenter' ),
					entry.horizontalIndex + 1
			  )
			: sprintf(
					/* translators: %s: Slide position, such as 2 or 2.1. */
					__( 'Options for Slide %s', 'presenter' ),
					entry.position
			  );

		return (
			<li
				key={ slide.clientId }
				className={ [
					isCurrent ? 'is-current' : '',
					isDragging ? 'is-dragging' : '',
					sourcePlaceholderClass,
					isNestTarget ? 'is-nest-target' : '',
					className,
				]
					.filter( Boolean )
					.join( ' ' ) }
				role="treeitem"
				aria-selected={ isCurrent }
				data-presenter-drag-placeholder-label={
					isDragging
						? __( 'Move Slide here', 'presenter' )
						: undefined
				}
				data-presenter-slide-id={ slide.clientId }
				draggable
				style={
					isDragging
						? {
								'--presenter-drag-placeholder-height': `${ dragState.height }px`,
						  }
						: undefined
				}
				onDragEnd={ finishDrag }
				onDragStart={ ( event ) =>
					startDrag( event, dragDescriptor, noOpDropKeys )
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
				<SlideActionMenu
					canDelete={
						stackActions
							? entries.length > stackActions.innerBlocks.length
							: 1 < entries.length
					}
					label={ menuLabel }
					moveItems={ moveItems }
					onDelete={ () =>
						stackActions
							? setDeletingStack( stackActions )
							: deleteSlide( entry )
					}
					onDuplicate={ () =>
						stackActions
							? duplicateStack( stackActions )
							: duplicateSlide( entry )
					}
					onVisibility={ () =>
						updateBlockAttributes( slide.clientId, {
							hidden: ! isHidden,
						} )
					}
					visibilityText={
						isHidden
							? __( 'Show', 'presenter' )
							: __( 'Hide', 'presenter' )
					}
				/>
				{ ( ! entry.stack || entry.isStackParent ) && (
					<NestDropZone
						activeDropKey={ activeDropKey }
						destination={ nestDestination }
						dragState={ dragState }
						label={ sprintf(
							/* translators: %s: Parent Slide position. */
							__( 'Nest under Slide %s', 'presenter' ),
							entry.position
						) }
						onActivate={ setActiveDropKey }
						onDrop={ ( data ) => handleNestDrop( data, entry ) }
						onFinish={ finishDrag }
					/>
				) }
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
		const isDraggingStack = Boolean(
			'stack' === dragState?.data.type &&
				dragState.data.id === item.clientId
		);
		const stackSourcePlaceholderClass = getDragSourceClass(
			isDraggingStack,
			activeDropKey
		);
		const unnestDropKey = getDropKey( {
			type: 'unnest',
			stackId: item.clientId,
		} );
		const isUnnestTarget = activeDropKey === unnestDropKey;
		const keepUnnestTargetActive = ( event ) => {
			event.preventDefault();
			event.stopPropagation();
			setActiveDropKey( unnestDropKey );
		};
		const allowUnnestPreviewDrop = ( event ) => {
			event.preventDefault();
			event.stopPropagation();
			event.dataTransfer.dropEffect = 'move';
		};
		const completeUnnestPreviewDrop = ( event ) => {
			event.preventDefault();
			event.stopPropagation();
			handleUnnestDrop(
				getDragData( event.dataTransfer ),
				item.clientId
			);
			finishDrag();
		};

		return (
			<li
				key={ item.clientId }
				className={ [
					'presenter-slide-navigator-stack',
					isDraggingStack ? 'is-dragging' : '',
					stackSourcePlaceholderClass,
				]
					.filter( Boolean )
					.join( ' ' ) }
				data-presenter-drag-placeholder-label={
					isDraggingStack
						? __( 'Move Nested Slides here', 'presenter' )
						: undefined
				}
				role="none"
				style={
					isDraggingStack
						? {
								'--presenter-drag-placeholder-height': `${ dragState.height }px`,
						  }
						: undefined
				}
			>
				<ol role="group">
					<DropZone
						activeDropKey={ activeDropKey }
						destination={ {
							type: 'stack',
							stackId: item.clientId,
							beforeId: firstSlide?.clientId ?? null,
						} }
						dragState={ dragState }
						onActivate={ setActiveDropKey }
						onDrop={ ( data ) =>
							handleStackDrop(
								data,
								item.clientId,
								firstSlide?.clientId ?? null
							)
						}
						onFinish={ finishDrag }
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
						<UnnestDropZone
							activeDropKey={ activeDropKey }
							dragState={ dragState }
							onActivate={ setActiveDropKey }
							onDrop={ ( data ) =>
								handleUnnestDrop( data, item.clientId )
							}
							onFinish={ finishDrag }
							stackId={ item.clientId }
						/>
						<DropZone
							activeDropKey={ activeDropKey }
							destination={ {
								type: 'stack',
								stackId: item.clientId,
								beforeId:
									continuationSlides[ 0 ]?.clientId ?? null,
							} }
							dragState={ dragState }
							onActivate={ setActiveDropKey }
							onDrop={ ( data ) =>
								handleStackDrop(
									data,
									item.clientId,
									continuationSlides[ 0 ]?.clientId ?? null
								)
							}
							onFinish={ finishDrag }
						/>
						{ continuationSlides.map( ( slide, slideIndex ) => (
							<Fragment key={ slide.clientId }>
								{ renderSlide( findEntry( slide ) ) }
								<DropZone
									activeDropKey={ activeDropKey }
									destination={ {
										type: 'stack',
										stackId: item.clientId,
										beforeId:
											continuationSlides[ slideIndex + 1 ]
												?.clientId ?? null,
									} }
									dragState={ dragState }
									onActivate={ setActiveDropKey }
									onDrop={ ( data ) => {
										handleStackDrop(
											data,
											item.clientId,
											continuationSlides[ slideIndex + 1 ]
												?.clientId ?? null
										);
									} }
									onFinish={ finishDrag }
								/>
							</Fragment>
						) ) }
					</ol>
				) }
				{ isUnnestTarget && (
					<div
						aria-hidden="true"
						className="presenter-slide-navigator-unnest-preview"
						style={ {
							'--presenter-drag-placeholder-height': `${ dragState.height }px`,
						} }
						onDragEnter={ keepUnnestTargetActive }
						onDragOver={ allowUnnestPreviewDrop }
						onDrop={ completeUnnestPreviewDrop }
					>
						<span>
							{ __(
								'Move Slide to top level here',
								'presenter'
							) }
						</span>
					</div>
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
								className={ `presenter-slide-navigator ${
									dragState ? 'is-dragging' : ''
								}` }
								aria-label={ __(
									'Slide navigator',
									'presenter'
								) }
								onDragOver={ ( event ) => {
									if ( ! dragState ) {
										return;
									}

									const bounds =
										event.currentTarget.getBoundingClientRect();
									const threshold = 56;
									const pointerY = event.clientY;

									if ( pointerY < bounds.top + threshold ) {
										event.currentTarget.scrollTop -=
											Math.ceil(
												( bounds.top +
													threshold -
													pointerY ) /
													4
											);
									} else if (
										pointerY >
										bounds.bottom - threshold
									) {
										event.currentTarget.scrollTop +=
											Math.ceil(
												( pointerY -
													( bounds.bottom -
														threshold ) ) /
													4
											);
									}
								} }
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
										activeDropKey={ activeDropKey }
										destination={ {
											type: 'deck',
											beforeId:
												deck.innerBlocks[ 0 ]
													?.clientId ?? null,
										} }
										dragState={ dragState }
										onActivate={ setActiveDropKey }
										onDrop={ ( data ) =>
											handleTopLevelDrop(
												data,
												deck.innerBlocks[ 0 ]
													?.clientId ?? null
											)
										}
										onFinish={ finishDrag }
									/>
									{ deck.innerBlocks.map( ( item, index ) => (
										<Fragment key={ item.clientId }>
											{ renderTopLevelItem(
												item,
												index
											) }
											<DropZone
												activeDropKey={ activeDropKey }
												destination={ {
													type: 'deck',
													beforeId:
														deck.innerBlocks[
															index + 1
														]?.clientId ?? null,
												} }
												dragState={ dragState }
												onActivate={ setActiveDropKey }
												onDrop={ ( data ) =>
													handleTopLevelDrop(
														data,
														deck.innerBlocks[
															index + 1
														]?.clientId ?? null
													)
												}
												onFinish={ finishDrag }
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
