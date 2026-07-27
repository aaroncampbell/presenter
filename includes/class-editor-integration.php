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
	 * Native deck structure validator.
	 *
	 * @var Native_Deck_Structure
	 */
	private Native_Deck_Structure $deck_structure;

	/**
	 * Authoritative deck-mode resolver.
	 *
	 * @var Deck_Mode
	 */
	private Deck_Mode $deck_mode;

	/**
	 * Theme registry.
	 *
	 * @var Theme_Registry
	 */
	private Theme_Registry $themes;

	/**
	 * Create the editor integration.
	 *
	 * @param Theme_Registry        $themes         Theme registry.
	 * @param Deck_Mode             $deck_mode      Authoritative deck-mode resolver.
	 * @param Native_Deck_Structure $deck_structure Native deck structure validator.
	 */
	public function __construct( Theme_Registry $themes, Deck_Mode $deck_mode, Native_Deck_Structure $deck_structure ) {
		$this->themes         = $themes;
		$this->deck_mode      = $deck_mode;
		$this->deck_structure = $deck_structure;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'add_editor_settings' ) );
		add_filter( 'use_block_editor_for_post', array( $this, 'use_block_editor_for_post' ), 10, 2 );
		add_filter( 'block_editor_settings_all', array( $this, 'use_stored_native_deck' ), 10, 2 );
	}

	/**
	 * Stop comparing a valid stored deck with the one-slide starter template.
	 *
	 * The post-type template seeds new slideshows and locks the root block list.
	 * Once a valid native Deck is stored, its Slides and their blocks are authored
	 * content rather than a fixed template. Removing only the equality template
	 * prevents false mismatch warnings while retaining the root template lock.
	 *
	 * @param array<string, mixed>     $settings Editor settings.
	 * @param \WP_Block_Editor_Context $context  Current editor context.
	 * @return array<string, mixed> Filtered editor settings.
	 */
	public function use_stored_native_deck( array $settings, \WP_Block_Editor_Context $context ): array {
		$post = $context->post;
		if (
			! $post instanceof \WP_Post
			|| 'slideshow' !== $post->post_type
			|| ! $this->deck_mode->uses_native_runtime( $post->ID )
			|| ! $this->deck_structure->is_valid( $post->post_content )
		) {
			return $settings;
		}

		unset( $settings['template'] );

		return $settings;
	}

	/**
	 * Keep authoritative legacy decks in their purpose-built classic editor.
	 *
	 * Presenter 1.x stores slides outside post content and edits them through
	 * per-slide TinyMCE controls. The native slideshow template therefore does
	 * not describe a legacy deck and must not be applied to it. New and migrated
	 * decks continue to use the block editor.
	 *
	 * @param bool     $use_block_editor Whether WordPress would use the block editor.
	 * @param \WP_Post $post             Post being edited.
	 * @return bool Whether the post should use the block editor.
	 */
	public function use_block_editor_for_post( bool $use_block_editor, \WP_Post $post ): bool {
		if ( ! $use_block_editor || 'slideshow' !== $post->post_type ) {
			return $use_block_editor;
		}

		return ! $this->deck_mode->uses_legacy_runtime( $post->ID );
	}

	/**
	 * Add trusted theme configuration before the Presenter editor script.
	 */
	public function add_editor_settings(): void {
		$screen = get_current_screen();

		if ( ! $screen || 'slideshow' !== $screen->post_type || ! $screen->is_block_editor() ) {
			return;
		}

		ob_start();
		do_action( 'presenter_editor_preview_footer' );
		$preview_footer_html = ob_get_clean();

		$settings = array(
			'themes'            => $this->themes->editor_themes(),
			'defaultTheme'      => $this->themes->editor_default_theme(),
			'previewFooterHtml' => false === $preview_footer_html ? '' : $preview_footer_html,
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
