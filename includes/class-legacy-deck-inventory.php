<?php
/**
 * Bounded legacy Presenter deck inventory.
 *
 * @package Presenter
 */

namespace Presenter;

use InvalidArgumentException;

/**
 * Finds legacy slideshow IDs without allowing public query filters to hide them.
 */
final class Legacy_Deck_Inventory {
	/** Maximum number of decks returned by one request. */
	public const MAX_BATCH_SIZE = 100;

	/**
	 * Count migration-eligible legacy slideshow posts.
	 *
	 * @return int Legacy slideshow count.
	 */
	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*)
				FROM %i AS legacy_posts
				WHERE legacy_posts.post_type = %s
					AND legacy_posts.post_status NOT IN (%s, %s, %s)
					AND EXISTS (
						SELECT 1
						FROM %i AS legacy_meta
						WHERE legacy_meta.post_id = legacy_posts.ID
							AND legacy_meta.meta_key = %s
					)',
				$wpdb->posts,
				'slideshow',
				'trash',
				'auto-draft',
				'inherit',
				$wpdb->postmeta,
				'_presenter_slides'
			)
		);
	}

	/**
	 * Find one deterministic page of legacy slideshow IDs.
	 *
	 * @param int $limit  Maximum result count.
	 * @param int $offset Result offset.
	 * @return array<int, int> Post IDs ordered ascending.
	 * @throws InvalidArgumentException When the requested page is not bounded.
	 */
	public function ids( int $limit, int $offset = 0 ): array {
		if ( $limit < 1 || $limit > self::MAX_BATCH_SIZE ) {
			throw new InvalidArgumentException( 'Legacy deck inventory limit must be from 1 through 100.' );
		}
		if ( $offset < 0 ) {
			throw new InvalidArgumentException( 'Legacy deck inventory offset must not be negative.' );
		}

		global $wpdb;

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT legacy_posts.ID
				FROM %i AS legacy_posts
				WHERE legacy_posts.post_type = %s
					AND legacy_posts.post_status NOT IN (%s, %s, %s)
					AND EXISTS (
						SELECT 1
						FROM %i AS legacy_meta
						WHERE legacy_meta.post_id = legacy_posts.ID
							AND legacy_meta.meta_key = %s
					)
				ORDER BY legacy_posts.ID ASC
				LIMIT %d OFFSET %d',
				$wpdb->posts,
				'slideshow',
				'trash',
				'auto-draft',
				'inherit',
				$wpdb->postmeta,
				'_presenter_slides',
				$limit,
				$offset
			)
		);

		return array_map( 'intval', $post_ids );
	}
}
