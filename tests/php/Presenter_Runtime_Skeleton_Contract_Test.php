<?php
/**
 * Presenter 2.0 runtime skeleton contract tests.
 *
 * These tests intentionally describe observable WordPress integration seams
 * instead of the names or constructors of the classes that implement them.
 *
 * @package Presenter
 */

/**
 * Verify the Presenter 2.0 runtime can replace the legacy bootstrap safely.
 */
class Presenter_Runtime_Skeleton_Contract_Test extends Presenter_Test_Case {
	/**
	 * The Reveal 6 runtime is registered without loading it on ordinary pages.
	 */
	public function test_modern_frontend_assets_are_registered_but_not_globally_enqueued(): void {
		wp_dequeue_script( 'presenter-frontend' );
		wp_dequeue_style( 'presenter-frontend' );
		wp_dequeue_style( 'presenter-reveal-6' );
		do_action( 'init' );

		$script         = wp_scripts()->query( 'presenter-frontend', 'registered' );
		$style          = wp_styles()->query( 'presenter-reveal-6', 'registered' );
		$frontend_style = wp_styles()->query( 'presenter-frontend', 'registered' );

		$this->assertInstanceOf( _WP_Dependency::class, $script );
		$this->assertInstanceOf( _WP_Dependency::class, $style );
		$this->assertStringEndsWith( '/build/frontend.js', $script->src );
		$this->assertStringEndsWith( '/build/reveal/reveal.css', $style->src );
		$this->assertFalse( wp_script_is( 'presenter-frontend', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'presenter-reveal-6', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'presenter-frontend', 'enqueued' ) );
		$this->assertInstanceOf( _WP_Dependency::class, $frontend_style );
		$this->assertStringEndsWith( '/build/frontend.css', $frontend_style->src );
		$this->assertSame( array( 'presenter-reveal-6' ), $frontend_style->deps );
	}

	/**
	 * The slideshow post type is available to the block editor and maps caps.
	 */
	public function test_slideshow_post_type_has_modern_editor_and_capability_registration(): void {
		$post_type = get_post_type_object( 'slideshow' );

		$this->assertNotNull( $post_type );
		$this->assertTrue( $post_type->show_in_rest );
		$this->assertTrue( $post_type->map_meta_cap );
		$this->assertTrue( post_type_supports( 'slideshow', 'editor' ) );
		$this->assertTrue( post_type_supports( 'slideshow', 'revisions' ) );
		$this->assertTrue( post_type_supports( 'slideshow', 'custom-fields' ) );
	}

	/**
	 * Publishing metadata has an explicit REST, sanitization, and auth contract.
	 */
	public function test_short_url_meta_has_an_explicit_slideshow_registration(): void {
		// The WordPress test framework resets registered metadata between tests.
		do_action( 'init' );
		$registered = get_registered_meta_keys( 'post', 'slideshow' );

		$this->assertArrayHasKey( '_presenter-short-url', $registered );
		$short_url = $registered['_presenter-short-url'];

		$this->assertSame( 'string', $short_url['type'] );
		$this->assertTrue( $short_url['single'] );
		$this->assertIsArray( $short_url['show_in_rest'] );
		$this->assertSame( 'string', $short_url['show_in_rest']['schema']['type'] );
		$this->assertSame( 'uri', $short_url['show_in_rest']['schema']['format'] );
		$this->assertIsCallable( $short_url['sanitize_callback'] );
		$this->assertIsCallable( $short_url['auth_callback'] );
		$this->assertSame(
			'',
			call_user_func( $short_url['sanitize_callback'], 'javascript:alert(1)' )
		);
	}

	/**
	 * The private deck-mode marker is revisioned and cannot expose new values.
	 */
	public function test_deck_mode_meta_has_a_fail_safe_private_registration(): void {
		// The WordPress test framework resets registered metadata between tests.
		do_action( 'init' );
		$registered = get_registered_meta_keys( 'post', 'slideshow' );

		$this->assertArrayHasKey( \Presenter\Deck_Mode::META_KEY, $registered );
		$deck_mode = $registered[ \Presenter\Deck_Mode::META_KEY ];

		$this->assertSame( 'string', $deck_mode['type'] );
		$this->assertTrue( $deck_mode['single'] );
		$this->assertFalse( $deck_mode['show_in_rest'] );
		$this->assertTrue( $deck_mode['revisions_enabled'] );
		$this->assertSame(
			\Presenter\Deck_Mode::NATIVE,
			call_user_func( $deck_mode['sanitize_callback'], \Presenter\Deck_Mode::NATIVE )
		);
		$this->assertSame( '', call_user_func( $deck_mode['sanitize_callback'], 'future-mode' ) );
	}

