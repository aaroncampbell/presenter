<?php
/**
 * Public hook and asset characterization tests.
 *
 * @package Presenter
 */

/**
 * Characterize Presenter 1.x template hooks and registered presentation assets.
 */
class Presenter_Public_Hooks_And_Assets_Test extends Presenter_Test_Case {
	/**
	 * The presentation template fires its public hooks in document order.
	 */
	public function test_presentation_template_fires_public_hooks_in_document_order(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_content' => '<section>CHARACTERIZATION-SLIDE</section>',
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => 'Characterization',
				'content' => '<p>CHARACTERIZATION-SLIDE</p>',
				'class'   => '',
			)
		);
		$this->go_to( get_permalink( $post_id ) );
		$this->set_slideshow_as_global_post( $post_id );
		$this->reset_presentation_asset_registrations();

		$template = presenter::get_instance()->single_template( '/tmp/fallback.php' );
		$events   = array();
		$head     = static function () use ( &$events ): void {
			$events[] = 'head';
			echo '<!-- presenter-head-test -->';
		};
		$reveal   = static function () use ( &$events ): void {
			$events[] = 'reveal-footer';
			echo '<!-- presenter-reveal-footer-test -->';
		};
		$before   = static function () use ( &$events ): void {
			$events[] = 'footer-before';
			echo '<!-- presenter-footer-before-test -->';
		};
		$after    = static function () use ( &$events ): void {
			$events[] = 'footer-after';
			echo '<!-- presenter-footer-after-test -->';
		};

		add_action( 'presenter-head', $head, 5 );
		add_action( 'presenter-reveal-footer', $reveal );
		add_action( 'presenter-footer', $before, 5 );
		add_action( 'presenter-footer', $after, 15 );

		ob_start();
		include $template;
		$output = ob_get_clean();

		remove_action( 'presenter-head', $head, 5 );
		remove_action( 'presenter-reveal-footer', $reveal );
		remove_action( 'presenter-footer', $before, 5 );
		remove_action( 'presenter-footer', $after, 15 );

		$this->assertSame( array( 'head', 'reveal-footer', 'footer-before', 'footer-after' ), $events );
		$this->assertStringContainsString( 'CHARACTERIZATION-SLIDE', $output );
		$this->assertOutputMarkersAreOrdered(
			$output,
			array(
				'<!-- presenter-head-test -->',
				'CHARACTERIZATION-SLIDE',
				'<!-- presenter-reveal-footer-test -->',
				'<!-- presenter-footer-before-test -->',
				'Reveal.initialize(',
				'<!-- presenter-footer-after-test -->',
			)
		);
	}

	/**
	 * Reveal dependency filters feed the registered script and style handles.
	 */
	public function test_reveal_dependency_filters_change_registered_asset_dependencies(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		$this->go_to( get_permalink( $post_id ) );
		$this->set_slideshow_as_global_post( $post_id );
		$this->reset_presentation_asset_registrations();

		$script_filter = static function ( array $dependencies ): array {
			$dependencies[] = 'PresenterCharacterizationPlugin';
			return $dependencies;
		};
		$style_filter  = static function ( array $dependencies ): array {
			$dependencies[] = 'PresenterCharacterizationStyle';
			return $dependencies;
		};

		add_filter( 'presenter-reveal-js-dependencies', $script_filter );
		add_filter( 'presenter-reveal-css-dependencies', $style_filter );
		presenter::get_instance()->single_template( '/tmp/fallback.php' );
		remove_filter( 'presenter-reveal-js-dependencies', $script_filter );
		remove_filter( 'presenter-reveal-css-dependencies', $style_filter );

		$reveal_script = wp_scripts()->query( 'reveal', 'registered' );
		$reveal_style  = wp_styles()->query( 'reveal', 'registered' );

		$this->assertInstanceOf( _WP_Dependency::class, $reveal_script );
		$this->assertInstanceOf( _WP_Dependency::class, $reveal_style );
		$this->assertSame(
			array(
				'RevealMarkdown',
				'RevealSearch',
				'RevealNotes',
				'RevealMath',
				'RevealZoom',
				'RevealHighlight',
				'PresenterCharacterizationPlugin',
			),
			$reveal_script->deps
		);
		$this->assertSame(
			array( 'RevealHighlightStyle', 'PresenterCharacterizationStyle' ),
			$reveal_style->deps
		);
	}

	/**
	 * Presenter registers its stable public frontend asset handles.
	 */
	public function test_presentation_asset_handles_are_registered_with_expected_sources(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		update_post_meta( $post_id, '_presenter-theme', '/presenter-themes/custom.css' );
		$this->go_to( get_permalink( $post_id ) );
		$this->set_slideshow_as_global_post( $post_id );
		$this->reset_presentation_asset_registrations();

		$received_theme = null;
		$theme_filter   = static function ( string $theme ) use ( &$received_theme ): string {
			$received_theme = $theme;
			return 'https://assets.example.test/presenter-theme.css';
		};
		add_filter( 'presenter-theme', $theme_filter );
		presenter::get_instance()->single_template( '/tmp/fallback.php' );
		remove_filter( 'presenter-theme', $theme_filter );

		$reveal_script   = wp_scripts()->query( 'reveal', 'registered' );
		$presenter_style = wp_styles()->query( 'presenter', 'registered' );
		$reveal_style    = wp_styles()->query( 'reveal', 'registered' );
		$theme_style     = wp_styles()->query( 'reveal-theme', 'registered' );

		$this->assertTrue( wp_script_is( 'reveal', 'registered' ) );
		$this->assertTrue( wp_style_is( 'presenter', 'registered' ) );
		$this->assertTrue( wp_style_is( 'reveal', 'registered' ) );
		$this->assertTrue( wp_style_is( 'reveal-theme', 'registered' ) );
		$this->assertInstanceOf( _WP_Dependency::class, $reveal_script );
		$this->assertInstanceOf( _WP_Dependency::class, $presenter_style );
		$this->assertInstanceOf( _WP_Dependency::class, $reveal_style );
		$this->assertInstanceOf( _WP_Dependency::class, $theme_style );
		$this->assertSame( plugins_url( 'reveal.js/dist/reveal.js', dirname( __DIR__, 2 ) . '/presenter.php' ), $reveal_script->src );
		$this->assertSame( plugins_url( 'css/presenter.css', dirname( __DIR__, 2 ) . '/presenter.php' ), $presenter_style->src );
		$this->assertSame( plugins_url( 'reveal.js/dist/reveal.css', dirname( __DIR__, 2 ) . '/presenter.php' ), $reveal_style->src );
		$this->assertSame( content_url( '/presenter-themes/custom.css' ), $received_theme );
		$this->assertSame( 'https://assets.example.test/presenter-theme.css', $theme_style->src );
		$this->assertSame( '4.1.2', $reveal_script->ver );
		$this->assertSame( '4.1.2', $reveal_style->ver );
	}

	/**
	 * Assert that output markers appear in their supplied order.
	 *
	 * @param string        $output  Rendered template output.
	 * @param array<string> $markers Ordered markers.
	 */
	private function assertOutputMarkersAreOrdered( string $output, array $markers ): void {
		$previous_position = -1;

		foreach ( $markers as $marker ) {
			$position = strpos( $output, $marker );
			$this->assertIsInt( $position, "Expected output marker not found: {$marker}" );
			$this->assertGreaterThan( $previous_position, $position, "Output marker was out of order: {$marker}" );
			$previous_position = $position;
		}
	}

	/**
	 * Set the slideshow as the current global post for get_the_ID()-based behavior.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function set_slideshow_as_global_post( int $post_id ): void {
		global $post;

		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Presenter 1.x resolves its theme through the global post.
		setup_postdata( $post );
	}

	/**
	 * Remove presentation registrations that otherwise persist between tests.
	 */
	private function reset_presentation_asset_registrations(): void {
		wp_deregister_script( 'reveal' );
		wp_deregister_style( 'presenter' );
		wp_deregister_style( 'reveal' );
		wp_deregister_style( 'reveal-theme' );
	}
}
