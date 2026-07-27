<?php
/**
 * WordPress revision integration for Presenter migration.
 *
 * @package Presenter
 */

namespace Presenter;

use WP_Post;

/**
 * Protects and reconciles the signed pre-conversion WordPress revision.
 */
final class Migration_Revision_Integration implements Hook_Provider {
	/**
	 * Create the integration.
	 *
	 * @param Migration_Restorer $restorer Verified restore service.
	 */
	public function __construct( private Migration_Restorer $restorer ) {}

	/** Register WordPress revision hooks. */
	public function register_hooks(): void {
		add_action( 'wp_restore_post_revision', array( $this, 'reconcile_restore' ), 20, 2 );
		add_filter( 'wp_save_post_revision_revisions_before_deletion', array( $this, 'protect_source_revision' ), 10, 2 );
	}

	/**
	 * Reconcile Presenter state after Core restores revisioned metadata.
	 *
	 * @param int $post_id     Restored post ID.
	 * @param int $revision_id Restored revision ID.
	 */
	public function reconcile_restore( int $post_id, int $revision_id ): void {
		$this->restorer->reconcile_revision_restore( $post_id, $revision_id );
	}

	/**
	 * Exclude only the verified source revision from Core's pruning candidates.
	 *
	 * @param WP_Post[] $revisions Oldest-first revisions considered for deletion.
	 * @param int       $post_id   Parent post ID.
	 * @return WP_Post[] Filtered deletion candidates.
	 */
	public function protect_source_revision( array $revisions, int $post_id ): array {
		$revision_id = $this->restorer->protected_revision_id( $post_id );
		if ( null === $revision_id ) {
			return $revisions;
		}

		return array_values(
			array_filter(
				$revisions,
				static fn ( WP_Post $revision ): bool => $revision_id !== $revision->ID
			)
		);
	}
}
