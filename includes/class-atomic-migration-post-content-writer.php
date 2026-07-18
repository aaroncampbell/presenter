<?php
/**
 * Atomic Presenter migration post-content writer.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Performs one byte-exact conditional content update without normal save hooks.
 */
final class Atomic_Migration_Post_Content_Writer implements Migration_Post_Content_Writer {
	/**
	 * Atomically replace content while every prepared post field still matches.
	 *
	 * Only post_content is updated. WordPress timestamps and every other field are
	 * deliberately preserved, and no normal post-save hooks are invoked.
	 *
	 * @param int                                                                                                                                               $post_id            Slideshow post ID.
	 * @param array{id: int, type: string, name: string, title: string, excerpt: string, menuOrder: int, status: string, password: string, postContent: string} $expected_post       Exact prepared post fields.
	 * @param string                                                                                                                                            $replacement_content Replacement post content.
	 * @return bool Whether exactly one row changed and exact readback succeeded.
	 */
	public function compare_and_swap( int $post_id, array $expected_post, string $replacement_content ): bool {
		if ( ! $this->is_valid_expected_post( $post_id, $expected_post ) ) {
			return false;
		}

		global $wpdb;

		$query = $wpdb->prepare(
			'UPDATE %i
			SET post_content = %s
			WHERE ID = %d
				AND BINARY post_type = BINARY %s
				AND BINARY post_name = BINARY %s
				AND BINARY post_title = BINARY %s
				AND BINARY post_excerpt = BINARY %s
				AND menu_order = %d
				AND BINARY post_status = BINARY %s
				AND BINARY post_password = BINARY %s
				AND BINARY post_content = BINARY %s',
			$wpdb->posts,
			$replacement_content,
			$post_id,
			$expected_post['type'],
			$expected_post['name'],
			$expected_post['title'],
			$expected_post['excerpt'],
			$expected_post['menuOrder'],
			$expected_post['status'],
			$expected_post['password'],
			$expected_post['postContent']
		);

		if ( ! is_string( $query ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- A single byte-exact compare-and-swap is unavailable through the post API; the query is prepared immediately above.
		$changed = $wpdb->query( $query );
		if ( 1 !== $changed ) {
			return false;
		}

		clean_post_cache( $post_id );

		return $this->verify_readback( $post_id, $expected_post, $replacement_content );
	}

	/**
	 * Validate the complete trusted prepared-post shape.
	 *
	 * @param int                  $post_id      Candidate slideshow ID.
	 * @param array<string, mixed> $expected_post Candidate prepared fields.
	 * @return bool Whether the candidate can be used in the conditional write.
	 */
	private function is_valid_expected_post( int $post_id, array $expected_post ): bool {
		$keys = array( 'id', 'type', 'name', 'title', 'excerpt', 'menuOrder', 'status', 'password', 'postContent' );
		if ( $post_id < 1 || array() !== array_diff( $keys, array_keys( $expected_post ) ) ) {
			return false;
		}

		return $post_id === $expected_post['id']
			&& 'slideshow' === $expected_post['type']
			&& is_string( $expected_post['name'] )
			&& is_string( $expected_post['title'] )
			&& is_string( $expected_post['excerpt'] )
			&& is_int( $expected_post['menuOrder'] )
			&& is_string( $expected_post['status'] )
			&& is_string( $expected_post['password'] )
			&& is_string( $expected_post['postContent'] );
	}

	/**
	 * Verify exact stored fields directly after the conditional update.
	 *
	 * @param int                                                                                                                                               $post_id            Slideshow post ID.
	 * @param array{id: int, type: string, name: string, title: string, excerpt: string, menuOrder: int, status: string, password: string, postContent: string} $expected_post       Prepared post fields.
	 * @param string                                                                                                                                            $replacement_content Replacement post content.
	 * @return bool Whether exact readback matches the intended result.
	 */
	private function verify_readback( int $post_id, array $expected_post, string $replacement_content ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact uncached readback is required immediately after the atomic write.
		$post = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ID, post_type, post_name, post_title, post_excerpt, menu_order, post_status, post_password, post_content FROM %i WHERE ID = %d',
				$wpdb->posts,
				$post_id
			)
		);

		return is_object( $post )
			&& $post_id === (int) $post->ID
			&& $expected_post['type'] === $post->post_type
			&& $expected_post['name'] === $post->post_name
			&& $expected_post['title'] === $post->post_title
			&& $expected_post['excerpt'] === $post->post_excerpt
			&& $expected_post['menuOrder'] === (int) $post->menu_order
			&& $expected_post['status'] === $post->post_status
			&& $expected_post['password'] === $post->post_password
			&& $replacement_content === $post->post_content;
	}
}
