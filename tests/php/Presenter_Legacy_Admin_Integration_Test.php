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
	 * The legacy editor safely projects false records in stable source order.
	 */
	public function test_legacy_editor_projects_false_slide_without_changing_stored_meta(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'draft',
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 3,
				'title'   => 'Third',
				'content' => 'THIRD-CONTENT',
				'class'   => '',
			)
		);
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reproduce a serialized boolean row from the production snapshot; the metadata API coerces false on write.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => '_presenter_slides',
				'meta_value' => 'b:0;',
			),
			array( '%d', '%s', '%s' )
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 2,
				'title'   => 'Duplicate Two',
				'content' => 'DUPLICATE-CONTENT',
				'class'   => '',
			)
		);
		wp_cache_delete( $post_id, 'post_meta' );
		$before = get_post_meta( $post_id, '_presenter_slides', false );

		ob_start();
		presenter::get_instance()->slides_meta_box( get_post( $post_id ) );
		$output = ob_get_clean();

		$empty_position     = strpos( $output, '<span class="title"></span>' );
		$duplicate_position = strpos( $output, '<span class="title">Duplicate Two</span>' );
		$third_position     = strpos( $output, '<span class="title">Third</span>' );
		$this->assertNotFalse( $empty_position );
		$this->assertNotFalse( $duplicate_position );
		$this->assertNotFalse( $third_position );
		$this->assertLessThan( $duplicate_position, $empty_position );
		$this->assertLessThan( $third_position, $duplicate_position );
		$this->assertSame( maybe_serialize( $before ), maybe_serialize( get_post_meta( $post_id, '_presenter_slides', false ) ) );
	}

	/**
	 * Stored legacy values cannot escape the classic editor's attributes or fields.
	 */
	public function test_legacy_editor_escapes_stored_slide_values(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'draft',
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => '"><script>window.presenterTitleInjected=true</script>',
				'content' => '<p>Retained editor content</p>',
				'class'   => '"><script>window.presenterClassInjected=true</script>',
				'notes'   => array(
					'notes'    => '</textarea><script>window.presenterNotesInjected=true</script>',
					'markdown' => false,
				),
				'data'    => array(
					(object) array(
						'name'  => '"><script>window.presenterDataInjected=true</script>',
						'value' => '"><script>window.presenterValueInjected=true</script>',
					),
				),
			)
		);

		ob_start();
		presenter::get_instance()->slides_meta_box( get_post( $post_id ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( '&lt;/textarea&gt;&lt;script&gt;window.presenterNotesInjected=true&lt;/script&gt;', $output );
		$this->assertStringNotContainsString( '<script>window.presenterTitleInjected', $output );
		$this->assertStringNotContainsString( '<script>window.presenterClassInjected', $output );
		$this->assertStringNotContainsString( '<script>window.presenterNotesInjected', $output );
		$this->assertStringNotContainsString( '<script>window.presenterDataInjected', $output );
		$this->assertStringNotContainsString( '<script>window.presenterValueInjected', $output );
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

	/**
	 * A native cutover does not restore classic UI from retained rollback data.
	 */
	public function test_native_marker_ignores_retained_legacy_editor_ui(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'draft',
				'post_content' => '<!-- wp:presenter/deck --><!-- wp:presenter/slide --><!-- wp:paragraph --><p>Native</p><!-- /wp:paragraph --><!-- /wp:presenter/slide --><!-- /wp:presenter/deck -->',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		update_post_meta( $post_id, \Presenter\Deck_Mode::META_KEY, \Presenter\Deck_Mode::NATIVE );
		$post = get_post( $post_id );

		presenter::get_instance()->register_legacy_meta_boxes( $post );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate the post.php global consumed by get_post().
		$GLOBALS['post'] = $post;
		presenter::get_instance()->print_editor_styles();
		presenter::get_instance()->print_editor_scripts();

		$this->assertArrayNotHasKey( 'slides', $GLOBALS['wp_meta_boxes']['slideshow']['normal']['core'] ?? array() );
		$this->assertArrayNotHasKey( 'pageparentdiv', $GLOBALS['wp_meta_boxes']['slideshow']['side']['default'] ?? array() );
		$this->assertFalse( wp_style_is( 'presenter-admin-edit-styles', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'presenter-admin-edit-styles', 'enqueued' ) );
	}
}
