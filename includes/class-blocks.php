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
	 * Shared Slide attribute validator.
	 *
	 * @var Slide_Attribute_Validator
	 */
	private Slide_Attribute_Validator $slide_attributes;

	/**
	 * Speaker notes security and rendering policy.
	 *
	 * @var Speaker_Notes
	 */
	private Speaker_Notes $speaker_notes;

	/**
	 * Create the block service.
	 *
	 * @param Plugin_Context            $context          Plugin context.
	 * @param Slide_Attribute_Validator $slide_attributes Slide attribute validator.
	 * @param Speaker_Notes             $speaker_notes    Speaker notes policy.
	 */
	public function __construct( Plugin_Context $context, Slide_Attribute_Validator $slide_attributes, Speaker_Notes $speaker_notes ) {
		$this->context          = $context;
		$this->slide_attributes = $slide_attributes;
		$this->speaker_notes    = $speaker_notes;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'register_block_type_args', array( $this, 'add_fragment_block_contract' ), 10, 2 );
		add_filter( 'render_block', array( $this, 'render_fragment' ), 10, 3 );
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'presenter_reveal_config', array( $this, 'add_deck_settings' ), 10, 2 );
	}

	/**
	 * Register Presenter fragment attributes and Slide context for content blocks.
	 *
	 * @param array<string, mixed> $args       Block type registration arguments.
	 * @param string               $block_type Block type name.
	 * @return array<string, mixed> Filtered registration arguments.
	 */
	public function add_fragment_block_contract( array $args, string $block_type ): array {
		if ( in_array( $block_type, array( 'presenter/deck', 'presenter/slide' ), true ) ) {
			return $args;
		}

		$attributes = is_array( $args['attributes'] ?? null ) ? $args['attributes'] : array();
		$attributes = array_merge(
			$attributes,
			array(
				'presenterFragment'              => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'presenterFragmentEffect'        => array(
					'type'    => 'string',
					'default' => '',
				),
				'presenterFragmentCustomClasses' => array(
					'type'    => 'string',
					'default' => '',
				),
				'presenterFragmentIndex'         => array( 'type' => 'number' ),
			)
		);

		$uses_context   = is_array( $args['uses_context'] ?? null ) ? $args['uses_context'] : array();
		$uses_context[] = 'presenter/insideSlide';

		$args['attributes']   = $attributes;
		$args['uses_context'] = array_values( array_unique( $uses_context ) );

		return $args;
	}

	/**
	 * Add Reveal fragment behavior to a content block inside a Presenter Slide.
	 *
	 * @param string    $block_content Rendered block markup.
	 * @param array     $block         Parsed block data.
	 * @param \WP_Block $instance      Rendered block instance.
	 * @return string Filtered block markup.
	 */
	public function render_fragment( string $block_content, array $block, \WP_Block $instance ): string {
		if (
			true !== ( $instance->context['presenter/insideSlide'] ?? null )
			|| true !== ( $block['attrs']['presenterFragment'] ?? false )
			|| in_array( $block['blockName'] ?? null, array( 'presenter/deck', 'presenter/slide' ), true )
			|| '' === trim( $block_content )
		) {
			return $block_content;
		}

		$classes = $this->fragment_classes( $block['attrs'] );
		if ( null === $classes ) {
			return $block_content;
		}

		$processor = new \WP_HTML_Tag_Processor( $block_content );
		if ( ! $processor->next_tag() ) {
			return $block_content;
		}

		foreach ( $classes as $class_name ) {
			$processor->add_class( $class_name );
		}

		$index = $block['attrs']['presenterFragmentIndex'] ?? null;
		if ( is_int( $index ) && $index >= 0 && $index <= 9999 ) {
			$processor->set_attribute( 'data-fragment-index', (string) $index );
		}

		return $processor->get_updated_html();
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

		if ( ! $registry->is_registered( 'presenter/chart' ) ) {
			register_block_type_from_metadata(
				$this->context->directory() . '/blocks/chart',
				array( 'render_callback' => array( $this, 'render_chart' ) )
			);
		}
	}

	/**
	 * Render an accessible chart canvas with a tabular fallback.
	 *
	 * @param array<string, mixed> $attributes Chart block attributes.
	 * @return string Rendered chart markup.
	 */
	public function render_chart( array $attributes ): string {
		$columns = $this->normalize_chart_row( $attributes['columns'] ?? null );
		$rows    = array();
		if ( is_array( $attributes['rows'] ?? null ) ) {
			foreach ( $attributes['rows'] as $row ) {
				$normalized_row = $this->normalize_chart_row( $row );
				if ( array() !== $normalized_row ) {
					$rows[] = $normalized_row;
				}
			}
		}
		if ( count( $columns ) < 2 || array() === $rows ) {
			return '';
		}

		$config = wp_json_encode(
			array(
				'chartType' => in_array( $attributes['chartType'] ?? '', array( 'line', 'bar' ), true ) ? $attributes['chartType'] : 'line',
				'columns'   => $columns,
				'rows'      => $rows,
				'options'   => $this->normalize_chart_options( $attributes['options'] ?? null ),
			)
		);
		if ( false === $config ) {
			return '';
		}

		$width       = min( 2000, max( 200, (int) ( $attributes['width'] ?? 800 ) ) );
		$height      = min( 1200, max( 150, (int) ( $attributes['height'] ?? 400 ) ) );
		$caption     = is_string( $attributes['caption'] ?? null ) ? $attributes['caption'] : '';
		$table_label = '' !== $caption ? $caption : __( 'Chart data', 'presenter' );

		$html  = '<figure class="wp-block-presenter-chart presenter-chart" style="height:' . $height . 'px;max-width:' . $width . 'px" data-presenter-chart="' . esc_attr( $config ) . '">';
		$html .= '<canvas aria-hidden="true"></canvas>';
		if ( '' !== $caption ) {
			$html .= '<figcaption>' . esc_html( $caption ) . '</figcaption>';
		}
		$html .= '<table class="presenter-chart-data" aria-label="' . esc_attr( $table_label ) . '"><thead><tr>';
		foreach ( $columns as $column ) {
			$html .= '<th scope="col">' . esc_html( null === $column ? '' : (string) $column ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			foreach ( array_values( $row ) as $index => $value ) {
				$tag_name        = 0 === $index ? 'th' : 'td';
				$cell_attributes = 0 === $index ? ' scope="row"' : '';
				$html           .= '<' . $tag_name . $cell_attributes . '>' . esc_html( null === $value ? '' : (string) $value ) . '</' . $tag_name . '>';
			}
			$html .= '</tr>';
		}

		return $html . '</tbody></table></figure>';
	}

	/**
	 * Keep chart cells inert, JSON-safe scalar values.
	 *
	 * @param mixed $row Candidate chart row.
	 * @return array<int, scalar|null> Normalized row, or empty for a non-row.
	 */
	private function normalize_chart_row( mixed $row ): array {
		if ( ! is_array( $row ) ) {
			return array();
		}

		return array_map(
			static function ( mixed $value ): string|int|float|bool|null {
				if ( is_float( $value ) && ! is_finite( $value ) ) {
					return null;
				}

				return is_scalar( $value ) || null === $value ? $value : null;
			},
			array_values( $row )
		);
	}

	/**
	 * Retain only the semantic option subset consumed by the Chart.js adapter.
	 *
	 * @param mixed $options Candidate block options.
	 * @return array<string, mixed> Validated semantic options.
	 */
	private function normalize_chart_options( mixed $options ): array {
		if ( ! is_array( $options ) ) {
			return array();
		}

		$normalized = array();
		foreach ( array( 'title', 'valueSuffix' ) as $key ) {
			if ( is_string( $options[ $key ] ?? null ) ) {
				$normalized[ $key ] = substr( $options[ $key ], 0, 200 );
			}
		}

		$legend = $options['legend'] ?? null;
		if ( is_array( $legend ) && 'none' === ( $legend['position'] ?? null ) ) {
			$normalized['legend'] = array( 'position' => 'none' );
		}

		foreach ( array( 'hAxis', 'vAxis' ) as $axis ) {
			$source = $options[ $axis ] ?? null;
			if ( ! is_array( $source ) ) {
				continue;
			}
			$settings = array();
			if ( is_string( $source['title'] ?? null ) ) {
				$settings['title'] = substr( $source['title'], 0, 200 );
			}
			if ( 'vAxis' === $axis ) {
				foreach ( array( 'minValue', 'maxValue' ) as $bound ) {
					$value = $source[ $bound ] ?? null;
					if ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
						$settings[ $bound ] = $value;
					}
				}
			}
			if ( array() !== $settings ) {
				$normalized[ $axis ] = $settings;
			}
		}

		return $normalized;
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
		$label            = $attributes['label'] ?? '';

		if ( is_string( $label ) && '' !== $label ) {
			$extra_attributes['aria-label'] = $label;
		}

		if ( '' !== $anchor ) {
			$extra_attributes['id'] = $anchor;
		}

		if ( true === ( $attributes['hidden'] ?? false ) ) {
			$extra_attributes['data-visibility'] = 'hidden';
		}

		$transition = $attributes['transition'] ?? '';
		if ( is_string( $transition ) && in_array( $transition, $this->transitions(), true ) ) {
			$extra_attributes['data-transition'] = $transition;
		}

		$background_color = $attributes['backgroundColor'] ?? '';
		if ( is_string( $background_color ) && 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', $background_color ) ) {
			$extra_attributes['data-background-color'] = $background_color;
		}

		$background_image = $this->sanitize_background_image_url( $attributes['backgroundImageUrl'] ?? '' );
		if ( '' !== $background_image ) {
			$extra_attributes['data-background-image'] = $background_image;
		}

		$background_size = $attributes['backgroundSize'] ?? '';
		if ( $this->slide_attributes->background_size( $background_size ) ) {
			$extra_attributes['data-background-size'] = $background_size;
		}
		$this->add_controlled_slide_attribute(
			$extra_attributes,
			$attributes,
			'backgroundPosition',
			'data-background-position',
			array( 'center', 'top', 'top right', 'right', 'bottom right', 'bottom', 'bottom left', 'left', 'top left' )
		);
		$this->add_controlled_slide_attribute(
			$extra_attributes,
			$attributes,
			'backgroundRepeat',
			'data-background-repeat',
			array( 'no-repeat', 'repeat', 'repeat-x', 'repeat-y' )
		);
		$this->add_controlled_slide_attribute(
			$extra_attributes,
			$attributes,
			'backgroundTransition',
			'data-background-transition',
			$this->transitions()
		);

		$background_opacity = $attributes['backgroundOpacity'] ?? null;
		if ( ( is_float( $background_opacity ) || is_int( $background_opacity ) ) && $background_opacity >= 0 && $background_opacity <= 1 ) {
			$extra_attributes['data-background-opacity'] = (string) $background_opacity;
		}

		if ( true === ( $attributes['autoAnimate'] ?? false ) ) {
			$extra_attributes['data-auto-animate'] = '';

			$auto_animate_id = $attributes['autoAnimateId'] ?? '';
			if ( is_string( $auto_animate_id ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $auto_animate_id ) ) {
				$extra_attributes['data-auto-animate-id'] = $auto_animate_id;
			}

			if ( true === ( $attributes['autoAnimateRestart'] ?? false ) ) {
				$extra_attributes['data-auto-animate-restart'] = '';
			}
		}

		$custom_classes = $this->slide_attributes->classes( $attributes['className'] ?? '' );
		if ( null !== $custom_classes && '' !== $custom_classes ) {
			$extra_attributes['class'] = $custom_classes;
		}

		$reveal_data = $this->slide_attributes->reveal_data( $attributes['revealDataAttributes'] ?? array() );
		if ( null !== $reveal_data ) {
			foreach ( $reveal_data as $name => $value ) {
				$extra_attributes[ $name ] = $value;
			}
		}

		$wrapper               = get_block_wrapper_attributes( $extra_attributes );
		$legacy_auto_paragraph = true === ( $attributes['legacyAutoParagraph'] ?? false );
		$legacy_notes          = $legacy_auto_paragraph || true === ( $attributes['legacyNotesProcessing'] ?? false );
		$notes_value           = $attributes['notes'] ?? '';

		// Legacy Presenter inserted raw notes before the_content texturization.
		// Preserve that order before the speaker-notes policy escapes or sanitizes them.
		if ( $legacy_notes && is_string( $notes_value ) ) {
			$notes_value = wptexturize( $notes_value );
		}

		$notes = $this->render_notes(
			$notes_value,
			$attributes['notesFormat'] ?? 'plain',
			$legacy_notes,
			$legacy_notes && ! $legacy_auto_paragraph
		);

		$section = '<section ' . $wrapper . '>' . $content . $notes . '</section>';

		// Legacy Presenter supplied complete sections before WordPress ran wpautop().
		// Preserve that stage only for Slides created by the migration planner.
		if ( $legacy_auto_paragraph ) {
			return wpautop( $section );
		}

		return $section;
	}

	/**
	 * Add validated, explicitly supported Deck settings to Reveal.
	 *
	 * @param array<string, mixed> $settings Existing Reveal settings.
	 * @param WP_Post|mixed        $post     Presentation post.
	 * @return array<string, mixed> Reveal settings.
	 */
	public function add_deck_settings( array $settings, mixed $post ): array {
		if ( ! $post instanceof WP_Post ) {
			return $settings;
		}

		foreach ( parse_blocks( $post->post_content ) as $block ) {
			if ( 'presenter/deck' !== ( $block['blockName'] ?? null ) ) {
				continue;
			}

			$attributes = $block['attrs'];
			if ( is_rtl() ) {
				$settings['rtl'] = true;
			}

			foreach ( array( 'width', 'height' ) as $dimension ) {
				$value = $attributes[ $dimension ] ?? null;

				if ( is_int( $value ) && $value >= 1 && $value <= 10000 ) {
					$settings[ $dimension ] = $value;
				}
			}

			$value = $attributes['margin'] ?? null;
			if ( ( is_float( $value ) || is_int( $value ) ) && $value >= 0 && $value < 1 ) {
				$settings['margin'] = (float) $value;
			}

			foreach ( array( 'controls', 'progress', 'hash', 'center', 'keyboard' ) as $boolean_setting ) {
				$value = $attributes[ $boolean_setting ] ?? null;
				if ( is_bool( $value ) ) {
					$settings[ $boolean_setting ] = $value;
				}
			}

			// Never render a presentation without an available navigation method.
			if ( false === ( $settings['controls'] ?? null ) && false === ( $settings['keyboard'] ?? null ) ) {
				$settings['keyboard'] = true;
			}

			foreach ( array( 'transition', 'backgroundTransition' ) as $transition_setting ) {
				$value = $attributes[ $transition_setting ] ?? null;
				if ( is_string( $value ) && in_array( $value, $this->transitions(), true ) ) {
					$settings[ $transition_setting ] = $value;
				}
			}

			break;
		}

		return $settings;
	}

	/**
	 * Reveal transitions supported by both Deck and Slide settings.
	 *
	 * @return array<int, string> Supported transition IDs.
	 */
	private function transitions(): array {
		return array( 'none', 'fade', 'slide', 'convex', 'concave', 'zoom' );
	}

	/**
	 * Copy an allow-listed Slide setting to its Reveal data attribute.
	 *
	 * @param array<string, string> $output     Output attributes, passed by reference.
	 * @param array<string, mixed>  $attributes Parsed Slide attributes.
	 * @param string                $source     Block attribute name.
	 * @param string                $target     Reveal data attribute name.
	 * @param array<int, string>    $allowed    Accepted values.
	 */
	private function add_controlled_slide_attribute( array &$output, array $attributes, string $source, string $target, array $allowed ): void {
		$value = $attributes[ $source ] ?? '';
		if ( is_string( $value ) && in_array( $value, $allowed, true ) ) {
			$output[ $target ] = $value;
		}
	}

	/**
	 * Build validated Reveal fragment classes from parsed block attributes.
	 *
	 * @param array<string, mixed> $attributes Parsed block attributes.
	 * @return array<int, string>|null Classes, or null for an invalid effect.
	 */
	private function fragment_classes( array $attributes ): ?array {
		$effect = $attributes['presenterFragmentEffect'] ?? '';
		if ( ! is_string( $effect ) ) {
			return null;
		}

		if ( 'custom' !== $effect ) {
			if ( ! in_array( $effect, $this->fragment_effects(), true ) ) {
				return null;
			}

			return '' === $effect ? array( 'fragment' ) : array( 'fragment', $effect );
		}

		$custom_classes = $attributes['presenterFragmentCustomClasses'] ?? '';
		if ( ! is_string( $custom_classes ) || '' === trim( $custom_classes ) || strlen( $custom_classes ) > 512 ) {
			return null;
		}

		$tokens   = preg_split( '/\s+/', trim( $custom_classes ) );
		$reserved = array( 'fragment', 'visible', 'current-fragment', 'disabled' );
		if ( ! is_array( $tokens ) || count( $tokens ) > 10 ) {
			return null;
		}

		$classes = array();
		foreach ( $tokens as $token ) {
			if (
				strlen( $token ) > 64
				|| 1 !== preg_match( '/^-?[A-Za-z_][A-Za-z0-9_-]*$/', $token )
				|| in_array( $token, $reserved, true )
			) {
				return null;
			}

			$classes[] = $token;
		}

		return array_merge( array( 'fragment' ), array_values( array_unique( $classes ) ) );
	}

	/**
	 * Reveal 6 fragment effects implemented by the bundled runtime stylesheet.
	 *
	 * The empty value selects Reveal's default fade-in behavior.
	 *
	 * @return array<int, string> Supported effect class names.
	 */
	private function fragment_effects(): array {
		return array(
			'',
			'grow',
			'shrink',
			'zoom-in',
			'fade-out',
			'semi-fade-out',
			'strike',
			'fade-up',
			'fade-down',
			'fade-left',
			'fade-right',
			'fade-in-then-out',
			'current-visible',
			'fade-in-then-semi-out',
			'highlight-red',
			'highlight-green',
			'highlight-blue',
			'highlight-current-red',
			'highlight-current-green',
			'highlight-current-blue',
		);
	}

	/**
	 * Accept only sanitized HTTP(S) image URLs for Reveal data attributes.
	 *
	 * This is presentation markup, not a server-side request, so local HTTP URLs
	 * remain valid for local development and intranet installations.
	 *
	 * @param mixed $url Candidate background image URL.
	 * @return string Sanitized URL or an empty string.
	 */
	private function sanitize_background_image_url( mixed $url ): string {
		if ( ! is_string( $url ) || ! $this->slide_attributes->resource_url( $url ) ) {
			return '';
		}

		return $url;
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
	 * @param mixed $notes                         Notes value.
	 * @param mixed $format                        Notes format.
	 * @param bool  $legacy_markdown_compatibility Whether to use legacy Markdown rendering.
	 * @param bool  $legacy_auto_paragraph         Whether to restore the legacy notes-only wpautop stage.
	 * @return string Notes markup.
	 */
	private function render_notes( mixed $notes, mixed $format, bool $legacy_markdown_compatibility = false, bool $legacy_auto_paragraph = false ): string {
		return $this->speaker_notes->render( $notes, $format, $legacy_markdown_compatibility, $legacy_auto_paragraph );
	}
}
