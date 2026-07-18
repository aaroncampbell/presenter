<?php
/**
 * Presenter migration deck-mode storage tests.
 *
 * @package Presenter
 */

use Presenter\Deck_Mode;
use Presenter\Migration_Deck_Mode_Store;

/**
 * Verify exact marker classification and ownership-aware cutover compensation.
 */
final class Presenter_Migration_Deck_Mode_Store_Test extends Presenter_Test_Case {
	/** Empty mode metadata can be cut over once and removed by its exact row ID. */
	public function test_exact_native_marker_lifecycle(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data();
		$store   = new Migration_Deck_Mode_Store();

		$this->assertSame(
			array(
				'state'    => Migration_Deck_Mode_Store::ABSENT,
				'rowCount' => 0,
			),
			$store->inspect( $post_id )
		);

		$meta_id = $store->create_native( $post_id );

		$this->assertIsInt( $meta_id );
		$this->assertSame(
			array(
				'state'    => Migration_Deck_Mode_Store::NATIVE,
				'rowCount' => 1,
			),
			$store->inspect( $post_id )
		);
		$this->assertTrue( $store->remove_created( $post_id, $meta_id ) );
		$this->assertSame( Migration_Deck_Mode_Store::ABSENT, $store->inspect( $post_id )['state'] );
	}

	/** Existing or ambiguous rows fail closed and are never overwritten. */
	public function test_existing_rows_prevent_marker_creation(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data();
		$store   = new Migration_Deck_Mode_Store();
		add_post_meta( $post_id, Deck_Mode::META_KEY, 'unexpected' );

		$this->assertSame( Migration_Deck_Mode_Store::INVALID, $store->inspect( $post_id )['state'] );
		$this->assertNull( $store->create_native( $post_id ) );

		add_post_meta( $post_id, Deck_Mode::META_KEY, Deck_Mode::NATIVE );

		$this->assertSame(
			array(
				'state'    => Migration_Deck_Mode_Store::INVALID,
				'rowCount' => 2,
			),
			$store->inspect( $post_id )
		);
		$this->assertNull( $store->create_native( $post_id ) );
	}

	/** Compensation never deletes another post's marker or a concurrent row. */
	public function test_removal_is_limited_to_the_created_row(): void {
		$post_id  = $this->create_slideshow_without_legacy_editor_post_data();
		$other_id = $this->create_slideshow_without_legacy_editor_post_data();
		$store    = new Migration_Deck_Mode_Store();
		$meta_id  = $store->create_native( $post_id );

		$this->assertIsInt( $meta_id );
		$this->assertFalse( $store->remove_created( $other_id, $meta_id ) );
		add_post_meta( $post_id, Deck_Mode::META_KEY, Deck_Mode::NATIVE );

		$this->assertFalse( $store->remove_created( $post_id, $meta_id ) );
		$this->assertSame(
			array(
				'state'    => Migration_Deck_Mode_Store::NATIVE,
				'rowCount' => 1,
			),
			$store->inspect( $post_id )
		);
	}

	/** Invalid post IDs expose no raw marker data and cannot be mutated. */
	public function test_invalid_post_fails_closed(): void {
		$store = new Migration_Deck_Mode_Store();

		$this->assertNull( $store->capture( 0 ) );
		$this->assertSame(
			array(
				'state'    => Migration_Deck_Mode_Store::INVALID,
				'rowCount' => 0,
			),
			$store->inspect( 0 )
		);
		$this->assertNull( $store->create_native( 0 ) );
		$this->assertFalse( $store->remove_created( 0, 0 ) );
	}
}
