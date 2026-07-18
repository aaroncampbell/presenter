<?php
/**
 * Migration lock renewal tests.
 *
 * @package Presenter
 */

use Presenter\Migration_Lock;
use Presenter\Migration_Lock_Handle;

/**
 * Verify atomic lease extension and ownership-version safety.
 */
final class Presenter_Migration_Lock_Renew_Test extends Presenter_Test_Case {
	/** Renewal extends a live lease while preserving its private owner token. */
	public function test_live_owner_can_atomically_extend_lease(): void {
		$now     = 1_700_000_000;
		$clock   = static function () use ( &$now ): int {
			return $now;
		};
		$post_id = self::factory()->post->create();
		$lock    = new Migration_Lock( $clock );
		$owner   = $lock->acquire( $post_id );

		$this->assertInstanceOf( Migration_Lock_Handle::class, $owner );
		$now    += 60;
		$renewed = $lock->renew( $owner );

		$this->assertInstanceOf( Migration_Lock_Handle::class, $renewed );
		$this->assertNotSame( $owner, $renewed );
		$this->assertSame( $owner->post_id(), $renewed->post_id() );
		$this->assertSame( $owner->token(), $renewed->token() );
		$this->assertGreaterThan( $owner->expires_at(), $renewed->expires_at() );
		$this->assertSame(
			array(
				'state'            => 'active',
				'secondsRemaining' => $renewed->expires_at() - $now,
			),
			$lock->inspect( $post_id )
		);

		$this->assertFalse( $lock->release( $owner ), 'The pre-renewal ownership version must be stale.' );
		$this->assertNull( $lock->renew( $owner ), 'The pre-renewal handle cannot extend the renewed version.' );
		$this->assertTrue( $lock->release( $renewed ) );
	}

	/** Renewal at expiry fails read-only and only acquisition may install a successor. */
	public function test_expired_owner_cannot_renew_or_affect_successor(): void {
		$now     = 1_700_000_000;
		$clock   = static function () use ( &$now ): int {
			return $now;
		};
		$post_id = self::factory()->post->create();
		$lock    = new Migration_Lock( $clock );
		$before  = $this->option_names();
		$stale   = $lock->acquire( $post_id );

		$this->assertInstanceOf( Migration_Lock_Handle::class, $stale );
		$option_name = $this->created_option_name( $before );
		$record      = get_option( $option_name, null );
		$now         = $stale->expires_at();

		$this->assertNull( $lock->renew( $stale ) );
		$this->assertSame( $record, get_option( $option_name, null ), 'Expired renewal must not reclaim or rewrite.' );
		$successor = $lock->acquire( $post_id );
		$this->assertInstanceOf( Migration_Lock_Handle::class, $successor );
		$this->assertNotSame( $stale->token(), $successor->token() );

		$this->assertNull( $lock->renew( $stale ) );
		$this->assertFalse( $lock->release( $stale ) );
		$this->assertSame( 'active', $lock->inspect( $post_id )['state'] );
		$this->assertTrue( $lock->release( $successor ) );
	}

	/** A fabricated owner cannot extend or release another owner's live lease. */
	public function test_wrong_owner_cannot_renew_or_release_live_lease(): void {
		$now     = 1_700_000_000;
		$post_id = self::factory()->post->create();
		$lock    = new Migration_Lock( static fn(): int => $now );
		$owner   = $lock->acquire( $post_id );

		$this->assertInstanceOf( Migration_Lock_Handle::class, $owner );
		$wrong_owner = new Migration_Lock_Handle(
			$post_id,
			'33333333-3333-4333-8333-333333333333',
			$owner->expires_at()
		);

		$this->assertNull( $lock->renew( $wrong_owner ) );
		$this->assertFalse( $lock->release( $wrong_owner ) );
		$this->assertSame( 'active', $lock->inspect( $post_id )['state'] );
		$this->assertTrue( $lock->release( $owner ) );
	}

	/** A matching token with an obsolete expiry is still a stale ownership version. */
	public function test_matching_token_with_stale_expiry_cannot_mutate_current_record(): void {
		$now     = 1_700_000_000;
		$clock   = static function () use ( &$now ): int {
			return $now;
		};
		$post_id = self::factory()->post->create();
		$lock    = new Migration_Lock( $clock );
		$owner   = $lock->acquire( $post_id );

		$this->assertInstanceOf( Migration_Lock_Handle::class, $owner );
		$now    += 30;
		$current = $lock->renew( $owner );
		$this->assertInstanceOf( Migration_Lock_Handle::class, $current );

		$stale_version = new Migration_Lock_Handle(
			$current->post_id(),
			$current->token(),
			$current->expires_at() - 1
		);
		$this->assertNull( $lock->renew( $stale_version ) );
		$this->assertFalse( $lock->release( $stale_version ) );
		$this->assertTrue( $lock->release( $current ) );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The atomic option footprint is the behavior under test.
		return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} ORDER BY option_name" );
	}
}
