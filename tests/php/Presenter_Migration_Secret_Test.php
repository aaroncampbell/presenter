<?php
/**
 * Persistent migration secret tests.
 *
 * @package Presenter
 */

use Presenter\Migration_Secret;

require_once dirname( __DIR__, 2 ) . '/includes/class-migration-secret.php';

/**
 * Verify migration-secret reads and explicit creation semantics.
 */
final class Presenter_Migration_Secret_Test extends Presenter_Test_Case {
	/** Remove the private option after every test. */
	public function tear_down(): void {
		delete_option( Migration_Secret::OPTION_NAME );
		parent::tear_down();
	}

	/** Reading a missing secret does not create or mutate an option. */
	public function test_read_is_zero_write_when_secret_is_absent(): void {
		global $wpdb;

		delete_option( Migration_Secret::OPTION_NAME );
		$secret = new Migration_Secret();

		$this->assertNull( $secret->read() );
		$this->assertFalse( get_option( Migration_Secret::OPTION_NAME, false ) );
		$this->assertSame(
			'0',
			(string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
					Migration_Secret::OPTION_NAME
				)
			)
		);
	}

	/** Creation persists one stable option that WordPress will not autoload. */
	public function test_get_or_create_persists_one_stable_non_autoloaded_secret(): void {
		global $wpdb;

		delete_option( Migration_Secret::OPTION_NAME );
		$secret = new Migration_Secret();

		$created = $secret->get_or_create();
		$again   = $secret->get_or_create();
		wp_cache_flush();

		$this->assertIsString( $created );
		$this->assertGreaterThanOrEqual( 32, strlen( $created ) );
		$this->assertSame( $created, $again );
		$this->assertSame( $created, ( new Migration_Secret() )->read() );
		$this->assertSame(
			'1',
			(string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
					Migration_Secret::OPTION_NAME
				)
			)
		);

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				Migration_Secret::OPTION_NAME
			)
		);
		$this->assertIsString( $autoload );
		$this->assertNotContains( $autoload, wp_autoload_values_to_autoload() );
	}
}
