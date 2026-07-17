<?php
/**
 * Presenter block editor integration.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Supplies presentation-specific data to the slideshow block editor.
 */
final class Editor_Integration implements Hook_Provider {
	/**
	 * Theme registry.
	 *
	 * @var Theme_Registry
	 */
	private Theme_Registry $themes;

	/**
	 * Create the editor integration.
	 *
	 * @param Theme_Registry $themes Theme registry.
	 */
	public function __construct( Theme_Registry $themes ) {
		$this->themes = $themes;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'add_editor_settings' ) );
	}

	/**
	 * Add trusted theme configuration before the Presenter editor script.
	 */
	public function add_editor_settings(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'slideshow' !== $screen->post_type || ! $screen->is_block_editor() ) {
			return;
		}

		$settings = array(
			'themes'       => $this->themes->editor_themes(),
			'defaultTheme' => $this->themes->editor_default_theme(),
		);
		$json     = wp_json_encode(
			$settings,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		if ( false === $json ) {
			return;
		}

		wp_add_inline_script(
			'presenter-block-editor',
			'window.presenterEditorSettings = ' . $json . ';',
			'before'
		);
	}
}
