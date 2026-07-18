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
		$this->assertSame( $meta_id, $store->find_native( $post_id ) );
		$this->assertSame(
			array(
				'state'    => Migration_Deck_Mode_Store::NATIVE,
				'rowCount' => 1,
			),
			$store->inspect( $post_id )
		);
		$this->assertTrue( $store->remove_created( $post_id, $meta_id ) );
		$this->assertSame( Migration_Deck_Mode_Store::ABSENT, $store->inspect( $post_id )['state'] );
		$this->assertNull( $store->find_native( $post_id ) );
	}

	/** Only one exact native row can expose its private metadata ID. */
	public function test_native_lookup_rejects_absent_malformed_and_duplicate_storage(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data();
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$store   = new Migration_Deck_Mode_Store();

		$this->assertNull( $store->find_native( $post_id ) );
		add_post_meta( $page_id, Deck_Mode::META_KEY, Deck_Mode::NATIVE );
		$this->assertNull( $store->find_native( $page_id ) );
		add_post_meta( $post_id, Deck_Mode::META_KEY, 'unexpected' );
		$this->assertNull( $store->find_native( $post_id ) );

		delete_post_meta( $post_id, Deck_Mode::META_KEY );
		$first_id  = add_post_meta( $post_id, Deck_Mode::META_KEY, Deck_Mode::NATIVE );
		$second_id = add_post_meta( $post_id, Deck_Mode::META_KEY, Deck_Mode::NATIVE );

		$this->assertIsInt( $first_id );
		$this->assertIsInt( $second_id );
		$this->assertNull( $store->find_native( $post_id ) );
	}

	/** Lookup bypasses stale metadata cache and removal invalidates that cache. */
	public function test_native_lookup_and_removal_have_exact_cache_behavior(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data();
		$store   = new Migration_Deck_Mode_Store();

		$this->assertSame( array(), get_post_meta( $post_id, Deck_Mode::META_KEY, false ) );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulate an interrupted direct cutover while the pre-cutover metadata cache remains primed.
		$inserted = $wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => Deck_Mode::META_KEY,
				'meta_value' => Deck_Mode::NATIVE,
			),
			array( '%d', '%s', '%s' )
		);
		$meta_id  = (int) $wpdb->insert_id;

		$this->assertSame( 1, $inserted );
		$this->assertGreaterThan( 0, $meta_id );
		$this->assertSame( array(), get_post_meta( $post_id, Deck_Mode::META_KEY, false ) );
		$this->assertSame( $meta_id, $store->find_native( $post_id ) );
		$this->assertTrue( $store->remove_created( $post_id, $meta_id ) );
		$this->assertSame( array(), get_post_meta( $post_id, Deck_Mode::META_KEY, false ) );
	}

	/** Database exceptions fail closed without deleting an exact native marker. */
	public function test_lookup_and_removal_are_exception_safe(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data();
		$store   = new Migration_Deck_Mode_Store();
		$meta_id = $store->create_native( $post_id );

		$this->assertIsInt( $meta_id );

		$throw_query = static function (): never {
			throw new RuntimeException( 'Simulated database failure.' );
		};
		add_filter( 'query', $throw_query );

		try {
			$this->assertNull( $store->find_native( $post_id ) );
			$this->assertFalse( $store->remove_created( $post_id, $meta_id ) );
		} finally {
			remove_filter( 'query', $throw_query );
		}

		$this->assertSame( $meta_id, $store->find_native( $post_id ) );
	}

	/** A marker changed immediately before deletion is not removed. */
	public function test_removal_condition_is_atomic_against_marker_mutation(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data();
		$store   = new Migration_Deck_Mode_Store();
		$meta_id = $store->create_native( $post_id );

		$this->assertIsInt( $meta_id );

		global $wpdb;

		$mutate_before_delete = null;
		$mutate_before_delete = static function ( string $query ) use ( $wpdb, $meta_id, &$mutate_before_delete ): string {
			if ( ! str_starts_with( ltrim( $query ), 'DELETE FROM' ) ) {
				return $query;
			}

			remove_filter( 'query', $mutate_before_delete );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberately create the race that the production conditional DELETE must detect.
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => 'changed-concurrently' ),
				array( 'meta_id' => $meta_id ),
				array( '%s' ),
				array( '%d' )
			);

			return $query;
		};
		add_filter( 'query', $mutate_before_delete );

		try {
			$this->assertFalse( $store->remove_created( $post_id, $meta_id ) );
		} finally {
			remove_filter( 'query', $mutate_before_delete );
		}

		wp_cache_delete( $post_id, 'post_meta' );
		$this->assertSame( array( 'changed-concurrently' ), get_post_meta( $post_id, Deck_Mode::META_KEY, false ) );
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
		$this->assertNull( $store->find_native( $other_id ) );
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
		$this->assertNull( $store->find_native( 0 ) );
		$this->assertFalse( $store->remove_created( 0, 0 ) );
	}
}
