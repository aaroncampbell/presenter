<?php
/**
 * Shared Presenter integration test case.
 *
 * @package Presenter
 */

/**
 * Base class for Presenter integration tests.
 */
abstract class Presenter_Test_Case extends WP_UnitTestCase {
	/**
	 * Create a slideshow without invoking the legacy editor-only save handler.
	 *
	 * Presenter 1.x assumes its classic-editor fields are present on every save.
	 * That behavior is characterized separately and must not obscure unrelated
	 * integration tests.
	 *
	 * @param array<string, mixed> $post_data Post factory data.
	 * @return int
	 */
	protected function create_slideshow_without_legacy_editor_post_data( array $post_data ): int {
		$presenter = presenter::get_instance();
		$priority  = has_action( 'save_post_slideshow', array( $presenter, 'save_post_slideshow' ) );

		$this->assertIsInt( $priority );
		remove_action( 'save_post_slideshow', array( $presenter, 'save_post_slideshow' ), $priority );
		$post_id = self::factory()->post->create( $post_data );
		add_action( 'save_post_slideshow', array( $presenter, 'save_post_slideshow' ), $priority, 3 );

		return $post_id;
	}

	/**
	 * Add the minimum repeated metadata record that identifies a legacy deck.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	protected function add_legacy_slide_fixture( int $post_id ): void {
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => 'Legacy route fixture',
				'content' => '<p>Legacy route fixture</p>',
				'class'   => '',
			)
		);
	}
}
