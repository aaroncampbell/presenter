export const SLIDE_BLOCK_NAME = 'presenter/slide';
export const STACK_BLOCK_NAME = 'presenter/stack';

/**
 * Flatten a Deck's supported two-level Slide structure for navigation.
 *
 * @param {Object} deck Presenter Deck block.
 * @return {Object[]} Slide entries in presentation order.
 */
export function getSlideEntries( deck ) {
	const entries = [];

	for ( const [ horizontalIndex, item ] of (
		deck?.innerBlocks ?? []
	).entries() ) {
		if ( SLIDE_BLOCK_NAME === item.name ) {
			entries.push( {
				horizontalIndex,
				index: horizontalIndex,
				parentId: deck.clientId,
				position: `${ horizontalIndex + 1 }`,
				slide: item,
				stack: null,
			} );
			continue;
		}

		if ( STACK_BLOCK_NAME !== item.name ) {
			continue;
		}

		for ( const [ index, slide ] of item.innerBlocks.entries() ) {
			if ( SLIDE_BLOCK_NAME !== slide.name ) {
				continue;
			}

			entries.push( {
				horizontalIndex,
				index,
				isStackParent: 0 === index,
				parentId: item.clientId,
				position:
					0 === index
						? `${ horizontalIndex + 1 }`
						: `${ horizontalIndex + 1 }.${ index }`,
				slide,
				stack: item,
			} );
		}
	}

	return entries;
}

/**
 * Resolve a selected block or descendant to its containing Slide entry.
 *
 * @param {Object[]} entries         Flattened Slide entries.
 * @param {string}   selectedId      Selected block client ID.
 * @param {string[]} selectedParents Selected block ancestor client IDs.
 * @return {Object|undefined} Selected Slide entry.
 */
export function getSelectedSlideEntry(
	entries,
	selectedId,
	selectedParents = []
) {
	return entries.find(
		( entry ) =>
			entry.slide.clientId === selectedId ||
			selectedParents.includes( entry.slide.clientId )
	);
}

/**
 * Replace a top-level Slide with a Stack containing it and a new Slide.
 *
 * @param {Object[]} items       Deck children.
 * @param {string}   slideId     Top-level Slide client ID.
 * @param {Object}   stack       New Stack block.
 * @param {Object}   nestedSlide New nested Slide block.
 * @return {Object[]|null} Updated Deck children, or null when unavailable.
 */
export function wrapSlideInStack( items, slideId, stack, nestedSlide ) {
	const index = items.findIndex(
		( item ) => SLIDE_BLOCK_NAME === item.name && item.clientId === slideId
	);

	if ( -1 === index ) {
		return null;
	}

	const nextItems = [ ...items ];
	nextItems[ index ] = {
		...stack,
		innerBlocks: [ items[ index ], nestedSlide ],
	};

	return nextItems;
}

/**
 * Move a Slide beneath a top-level Slide, creating the required Stack.
 *
 * The moving Slide may already be nested. Removing it first applies the normal
 * Stack-unwrapping rules before the destination parent is wrapped.
 *
 * @param {Object[]} items         Deck children.
 * @param {string}   slideId       Moving Slide client ID.
 * @param {string}   parentSlideId Destination top-level Slide client ID.
 * @param {Object}   stack         New Stack block.
 * @return {Object[]|null} Updated Deck children, or null when unavailable.
 */
export function nestSlideUnder( items, slideId, parentSlideId, stack ) {
	if ( slideId === parentSlideId ) {
		return null;
	}

	const source = findSlide( items, slideId );
	const destination = findSlide( items, parentSlideId );

	if ( ! source || ! destination || destination.stack ) {
		return null;
	}

	const nextItems = removeSlideAt( items, source );

	return wrapSlideInStack( nextItems, parentSlideId, stack, source.slide );
}

/**
 * Insert a sibling immediately before or after an existing Slide.
 *
 * @param {Object[]} items      Deck children.
 * @param {string}   slideId    Existing Slide client ID.
 * @param {Object}   addedSlide New Slide block.
 * @param {number}   offset     Zero for before, one for after.
 * @return {Object[]|null} Updated Deck children, or null when unavailable.
 */
export function insertSlideRelative( items, slideId, addedSlide, offset ) {
	const location = findSlide( items, slideId );

	if ( ! location ) {
		return null;
	}

	const nextItems = [ ...items ];

	if ( ! location.stack ) {
		nextItems.splice( location.itemIndex + offset, 0, addedSlide );
		return nextItems;
	}

	const children = [ ...location.stack.innerBlocks ];
	children.splice( location.slideIndex + offset, 0, addedSlide );
	nextItems[ location.itemIndex ] = {
		...location.stack,
		innerBlocks: children,
	};

	return nextItems;
}

/**
 * Find a Slide and its supported parent in a Deck child list.
 *
 * @param {Object[]} items   Deck children.
 * @param {string}   slideId Slide client ID.
 * @return {Object|null} Slide location.
 */
function findSlide( items, slideId ) {
	for ( const [ itemIndex, item ] of items.entries() ) {
		if ( SLIDE_BLOCK_NAME === item.name && item.clientId === slideId ) {
			return {
				itemIndex,
				slide: item,
				stack: null,
				slideIndex: itemIndex,
			};
		}

		if ( STACK_BLOCK_NAME !== item.name ) {
			continue;
		}

		const slideIndex = item.innerBlocks.findIndex(
			( slide ) => slide.clientId === slideId
		);

		if ( -1 !== slideIndex ) {
			return {
				itemIndex,
				slide: item.innerBlocks[ slideIndex ],
				stack: item,
				slideIndex,
			};
		}
	}

	return null;
}

