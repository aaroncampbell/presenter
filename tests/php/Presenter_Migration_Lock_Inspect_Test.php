<?php
/**
 * Content-free migration lock inspection tests.
 *
 * @package Presenter
 */

use Presenter\Migration_Lock;
use Presenter\Migration_Lock_Handle;

/**
 * Verify read-only lock status without exposing ownership records.
 */
final class Presenter_Migration_Lock_Inspect_Test extends Presenter_Test_Case {
	/** An unlocked post reports an exact content-free shape without writing. */
	public function test_unlocked_inspection_is_content_free_and_zero_write(): void {
		$post_id = self::factory()->post->create();
		$before  = $this->option_names();

		$status = ( new Migration_Lock() )->inspect( $post_id );

		$this->assertSame(
			array(
				'state'            => 'unlocked',
				'secondsRemaining' => 0,
			),
			$status
		);
		$this->assertSame( $before, $this->option_names() );
		$this->assert_content_free_shape( $status );
	}

	/** Active status follows the injected clock and never exposes ownership. */
	public function test_active_inspection_reports_exact_remaining_seconds(): void {
		$now     = 1_700_000_000;
		$clock   = static function () use ( &$now ): int {
			return $now;
		};
		$post_id = self::factory()->post->create();
		$lock    = new Migration_Lock( $clock );
		$before  = $this->option_names();
		$handle  = $lock->acquire( $post_id );

		$this->assertInstanceOf( Migration_Lock_Handle::class, $handle );
		$option_name = $this->created_option_name( $before );
		$record      = get_option( $option_name, null );
		$initial     = $lock->inspect( $post_id );
		$this->assertSame(
			array(
				'state'            => 'active',
				'secondsRemaining' => $handle->expires_at() - $now,
			),
			$initial
		);

		$now  += 47;
		$later = $lock->inspect( $post_id );
		$this->assertSame( $handle->expires_at() - $now, $later['secondsRemaining'] );
		$this->assertSame( 'active', $later['state'] );
		$this->assert_content_free_shape( $later );
		$this->assertStringNotContainsString( $handle->token(), wp_json_encode( $later ) );
		$this->assertSame( $record, get_option( $option_name, null ) );

		$this->assertTrue( $lock->release( $handle ) );
	}

	/** Expiry inspection does not reclaim, replace, or delete the record. */
	public function test_expired_inspection_is_read_only_and_does_not_reclaim(): void {
		$now     = 1_700_000_000;
		$clock   = static function () use ( &$now ): int {
			return $now;
		};
		$post_id = self::factory()->post->create();
		$lock    = new Migration_Lock( $clock );
		$before  = $this->option_names();
		$handle  = $lock->acquire( $post_id );

		$this->assertInstanceOf( Migration_Lock_Handle::class, $handle );
		$option_name = $this->created_option_name( $before );
		$record      = get_option( $option_name, null );
		$now         = $handle->expires_at();

		$this->assertSame(
			array(
				'state'            => 'expired',
				'secondsRemaining' => 0,
			),
			$lock->inspect( $post_id )
		);
		$this->assertSame( $record, get_option( $option_name, null ) );
		$this->assertSame(
			array(
				'state'            => 'expired',
				'secondsRemaining' => 0,
			),
			$lock->inspect( $post_id )
		);
		$this->assertSame( $record, get_option( $option_name, null ) );

		$replacement = $lock->acquire( $post_id );
		$this->assertInstanceOf( Migration_Lock_Handle::class, $replacement, 'Only acquisition may reclaim an expired record.' );
		$this->assertNotSame( $handle->token(), $replacement->token() );
		$this->assertTrue( $lock->release( $replacement ) );
	}

	/** Malformed stored ownership fails closed and remains untouched. */
	public function test_invalid_record_reports_invalid_without_rewriting(): void {
		$post_id = self::factory()->post->create();
		$lock    = new Migration_Lock( static fn(): int => 1_700_000_000 );
		$before  = $this->option_names();
		$handle  = $lock->acquire( $post_id );

		$this->assertInstanceOf( Migration_Lock_Handle::class, $handle );
		$option_name = $this->created_option_name( $before );
		$invalid     = '{"schemaVersion":1,"token":"private-token-sentinel"}';
		update_option( $option_name, $invalid, false );

		$status = $lock->inspect( $post_id );

		$this->assertSame(
			array(
				'state'            => 'invalid',
				'secondsRemaining' => 0,
			),
			$status
		);
		$this->assertSame( $invalid, get_option( $option_name, null ) );
		$this->assert_content_free_shape( $status );
		$this->assertStringNotContainsString( 'private-token-sentinel', wp_json_encode( $status ) );
		$this->assertStringNotContainsString( $option_name, wp_json_encode( $status ) );

		delete_option( $option_name );
	}

	/**
	 * Nonpositive IDs are invalid and never create an option.
	 *
	 * @dataProvider invalid_post_ids
	 *
	 * @param int $post_id Invalid post ID.
	 */
	public function test_invalid_post_id_is_content_free_and_zero_write( int $post_id ): void {
		$before = $this->option_names();
		$status = ( new Migration_Lock() )->inspect( $post_id );

		$this->assertSame(
			array(
				'state'            => 'invalid',
				'secondsRemaining' => 0,
			),
			$status
		);
		$this->assertSame( $before, $this->option_names() );
		$this->assert_content_free_shape( $status );
	}

	/** Provide invalid lock IDs. */
	public function invalid_post_ids(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -1 ),
		);
	}

	/**
	 * Assert the public result has exactly the safe status fields.
	 *
	 * @param array<string, mixed> $status Lock status.
	 */
	private function assert_content_free_shape( array $status ): void {
		$this->assertSame( array( 'state', 'secondsRemaining' ), array_keys( $status ) );
		$this->assertArrayNotHasKey( 'token', $status );
		$this->assertArrayNotHasKey( 'optionName', $status );
	}

	/**
	 * Find the one option created by lock acquisition.
	 *
	 * @param array<int, string> $before Option names before acquisition.
	 */
	private function created_option_name( array $before ): string {
		$created = array_values( array_diff( $this->option_names(), $before ) );
		$this->assertCount( 1, $created );

		return $created[0];
	}

	/** Read option names without depending on the lock's private key format. */
	private function option_names(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The read-only option footprint is the behavior under test.
		return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} ORDER BY option_name" );
	}
}
