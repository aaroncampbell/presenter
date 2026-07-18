<?php
/**
 * Domain-separated Presenter migration hashing.
 *
 * @package Presenter
 */

namespace Presenter;

use InvalidArgumentException;

/**
 * Produces deterministic site-keyed integrity hashes for migration records.
 */
final class Migration_Hasher {
	/** Hashing contract version. */
	private const SCHEMA_VERSION = 1;

	/**
	 * Create a hasher.
	 *
	 * @param string $secret Persistent site-local signing secret.
	 * @throws InvalidArgumentException When the secret is empty.
	 */
	public function __construct( private string $secret ) {
		if ( '' === $secret ) {
			throw new InvalidArgumentException( 'A migration signing secret is required.' );
		}
	}

	/**
	 * Hash one typed value for an explicit purpose.
	 *
	 * @param string $purpose Domain-specific record purpose.
	 * @param mixed  $value   Value to hash.
	 * @return string Lowercase hexadecimal HMAC-SHA256.
	 * @throws InvalidArgumentException When the purpose is empty.
	 */
	public function hash( string $purpose, mixed $value ): string {
		if ( '' === $purpose ) {
			throw new InvalidArgumentException( 'A migration hash purpose is required.' );
		}

		$message = sprintf( 'presenter-migration:%d:%s', self::SCHEMA_VERSION, $purpose )
			. "\0"
			. Migration_Value_Encoder::encode( $value );

		return hash_hmac( 'sha256', $message, $this->secret );
	}
}
