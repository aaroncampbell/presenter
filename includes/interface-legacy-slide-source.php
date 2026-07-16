<?php
/**
 * Legacy slide source contract.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Provides read-only access to Presenter 1.x slide records.
 */
interface Legacy_Slide_Source {
	/**
	 * Determine whether a post has legacy slide records.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether legacy slides exist.
	 */
	public function has_slides( int $post_id ): bool;

	/**
	 * Read legacy slide records in their stored order.
	 *
	 * Values are intentionally returned without conversion so compatibility
	 * rendering cannot accidentally discard legacy fields.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, mixed> Legacy slide records.
	 */
	public function read_slides( int $post_id ): array;
}
