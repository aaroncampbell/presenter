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
	 * Stored native decks keep the root lock without the starter equality check.
	 */
	public function test_stored_native_deck_does_not_use_starter_template_for_validation(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:presenter/deck -->'
					. '<!-- wp:presenter/slide --><!-- wp:html --><p>First</p><!-- /wp:html --><!-- /wp:presenter/slide -->'
					. '<!-- wp:presenter/slide /-->'
					. '<!-- /wp:presenter/deck -->',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		update_post_meta( $post_id, \Presenter\Deck_Mode::META_KEY, \Presenter\Deck_Mode::NATIVE );
		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$post_type = get_post_type_object( 'slideshow' );
		$this->assertInstanceOf( WP_Post_Type::class, $post_type );
		$template = $post_type->template;
		$settings = apply_filters(
			'block_editor_settings_all',
			array(
				'template'     => $template,
				'templateLock' => 'all',
				'sentinel'     => true,
			),
			new WP_Block_Editor_Context( array( 'post' => $post ) )
		);

		$this->assertArrayNotHasKey( 'template', $settings );
		$this->assertSame( 'all', $settings['templateLock'] );
		$this->assertTrue( $settings['sentinel'] );
	}

	/**
	 * Empty and structurally invalid decks retain the starter validation contract.
	 */
	public function test_starter_template_remains_for_empty_and_invalid_slideshows(): void {
		$post_type = get_post_type_object( 'slideshow' );
		$this->assertInstanceOf( WP_Post_Type::class, $post_type );
		$template = $post_type->template;
		$post_ids = array(
			$this->create_slideshow_without_legacy_editor_post_data( array( 'post_status' => 'draft' ) ),
			$this->create_slideshow_without_legacy_editor_post_data(
				array(
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:paragraph --><p>Invalid root</p><!-- /wp:paragraph -->',
				)
			),
		);

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			$this->assertInstanceOf( WP_Post::class, $post );
			$settings = apply_filters(
				'block_editor_settings_all',
				array(
					'template'     => $template,
					'templateLock' => 'all',
				),
				new WP_Block_Editor_Context( array( 'post' => $post ) )
			);

			$this->assertSame( $template, $settings['template'] );
			$this->assertSame( 'all', $settings['templateLock'] );
		}
	}

	/**
	 * Authoritative legacy decks use their per-slide classic editor.
	 */
	public function test_legacy_slideshow_uses_classic_editor_without_changing_storage(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Legacy post-content sentinel.',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		$post           = get_post( $post_id );
		$content_before = $post->post_content;
		$slides_before  = get_post_meta( $post_id, '_presenter_slides', false );

		$this->assertFalse( use_block_editor_for_post( $post ) );
		$this->assertSame( $content_before, get_post_field( 'post_content', $post_id ) );
		$this->assertSame( maybe_serialize( $slides_before ), maybe_serialize( get_post_meta( $post_id, '_presenter_slides', false ) ) );
	}

	/**
	 * New and native slideshows retain the native block editor contract.
	 */
	public function test_native_slideshows_use_block_editor(): void {
		$new_post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array( 'post_status' => 'draft' )
		);
		$cutover_id  = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:presenter/deck --><!-- wp:presenter/slide /--><!-- /wp:presenter/deck -->',
			)
		);
		$this->add_legacy_slide_fixture( $cutover_id );
		update_post_meta( $cutover_id, \Presenter\Deck_Mode::META_KEY, \Presenter\Deck_Mode::NATIVE );

		$this->assertTrue( use_block_editor_for_post( $new_post_id ) );
		$this->assertTrue( use_block_editor_for_post( $cutover_id ) );
	}

	/**
	 * Invalid cutover storage fails safely to the legacy editor.
	 */
	public function test_invalid_cutover_marker_with_legacy_slides_uses_classic_editor(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array( 'post_status' => 'publish' )
		);
		$this->add_legacy_slide_fixture( $post_id );
		add_post_meta( $post_id, \Presenter\Deck_Mode::META_KEY, 'invalid' );

		$this->assertFalse( use_block_editor_for_post( $post_id ) );
	}

	/**
	 * Presenter does not override other editor-routing decisions.
	 */
	public function test_editor_routing_preserves_unrelated_and_upstream_decisions(): void {
		$post_id      = self::factory()->post->create();
		$slideshow_id = $this->create_slideshow_without_legacy_editor_post_data(
			array( 'post_status' => 'draft' )
		);

		$this->assertTrue( use_block_editor_for_post( $post_id ) );

		$disable = static function ( bool $use_block_editor, \WP_Post $candidate ) use ( $slideshow_id ): bool {
			return $candidate->ID === $slideshow_id ? false : $use_block_editor;
		};
		add_filter( 'use_block_editor_for_post', $disable, 5, 2 );

		try {
			$this->assertFalse( use_block_editor_for_post( $slideshow_id ) );
		} finally {
			remove_filter( 'use_block_editor_for_post', $disable, 5 );
		}
	}

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
		$footer_html = '<p class="preview-footer">Fixture </script><script>alert(2)</script></p>';
		$add_footer  = static function () use ( $footer_html ): void {
			echo $footer_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A trusted extension hook supplies presentation markup.
		};
		add_filter( 'presenter_theme_registry', $add_theme );
		add_action( 'presenter_editor_preview_footer', $add_footer );

		$inline = $this->enqueue_editor_settings( 'slideshow', true );

		remove_filter( 'presenter_theme_registry', $add_theme );
		remove_action( 'presenter_editor_preview_footer', $add_footer );

		$this->assertStringStartsWith( 'window.presenterEditorSettings = ', $inline );
		$this->assertStringNotContainsString( '</script>', $inline );

		$settings = $this->decode_settings( $inline );
		$this->assertSame(
			array( 'themes', 'defaultTheme', 'previewFooterHtml' ),
			array_keys( $settings )
		);
		$this->assertSame( $footer_html, $settings['previewFooterHtml'] );
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

	/** Invalid theme registries fall back to safe built-ins in the editor. */
	public function test_editor_theme_settings_recover_from_invalid_registry_filter(): void {
		$invalid_registry = static fn(): string => 'invalid-registry';
		add_filter( 'presenter_theme_registry', $invalid_registry );
		$this->setExpectedIncorrectUsage( 'Presenter\\Theme_Registry::report_editor_recovery' );

		try {
			$inline = $this->enqueue_editor_settings( 'slideshow', true );
		} finally {
			remove_filter( 'presenter_theme_registry', $invalid_registry );
		}

		$settings = $this->decode_settings( $inline );
		$this->assertCount( 14, $settings['themes'] );
		$this->assertSame( 'black', $settings['defaultTheme']['id'] );
	}

	/** Invalid default IDs fall back to bundled Black in the editor. */
	public function test_editor_theme_settings_recover_from_invalid_default_filter(): void {
		$invalid_default = static fn(): array => array( 'invalid-default' );
		add_filter( 'presenter_default_theme_id', $invalid_default );
		$this->setExpectedIncorrectUsage( 'Presenter\\Theme_Registry::report_editor_recovery' );

		try {
			$inline = $this->enqueue_editor_settings( 'slideshow', true );
		} finally {
			remove_filter( 'presenter_default_theme_id', $invalid_default );
		}

		$settings = $this->decode_settings( $inline );
		$this->assertCount( 14, $settings['themes'] );
		$this->assertSame( 'black', $settings['defaultTheme']['id'] );
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
