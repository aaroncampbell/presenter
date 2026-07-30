<?php
/**
 * Legacy and native deck routing contract tests.
 *
 * These tests describe the public WordPress behavior at the compatibility
 * boundary without coupling the contract to a particular router or renderer
 * implementation.
 *
 * @package Presenter
 */

/**
 * Verify each storage format uses its intended presentation runtime.
 */
class Presenter_Deck_Routing_Contract_Test extends Presenter_Test_Case {
	/**
	 * Legacy metadata continues through the characterized Reveal 4 template.
	 */
	public function test_legacy_deck_stays_on_legacy_reveal_4_path(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_content' => '<p>POST-CONTENT-MUST-NOT-REPLACE-LEGACY-SLIDES</p>',
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => 'Legacy route fixture',
				'content' => '<p>LEGACY-ROUTE-SENTINEL</p>',
				'class'   => '',
			)
		);

		$this->prepare_frontend_request( $post_id );
		$template = apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );

		$this->assertSame( dirname( __DIR__, 2 ) . '/templates/index.php', $template );
		$this->assertTrue( wp_script_is( 'reveal', 'registered' ) );
		$this->assertSame( '4.1.2', wp_scripts()->registered['reveal']->ver );
		$this->assertFalse( wp_script_is( 'presenter-frontend', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'presenter-reveal-6', 'enqueued' ) );

		$output = $this->render_template( $template );

		$this->assertStringContainsString( 'LEGACY-ROUTE-SENTINEL', $output );
		$this->assertStringNotContainsString( 'POST-CONTENT-MUST-NOT-REPLACE-LEGACY-SLIDES', $output );
	}

	/**
	 * The legacy standalone document allows zoom and owns one current title.
	 */
	public function test_legacy_template_owns_accessible_document_metadata(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
				'post_title'  => 'Legacy document metadata',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		$this->prepare_frontend_request( $post_id );

		$block_title_priority = has_action( 'wp_head', '_block_template_render_title_tag' );
		if ( false === $block_title_priority ) {
			add_action( 'wp_head', '_block_template_render_title_tag', 1 );
		}

		try {
			$template = apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );
			$output   = $this->render_template( $template );
		} finally {
			if ( false === $block_title_priority ) {
				remove_action( 'wp_head', '_block_template_render_title_tag', 1 );
			}
		}

		$this->assertSame( 1, substr_count( $output, '<meta name="viewport" content="width=device-width, initial-scale=1">' ) );
		$this->assertStringNotContainsString( 'user-scalable=no', $output );
		$this->assertSame( 1, substr_count( $output, '<title>Legacy document metadata' ) );
	}

	/**
	 * A block deck uses a distinct Presenter 2 renderer and Reveal 6 assets.
	 */
	public function test_native_block_deck_uses_modern_template_renderer_and_assets(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_content' => $this->native_deck_content( 'NATIVE-ROUTE-SENTINEL' ),
			)
		);

		$this->prepare_frontend_request( $post_id );
		$template        = apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );
		$legacy_template = dirname( __DIR__, 2 ) . '/templates/index.php';

		$this->assertNotSame( '/tmp/presenter-theme-fallback.php', $template );
		$this->assertNotSame( $legacy_template, $template, 'Native decks must not enter the Presenter 1.x template.' );
		$this->assertTrue( wp_script_is( 'presenter-frontend', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'presenter-reveal-6', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'reveal', 'enqueued' ), 'The legacy Reveal 4 runtime must not load for native decks.' );

		$output = $this->render_template( $template );

		$this->assertStringContainsString( 'data-presenter-reveal-root', $output );
		$this->assertStringContainsString( 'NATIVE-ROUTE-SENTINEL', $output );
	}

	/**
	 * The native skip-link target accepts programmatic focus.
	 */
	public function test_native_template_skip_link_target_is_programmatically_focusable(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_content' => $this->native_deck_content( 'ACCESSIBLE-MAIN-SENTINEL' ),
			)
		);
		$this->prepare_frontend_request( $post_id );

		$template = apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );
		$output   = $this->render_template( $template );

		$this->assertStringContainsString(
			'<a class="screen-reader-text skip-link" href="#presenter-presentation">',
			$output
		);
		$this->assertStringContainsString(
			'<main id="presenter-presentation" tabindex="-1">',
			$output
		);
	}

	/**
	 * The standalone template owns one title and responsive viewport.
	 */
	public function test_native_template_owns_document_title_and_viewport_without_theme_support(): void {
		global $_wp_theme_features;

		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_title'   => 'Presenter document metadata',
				'post_content' => $this->native_deck_content( 'DOCUMENT-METADATA-SENTINEL' ),
			)
		);
		$this->prepare_frontend_request( $post_id );
		$title_support = $_wp_theme_features['title-tag'] ?? null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test restores this isolated theme capability exactly.
		remove_theme_support( 'title-tag' );
		$block_title_priority = has_action( 'wp_head', '_block_template_render_title_tag' );
		if ( false === $block_title_priority ) {
			add_action( 'wp_head', '_block_template_render_title_tag', 1 );
		}

		try {
			$template = apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );
			$output   = $this->render_template( $template );
		} finally {
			if ( false === $block_title_priority ) {
				remove_action( 'wp_head', '_block_template_render_title_tag', 1 );
			}
			if ( null === $title_support ) {
				unset( $_wp_theme_features['title-tag'] );
			} else {
				$_wp_theme_features['title-tag'] = $title_support; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact pre-test theme capability.
			}
		}

		$this->assertSame( 1, substr_count( $output, '<meta name="viewport" content="width=device-width, initial-scale=1">' ) );
		$this->assertSame( 1, substr_count( $output, '<title>Presenter document metadata' ) );
	}

	/**
	 * Native routing resolves the revisioned stable theme before the head prints.
	 */
	public function test_native_route_enqueues_stored_stable_theme(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:presenter/deck {"theme":"white"} --><!-- wp:presenter/slide --><p>Theme</p><!-- /wp:presenter/slide --><!-- /wp:presenter/deck -->',
			)
		);
		$this->prepare_frontend_request( $post_id );

		apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );
		$style = wp_styles()->query( 'reveal-theme', 'registered' );

		$this->assertInstanceOf( _WP_Dependency::class, $style );
		$this->assertStringEndsWith( '/build/reveal/theme/white.css', $style->src );
		$this->assertTrue( wp_style_is( 'reveal-theme', 'enqueued' ) );
	}

	/**
	 * Synthetic whitespace blocks cannot hide the native Deck theme.
	 */
	public function test_native_route_reads_theme_after_leading_whitespace(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_content' => "\n\t<!-- wp:presenter/deck {\"theme\":\"white\"} --><!-- wp:presenter/slide --><p>Theme</p><!-- /wp:presenter/slide --><!-- /wp:presenter/deck -->\n",
			)
		);
		$this->prepare_frontend_request( $post_id );

		apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );
		$style = wp_styles()->query( 'reveal-theme', 'registered' );

		$this->assertInstanceOf( _WP_Dependency::class, $style );
		$this->assertStringEndsWith( '/build/reveal/theme/white.css', $style->src );
	}

	/**
	 * Invalid native theme data uses a deterministic bundled fallback.
	 */
	public function test_native_route_falls_back_safely_for_invalid_theme(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:presenter/deck {"theme":"../../evil.css"} --><!-- wp:presenter/slide --><p>Theme</p><!-- /wp:presenter/slide --><!-- /wp:presenter/deck -->',
			)
		);
		$this->prepare_frontend_request( $post_id );

		apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );
		$style = wp_styles()->query( 'reveal-theme', 'registered' );

		$this->assertInstanceOf( _WP_Dependency::class, $style );
		$this->assertStringEndsWith( '/build/reveal/theme/black.css', $style->src );
	}

	/**
	 * Native presentation markup participates in standard WordPress hooks.
	 */
	public function test_native_template_fires_standard_wordpress_hooks_in_order(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_content' => $this->native_deck_content( 'NATIVE-HOOK-SENTINEL' ),
			)
		);
		$this->prepare_frontend_request( $post_id );
		add_post_meta( $post_id, '_presenter-short-url', 'https://example.com/first?a=1&b=2' );
		add_post_meta( $post_id, '_presenter-short-url', 'https://example.com/second' );

		$events        = array();
		$head          = static function () use ( &$events ): void {
			$events[] = 'wp_head';
		};
		$body          = static function () use ( &$events ): void {
			$events[] = 'wp_body_open';
		};
		$reveal_footer = static function () use ( &$events ): void {
			$events[] = 'presenter_reveal_footer';
			echo '<!-- native-reveal-footer -->';
		};
		$footer        = static function () use ( &$events ): void {
			$events[] = 'wp_footer';
		};
		add_action( 'wp_head', $head );
		add_action( 'wp_body_open', $body );
		add_action( 'presenter-reveal-footer', $reveal_footer );
		add_action( 'wp_footer', $footer );

		$template = apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );
		$output   = $this->render_template( $template );

		remove_action( 'wp_head', $head );
		remove_action( 'wp_body_open', $body );
		remove_action( 'presenter-reveal-footer', $reveal_footer );
		remove_action( 'wp_footer', $footer );

		$this->assertSame(
			array( 'wp_head', 'wp_body_open', 'presenter_reveal_footer', 'wp_footer' ),
			$events
		);
		$this->assertMatchesRegularExpression(
			'/<div class="reveal" data-presenter-reveal-root>.*<div class="slides">.*NATIVE-HOOK-SENTINEL.*<\/div><p class="permalink"><a href="https:\/\/example\.com\/first\?a=1&#038;b=2">https:\/\/example\.com\/first\?a=1&amp;b=2<\/a><\/p><!-- native-reveal-footer --><\/div>/s',
			$output
		);
		$this->assertStringNotContainsString( 'https://example.com/second', $output );
	}

	/**
	 * A slideshow without either storage shape remains a normal theme request.
	 */
	public function test_empty_slideshow_keeps_theme_template_and_loads_no_runtime(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_content' => '<p>Ordinary slideshow content</p>',
			)
		);
		$this->prepare_frontend_request( $post_id );

		$template = apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );

		$this->assertSame( '/tmp/presenter-theme-fallback.php', $template );
		$this->assertFalse( wp_script_is( 'presenter-frontend', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'presenter-reveal-6', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'reveal', 'registered' ) );
	}

	/**
	 * Malformed native trees retain the theme route instead of booting Reveal.
	 *
	 * @dataProvider malformed_native_trees
	 *
	 * @param string $content Malformed serialized block content.
	 */
	public function test_malformed_native_tree_keeps_theme_template( string $content ): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		$this->prepare_frontend_request( $post_id );

		$template = apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );

		$this->assertSame( '/tmp/presenter-theme-fallback.php', $template );
		$this->assertFalse( wp_script_is( 'presenter-frontend', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'presenter-reveal-6', 'enqueued' ) );
	}

	/**
	 * Provide native trees that cannot map to flat Reveal slides.
	 *
	 * @return array<string, array{string}>
	 */
	public function malformed_native_trees(): array {
		$slide = '<!-- wp:presenter/slide --><!-- wp:paragraph --><p>Slide</p><!-- /wp:paragraph --><!-- /wp:presenter/slide -->';
		$deck  = '<!-- wp:presenter/deck -->' . $slide . '<!-- /wp:presenter/deck -->';

		return array(
			'empty Deck'           => array( '<!-- wp:presenter/deck --><!-- /wp:presenter/deck -->' ),
			'two root Decks'       => array( $deck . $deck ),
			'stray root block'     => array( '<!-- wp:paragraph --><p>Stray</p><!-- /wp:paragraph -->' . $deck ),
			'non-Slide Deck child' => array( '<!-- wp:presenter/deck --><!-- wp:paragraph --><p>Invalid</p><!-- /wp:paragraph --><!-- /wp:presenter/deck -->' ),
			'nested Deck child'    => array( '<!-- wp:presenter/deck -->' . $deck . '<!-- /wp:presenter/deck -->' ),
			'saved Deck wrapper'   => array( '<!-- wp:presenter/deck --><div>' . $slide . '</div><!-- /wp:presenter/deck -->' ),
		);
	}

	/**
	 * Legacy data wins when both storage shapes exist without a cutover marker.
	 */
	public function test_legacy_metadata_takes_precedence_over_native_blocks(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_content' => $this->native_deck_content( 'NATIVE-MIXED-SENTINEL' ),
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		$this->assertFalse( metadata_exists( 'post', $post_id, \Presenter\Deck_Mode::META_KEY ) );
		$this->prepare_frontend_request( $post_id );

		$template = apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );

		$this->assertSame( dirname( __DIR__, 2 ) . '/templates/index.php', $template );
		$this->assertTrue( wp_script_is( 'reveal', 'registered' ) );
		$this->assertFalse( wp_script_is( 'presenter-frontend', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'presenter-reveal-6', 'enqueued' ) );
	}

	/**
	 * A native cutover uses Reveal 6 while retaining rollback metadata.
	 */
	public function test_native_marker_routes_blocks_despite_retained_legacy_metadata(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_content' => $this->native_deck_content( 'NATIVE-CUTOVER-SENTINEL' ),
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		update_post_meta( $post_id, \Presenter\Deck_Mode::META_KEY, \Presenter\Deck_Mode::NATIVE );
		$this->prepare_frontend_request( $post_id );

		$template = apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );
		$content  = apply_filters( 'the_content', get_the_content() );
		$output   = $this->render_template( $template );

		$this->assertSame( dirname( __DIR__, 2 ) . '/templates/presentation.php', $template );
		$this->assertTrue( wp_script_is( 'presenter-frontend', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'presenter-reveal-6', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'reveal', 'registered' ) );
		$this->assertStringContainsString( 'NATIVE-CUTOVER-SENTINEL', $content );
		$this->assertStringNotContainsString( 'Legacy route fixture', $content );
		$this->assertStringContainsString( 'NATIVE-CUTOVER-SENTINEL', $output );
		$this->assertStringNotContainsString( 'Legacy route fixture', $output );
	}

	/**
	 * Password protection keeps the request in the active WordPress theme.
	 */
	public function test_protected_native_deck_keeps_theme_password_gate_and_exposes_no_presentation(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'     => 'slideshow',
				'post_status'   => 'publish',
				'post_password' => 'local-secret',
				'post_content'  => $this->native_deck_content( 'PROTECTED-NATIVE-SECRET' ),
			)
		);

		$this->prepare_frontend_request( $post_id );
		$template = apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );
		$content  = apply_filters( 'the_content', get_the_content() );

		$this->assertSame( '/tmp/presenter-theme-fallback.php', $template );
		$this->assertStringContainsString( 'post_password', $content );
		$this->assertStringNotContainsString( 'PROTECTED-NATIVE-SECRET', $content );
		$this->assertStringNotContainsString( 'data-presenter-reveal-root', $content );
		$this->assertFalse( wp_script_is( 'presenter-frontend', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'presenter-reveal-6', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'reveal', 'registered' ) );
	}

	/**
	 * A valid WordPress password cookie allows the native presentation route.
	 */
	public function test_protected_native_deck_uses_modern_route_after_authentication(): void {
		require_once ABSPATH . WPINC . '/class-phpass.php';

		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'     => 'slideshow',
				'post_status'   => 'publish',
				'post_password' => 'local-secret',
				'post_content'  => $this->native_deck_content( 'AUTHENTICATED-NATIVE-SENTINEL' ),
			)
		);

		$hasher = new PasswordHash( 8, true );

		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = $hasher->HashPassword( 'local-secret' );
		$this->prepare_frontend_request( $post_id );

		$template = apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );

		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );

		$this->assertSame( dirname( __DIR__, 2 ) . '/templates/presentation.php', $template );
		$this->assertTrue( wp_script_is( 'presenter-frontend', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'presenter-reveal-6', 'enqueued' ) );
	}

	/**
	 * Selecting and rendering either route is read-only.
	 *
	 * @dataProvider deck_storage_routes
	 *
	 * @param string $route Storage route under test.
	 */
	public function test_routing_and_rendering_do_not_write_to_the_database( string $route ): void {
		$is_legacy = 'legacy' === $route;
		$post_id   = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_content' => $is_legacy
					? '<p>LEGACY-READ-ONLY-POST-CONTENT</p>'
					: $this->native_deck_content( 'NATIVE-READ-ONLY-SLIDE' ),
			)
		);
		if ( $is_legacy ) {
			add_post_meta(
				$post_id,
				'_presenter_slides',
				(object) array(
					'number'  => 1,
					'title'   => 'Legacy read-only fixture',
					'content' => '<p>LEGACY-READ-ONLY-SLIDE</p>',
					'class'   => '',
				)
			);
		}

		$before_post = get_post( $post_id, ARRAY_A );
		$before_meta = get_post_meta( $post_id );
		$this->prime_lazy_wordpress_state();
		$this->prepare_frontend_request( $post_id );

		$write_queries = array();
		$query_filter  = static function ( string $query ) use ( &$write_queries ): string {
			if ( preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $query ) ) {
				$write_queries[] = $query;
			}

			return $query;
		};
		add_filter( 'query', $query_filter );

		$template = apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );
		if ( ! is_readable( $template ) ) {
			remove_filter( 'query', $query_filter );
			$this->fail( ucfirst( $route ) . ' routing did not select a readable presentation template.' );
		}
		$this->render_template( $template );

		remove_filter( 'query', $query_filter );

		$this->assertSame( array(), $write_queries, ucfirst( $route ) . ' routing or rendering issued a database write.' );
		$this->assertSame( $before_post, get_post( $post_id, ARRAY_A ) );
		$this->assertSame( $before_meta, get_post_meta( $post_id ) );
	}

	/**
	 * Provide each storage branch independently so template loops cannot leak.
	 *
	 * @return array<string, array{string}>
	 */
	public function deck_storage_routes(): array {
		return array(
			'legacy metadata' => array( 'legacy' ),
			'native blocks'   => array( 'native' ),
		);
	}

	/**
	 * Build the minimum serialized block tree that identifies a native deck.
	 *
	 * @param string $sentinel Visible slide content.
	 * @return string
	 */
	private function native_deck_content( string $sentinel ): string {
		return '<!-- wp:presenter/deck --><!-- wp:presenter/slide --><!-- wp:paragraph --><p>'
			. esc_html( $sentinel )
			. '</p><!-- /wp:paragraph --><!-- /wp:presenter/slide --><!-- /wp:presenter/deck -->';
	}

	/**
	 * Establish a singular slideshow request and clear route-specific assets.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function prepare_frontend_request( int $post_id ): void {
		global $post;

		$this->go_to( get_permalink( $post_id ) );
		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Template routing requires the current global post.
		setup_postdata( $post );
		do_action( 'init' );

		wp_dequeue_script( 'presenter-frontend' );
		wp_dequeue_style( 'presenter-frontend' );
		wp_dequeue_style( 'presenter-reveal-6' );
		wp_dequeue_script( 'reveal' );
		wp_deregister_script( 'reveal' );
		wp_deregister_style( 'presenter' );
		wp_deregister_style( 'reveal' );
		wp_deregister_style( 'reveal-theme' );
	}

	/**
	 * Render an already-selected plugin template.
	 *
	 * @param string $template Absolute template path.
	 * @return string
	 */
	private function render_template( string $template ): string {
		ob_start();
		include $template;

		return (string) ob_get_clean();
	}

	/**
	 * Initialize WordPress state that may legitimately be created lazily.
	 */
	private function prime_lazy_wordpress_state(): void {
		get_theme_mods();
		wp_get_custom_css_post();
		wp_enqueue_global_styles();
	}
}
