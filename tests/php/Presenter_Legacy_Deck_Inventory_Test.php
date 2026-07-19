<?php
/**
 * Legacy deck inventory tests.
 *
 * @package Presenter
 */

use Presenter\Legacy_Deck_Inventory;

/** Verify bounded, filter-independent migration discovery. */
final class Presenter_Legacy_Deck_Inventory_Test extends Presenter_Test_Case {
	/** The inventory includes every eligible deck in deterministic pages. */
	public function test_inventory_is_complete_ordered_and_bounded(): void {
		$first = $this->legacy_deck( 'publish' );
		$this->create_slideshow_without_legacy_editor_post_data( array( 'post_status' => 'publish' ) );
		$protected = $this->legacy_deck( 'publish', 'local-password' );
		$draft     = $this->legacy_deck( 'draft' );
		$this->legacy_deck( 'trash' );

		add_action(
			'pre_get_posts',
			static function ( WP_Query $query ): void {
				$query->set( 'post__in', array( 0 ) );
			}
		);

		$inventory = new Legacy_Deck_Inventory();

		$this->assertSame( 3, $inventory->count() );
		$this->assertSame( array( $first, $protected ), $inventory->ids( 2 ) );
		$this->assertSame( array( $draft ), $inventory->ids( 2, 2 ) );
	}

	/** Invalid page bounds fail before querying WordPress. */
	public function test_inventory_rejects_invalid_bounds(): void {
		$inventory = new Legacy_Deck_Inventory();

		foreach ( array( array( 0, 0 ), array( 101, 0 ), array( 1, -1 ) ) as $arguments ) {
			try {
				$inventory->ids( $arguments[0], $arguments[1] );
				$this->fail( 'Invalid inventory bounds should throw.' );
			} catch ( InvalidArgumentException $exception ) {
				$this->assertStringContainsString( 'inventory', $exception->getMessage() );
			}
		}
	}

	/**
	 * Create one legacy deck without invoking the classic-editor save path.
	 *
	 * @param string $status   Post status.
	 * @param string $password Optional post password.
	 * @return int Slideshow post ID.
	 */
	private function legacy_deck( string $status, string $password = '' ): int {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_status'   => $status,
				'post_password' => $password,
			)
		);
		$this->add_legacy_slide_fixture( $post_id );

		return $post_id;
	}
}