/**
 * Remove a Slide and normalize a group that can no longer be nested.
 *
 * A one-Slide Stack is accepted at the rendering boundary for defensive
 * compatibility, but editing transformations unwrap it to keep authoring
 * structure minimal.
 *
 * @param {Object[]} items    Deck children.
 * @param {Object}   location Result from findSlide().
 * @return {Object[]} Deck children without the Slide.
 */
function removeSlideAt( items, location ) {
	const nextItems = [ ...items ];

	if ( ! location.stack ) {
		nextItems.splice( location.itemIndex, 1 );
		return nextItems;
	}

	const children = [ ...location.stack.innerBlocks ];
	children.splice( location.slideIndex, 1 );

	if ( 0 === children.length ) {
		nextItems.splice( location.itemIndex, 1 );
	} else if ( 1 === children.length ) {
		nextItems.splice( location.itemIndex, 1, children[ 0 ] );
	} else {
		nextItems[ location.itemIndex ] = {
			...location.stack,
			innerBlocks: children,
		};
	}

	return nextItems;
}

/**
 * Move a top-level Deck item before another item (or to the end).
 *
 * @param {Object[]}    items        Deck children.
 * @param {string}      itemId       Moving item client ID.
 * @param {string|null} beforeItemId Destination item client ID, or null.
 * @return {Object[]|null} Updated children, or null for an invalid move.
 */
export function moveDeckItemBefore( items, itemId, beforeItemId = null ) {
	const sourceIndex = items.findIndex( ( item ) => item.clientId === itemId );

	if ( -1 === sourceIndex || itemId === beforeItemId ) {
		return null;
	}

	if (
		beforeItemId &&
		! items.some( ( candidate ) => candidate.clientId === beforeItemId )
	) {
		return null;
	}

	const nextItems = [ ...items ];
	const [ item ] = nextItems.splice( sourceIndex, 1 );
	const targetIndex = beforeItemId
		? nextItems.findIndex(
				( candidate ) => candidate.clientId === beforeItemId
		  )
		: nextItems.length;

	nextItems.splice( targetIndex, 0, item );
	return nextItems;
}

/**
 * Move a Slide to a top-level or Stack insertion boundary.
 *
 * Destinations use a stable block client ID rather than a numeric boundary so
 * removing and unwrapping the source cannot shift the requested target.
 *
 * @param {Object[]} items       Deck children.
 * @param {string}   slideId     Moving Slide client ID.
 * @param {Object}   destination Destination descriptor.
 * @return {Object[]|null} Updated children, or null for an invalid move.
 */
export function moveSlideTo( items, slideId, destination ) {
	const location = findSlide( items, slideId );

	if ( ! location ) {
		return null;
	}

	if ( 'deck' === destination.type && ! location.stack ) {
		return moveDeckItemBefore( items, slideId, destination.beforeId );
	}

	if (
		'stack' === destination.type &&
		location.stack?.clientId === destination.stackId
	) {
		const children = [ ...location.stack.innerBlocks ];
		const [ slide ] = children.splice( location.slideIndex, 1 );
		const targetIndex = destination.beforeId
			? children.findIndex(
					( candidate ) => candidate.clientId === destination.beforeId
			  )
			: children.length;

		if ( -1 === targetIndex ) {
			return null;
		}

		children.splice( targetIndex, 0, slide );
		const nextItems = [ ...items ];
		nextItems[ location.itemIndex ] = {
			...location.stack,
			innerBlocks: children,
		};
		return nextItems;
	}

	const nextItems = removeSlideAt( items, location );

	if ( 'deck' === destination.type ) {
		const targetIndex = destination.beforeId
			? nextItems.findIndex(
					( item ) => item.clientId === destination.beforeId
			  )
			: nextItems.length;

		if ( -1 === targetIndex ) {
			return null;
		}

		nextItems.splice( targetIndex, 0, location.slide );
		return nextItems;
	}

	if ( 'stack' !== destination.type ) {
		return null;
	}

	const stackIndex = nextItems.findIndex(
		( item ) =>
			STACK_BLOCK_NAME === item.name &&
			item.clientId === destination.stackId
	);

	if ( -1 === stackIndex ) {
		return null;
	}

	const stack = nextItems[ stackIndex ];
	const children = [ ...stack.innerBlocks ];
	const targetIndex = destination.beforeId
		? children.findIndex(
				( slide ) => slide.clientId === destination.beforeId
		  )
		: children.length;

	if ( -1 === targetIndex ) {
		return null;
	}

	children.splice( targetIndex, 0, location.slide );
	nextItems[ stackIndex ] = { ...stack, innerBlocks: children };
	return nextItems;
}

/**
 * Delete a Slide while applying Stack unwrapping rules.
 *
 * @param {Object[]} items   Deck children.
 * @param {string}   slideId Slide client ID.
 * @return {Object[]|null} Updated children, or null when unavailable.
 */
export function removeSlide( items, slideId ) {
	const location = findSlide( items, slideId );

	return location ? removeSlideAt( items, location ) : null;
}

/**
 * Find the immediately adjacent Stack available to a top-level Slide.
 *
 * @param {Object[]} items     Deck children.
 * @param {string}   slideId   Top-level Slide client ID.
 * @param {number}   direction -1 for before, 1 for after.
 * @return {Object|null} Adjacent Stack block.
 */
export function getAdjacentStack( items, slideId, direction ) {
	const index = items.findIndex( ( item ) => item.clientId === slideId );

	if ( -1 === index ) {
		return null;
	}

	const candidate = items[ index + direction ];

	return STACK_BLOCK_NAME === candidate?.name ? candidate : null;
}
