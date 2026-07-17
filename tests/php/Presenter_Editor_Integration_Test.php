<?php
/**
 * Presenter slideshow block editor integration tests.
 *
 * @package Presenter
 */

use Presenter\Application;
use Presenter\Theme;

/**
 * Verify that trusted theme data is scoped to the slideshow block editor.
 */
class Presenter_Editor_Integration_Test extends Presenter_Test_Case {
	/**
	 * Restore the current screen after each test.
	 */
	public function tear_down(): void {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * The slideshow editor receives the ordered registry and resolved default.
	 */
	public function test_slideshow_block_editor_receives_theme_settings_before_script(): void {
		$application = $this->application();
		$unsafe      = new Theme(
			'fixture-safe-json',
			'Fixture </script><script>alert(1)</script>',
			'https://assets.example.test/fixture-safe-json.css'
		);
		$add_theme   = static function ( array $themes ) use ( $unsafe ): array {
			$themes[ $unsafe->id() ] = $unsafe;

			return $themes;
		};
		add_filter( 'presenter_theme_registry', $add_theme );

		$inline = $this->enqueue_editor_settings( 'slideshow', true );

		remove_filter( 'presenter_theme_registry', $add_theme );

		$this->assertStringStartsWith( 'window.presenterEditorSettings = ', $inline );
		$this->assertStringNotContainsString( '</script>', $inline );

		$settings = $this->decode_settings( $inline );
		$this->assertSame(
			array( 'themes', 'defaultTheme' ),
			array_keys( $settings )
		);
		$this->assertSame( 'beige', $settings['themes'][0]['id'] );
		$this->assertSame( 'Beige', $settings['themes'][0]['label'] );
		$this->assertStringEndsWith(
			'/build/reveal/theme/beige.css',
			$settings['themes'][0]['stylesheetUrl']
		);
		$this->assertSame( 'fixture-safe-json', $settings['themes'][14]['id'] );
		$this->assertSame( $unsafe->label(), $settings['themes'][14]['label'] );
		$this->assertSame(
			array(
				'id'            => 'black',
				'label'         => 'Black',
				'stylesheetUrl' => $application->themes()->presentation_stylesheet_url(),
			),
			$settings['defaultTheme']
		);
	}

	/**
	 * The default serialization honors modern identity and legacy URL seams.
	 */
	public function test_default_theme_settings_follow_compatibility_resolution(): void {
		$custom      = new Theme(
			'fixture-purple',
			'Fixture Purple',
			'https://assets.example.test/fixture-purple.css'
		);
		$add_theme   = static function ( array $themes ) use ( $custom ): array {
			$themes[ $custom->id() ] = $custom;

			return $themes;
		};
		$set_default = static function (): string {
			return 'fixture-purple';
		};
		$legacy_url  = static function (): string {
			return '/presenter-themes/legacy-site-default.css';
		};
		add_filter( 'presenter_theme_registry', $add_theme );
		add_filter( 'presenter_default_theme_id', $set_default );
		add_filter( 'presenter-default-theme', $legacy_url );

		$default = $this->application()->themes()->editor_default_theme();

		remove_filter( 'presenter_theme_registry', $add_theme );
		remove_filter( 'presenter_default_theme_id', $set_default );
		remove_filter( 'presenter-default-theme', $legacy_url );

		$this->assertSame(
			array(
				'id'            => 'fixture-purple',
				'label'         => 'Fixture Purple',
				'stylesheetUrl' => content_url( '/presenter-themes/legacy-site-default.css' ),
			),
			$default
		);
	}

	/**
	 * Theme settings are absent on unrelated and classic-editor screens.
	 */
	public function test_theme_settings_are_scoped_to_slideshow_block_editor(): void {
		$this->assertSame( '', $this->enqueue_editor_settings( 'post', true ) );
		$this->assertSame( '', $this->enqueue_editor_settings( 'slideshow', false ) );
	}

	/**
	 * Run the editor asset hook for one simulated screen.
	 *
	 * @param string $screen_id       Screen ID.
	 * @param bool   $is_block_editor Whether the screen uses the block editor.
	 * @return string Added inline script, or an empty string.
	 */
	private function enqueue_editor_settings( string $screen_id, bool $is_block_editor ): string {
		do_action( 'init' );
		wp_scripts()->add_data( 'presenter-block-editor', 'before', array() );

		set_current_screen( $screen_id );
		$screen = get_current_screen();
		$this->assertInstanceOf( WP_Screen::class, $screen );
		$screen->is_block_editor( $is_block_editor );
		do_action( 'enqueue_block_editor_assets' );

		$before = wp_scripts()->get_data( 'presenter-block-editor', 'before' );

		return is_array( $before ) ? implode( "\n", $before ) : '';
	}

	/**
	 * Decode an inline settings assignment.
	 *
	 * @param string $inline Inline script.
	 * @return array<string, mixed> Decoded settings.
	 */
	private function decode_settings( string $inline ): array {
		$json = substr( $inline, strlen( 'window.presenterEditorSettings = ' ), -1 );
		$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		$this->assertIsArray( $data );

		return $data;
	}

	/**
	 * Get the active Presenter 2.0 application.
	 *
	 * @return Application Presenter application.
	 */
	private function application(): Application {
		$application = presenter_get_runtime();
		$this->assertInstanceOf( Application::class, $application );

		return $application;
	}
}
