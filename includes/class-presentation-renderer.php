<?php
/**
 * Presenter front-end presentation renderer.
 *
 * @package Presenter
 */

namespace Presenter;

use Throwable;

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
	 * @param string                  $short_url        Optional legacy short URL.
	 * @param string                  $reveal_footer    Trusted plugin markup rendered inside Reveal.
	 * @param array<int, string>|null $feature_plugins  Pre-detected safe fallback plugins.
	 * @return string Reveal shell and configuration element.
	 */
	public function render_blocks( string $rendered_slides, array $settings = array(), ?array $plugins = null, string $short_url = '', string $reveal_footer = '', ?array $feature_plugins = null ): string {
		return $this->render_shell( $rendered_slides, $settings, $plugins, $short_url, $reveal_footer, $feature_plugins );
	}

	/**
	 * Render Presenter 1.x slide values without modifying their source.
	 *
	 * Untrusted legacy content and notes use WordPress's post HTML allow-list.
	 * Callers may preserve raw HTML only after verifying a content-bound trust
	 * fingerprint for the exact supplied slide set. Presenter-owned wrapper
	 * attributes are escaped in their HTML attribute context.
	 *
	 * @param array<int, mixed>       $slides   Legacy slide records in stored order.
	 * @param array<string, mixed>    $settings Reveal settings.
	 * @param array<int, string>|null $plugins  Registered plugin IDs.
	 * @param bool                    $trusted_html Whether raw legacy HTML is trusted.
	 * @return string Reveal shell and configuration element.
	 */
	public function render_legacy( array $slides, array $settings = array(), ?array $plugins = null, bool $trusted_html = false ): string {
		$html         = '';
		$used_anchors = array();

		foreach ( array_values( $slides ) as $index => $slide ) {
			$record  = $this->legacy_record( $slide );
			$anchor  = $this->legacy_anchor( $record, $index + 1, $used_anchors );
			$classes = $this->legacy_classes( $record['class'] ?? '' );
			$content = is_string( $record['content'] ?? null ) ? $record['content'] : '';
			$content = $trusted_html ? $content : wp_kses_post( $content );
			$notes   = $this->legacy_notes( $record['notes'] ?? null, $trusted_html );

			$html .= '<section id="' . esc_attr( $anchor ) . '"';

			if ( '' !== $classes ) {
				$html .= ' class="' . esc_attr( $classes ) . '"';
			}

			$html .= $this->legacy_data_attributes( $record['data'] ?? null );
			$html .= '>' . $content . $notes . '</section>';
		}

		return $this->render_shell( $html, $settings, $plugins, '', '', null );
	}

	/**
	 * Render the stable Reveal container and non-executable configuration.
	 *
	 * @param string                  $slides_html Rendered section elements.
	 * @param array<string, mixed>    $settings    Reveal settings.
	 * @param array<int, string>|null $plugins     Registered plugin IDs.
	 * @param string                  $short_url    Optional legacy short URL.
	 * @param string                  $reveal_footer Trusted plugin markup rendered inside Reveal.
	 * @param array<int, string>|null $feature_plugins Pre-detected safe fallback plugins.
	 * @return string Presentation markup.
	 */
	private function render_shell( string $slides_html, array $settings, ?array $plugins, string $short_url, string $reveal_footer, ?array $feature_plugins ): string {
		$json = $this->render_configuration( $slides_html, $settings, $plugins, $feature_plugins );

		return '<div class="reveal" data-presenter-reveal-root>'
			. '<div class="slides">' . $slides_html . '</div>'
			. $this->render_short_url( $short_url )
			. $reveal_footer
			. '</div>'
			. '<script type="application/json" data-presenter-reveal-config>'
			. $json
			. '</script>';
	}

	/**
	 * Build public runtime configuration without letting extension input take
	 * down an otherwise renderable presentation.
	 *
	 * Strict validation remains in Reveal_Config for migration, diagnostics,
	 * and direct callers. The public seam first discards a broken legacy filter
	 * while retaining valid native settings, then falls back completely if a
	 * modern settings or plugin filter also supplied an invalid value.
	 *
	 * @param string                  $slides_html Rendered section elements.
	 * @param array<string, mixed>    $settings    Reveal settings.
	 * @param array<int, string>|null $plugins     Registered plugin IDs.
	 * @param array<int, string>|null $feature_plugins Pre-detected safe fallback plugins.
	 * @return string Script-safe JSON.
	 */
	private function render_configuration( string $slides_html, array $settings, ?array $plugins, ?array $feature_plugins ): string {
		$feature_plugins = null === $feature_plugins
			? Reveal_Config::plugins_for_markup( $slides_html )
			: array_values( array_intersect( Reveal_Config::default_plugins(), $feature_plugins ) );
		$plugins         = $plugins ?? $feature_plugins;

		try {
			$filtered_settings = $this->config->apply_legacy_settings_filter( $settings );

			return $this->config->encode( $this->config->envelope( $filtered_settings, $plugins ) );
		} catch ( Throwable $error ) {
			$this->report_configuration_recovery( $error );
		}

		try {
			return $this->config->encode( $this->config->envelope( $settings, $plugins ) );
		} catch ( Throwable ) {
			$settings = array();
			$plugins  = $feature_plugins;
		}

		return $this->config->encode( $this->config->envelope( $settings, $plugins ) );
	}

	/**
	 * Report extension input discarded at the public rendering boundary.
	 *
	 * @param Throwable $error Validation or filter failure.
	 */
	private function report_configuration_recovery( Throwable $error ): void {
		_doing_it_wrong(
			__METHOD__,
			sprintf(
				/* translators: %s: PHP exception class. */
				esc_html__( 'Presenter discarded invalid filter-supplied Reveal configuration and used a safe fallback (%s).', 'presenter' ),
				esc_html( get_class( $error ) )
			),
			'2.0.0'
		);
	}

	/**
	 * Render the optional Presenter 1.x short-URL chrome.
	 *
	 * @param string $short_url Stored short URL.
	 * @return string Escaped permalink markup or an empty string.
	 */
	private function render_short_url( string $short_url ): string {
		$short_url = Meta::sanitize_short_url( $short_url );
		$url       = esc_url( $short_url, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}

		return '<p class="permalink"><a href="' . $url . '">' . esc_html( $short_url ) . '</a></p>';
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
	 * @param mixed $notes        Legacy notes record.
	 * @param bool  $trusted_html Whether raw legacy HTML is trusted.
	 * @return string Speaker notes markup.
	 */
	private function legacy_notes( mixed $notes, bool $trusted_html ): string {
		$record  = $this->legacy_record( $notes );
		$content = is_string( $record['notes'] ?? null ) ? $record['notes'] : '';

		if ( '' === $content ) {
			return '';
		}

		$markdown = ! empty( $record['markdown'] ) ? ' data-markdown=""' : '';
		$content  = $trusted_html ? $content : wp_kses_post( $content );

		return '<aside class="notes"' . $markdown . '>' . $content . '</aside>';
	}
}
