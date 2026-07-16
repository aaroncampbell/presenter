<?php
/**
 * Presenter 2.0 theme registry tests.
 *
 * @package Presenter
 */

use Presenter\Application;
use Presenter\Theme;

/**
 * Verify stable theme identities and compatibility seams.
 */
class Presenter_Modern_Theme_Registry_Test extends Presenter_Test_Case {
	/**
	 * Reveal's bundled themes use stable IDs and built asset URLs.
	 */
	public function test_builtin_themes_have_stable_ids_and_built_stylesheets(): void {
		$application = $this->application();
		do_action( 'init' );

		$themes = $application->themes()->all();

		$this->assertArrayHasKey( 'black', $themes );
		$this->assertArrayHasKey( 'white', $themes );
		$this->assertArrayHasKey( 'dracula', $themes );
		$this->assertSame( 'black', $application->themes()->default_theme()->id() );
		$this->assertStringEndsWith(
			'/build/reveal/theme/black.css',
			$themes['black']->stylesheet_url()
		);
	}

	/**
	 * Extensions can add a stable theme and select it as the default.
	 */
	public function test_extensions_can_filter_the_registry_and_default_id(): void {
		$application = $this->application();
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
		add_filter( 'presenter_theme_registry', $add_theme );
		add_filter( 'presenter_default_theme_id', $set_default );

		$default = $application->themes()->default_theme();

		remove_filter( 'presenter_theme_registry', $add_theme );
		remove_filter( 'presenter_default_theme_id', $set_default );

		$this->assertSame( $custom, $default );
	}

	/**
	 * A filtered registry still resolves deterministically without black.
	 */
	public function test_filtered_registry_falls_back_to_its_first_theme(): void {
		$application = $this->application();
		$custom      = new Theme(
			'fixture-only',
			'Fixture Only',
			'https://assets.example.test/fixture-only.css'
		);
		$only_custom = static function () use ( $custom ): array {
			return array( $custom->id() => $custom );
		};
		$unknown_id  = static function (): string {
			return 'missing-theme';
		};
		add_filter( 'presenter_theme_registry', $only_custom );
		add_filter( 'presenter_default_theme_id', $unknown_id );

		$default = $application->themes()->default_theme();

		remove_filter( 'presenter_theme_registry', $only_custom );
		remove_filter( 'presenter_default_theme_id', $unknown_id );

		$this->assertSame( $custom, $default );
	}

	/**
	 * Legacy stylesheet URLs continue through the historical public filter.
	 */
	public function test_legacy_stylesheet_adapter_preserves_the_public_filter(): void {
		$application = $this->application();
		$filter      = static function (): string {
			return 'https://assets.example.test/legacy-adapted.css';
		};
		add_filter( 'presenter-theme', $filter );

		$adapted = $application->themes()->adapt_legacy_stylesheet_url(
			'https://assets.example.test/legacy-original.css'
		);

		remove_filter( 'presenter-theme', $filter );

		$this->assertSame( 'https://assets.example.test/legacy-adapted.css', $adapted );
	}

	/**
	 * A stored stable ID selects its theme and an unknown ID safely falls back.
	 */
	public function test_presentation_stylesheet_resolves_stable_id_with_safe_fallback(): void {
		$themes = $this->application()->themes();

		$this->assertStringEndsWith(
			'/build/reveal/theme/white.css',
			$themes->presentation_stylesheet_url( 'white' )
		);
		$this->assertStringEndsWith(
			'/build/reveal/theme/black.css',
			$themes->presentation_stylesheet_url( '../../not-a-theme' )
		);
	}

	/**
	 * An explicit stable ID is not replaced by the legacy site-default seam.
	 */
	public function test_explicit_theme_is_not_overwritten_by_legacy_default_filter(): void {
		$legacy_default = static function (): string {
			return '/presenter-themes/site-default.css';
		};
		add_filter( 'presenter-default-theme', $legacy_default );

		$explicit = $this->application()->themes()->presentation_stylesheet_url( 'white' );
		$default  = $this->application()->themes()->presentation_stylesheet_url();

		remove_filter( 'presenter-default-theme', $legacy_default );

		$this->assertStringEndsWith( '/build/reveal/theme/white.css', $explicit );
		$this->assertSame( content_url( '/presenter-themes/site-default.css' ), $default );
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
