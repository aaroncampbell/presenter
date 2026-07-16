<?php
/**
 * WordPress legacy slide source.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Reads Presenter 1.x slide metadata without changing it.
 */
final class WordPress_Legacy_Slide_Source implements Legacy_Slide_Source {
	/**
	 * Presenter 1.x repeated slide metadata key.
	 *
	 * @var string
	 */
	private const META_KEY = '_presenter_slides';

	/**
	 * Determine whether a post has legacy slide records.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether legacy slides exist.
	 */
	public function has_slides( int $post_id ): bool {
		if ( $post_id < 1 ) {
			return false;
		}

		return metadata_exists( 'post', $post_id, self::META_KEY );
	}

	/**
	 * Read legacy slide records in their stored order.
	 *
	 * The third argument to get_post_meta() remains false because Presenter 1.x
	 * stored one record per slide under the same metadata key.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, mixed> Legacy slide records.
	 */
	public function read_slides( int $post_id ): array {
		if ( $post_id < 1 ) {
			return array();
		}

		$slides = get_post_meta( $post_id, self::META_KEY, false );

		return is_array( $slides ) ? array_values( $slides ) : array();
	}
}
