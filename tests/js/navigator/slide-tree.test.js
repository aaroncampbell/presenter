import {
	getAdjacentStack,
	getSelectedSlideEntry,
	getSlideEntries,
	insertSlideRelative,
	moveDeckItemBefore,
	moveSlideTo,
	nestSlideUnder,
	removeSlide,
	wrapSlideInStack,
} from '../../../src/navigator/slide-tree';

const slide = ( id ) => ( {
	name: 'presenter/slide',
	clientId: id,
	attributes: {},
	innerBlocks: [],
} );
const stack = ( id, children ) => ( {
	name: 'presenter/stack',
	clientId: id,
	attributes: {},
	innerBlocks: children,
} );
const deck = ( children ) => ( {
	name: 'presenter/deck',
	clientId: 'deck',
	attributes: {},
	innerBlocks: children,
} );

describe( 'nested Slide tree utilities', () => {
	it( 'flattens supported Slides with dotted positions', () => {
		const entries = getSlideEntries(
			deck( [
				slide( 'one' ),
				stack( 'group', [ slide( 'two-a' ), slide( 'two-b' ) ] ),
				slide( 'three' ),
			] )
		);

		expect( entries.map( ( entry ) => entry.position ) ).toEqual( [
			'1',
			'2',
			'2.1',
			'3',
		] );
		expect( entries[ 1 ].isStackParent ).toBe( true );
		expect( entries[ 2 ].isStackParent ).toBe( false );
		expect( entries[ 2 ].parentId ).toBe( 'group' );
		expect( entries[ 2 ].stack.clientId ).toBe( 'group' );
	} );

	it( 'resolves a selected Slide from a descendant', () => {
		const entries = getSlideEntries(
			deck( [ stack( 'group', [ slide( 'nested' ) ] ) ] )
		);

		expect(
			getSelectedSlideEntry( entries, 'paragraph', [
				'nested',
				'group',
				'deck',
			] ).slide.clientId
		).toBe( 'nested' );
	} );

	it( 'wraps only a top-level Slide as a two-Slide Stack', () => {
		const original = slide( 'original' );
		const added = slide( 'added' );
		const group = stack( 'group', [] );
		const result = wrapSlideInStack(
			[ original ],
			'original',
			group,
			added
		);

		expect( result[ 0 ].clientId ).toBe( 'group' );
		expect( result[ 0 ].innerBlocks ).toEqual( [ original, added ] );
		expect(
			wrapSlideInStack(
				[ stack( 'existing', [ original ] ) ],
				'original',
				group,
				added
			)
		).toBeNull();
	} );

	it( 'inserts before and after at the existing Slide level', () => {
		const topLevel = insertSlideRelative(
			[ slide( 'one' ), slide( 'three' ) ],
			'three',
			slide( 'two' ),
			0
		);
		const nested = insertSlideRelative(
			[ stack( 'group', [ slide( 'a' ), slide( 'c' ) ] ) ],
			'a',
			slide( 'b' ),
			1
		);

		expect( topLevel.map( ( item ) => item.clientId ) ).toEqual( [
			'one',
			'two',
			'three',
		] );
		expect(
			nested[ 0 ].innerBlocks.map( ( item ) => item.clientId )
		).toEqual( [ 'a', 'b', 'c' ] );
	} );

	it( 'reorders top-level Slides and groups by stable boundaries', () => {
		const result = moveDeckItemBefore(
			[ slide( 'one' ), stack( 'group', [] ), slide( 'three' ) ],
			'one',
			null
		);

		expect( result.map( ( item ) => item.clientId ) ).toEqual( [
			'group',
			'three',
			'one',
		] );
	} );

	it( 'moves a top-level Slide into an adjacent group', () => {
		const result = moveSlideTo(
			[ slide( 'one' ), stack( 'group', [ slide( 'nested' ) ] ) ],
			'one',
			{ type: 'stack', stackId: 'group', beforeId: 'nested' }
		);

		expect( result ).toHaveLength( 1 );
		expect(
			result[ 0 ].innerBlocks.map( ( item ) => item.clientId )
		).toEqual( [ 'one', 'nested' ] );
	} );

	it( 'moves a nested Slide to the top level and unwraps its source', () => {
		const result = moveSlideTo(
			[
				slide( 'one' ),
				stack( 'group', [ slide( 'nested-a' ), slide( 'nested-b' ) ] ),
				slide( 'three' ),
			],
			'nested-a',
			{ type: 'deck', beforeId: 'three' }
		);

		expect( result.map( ( item ) => item.clientId ) ).toEqual( [
			'one',
			'nested-b',
			'nested-a',
			'three',
		] );
	} );

	it( 'moves Slides between groups and reorders within one group', () => {
		const items = [
			stack( 'first', [ slide( 'a' ), slide( 'b' ), slide( 'c' ) ] ),
			stack( 'second', [ slide( 'd' ), slide( 'e' ) ] ),
		];
		const reordered = moveSlideTo( items, 'c', {
			type: 'stack',
			stackId: 'first',
			beforeId: 'a',
		} );
		const moved = moveSlideTo( reordered, 'a', {
			type: 'stack',
			stackId: 'second',
			beforeId: 'e',
		} );

		expect(
			moved[ 0 ].innerBlocks.map( ( item ) => item.clientId )
		).toEqual( [ 'c', 'b' ] );
		expect(
			moved[ 1 ].innerBlocks.map( ( item ) => item.clientId )
		).toEqual( [ 'd', 'a', 'e' ] );
	} );

	it( 'nests a top-level Slide beneath another top-level Slide', () => {
		const result = nestSlideUnder(
			[ slide( 'one' ), slide( 'two' ), slide( 'three' ) ],
			'three',
			'one',
			stack( 'new-group', [] )
		);

		expect( result.map( ( item ) => item.clientId ) ).toEqual( [
			'new-group',
			'two',
		] );
		expect(
			result[ 0 ].innerBlocks.map( ( item ) => item.clientId )
		).toEqual( [ 'one', 'three' ] );
	} );

	it( 'moves an existing nested Slide beneath a new parent', () => {
		const result = nestSlideUnder(
			[
				stack( 'old-group', [ slide( 'a' ), slide( 'b' ) ] ),
				slide( 'parent' ),
			],
			'b',
			'parent',
			stack( 'new-group', [] )
		);

		expect( result.map( ( item ) => item.clientId ) ).toEqual( [
			'a',
			'new-group',
		] );
		expect(
			result[ 1 ].innerBlocks.map( ( item ) => item.clientId )
		).toEqual( [ 'parent', 'b' ] );
	} );

	it( 'rejects unsupported nesting destinations', () => {
		const items = [
			stack( 'group', [ slide( 'parent' ), slide( 'nested' ) ] ),
			slide( 'other' ),
		];

		expect(
			nestSlideUnder(
				items,
				'other',
				'nested',
				stack( 'new-group', [] )
			)
		).toBeNull();
		expect(
			nestSlideUnder(
				items,
				'other',
				'other',
				stack( 'new-group', [] )
			)
		).toBeNull();
	} );

	it( 'unwraps a group when deleting one of its final two Slides', () => {
		const result = removeSlide(
			[ slide( 'one' ), stack( 'group', [ slide( 'a' ), slide( 'b' ) ] ) ],
			'a'
		);

		expect( result.map( ( item ) => item.clientId ) ).toEqual( [
			'one',
			'b',
		] );
	} );

	it( 'finds only immediately adjacent groups', () => {
		const items = [
			stack( 'before', [] ),
			slide( 'selected' ),
			stack( 'after', [] ),
		];

		expect( getAdjacentStack( items, 'selected', -1 ).clientId ).toBe(
			'before'
		);
		expect( getAdjacentStack( items, 'selected', 1 ).clientId ).toBe(
			'after'
		);
		expect( getAdjacentStack( items, 'missing', 1 ) ).toBeNull();
	} );
} );
