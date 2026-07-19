<?php
/**
 * Presenter front-end presentation renderer.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Renders a Reveal shell around native or read-only legacy slide markup.
 */
final class Presentation_Renderer {
	/**
	 * Reveal configuration service.
	 *
	 * @var Reveal_Config
	 */
	private Reveal_Config $config;

	/**
	 * Create the renderer.
	 *
	 * @param Reveal_Config $config Reveal configuration service.
	 */
	public function __construct( Reveal_Config $config ) {
		$this->config = $config;
	}

	/**
	 * Render server-rendered native Slide block output.
	 *
	 * The supplied HTML has already passed through WordPress block rendering.
	 * It intentionally remains unescaped so dynamic blocks, view scripts, and
	 * arbitrary blocks retain their normal front-end markup.
	 *
	 * @param string                  $rendered_slides Rendered Slide block HTML.
	 * @param array<string, mixed>    $settings        Reveal settings.
	 * @param array<int, string>|null $plugins         Registered plugin IDs.
	 * @return string Reveal shell and configuration element.
	 */
	public function render_blocks( string $rendered_slides, array $settings = array(), ?array $plugins = null ): string {
		return $this->render_shell( $rendered_slides, $settings, $plugins );
	}

	/**
	 * Render Presenter 1.x slide values without modifying their source.
	 *
	 * Legacy content and notes are preserved as administrator-authored HTML.
	 * Only Presenter-owned wrapper attributes are constructed here, and each is
	 * escaped in its HTML attribute context.
	 *
	 * @param array<int, mixed>       $slides   Legacy slide records in stored order.
	 * @param array<string, mixed>    $settings Reveal settings.
	 * @param array<int, string>|null $plugins  Registered plugin IDs.
	 * @return string Reveal shell and configuration element.
	 */
	public function render_legacy( array $slides, array $settings = array(), ?array $plugins = null ): string {
		$html         = '';
		$used_anchors = array();

		foreach ( array_values( $slides ) as $index => $slide ) {
			$record  = $this->legacy_record( $slide );
			$anchor  = $this->legacy_anchor( $record, $index + 1, $used_anchors );
			$classes = $this->legacy_classes( $record['class'] ?? '' );
			$content = is_string( $record['content'] ?? null ) ? $record['content'] : '';
			$notes   = $this->legacy_notes( $record['notes'] ?? null );

			$html .= '<section id="' . esc_attr( $anchor ) . '"';

			if ( '' !== $classes ) {
				$html .= ' class="' . esc_attr( $classes ) . '"';
			}

			$html .= $this->legacy_data_attributes( $record['data'] ?? null );
			$html .= '>' . $content . $notes . '</section>';
		}

		return $this->render_shell( $html, $settings, $plugins );
	}

	/**
	 * Render the stable Reveal container and non-executable configuration.
	 *
	 * @param string                  $slides_html Rendered section elements.
	 * @param array<string, mixed>    $settings    Reveal settings.
	 * @param array<int, string>|null $plugins     Registered plugin IDs.
	 * @return string Presentation markup.
	 */
	private function render_shell( string $slides_html, array $settings, ?array $plugins ): string {
		$settings = $this->config->apply_legacy_settings_filter( $settings );
		$envelope = $this->config->envelope( $settings, $plugins );
		$json     = $this->config->encode( $envelope );

		return '<div class="reveal" data-presenter-reveal-root>'
			. '<div class="slides">' . $slides_html . '</div>'
			. '</div>'
			. '<script type="application/json" data-presenter-reveal-config>'
			. $json
			. '</script>';
	}

	/**
	 * Normalize a legacy object or array into a local value copy.
	 *
	 * @param mixed $slide Legacy slide value.
	 * @return array<string, mixed> Local record copy.
	 */
	private function legacy_record( mixed $slide ): array {
		if ( is_object( $slide ) ) {
			return get_object_vars( $slide );
		}

		return is_array( $slide ) ? $slide : array();
	}

	/**
	 * Build a deterministic, unique legacy slide anchor.
	 *
	 * @param array<string, mixed> $record       Legacy record.
	 * @param int                  $position     One-based slide position.
	 * @param array<string, int>   $used_anchors Previously used anchors.
	 * @return string Slide anchor.
	 */
	private function legacy_anchor( array $record, int $position, array &$used_anchors ): string {
		$title  = is_string( $record['title'] ?? null ) ? $record['title'] : '';
		$anchor = sanitize_title_with_dashes( $title );

		if ( '' === $anchor ) {
			$anchor = 'slide-' . $position;
		}

		$count                   = ( $used_anchors[ $anchor ] ?? 0 ) + 1;
		$used_anchors[ $anchor ] = $count;

		return 1 === $count ? $anchor : $anchor . '-' . $count;
	}

	/**
	 * Sanitize a legacy whitespace-separated class list.
	 *
	 * @param mixed $classes Legacy class value.
	 * @return string Safe class list.
	 */
	private function legacy_classes( mixed $classes ): string {
		if ( ! is_string( $classes ) ) {
			return '';
		}

		$class_names = preg_split( '/\s+/', trim( $classes ) );
		$normalized  = array_filter(
			array_map( 'sanitize_html_class', false !== $class_names ? $class_names : array() )
		);

		return implode( ' ', array_unique( $normalized ) );
	}

	/**
	 * Render validated legacy Reveal data attributes.
	 *
	 * @param mixed $data Legacy data-attribute records.
	 * @return string Rendered attributes.
	 */
	private function legacy_data_attributes( mixed $data ): string {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$attributes = '';

		foreach ( $data as $item ) {
			$attribute = $this->legacy_record( $item );
			$name      = is_string( $attribute['name'] ?? null ) ? strtolower( $attribute['name'] ) : '';

			if ( 1 !== preg_match( '/^[a-z][a-z0-9_.:-]*$/', $name ) ) {
				continue;
			}

			$value       = is_scalar( $attribute['value'] ?? null ) ? (string) $attribute['value'] : '';
			$attributes .= ' data-' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}

		return $attributes;
	}

	/**
	 * Render legacy speaker notes.
	 *
	 * @param mixed $notes Legacy notes record.
	 * @return string Speaker notes markup.
	 */
	private function legacy_notes( mixed $notes ): string {
		$record  = $this->legacy_record( $notes );
		$content = is_string( $record['notes'] ?? null ) ? $record['notes'] : '';

		if ( '' === $content ) {
			return '';
		}

		$markdown = ! empty( $record['markdown'] ) ? ' data-markdown=""' : '';

		return '<aside class="notes"' . $markdown . '>' . $content . '</aside>';
	}
}
