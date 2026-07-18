<?php
/**
 * Exact private deck-mode metadata operations for migration.
 *
 * @package Presenter
 */

namespace Presenter;

use Throwable;
use WP_Post;

/**
 * Isolates fail-safe inspection and ownership-aware native cutover mutations.
 */
final class Migration_Deck_Mode_Store {
	/** Deck-mode metadata is absent and safe to prepare or apply. */
	public const ABSENT = 'absent';

	/** Exactly one verified native cutover marker exists. */
	public const NATIVE = 'native';

	/** Stored deck-mode metadata is malformed or ambiguous. */
	public const INVALID = 'invalid';

	/**
	 * Capture every stored mode value without changing state.
	 *
	 * This trusted value may be hashed or backed up but must never be displayed.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array<int, mixed>|null Exact ordered values, or null for an invalid post.
	 */
	public function capture( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'slideshow' !== $post->post_type ) {
			return null;
		}

		if ( ! metadata_exists( 'post', $post_id, Deck_Mode::META_KEY ) ) {
			return array();
		}

		return array_values( get_post_meta( $post_id, Deck_Mode::META_KEY, false ) );
	}

	/**
	 * Return a content-free classification of the complete stored marker set.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array{state: string, rowCount: int} Marker status.
	 */
	public function inspect( int $post_id ): array {
		$values = $this->capture( $post_id );
		if ( null === $values ) {
			return array(
				'state'    => self::INVALID,
				'rowCount' => 0,
			);
		}

		return array(
			'state'    => array() === $values
				? self::ABSENT
				: ( array( Deck_Mode::NATIVE ) === $values ? self::NATIVE : self::INVALID ),
			'rowCount' => count( $values ),
		);
	}

	/**
	 * Add one native marker only when no deck-mode row already exists.
	 *
	 * The returned metadata ID is private ownership evidence for compensation.
	 * Callers must reread {@see self::inspect()} before treating cutover as valid.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return int|null Created metadata ID, or null when creation was unsafe or failed.
	 */
	public function create_native( int $post_id ): ?int {
		if ( self::ABSENT !== $this->inspect( $post_id )['state'] ) {
			return null;
		}

		global $wpdb;

		$query = $wpdb->prepare(
			'INSERT INTO %i (post_id, meta_key, meta_value)
			SELECT %d, %s, %s
			WHERE NOT EXISTS (
				SELECT 1 FROM %i WHERE post_id = %d AND meta_key = %s
			)',
			$wpdb->postmeta,
			$post_id,
			Deck_Mode::META_KEY,
			Deck_Mode::NATIVE,
			$wpdb->postmeta,
			$post_id,
			Deck_Mode::META_KEY
		);
		if ( ! is_string( $query ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The final cutover row requires one exception-free conditional insert; the query is prepared immediately above and cache is cleared below.
		$inserted = $wpdb->query( $query );
		$meta_id  = (int) $wpdb->insert_id;
		if ( 1 !== $inserted || $meta_id < 1 ) {
			return null;
		}

		try {
			wp_cache_delete( $post_id, 'post_meta' );
		} catch ( Throwable ) {
			return $meta_id;
		}

		return $meta_id;
	}

	/**
	 * Remove only the exact marker row created by this migration attempt.
	 *
	 * @param int $post_id Slideshow post ID that owns the marker.
	 * @param int $meta_id Created metadata row ID.
	 * @return bool Whether the owned row was removed and deck-mode storage is empty.
	 */
	public function remove_created( int $post_id, int $meta_id ): bool {
		$record = 0 < $meta_id ? get_metadata_by_mid( 'post', $meta_id ) : false;
		if (
			! is_object( $record )
			|| (int) $record->post_id !== $post_id
			|| Deck_Mode::META_KEY !== $record->meta_key
			|| Deck_Mode::NATIVE !== $record->meta_value
		) {
			return false;
		}

		return delete_metadata_by_mid( 'post', $meta_id )
			&& self::ABSENT === $this->inspect( $post_id )['state'];
	}
}
