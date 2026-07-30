<?php
/**
 * Presenter template routing.
 *
 * @package Presenter
 */

namespace Presenter;

use WP_Post;

/**
 * Selects the native runtime without disturbing legacy or protected decks.
 */
final class Template_Router implements Hook_Provider {
	/**
	 * Plugin context.
	 *
	 * @var Plugin_Context
	 */
	private Plugin_Context $context;

	/**
	 * Authoritative deck-mode resolver.
	 *
	 * @var Deck_Mode
	 */
	private Deck_Mode $deck_mode;

	/**
	 * Modern asset registry.
	 *
	 * @var Assets
	 */
	private Assets $assets;

	/**
	 * Theme registry.
	 *
	 * @var Theme_Registry
	 */
	private Theme_Registry $themes;

	/**
	 * Native Deck structure validator.
	 *
	 * @var Native_Deck_Structure
	 */
	private Native_Deck_Structure $deck_structure;

	/**
	 * Create the template router.
	 *
	 * @param Plugin_Context        $context        Plugin context.
	 * @param Deck_Mode             $deck_mode      Deck-mode resolver.
	 * @param Assets                $assets         Modern assets.
	 * @param Theme_Registry        $themes         Theme registry.
	 * @param Native_Deck_Structure $deck_structure Native Deck structure validator.
	 */
	public function __construct(
		Plugin_Context $context,
		Deck_Mode $deck_mode,
		Assets $assets,
		Theme_Registry $themes,
		Native_Deck_Structure $deck_structure
	) {
		$this->context        = $context;
		$this->deck_mode      = $deck_mode;
		$this->assets         = $assets;
		$this->themes         = $themes;
		$this->deck_structure = $deck_structure;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'single_template', array( $this, 'route' ), 20 );
	}

	/**
	 * Route native block decks to the Presenter 2.0 shell.
	 *
	 * @param string $template Theme or earlier plugin template.
	 * @return string Selected template.
	 */
	public function route( string $template ): string {
		if ( ! is_singular( 'slideshow' ) || post_password_required( get_the_ID() ) ) {
			return $template;
		}

		$post = get_post( get_the_ID() );

		if ( ! $post instanceof WP_Post || ! $this->deck_mode->uses_native_runtime( $post->ID ) ) {
			return $template;
		}

		$deck_block = $this->deck_structure->deck_block( $post->post_content );
		if ( null === $deck_block ) {
			return $template;
		}

		$native_template = $this->context->directory() . '/templates/presentation.php';

		if ( ! is_readable( $native_template ) ) {
			return $template;
		}

		$this->assets->enqueue_presentation(
			$this->themes->presentation_stylesheet_url( $this->native_theme_id( $deck_block ) )
		);

		return $native_template;
	}

	/**
	 * Read the stable theme ID from the valid native Deck root.
	 *
	 * Unknown IDs are deliberately passed to the registry, which owns the safe,
	 * deterministic fallback policy.
	 *
	 * @param array<string, mixed> $deck_block Validated native Deck block.
	 * @return string|null Stored theme ID, or null to follow the site default.
	 */
	private function native_theme_id( array $deck_block ): ?string {
		$theme = $deck_block['attrs']['theme'] ?? null;

		return is_string( $theme ) && '' !== $theme ? $theme : null;
	}
}
