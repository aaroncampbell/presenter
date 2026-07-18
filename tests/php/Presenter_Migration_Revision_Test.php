<?php
/**
 * Presenter migration revision integration tests.
 *
 * @package Presenter
 */

use Presenter\Migration_Revision;

/**
 * Verify migration revisions preserve the exact pre-write WordPress fields.
 */
final class Presenter_Migration_Revision_Test extends Presenter_Test_Case {
	/** Invalid and non-slideshow posts cannot create migration revisions. */
	public function test_ensure_rejects_invalid_and_non_slideshow_posts(): void {
		$service = new Migration_Revision();
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$this->assertNull( $service->ensure( 0 ) );
		$this->assertNull( $service->ensure( -1 ) );
		$this->assertNull( $service->ensure( $post_id ) );
		$this->assertFalse( $service->verify( 0, 0 ) );
		$this->assertFalse( $service->verify( $post_id, $post_id ) );
	}

	/** Ensure creates a normal revision matching title, content, and excerpt. */
	public function test_ensure_creates_exact_pre_write_revision(): void {
		$post_id     = $this->create_slideshow();
		$service     = new Migration_Revision();
		$revision_id = $service->ensure( $post_id );
		$post        = get_post( $post_id );
		$revision    = get_post( $revision_id );

		$this->assertIsInt( $revision_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertInstanceOf( WP_Post::class, $revision );
		$this->assertSame( $post_id, wp_is_post_revision( $revision_id ) );
		$this->assertFalse( wp_is_post_autosave( $revision_id ) );
		$this->assertSame( $post->post_title, $revision->post_title );
		$this->assertSame( $post->post_content, $revision->post_content );
		$this->assertSame( $post->post_excerpt, $revision->post_excerpt );
		$this->assertTrue( $service->verify( $post_id, $revision_id ) );
	}

	/** A retry reuses the exact revision when core declines a duplicate save. */
	public function test_ensure_is_idempotent_when_core_declines_duplicate_revision(): void {
		$post_id       = $this->create_slideshow();
		$service       = new Migration_Revision();
		$first         = $service->ensure( $post_id );
		$revision_ids  = $this->normal_revision_ids( $post_id );
		$second        = $service->ensure( $post_id );
		$after_retries = $this->normal_revision_ids( $post_id );

		$this->assertIsInt( $first );
		$this->assertSame( $first, $second );
		$this->assertSame( array( $first ), $revision_ids );
		$this->assertSame( $revision_ids, $after_retries );
		$this->assertTrue( $service->verify( $post_id, $second ) );
	}

	/**
	 * Any changed revisioned field makes the prior revision inexact.
	 *
	 * @dataProvider revisioned_field_changes
	 *
	 * @param string $field Changed post field.
	 * @param string $value Replacement value.
	 */
	public function test_verify_rejects_changed_revisioned_field( string $field, string $value ): void {
		$post_id     = $this->create_slideshow();
		$service     = new Migration_Revision();
		$revision_id = $service->ensure( $post_id );

		$this->assertIsInt( $revision_id );
		$this->assertSame(
			$post_id,
			wp_update_post(
				array(
					'ID'   => $post_id,
					$field => $value,
				)
			)
		);
		$this->assertFalse( $service->verify( $post_id, $revision_id ) );
	}

	/**
	 * Provide each core revision field independently.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function revisioned_field_changes(): array {
		return array(
			'title'   => array( 'post_title', 'Changed title' ),
			'content' => array( 'post_content', '<p>Changed content</p>' ),
			'excerpt' => array( 'post_excerpt', 'Changed excerpt' ),
		);
	}

	/** A revision belonging to another slideshow cannot verify. */
	public function test_verify_rejects_revision_with_mismatched_parent(): void {
		$first_post  = $this->create_slideshow();
		$second_post = $this->create_slideshow();
		$service     = new Migration_Revision();
		$revision_id = $service->ensure( $first_post );

		$this->assertIsInt( $revision_id );
		$this->assertFalse( $service->verify( $second_post, $revision_id ) );
	}

	/** An ordinary post ID cannot be treated as a revision ID. */
	public function test_verify_rejects_non_revision_post(): void {
		$post_id = $this->create_slideshow();

		$this->assertFalse( ( new Migration_Revision() )->verify( $post_id, $post_id ) );
	}

	/** Autosaves are not accepted as durable pre-migration revisions. */
	public function test_verify_rejects_matching_autosave(): void {
		$post_id     = $this->create_slideshow();
		$autosave_id = _wp_put_post_revision( get_post( $post_id ), true );
		$post        = get_post( $post_id );
		$autosave    = get_post( $autosave_id );

		$this->assertIsInt( $autosave_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertInstanceOf( WP_Post::class, $autosave );
		$this->assertSame( $post_id, wp_is_post_autosave( $autosave_id ) );
		$this->assertSame( $post->post_title, $autosave->post_title );
		$this->assertSame( $post->post_content, $autosave->post_content );
		$this->assertSame( $post->post_excerpt, $autosave->post_excerpt );
		$this->assertFalse( ( new Migration_Revision() )->verify( $post_id, $autosave_id ) );
	}

	/** Create one slideshow with all default core revision fields populated. */
	private function create_slideshow(): int {
		return $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_title'   => 'Pre-migration title',
				'post_content' => '<p>Pre-migration content</p>',
				'post_excerpt' => 'Pre-migration excerpt',
			)
		);
	}

	/**
	 * Read durable revision IDs without autosaves, newest first.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array<int, int>
	 */
	private function normal_revision_ids( int $post_id ): array {
		return array_values(
			array_map(
				static fn( WP_Post $revision ): int => $revision->ID,
				array_filter(
					wp_get_post_revisions( $post_id ),
					static fn( WP_Post $revision ): bool => false === wp_is_post_autosave( $revision )
				)
			)
		);
	}
}
