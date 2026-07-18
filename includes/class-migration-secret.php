<?php
/**
 * Persistent Presenter migration signing secret.
 *
 * @package Presenter
 */

namespace Presenter;

use RuntimeException;

/**
 * Reads or explicitly creates the site-local migration signing secret.
 */
final class Migration_Secret {
	/** Private, non-autoloaded option name. */
	public const OPTION_NAME = '_presenter_migration_secret_v1';

	/**
	 * Read the existing secret without writing WordPress state.
	 *
	 * @return string|null Encoded secret, or null when missing or invalid.
	 */
	public function read(): ?string {
		$secret = get_option( self::OPTION_NAME, null );

		return is_string( $secret ) && $this->is_valid( $secret ) ? $secret : null;
	}

	/**
	 * Get the existing secret or create it during an explicit write operation.
	 *
	 * @return string Encoded site-local secret.
	 * @throws RuntimeException When an invalid stored value prevents safe use.
	 */
	public function get_or_create(): string {
		$existing = $this->read();
		if ( null !== $existing ) {
			return $existing;
		}

		if ( false !== get_option( self::OPTION_NAME, false ) ) {
			throw new RuntimeException( 'The stored Presenter migration secret is invalid.' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes random binary option data; it does not obscure executable code.
		$candidate = base64_encode( random_bytes( 32 ) );
		if ( add_option( self::OPTION_NAME, $candidate, '', false ) ) {
			return $candidate;
		}

		$winner = $this->read();
		if ( null === $winner ) {
			throw new RuntimeException( 'Presenter could not establish a migration signing secret.' );
		}

		return $winner;
	}

	/**
	 * Validate the encoded secret shape and entropy length.
	 *
	 * @param string $secret Encoded secret.
	 * @return bool Whether the value is a 32-byte base64 secret.
	 */
	private function is_valid( string $secret ): bool {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Validates random binary option data; it does not decode executable code.
		$decoded = base64_decode( $secret, true );

		return is_string( $decoded ) && 32 === strlen( $decoded );
	}
}
