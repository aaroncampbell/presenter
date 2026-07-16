<?php
/**
 * Presenter block registration and server rendering.
 *
 * @package Presenter
 */

namespace Presenter;

use WP_Block_Type_Registry;
use WP_Post;

/**
 * Registers the structural Deck and Slide blocks.
 */
final class Blocks implements Hook_Provider {
	/**
	 * Plugin context.
	 *
	 * @var Plugin_Context
	 */
	private Plugin_Context $context;

	/**
	 * Create the block service.
	 *
	 * @param Plugin_Context $context Plugin context.
	 */
	public function __construct( Plugin_Context $context ) {
		$this->context = $context;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'presenter_reveal_config', array( $this, 'add_deck_dimensions' ), 10, 2 );
	}

	/**
	 * Register blocks from their canonical metadata files.
	 */
	public function register(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		if ( ! $registry->is_registered( 'presenter/deck' ) ) {
			register_block_type_from_metadata(
				$this->context->directory() . '/blocks/deck',
				array( 'render_callback' => array( $this, 'render_deck' ) )
			);
		}

		if ( ! $registry->is_registered( 'presenter/slide' ) ) {
			register_block_type_from_metadata(
				$this->context->directory() . '/blocks/slide',
				array( 'render_callback' => array( $this, 'render_slide' ) )
			);
		}
	}

	/**
	 * Render only direct Slide children, without adding a deck wrapper.
	 *
	 * WordPress has already rendered each inner block into `$content`. Returning
	 * that value unchanged preserves dynamic-block behavior and guarantees each
	 * child executes exactly once.
	 *
	 * @param array<string, mixed> $attributes Deck attributes.
	 * @param string               $content    Rendered Slide sections.
	 * @return string Rendered Slide sections.
	 */
	public function render_deck( array $attributes, string $content ): string {
		unset( $attributes );

		return $content;
	}

	/**
	 * Render a Slide as the direct Reveal section around normal block output.
	 *
	 * @param array<string, mixed> $attributes Slide attributes.
	 * @param string               $content    Rendered inner blocks.
	 * @return string Slide section.
	 */
	public function render_slide( array $attributes, string $content ): string {
		$extra_attributes = array();
		$anchor           = $this->normalize_anchor( $attributes['anchor'] ?? '' );

		if ( '' !== $anchor ) {
			$extra_attributes['id'] = $anchor;
		}

		if ( true === ( $attributes['hidden'] ?? false ) ) {
			$extra_attributes['data-visibility'] = 'hidden';
		}

		$wrapper = get_block_wrapper_attributes( $extra_attributes );
		$notes   = $this->render_notes(
			$attributes['notes'] ?? '',
			$attributes['notesFormat'] ?? 'plain'
		);

		return '<section ' . $wrapper . '>' . $content . $notes . '</section>';
	}

	/**
	 * Add validated Deck dimensions to the Reveal configuration.
	 *
	 * @param array<string, mixed> $settings Existing Reveal settings.
	 * @param WP_Post|mixed        $post     Presentation post.
	 * @return array<string, mixed> Reveal settings.
	 */
	public function add_deck_dimensions( array $settings, mixed $post ): array {
		if ( ! $post instanceof WP_Post ) {
			return $settings;
		}

		foreach ( parse_blocks( $post->post_content ) as $block ) {
			if ( 'presenter/deck' !== ( $block['blockName'] ?? null ) ) {
				continue;
			}

			$attributes = $block['attrs'];

			foreach ( array( 'width', 'height' ) as $dimension ) {
				$value = $attributes[ $dimension ] ?? null;

				if ( is_int( $value ) && $value >= 1 && $value <= 10000 ) {
					$settings[ $dimension ] = $value;
				}
			}

			break;
		}

		return $settings;
	}

	/**
	 * Normalize a stable, user-visible slide anchor.
	 *
	 * Invalid values are rejected rather than silently rewritten to a different
	 * public URL fragment.
	 *
	 * @param mixed $anchor Candidate anchor.
	 * @return string Valid anchor or an empty string.
	 */
	private function normalize_anchor( mixed $anchor ): string {
		if ( ! is_string( $anchor ) || '' === $anchor ) {
			return '';
		}

		return sanitize_title_with_dashes( $anchor ) === $anchor ? $anchor : '';
	}

	/**
	 * Render plain-text or Markdown speaker notes as inert authored text.
	 *
	 * @param mixed $notes  Notes value.
	 * @param mixed $format Notes format.
	 * @return string Notes markup.
	 */
	private function render_notes( mixed $notes, mixed $format ): string {
		if ( ! is_string( $notes ) || '' === $notes ) {
			return '';
		}

		$markdown = 'markdown' === $format ? ' data-markdown=""' : '';

		return '<aside class="notes"' . $markdown . '>' . esc_html( $notes ) . '</aside>';
	}
}
