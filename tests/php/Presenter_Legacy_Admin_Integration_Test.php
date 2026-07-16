<?php
/**
 * Legacy slideshow editor integration tests.
 *
 * @package Presenter
 */

/**
 * Verify that classic Presenter controls remain isolated from native decks.
 */
final class Presenter_Legacy_Admin_Integration_Test extends Presenter_Test_Case {
	/**
	 * Reset shared admin state before each assertion.
	 */
	public function set_up(): void {
		parent::set_up();

		set_current_screen( 'slideshow' );
		wp_dequeue_style( 'presenter-admin-edit-styles' );
		wp_dequeue_script( 'presenter-admin-edit-styles' );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate the core meta-box registry between integration tests.
		$GLOBALS['wp_meta_boxes']['slideshow'] = array();
	}

	/**
	 * Restore global state after each assertion.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['post'], $GLOBALS['wp_meta_boxes']['slideshow'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Empty and native slideshow editors do not receive classic meta boxes.
	 */
	public function test_native_slideshow_does_not_register_legacy_meta_boxes(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'draft',
				'post_content' => '<!-- wp:presenter/deck --><!-- wp:presenter/slide --><section></section><!-- /wp:presenter/slide --><!-- /wp:presenter/deck -->',
			)
		);
		$post    = get_post( $post_id );

		presenter::get_instance()->register_legacy_meta_boxes( $post );

		$this->assertArrayNotHasKey( 'slides', $GLOBALS['wp_meta_boxes']['slideshow']['normal']['core'] ?? array() );
		$this->assertArrayNotHasKey( 'pageparentdiv', $GLOBALS['wp_meta_boxes']['slideshow']['side']['default'] ?? array() );
	}

	/**
	 * Existing legacy decks retain both classic Presenter meta boxes.
	 */
	public function test_legacy_slideshow_registers_legacy_meta_boxes(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'draft',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );

		presenter::get_instance()->register_legacy_meta_boxes( get_post( $post_id ) );

		$this->assertArrayHasKey( 'slides', $GLOBALS['wp_meta_boxes']['slideshow']['normal']['core'] );
		$this->assertArrayHasKey( 'pageparentdiv', $GLOBALS['wp_meta_boxes']['slideshow']['side']['default'] );
	}

	/**
	 * Legacy editor assets are not enqueued for a native slideshow.
	 */
	public function test_native_slideshow_does_not_enqueue_legacy_editor_assets(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'draft',
			)
		);

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate the post.php global consumed by get_post().
		$GLOBALS['post'] = get_post( $post_id );
		presenter::get_instance()->print_editor_styles();
		presenter::get_instance()->print_editor_scripts();

		$this->assertFalse( wp_style_is( 'presenter-admin-edit-styles', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'presenter-admin-edit-styles', 'enqueued' ) );
	}

	/**
	 * Existing legacy decks retain their classic editor stylesheet and script.
	 */
	public function test_legacy_slideshow_enqueues_legacy_editor_assets(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'draft',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate the post.php global consumed by get_post().
		$GLOBALS['post'] = get_post( $post_id );
		presenter::get_instance()->print_editor_styles();
		presenter::get_instance()->print_editor_scripts();

		$this->assertTrue( wp_style_is( 'presenter-admin-edit-styles', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'presenter-admin-edit-styles', 'enqueued' ) );
	}
}
