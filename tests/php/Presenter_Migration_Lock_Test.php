<?php
/**
 * Presenter migration lock integration tests.
 *
 * @package Presenter
 */

use Presenter\Migration_Lock;
use Presenter\Migration_Lock_Handle;

/**
 * Verify migration ownership is atomic, expiring, and safe to release.
 */
final class Presenter_Migration_Lock_Test extends Presenter_Test_Case {
	/**
	 * Invalid post IDs cannot own a migration lock.
	 *
	 * @dataProvider invalid_post_ids
	 *
	 * @param int $post_id Invalid post ID.
	 */
	public function test_invalid_post_id_cannot_acquire_lock( int $post_id ): void {
		$lock = new Migration_Lock();

		$this->assertNull( $lock->acquire( $post_id ) );
	}

	/**
	 * Provide invalid post IDs at the public boundary.
	 *
	 * @return array<string, array{int}>
	 */
	public function invalid_post_ids(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -1 ),
		);
	}

	/**
	 * Acquisition returns an owner handle and creates one option for the post.
	 */
	public function test_acquisition_returns_handle_backed_by_one_option(): void {
		$post_id        = self::factory()->post->create();
		$before_options = $this->option_names();
		$now            = 1_700_000_000;
		$lock           = new Migration_Lock( static fn(): int => $now );
		$handle         = $lock->acquire( $post_id );
		$created        = array_values( array_diff( $this->option_names(), $before_options ) );

		$this->assertInstanceOf( Migration_Lock_Handle::class, $handle );
		$this->assertSame( $post_id, $handle->post_id() );
		$this->assertIsString( $handle->token() );
		$this->assertNotSame( '', $handle->token() );
		$this->assertGreaterThan( $now, $handle->expires_at() );
		$this->assertCount( 1, $created, 'One acquisition must create exactly one WordPress option.' );

		$this->assertTrue( $lock->release( $handle ) );
		$this->assertSame( array(), array_values( array_diff( $this->option_names(), $before_options ) ) );
	}

	/**
	 * A live owner rejects a second acquisition for the same post.
	 */
	public function test_live_lock_rejects_contention(): void {
		$post_id = self::factory()->post->create();
		$owner   = new Migration_Lock();
		$rival   = new Migration_Lock();
		$handle  = $owner->acquire( $post_id );

		$this->assertInstanceOf( Migration_Lock_Handle::class, $handle );
		$this->assertNull( $rival->acquire( $post_id ) );
		$this->assertTrue( $owner->release( $handle ) );
	}

	/**
	 * Lock ownership is scoped independently to each post.
	 */
	public function test_different_posts_can_be_locked_at_the_same_time(): void {
		$first_post  = self::factory()->post->create();
		$second_post = self::factory()->post->create();
		$lock        = new Migration_Lock();
		$first       = $lock->acquire( $first_post );
		$second      = $lock->acquire( $second_post );

		$this->assertInstanceOf( Migration_Lock_Handle::class, $first );
		$this->assertInstanceOf( Migration_Lock_Handle::class, $second );
		$this->assertSame( $first_post, $first->post_id() );
		$this->assertSame( $second_post, $second->post_id() );
		$this->assertTrue( $lock->release( $first ) );
		$this->assertTrue( $lock->release( $second ) );
	}

	/**
	 * Releasing an owner is single-use and allows a later acquisition.
	 */
	public function test_matching_owner_can_release_lock_once(): void {
		$post_id = self::factory()->post->create();
		$lock    = new Migration_Lock();
		$handle  = $lock->acquire( $post_id );

		$this->assertInstanceOf( Migration_Lock_Handle::class, $handle );
		$this->assertTrue( $lock->release( $handle ) );
		$this->assertFalse( $lock->release( $handle ) );
		$this->assertInstanceOf( Migration_Lock_Handle::class, $lock->acquire( $post_id ) );
	}

	/**
	 * Expired ownership can be reclaimed without empowering the stale handle.
	 */
	public function test_expired_lock_can_be_reclaimed_without_stale_owner_deleting_replacement(): void {
		$now     = 1_700_000_000;
		$clock   = static function () use ( &$now ): int {
			return $now;
		};
		$post_id = self::factory()->post->create();
		$lock    = new Migration_Lock( $clock );
		$stale   = $lock->acquire( $post_id );

		$this->assertInstanceOf( Migration_Lock_Handle::class, $stale );
		$now         = $stale->expires_at() + 1;
		$replacement = $lock->acquire( $post_id );

		$this->assertInstanceOf( Migration_Lock_Handle::class, $replacement );
		$this->assertFalse( $lock->release( $stale ), 'A stale owner must not release its replacement.' );
		$this->assertNull( $lock->acquire( $post_id ), 'The replacement must remain locked after a stale release.' );
		$this->assertTrue( $lock->release( $replacement ) );
	}

	/**
	 * Read the current option names without depending on the lock's private key.
	 *
	 * @return array<int, string>
	 */
	private function option_names(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The atomic option footprint is the behavior under test.
		return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" );
	}
}
