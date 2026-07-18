<?php
/**
 * Presenter migration lock.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Provides atomic, ownership-safe, time-bounded migration locks per deck.
 */
final class Migration_Lock {
	/** Lock record schema version. */
	private const SCHEMA_VERSION = 1;

	/** Lock lifetime in seconds. */
	private const TTL = 300;

	/**
	 * Clock returning a Unix timestamp.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Create the lock service.
	 *
	 * @param callable(): int|null $clock Optional clock used by tests.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Acquire one post's lock or report contention.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return Migration_Lock_Handle|null Ownership handle, or null on failure.
	 */
	public function acquire( int $post_id ): ?Migration_Lock_Handle {
		if ( $post_id < 1 ) {
			return null;
		}

		$now        = ( $this->clock )();
		$token      = wp_generate_uuid4();
		$expires_at = $now + self::TTL;
		$record     = $this->encode_record( $token, $now, $expires_at );
		$name       = $this->option_name( $post_id );

		if ( add_option( $name, $record, '', false ) ) {
			return new Migration_Lock_Handle( $post_id, $token, $expires_at );
		}

		$current = get_option( $name, null );
		if ( ! is_string( $current ) || ! $this->is_expired_record( $current, $now ) ) {
			return null;
		}

		if ( ! $this->compare_and_swap( $name, $current, $record ) ) {
			return null;
		}

		return new Migration_Lock_Handle( $post_id, $token, $expires_at );
	}

	/**
	 * Release a lock only when the supplied handle still owns it.
	 *
	 * @param Migration_Lock_Handle $handle Ownership handle.
	 * @return bool Whether the matching lock was removed.
	 */
	public function release( Migration_Lock_Handle $handle ): bool {
		$name    = $this->option_name( $handle->post_id() );
		$current = get_option( $name, null );
		$decoded = is_string( $current ) ? json_decode( $current, true ) : null;

		if (
			! is_array( $decoded ) ||
			! isset( $decoded['token'] ) ||
			! is_string( $decoded['token'] ) ||
			! hash_equals( $decoded['token'], $handle->token() )
		) {
			return false;
		}

		return $this->compare_and_delete( $name, $current );
	}

	/**
	 * Encode one deterministic lock record.
	 *
	 * @param string $token       Owner token.
	 * @param int    $acquired_at Acquisition timestamp.
	 * @param int    $expires_at  Expiry timestamp.
	 * @return string JSON record.
	 */
	private function encode_record( string $token, int $acquired_at, int $expires_at ): string {
		$json = wp_json_encode(
			array(
				'schemaVersion' => self::SCHEMA_VERSION,
				'token'         => $token,
				'acquiredAt'    => $acquired_at,
				'expiresAt'     => $expires_at,
			),
			JSON_UNESCAPED_SLASHES
		);

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Determine whether a stored record is valid and expired.
	 *
	 * Malformed records fail closed and require manual inspection.
	 *
	 * @param string $record Stored JSON record.
	 * @param int    $now    Current Unix timestamp.
	 * @return bool Whether the record can be reclaimed.
	 */
	private function is_expired_record( string $record, int $now ): bool {
		$decoded = json_decode( $record, true );

		return is_array( $decoded )
			&& self::SCHEMA_VERSION === ( $decoded['schemaVersion'] ?? null )
			&& isset( $decoded['token'] )
			&& is_string( $decoded['token'] )
			&& wp_is_uuid( $decoded['token'], 4 )
			&& isset( $decoded['expiresAt'] )
			&& is_int( $decoded['expiresAt'] )
			&& $decoded['expiresAt'] <= $now;
	}

	/**
	 * Replace an option only when its complete stored value still matches.
	 *
	 * WordPress has no compare-and-swap option API. This narrowly isolated query
	 * is required to prevent one expired owner from replacing a newer owner.
	 *
	 * @param string $name        Option name.
	 * @param string $expected    Expected stored value.
	 * @param string $replacement Replacement stored value.
	 * @return bool Whether exactly one matching row changed.
	 */
	private function compare_and_swap( string $name, string $expected, string $replacement ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic ownership replacement requires a conditional write unavailable in the Options API.
		$changed = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $replacement ),
			array(
				'option_name'  => $name,
				'option_value' => $expected,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);

		$this->clear_option_cache( $name );

		return 1 === $changed;
	}

	/**
	 * Delete an option only when its complete stored value still matches.
	 *
	 * @param string $name     Option name.
	 * @param string $expected Expected stored value.
	 * @return bool Whether exactly one matching row was removed.
	 */
	private function compare_and_delete( string $name, string $expected ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic ownership release requires a conditional write unavailable in the Options API.
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => $expected,
			),
			array( '%s', '%s' )
		);

		$this->clear_option_cache( $name );

		return 1 === $deleted;
	}

	/**
	 * Clear option caches after an atomic query bypasses the Options API.
	 *
	 * @param string $name Option name.
	 */
	private function clear_option_cache( string $name ): void {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Build the unique option name for one post.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return string Option name.
	 */
	private function option_name( int $post_id ): string {
		return 'presenter_migration_lock_' . $post_id;
	}
}
