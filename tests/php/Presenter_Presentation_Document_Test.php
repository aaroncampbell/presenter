<?php
/**
 * Standalone presentation document isolation tests.
 *
 * @package Presenter
 */

/**
 * Verify the active site theme cannot restyle a standalone presentation.
 */
class Presenter_Presentation_Document_Test extends Presenter_Test_Case {
	/** Emit a marker from a callback implemented beneath a filtered theme root. */
	public function theme_callback_fixture(): void {
		echo '<!-- active-theme-callback -->';
	}

	/**
	 * Theme assets are removed without discarding core, plugin, or allowed assets.
	 */
	public function test_isolates_active_theme_assets_with_explicit_allowlists(): void {
		$handles    = array(
			'presenter-test-theme-style',
			'presenter-test-allowed-theme-style',
			'presenter-test-plugin-style',
			'presenter-test-theme-script',
			'presenter-test-allowed-theme-script',
			'presenter-test-plugin-script',
		);
		$theme_root = trailingslashit( get_theme_root_uri() );
		$plugin_url = plugins_url( 'tests/fixtures/presentation-document', dirname( __DIR__, 2 ) . '/presenter.php' );

		wp_register_style( $handles[0], $theme_root . 'fixture/theme.css', array(), '1' );
		wp_register_style( $handles[1], $theme_root . 'fixture/allowed.css', array(), '1' );
		wp_register_style( $handles[2], $plugin_url . '/plugin.css', array(), '1' );
		wp_register_script( $handles[3], $theme_root . 'fixture/theme.js', array(), '1', true );
		wp_register_script( $handles[4], $theme_root . 'fixture/allowed.js', array(), '1', true );
		wp_register_script( $handles[5], $plugin_url . '/plugin.js', array(), '1', true );

		foreach ( array_slice( $handles, 0, 3 ) as $handle ) {
			wp_enqueue_style( $handle );
		}
		foreach ( array_slice( $handles, 3 ) as $handle ) {
			wp_enqueue_script( $handle );
		}
		wp_enqueue_style( 'global-styles' );
		wp_register_style( 'wp-block-fixture-theme', false, array(), '1' );
		wp_enqueue_style( 'wp-block-fixture-theme' );

		$allowed_styles  = static fn (): array => array( 'presenter-test-allowed-theme-style' );
		$allowed_scripts = static fn (): array => array( 'presenter-test-allowed-theme-script' );
		add_filter( 'presenter_presentation_allowed_theme_style_handles', $allowed_styles );
		add_filter( 'presenter_presentation_allowed_theme_script_handles', $allowed_scripts );

		try {
			\Presenter\Presentation_Document::begin();

			$this->assertFalse( wp_style_is( $handles[0], 'enqueued' ) );
			$this->assertTrue( wp_style_is( $handles[1], 'enqueued' ) );
			$this->assertTrue( wp_style_is( $handles[2], 'enqueued' ) );
			$this->assertFalse( wp_script_is( $handles[3], 'enqueued' ) );
			$this->assertTrue( wp_script_is( $handles[4], 'enqueued' ) );
			$this->assertTrue( wp_script_is( $handles[5], 'enqueued' ) );
			$this->assertFalse( wp_style_is( 'global-styles', 'enqueued' ) );
			$this->assertFalse( wp_style_is( 'wp-block-fixture-theme', 'enqueued' ) );
			$this->assertSame(
				PHP_INT_MAX,
				has_action( 'wp_enqueue_scripts', array( \Presenter\Presentation_Document::class, 'isolate_assets' ) )
			);
		} finally {
			\Presenter\Presentation_Document::end();
			remove_filter( 'presenter_presentation_allowed_theme_style_handles', $allowed_styles );
			remove_filter( 'presenter_presentation_allowed_theme_script_handles', $allowed_scripts );
			foreach ( array_slice( $handles, 0, 3 ) as $handle ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
			wp_dequeue_style( 'wp-block-fixture-theme' );
			wp_deregister_style( 'wp-block-fixture-theme' );
			foreach ( array_slice( $handles, 3 ) as $handle ) {
				wp_dequeue_script( $handle );
				wp_deregister_script( $handle );
			}
		}

		$this->assertFalse(
			has_action( 'wp_enqueue_scripts', array( \Presenter\Presentation_Document::class, 'isolate_assets' ) )
		);
	}

	/**
	 * Theme-owned callbacks are absent only while the presentation is rendered.
	 */
	public function test_temporarily_removes_theme_callbacks_from_document_hooks(): void {
		$theme_directory = static fn (): string => __DIR__;
		$callback        = array( $this, 'theme_callback_fixture' );
		add_filter( 'stylesheet_directory', $theme_directory );
		add_filter( 'template_directory', $theme_directory );
		add_action( 'wp_head', $callback );

		try {
			\Presenter\Presentation_Document::begin();
			$this->assertFalse( has_action( 'wp_head', $callback ) );
			\Presenter\Presentation_Document::end();
			$this->assertSame( 10, has_action( 'wp_head', $callback ) );
		} finally {
			\Presenter\Presentation_Document::end();
			remove_action( 'wp_head', $callback );
			remove_filter( 'stylesheet_directory', $theme_directory );
			remove_filter( 'template_directory', $theme_directory );
		}
	}
}
