<?php
/**
 * Domain-separated migration hasher tests.
 *
 * @package Presenter
 */

use Presenter\Migration_Hasher;

require_once dirname( __DIR__, 2 ) . '/includes/class-migration-value-encoder.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-migration-hasher.php';

/**
 * Verify safe deterministic hashes for migration integrity checks.
 */
final class Presenter_Migration_Hasher_Test extends Presenter_Test_Case {
	/** Hashes are deterministic lowercase SHA-256 hex values. */
	public function test_hash_is_deterministic_lowercase_hex(): void {
		$hasher = new Migration_Hasher( 'test-secret-that-remains-private' );
		$value  = array( 'ordered', 2, true );

		$first = $hasher->hash( 'backup-payload', $value );

		$this->assertSame( $first, $hasher->hash( 'backup-payload', $value ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $first );
	}

	/** Purpose, secret, PHP type, and value each affect the digest. */
	public function test_hash_is_domain_separated_and_type_sensitive(): void {
		$value  = array( 'value' => 1 );
		$hasher = new Migration_Hasher( 'first-test-secret' );

		$this->assertNotSame(
			$hasher->hash( 'backup-payload', $value ),
			$hasher->hash( 'journal-event', $value )
		);
		$this->assertNotSame(
			$hasher->hash( 'backup-payload', $value ),
			( new Migration_Hasher( 'second-test-secret' ) )->hash( 'backup-payload', $value )
		);
		$this->assertNotSame(
			$hasher->hash( 'backup-payload', 1 ),
			$hasher->hash( 'backup-payload', '1' )
		);
	}

	/** The digest never returns the secret, purpose, or authored value. */
	public function test_hash_does_not_expose_private_inputs(): void {
		$secret   = 'migration-secret-sentinel';
		$purpose  = 'purpose-sentinel';
		$authored = 'authored-content-sentinel';
		$digest   = ( new Migration_Hasher( $secret ) )->hash( $purpose, $authored );

		$this->assertStringNotContainsString( $secret, $digest );
		$this->assertStringNotContainsString( $purpose, $digest );
		$this->assertStringNotContainsString( $authored, $digest );
	}
}
