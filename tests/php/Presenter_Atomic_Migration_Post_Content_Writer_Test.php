<?php
/**
 * Atomic migration post-content writer tests.
 *
 * @package Presenter
 */

use Presenter\Atomic_Migration_Post_Content_Writer;

/**
 * Verify migration content writes are exact, isolated, and race-safe.
 */
final class Presenter_Atomic_Migration_Post_Content_Writer_Test extends Presenter_Test_Case {
	/** A matching slideshow row updates content and nothing else. */
	public function test_matching_row_updates_only_content_without_save_hooks(): void {
		$post_id      = $this->create_post();
		$expected     = $this->expected_post( $post_id );
		$before       = $this->database_post( $post_id );
		$hooks        = array(
			'post_updated' => 0,
			'save_post'    => 0,
		);
		$post_updated = static function () use ( &$hooks ): void {
			++$hooks['post_updated'];
		};
		$save_post    = static function () use ( &$hooks ): void {
			++$hooks['save_post'];
		};
		add_action( 'post_updated', $post_updated );
		add_action( 'save_post', $save_post );

		$result = $this->writer()->compare_and_swap( $post_id, $expected, '<!-- wp:presenter/deck /-->' );

		remove_action( 'post_updated', $post_updated );
		remove_action( 'save_post', $save_post );
		$after = $this->database_post( $post_id );

		$this->assertTrue( $result );
		$this->assertSame( '<!-- wp:presenter/deck /-->', $after->post_content );
		$after->post_content = $before->post_content;
		$this->assertEquals( $before, $after );
		$this->assertSame(
			array(
				'post_updated' => 0,
				'save_post'    => 0,
			),
			$hooks
		);
	}

	/** A stale content precondition cannot overwrite the current post. */
	public function test_stale_content_returns_false_without_writing(): void {
		$post_id  = $this->create_post();
		$expected = $this->expected_post( $post_id );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Concurrent content',
			)
		);

		$this->assertFalse( $this->writer()->compare_and_swap( $post_id, $expected, 'Replacement' ) );
		$this->assertSame( 'Concurrent content', get_post( $post_id )->post_content );
	}

	/** Any changed non-content prepared field prevents the content update. */
	public function test_changed_noncontent_field_returns_false_without_writing(): void {
		$mutations = array(
			'post_title'    => 'Changed title',
			'post_excerpt'  => 'Changed excerpt',
			'post_name'     => 'changed-slug',
			'post_status'   => 'draft',
			'post_password' => 'changed-password',
			'menu_order'    => 99,
		);

		foreach ( $mutations as $field => $value ) {
			$post_id  = $this->create_post();
			$expected = $this->expected_post( $post_id );
			wp_update_post(
				array(
					'ID'   => $post_id,
					$field => $value,
				)
			);

			$this->assertFalse( $this->writer()->compare_and_swap( $post_id, $expected, 'Replacement' ), $field );
			$this->assertSame( 'Original content', get_post( $post_id )->post_content, $field );
		}
	}

	/** Invalid IDs, mismatched IDs, and non-slideshow types fail closed. */
	public function test_wrong_post_or_type_returns_false(): void {
		$post_id  = $this->create_post();
		$expected = $this->expected_post( $post_id );
		$other_id = $this->create_post();
		$page_id  = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => 'Page content',
			)
		);
		$page     = $this->expected_post( $page_id );

		$this->assertFalse( $this->writer()->compare_and_swap( 0, $expected, 'Replacement' ) );
		$this->assertFalse( $this->writer()->compare_and_swap( $other_id, $expected, 'Replacement' ) );
		$this->assertFalse( $this->writer()->compare_and_swap( $page_id, $page, 'Replacement' ) );
		$this->assertSame( 'Original content', get_post( $post_id )->post_content );
		$this->assertSame( 'Page content', get_post( $page_id )->post_content );
	}

	/** A primed post cache is cleared and subsequent reads see exact new bytes. */
	public function test_success_clears_primed_cache_and_reads_back_exact_bytes(): void {
		$post_id  = $this->create_post();
		$expected = $this->expected_post( $post_id );
		$target   = "Target bytes\n<!-- exact -->";
		$this->assertSame( 'Original content', get_post( $post_id )->post_content );

		$this->assertTrue( $this->writer()->compare_and_swap( $post_id, $expected, $target ) );
		$this->assertSame( $target, get_post( $post_id )->post_content );
		$this->assertSame( $target, $this->database_post( $post_id )->post_content );
	}

	/** The same primitive safely performs an inverse target-to-original rollback. */
	public function test_inverse_compare_and_swap_restores_original_content(): void {
		$post_id  = $this->create_post();
		$original = $this->expected_post( $post_id );
		$target   = '<!-- wp:presenter/deck /-->';
		$writer   = $this->writer();

		$this->assertTrue( $writer->compare_and_swap( $post_id, $original, $target ) );
		$applied                = $original;
		$applied['postContent'] = $target;
		$this->assertTrue( $writer->compare_and_swap( $post_id, $applied, $original['postContent'] ) );
		$this->assertSame( 'Original content', get_post( $post_id )->post_content );
	}

	/** Create one fully populated slideshow row. */
	private function create_post(): int {
		return $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'     => 'slideshow',
				'post_name'     => 'prepared-slug',
				'post_title'    => 'Prepared title',
				'post_excerpt'  => 'Prepared excerpt',
				'post_content'  => 'Original content',
				'post_status'   => 'publish',
				'post_password' => 'prepared-password',
				'menu_order'    => 7,
			)
		);
	}

	/**
	 * Capture the prepared post shape used by migration backups.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return array<string, mixed> Exact prepared fields.
	 */
	private function expected_post( int $post_id ): array {
		$post = get_post( $post_id );

		return array(
			'id'          => $post->ID,
			'type'        => $post->post_type,
			'name'        => $post->post_name,
			'title'       => $post->post_title,
			'excerpt'     => $post->post_excerpt,
			'menuOrder'   => $post->menu_order,
			'status'      => $post->post_status,
			'password'    => $post->post_password,
			'postContent' => $post->post_content,
		);
	}

	/**
	 * Read the complete database row without consulting the object cache.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return object Stored posts-table row.
	 */
	private function database_post( int $post_id ): object {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d', $wpdb->posts, $post_id ) );
	}

	/** Create the production writer. */
	private function writer(): Atomic_Migration_Post_Content_Writer {
		return new Atomic_Migration_Post_Content_Writer();
	}
}