	/**
	 * Compatibility rendering reads legacy storage without changing the DB.
	 */
	public function test_legacy_compatibility_render_is_read_only(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_title'   => 'Read-only compatibility fixture',
				'post_content' => '<p>Stored post-content sentinel</p>',
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => 'Legacy compatibility slide',
				'content' => '<p>LEGACY-READ-ONLY-SENTINEL</p>',
				'class'   => '',
			)
		);
		$before_post = get_post( $post_id, ARRAY_A );
		$before_meta = get_post_meta( $post_id );

		$this->go_to( get_permalink( $post_id ) );
		$this->set_slideshow_as_global_post( $post_id );
		// Prime WordPress's lazy default-theme settings before observing writes.
		get_theme_mods();
		wp_get_custom_css_post();
		wp_enqueue_global_styles();

		$write_queries = array();
		$query_filter  = static function ( string $query ) use ( &$write_queries ): string {
			if ( preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $query ) ) {
				$write_queries[] = $query;
			}

			return $query;
		};
		add_filter( 'query', $query_filter );

		$template = apply_filters( 'single_template', '/tmp/presenter-fallback.php' );
		ob_start();
		include $template;
		$output = ob_get_clean();

		remove_filter( 'query', $query_filter );

		$this->assertStringContainsString( 'LEGACY-READ-ONLY-SENTINEL', $output );
		$this->assertSame( array(), $write_queries, 'Legacy compatibility rendering issued a database write.' );
		$this->assertSame( $before_post, get_post( $post_id, ARRAY_A ) );
		$this->assertSame( $before_meta, get_post_meta( $post_id ) );
	}

	/**
	 * The presentation shell participates in standard WordPress template hooks.
	 */
	public function test_presentation_template_fires_standard_wordpress_hooks_in_order(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => 'Standard hook fixture',
				'content' => '<p>STANDARD-HOOK-SENTINEL</p>',
				'class'   => '',
			)
		);
		$this->go_to( get_permalink( $post_id ) );
		$this->set_slideshow_as_global_post( $post_id );

		$events = array();
		$head   = static function () use ( &$events ): void {
			$events[] = 'wp_head';
		};
		$body   = static function () use ( &$events ): void {
			$events[] = 'wp_body_open';
		};
		$footer = static function () use ( &$events ): void {
			$events[] = 'wp_footer';
		};
		add_action( 'wp_head', $head );
		add_action( 'wp_body_open', $body );
		add_action( 'wp_footer', $footer );

		$template = apply_filters( 'single_template', '/tmp/presenter-fallback.php' );
		ob_start();
		include $template;
		ob_end_clean();

		remove_action( 'wp_head', $head );
		remove_action( 'wp_body_open', $body );
		remove_action( 'wp_footer', $footer );

		$this->assertSame( array( 'wp_head', 'wp_body_open', 'wp_footer' ), $events );
	}

	/**
	 * Legacy theme and asset extension points remain usable through the adapter.
	 */
	public function test_legacy_theme_filter_and_public_asset_handles_remain_adapter_seams(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		update_post_meta( $post_id, '_presenter-theme', '/presenter-themes/adapter-fixture.css' );
		$this->go_to( get_permalink( $post_id ) );
		$this->set_slideshow_as_global_post( $post_id );
		wp_deregister_script( 'reveal' );
		wp_deregister_style( 'presenter' );
		wp_deregister_style( 'reveal' );
		wp_deregister_style( 'reveal-theme' );

		$received_theme = null;
		$theme_filter   = static function ( string $theme_url ) use ( &$received_theme ): string {
			$received_theme = $theme_url;

			return 'https://assets.example.test/adapter-theme.css';
		};
		add_filter( 'presenter-theme', $theme_filter );

		$template = apply_filters( 'single_template', '/tmp/presenter-fallback.php' );

		remove_filter( 'presenter-theme', $theme_filter );

		$this->assertNotSame( '/tmp/presenter-fallback.php', $template );
		$this->assertSame( content_url( '/presenter-themes/adapter-fixture.css' ), $received_theme );
		$this->assertTrue( wp_script_is( 'reveal', 'registered' ) );
		$this->assertTrue( wp_style_is( 'presenter', 'registered' ) );
		$this->assertTrue( wp_style_is( 'reveal', 'registered' ) );
		$this->assertTrue( wp_style_is( 'reveal-theme', 'registered' ) );
		$this->assertSame(
			'https://assets.example.test/adapter-theme.css',
			wp_styles()->registered['reveal-theme']->src
		);
	}

	/**
	 * Set a slideshow as the current global post for template behavior.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function set_slideshow_as_global_post( int $post_id ): void {
		global $post;

		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Template behavior requires a global post.
		setup_postdata( $post );
	}
}
