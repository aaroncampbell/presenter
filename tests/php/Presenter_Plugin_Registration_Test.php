<?php
/**
 * Plugin registration characterization tests.
 *
 * @package Presenter
 */

/**
 * Verify the legacy plugin loads in the WordPress test environment.
 */
class Presenter_Plugin_Registration_Test extends Presenter_Test_Case {
	/**
	 * Presenter registers its historical slideshow post type.
	 */
	public function test_slideshow_post_type_is_registered(): void {
		$post_type = get_post_type_object( 'slideshow' );

		$this->assertNotNull( $post_type );
		$this->assertTrue( $post_type->public );
		$this->assertSame( 'slideshows', $post_type->has_archive );
		$supports = array_keys( get_all_post_type_supports( 'slideshow' ) );
		sort( $supports );
		$this->assertSame(
			array( 'autosave', 'custom-fields', 'editor', 'excerpt', 'page-attributes', 'revisions', 'title' ),
			$supports
		);
	}

	/**
	 * The presenter-url shortcode prefers the stored short URL.
	 */
	public function test_presenter_url_shortcode_prefers_short_url(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_presenter-short-url', 'https://example.test/talk' );

		$this->assertSame(
			'https://example.test/talk',
			$this->render_presenter_url_shortcode( $post_id )
		);
	}

	/**
	 * The presenter-url shortcode falls back to the slideshow permalink.
	 */
	public function test_presenter_url_shortcode_falls_back_to_permalink(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);

		$this->assertSame(
			get_permalink( $post_id ),
			$this->render_presenter_url_shortcode( $post_id )
		);
	}

	/**
	 * An unprotected slideshow uses Presenter's presentation template.
	 */
	public function test_unprotected_slideshow_uses_presenter_template(): void {
		global $wp_scripts;

		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		$this->go_to( get_permalink( $post_id ) );
		$wp_scripts = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reproduces WordPress 7 template-loader timing.

		$template = presenter::get_instance()->single_template( '/tmp/fallback.php' );

		$this->assertSame( dirname( __DIR__, 2 ) . '/templates/index.php', $template );
		$this->assertInstanceOf( WP_Scripts::class, wp_scripts() );
	}

	/**
	 * A protected slideshow retains WordPress's password-aware theme template.
	 */
	public function test_protected_slideshow_keeps_theme_template(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'     => 'slideshow',
				'post_status'   => 'publish',
				'post_password' => 'local-secret',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame(
			'/tmp/fallback.php',
			presenter::get_instance()->single_template( '/tmp/fallback.php' )
		);
	}

	/**
	 * Render the shortcode with a specific slideshow as the global post.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return string
	 */
	private function render_presenter_url_shortcode( int $post_id ): string {
		global $post;

		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Characterizing a context-dependent shortcode.
		setup_postdata( $post );
		$output = do_shortcode( '[presenter-url ignored="value"]ignored content[/presenter-url]' );
		wp_reset_postdata();

		return $output;
	}
}
