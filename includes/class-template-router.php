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
	 * Read-only legacy slide source.
	 *
	 * @var Legacy_Slide_Source
	 */
	private Legacy_Slide_Source $legacy_slides;

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
	 * Create the template router.
	 *
	 * @param Plugin_Context      $context       Plugin context.
	 * @param Legacy_Slide_Source $legacy_slides Read-only legacy source.
	 * @param Assets              $assets        Modern assets.
	 * @param Theme_Registry      $themes        Theme registry.
	 */
	public function __construct(
		Plugin_Context $context,
		Legacy_Slide_Source $legacy_slides,
		Assets $assets,
		Theme_Registry $themes
	) {
		$this->context       = $context;
		$this->legacy_slides = $legacy_slides;
		$this->assets        = $assets;
		$this->themes        = $themes;
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

		if (
			! $post instanceof WP_Post ||
			$this->legacy_slides->has_slides( $post->ID ) ||
			! $this->has_valid_native_deck( $post )
		) {
			return $template;
		}

		$native_template = $this->context->directory() . '/templates/presentation.php';

		if ( ! is_readable( $native_template ) ) {
			return $template;
		}

		$this->assets->enqueue_presentation(
			$this->themes->presentation_stylesheet_url( $this->native_theme_id( $post ) )
		);

		return $native_template;
	}

	/**
	 * Read the stable theme ID from the valid native Deck root.
	 *
	 * Unknown IDs are deliberately passed to the registry, which owns the safe,
	 * deterministic fallback policy.
	 *
	 * @param WP_Post $post Native presentation post.
	 * @return string|null Stored theme ID, or null to follow the site default.
	 */
	private function native_theme_id( WP_Post $post ): ?string {
		$blocks = parse_blocks( $post->post_content );
		$theme  = $blocks[0]['attrs']['theme'] ?? null;

		return is_string( $theme ) && '' !== $theme ? $theme : null;
	}

	/**
	 * Confirm post content can produce a valid flat Reveal slide hierarchy.
	 *
	 * Editor constraints improve authoring but are not a rendering boundary;
	 * manually edited markup must not opt malformed content into Reveal.
	 *
	 * @param WP_Post $post Candidate slideshow post.
	 * @return bool Whether the post contains one valid native Deck tree.
	 */
	private function has_valid_native_deck( WP_Post $post ): bool {
		$root_blocks = array_values(
			array_filter(
				parse_blocks( $post->post_content ),
				static function ( array $block ): bool {
					return null !== $block['blockName'] || '' !== trim( $block['innerHTML'] );
				}
			)
		);

		if ( 1 !== count( $root_blocks ) || 'presenter/deck' !== ( $root_blocks[0]['blockName'] ?? null ) ) {
			return false;
		}

		foreach ( $root_blocks[0]['innerContent'] as $saved_fragment ) {
			if ( is_string( $saved_fragment ) && '' !== trim( $saved_fragment ) ) {
				return false;
			}
		}

		$slides = $root_blocks[0]['innerBlocks'];

		if ( array() === $slides ) {
			return false;
		}

		foreach ( $slides as $slide ) {
			if ( 'presenter/slide' !== ( $slide['blockName'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}
}
