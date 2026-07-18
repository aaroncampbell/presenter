<?php
/**
 * Verified WordPress revision support for Presenter migration.
 *
 * @package Presenter
 */

namespace Presenter;

use WP_Error;
use WP_Post;

/**
 * Creates or reuses an exact revision of the current pre-migration post fields.
 */
final class Migration_Revision {
	/**
	 * Ensure one exact revision exists for the current slideshow representation.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return int|null Verified revision ID, or null on failure.
	 */
	public function ensure( int $post_id ): ?int {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'slideshow' !== $post->post_type ) {
			return null;
		}

		$created = wp_save_post_revision( $post_id );
		if ( $created instanceof WP_Error ) {
			return null;
		}

		if ( is_int( $created ) && 0 < $created && $this->verify( $post_id, $created ) ) {
			return $created;
		}

		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'order'          => 'DESC',
				'orderby'        => 'ID',
				'posts_per_page' => -1,
			)
		);

		foreach ( $revisions as $revision ) {
			if (
				$revision instanceof WP_Post &&
				false === wp_is_post_autosave( $revision->ID ) &&
				$this->matches( $post, $revision )
			) {
				return $revision->ID;
			}
		}

		return null;
	}

	/**
	 * Verify a revision belongs to the post and exactly matches revisioned fields.
	 *
	 * @param int $post_id     Slideshow post ID.
	 * @param int $revision_id Candidate revision ID.
	 * @return bool Whether the revision is an exact pre-migration representation.
	 * @phpstan-impure Reads mutable WordPress post and revision state.
	 */
	public function verify( int $post_id, int $revision_id ): bool {
		$post     = get_post( $post_id );
		$revision = get_post( $revision_id );

		return $post instanceof WP_Post
			&& 'slideshow' === $post->post_type
			&& $revision instanceof WP_Post
			&& wp_is_post_revision( $revision_id ) === $post_id
			&& false === wp_is_post_autosave( $revision_id )
			&& $this->matches( $post, $revision );
	}

	/**
	 * Verify an independently hashed revision after current content may change.
	 *
	 * @param int              $post_id      Slideshow post ID.
	 * @param int              $revision_id  Candidate revision ID.
	 * @param Migration_Hasher $hasher       Persistent site-keyed hasher.
	 * @param string           $expected_hash Expected revision-fields hash.
	 * @return bool Whether the stored revision remains intact.
	 * @phpstan-impure Reads mutable WordPress revision state.
	 */
	public function verify_hash( int $post_id, int $revision_id, Migration_Hasher $hasher, string $expected_hash ): bool {
		$revision = get_post( $revision_id );
		if (
			! $revision instanceof WP_Post ||
			wp_is_post_revision( $revision_id ) !== $post_id ||
			false !== wp_is_post_autosave( $revision_id )
		) {
			return false;
		}

		$actual_hash = $hasher->hash(
			'revision-fields',
			array(
				'title'   => $revision->post_title,
				'content' => $revision->post_content,
				'excerpt' => $revision->post_excerpt,
			)
		);

		return hash_equals( $expected_hash, $actual_hash );
	}

	/**
	 * Compare every core field stored by the WordPress revisions subsystem.
	 *
	 * @param WP_Post $post     Current slideshow.
	 * @param WP_Post $revision Candidate revision.
	 * @return bool Whether revisioned post fields match exactly.
	 */
	private function matches( WP_Post $post, WP_Post $revision ): bool {
		return $post->post_title === $revision->post_title
			&& $post->post_content === $revision->post_content
			&& $post->post_excerpt === $revision->post_excerpt;
	}
}
