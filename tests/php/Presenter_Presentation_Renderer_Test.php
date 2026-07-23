<?php
/**
 * Presenter presentation renderer tests.
 *
 * @package Presenter
 */

use Presenter\Presentation_Renderer;
use Presenter\Reveal_Config;

require_once dirname( __DIR__, 2 ) . '/includes/class-reveal-config.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-presentation-renderer.php';

/**
 * Verify the modern renderer's isolated HTML and configuration contract.
 */
class Presenter_Presentation_Renderer_Test extends Presenter_Test_Case {
	/**
	 * Optional plugins with external runtime dependencies are not enabled globally.
	 */
	public function test_default_plugins_do_not_enable_math_for_every_deck(): void {
		$envelope = ( new Reveal_Config() )->envelope();

		$this->assertNotContains( 'math', $envelope['plugins'] );
	}

	/**
	 * Native rendering retains the characterized Presenter 1.x settings seam.
	 */
	public function test_native_renderer_applies_legacy_settings_filter(): void {
		$filter = static function ( object $settings ): object {
			$settings->transition           = 'none';
			$settings->backgroundTransition = 'none'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reveal.js owns this public configuration key.

			return $settings;
		};
		add_filter( 'presenter-init-object', $filter );

		try {
			$output = ( new Presentation_Renderer( new Reveal_Config() ) )->render_blocks(
				'<section></section>',
				array( 'transition' => 'convex' )
			);
		} finally {
			remove_filter( 'presenter-init-object', $filter );
		}

		preg_match( '/<script[^>]+>(.*)<\/script>/', $output, $matches );
		$envelope = json_decode( $matches[1], true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 'convex', $envelope['reveal']['transition'] );
		$this->assertSame( 'none', $envelope['reveal']['backgroundTransition'] );
	}

	/**
	 * Native block output is retained inside the required Reveal structure.
	 */
	public function test_rendered_block_content_is_preserved_in_reveal_shell(): void {
		$renderer = new Presentation_Renderer( new Reveal_Config() );
		$output   = $renderer->render_blocks(
			'<section id="native"><p>Native block sentinel</p></section>',
			array( 'width' => 1440 ),
			array( 'notes' )
		);

		$this->assertStringContainsString(
			'<div class="reveal" data-presenter-reveal-root><div class="slides"><section id="native"><p>Native block sentinel</p></section></div></div>',
			$output
		);
		$this->assertStringContainsString( 'type="application/json" data-presenter-reveal-config', $output );

		preg_match( '/<script[^>]+>(.*)<\/script>/', $output, $matches );
		$envelope = json_decode( $matches[1], true, 512, JSON_THROW_ON_ERROR );

		$this->assertSame( 1440, $envelope['reveal']['width'] );
		$this->assertSame( 720, $envelope['reveal']['height'] );
		$this->assertSame( array( 'notes' ), $envelope['plugins'] );
	}

	/**
	 * Legacy Reveal-footer integrations remain inside the Reveal root.
	 */
	public function test_rendered_blocks_place_compatibility_footer_after_slides(): void {
		$output = ( new Presentation_Renderer( new Reveal_Config() ) )->render_blocks(
			'<section id="native"></section>',
			array(),
			null,
			'',
			'<p class="persistent-footer">Footer</p>'
		);

		$this->assertStringContainsString(
			'<div class="slides"><section id="native"></section></div><p class="persistent-footer">Footer</p></div>',
			$output
		);
	}

	/**
	 * Legacy short-URL chrome is escaped and precedes integration markup.
	 */
	public function test_rendered_blocks_place_an_escaped_short_url_before_the_compatibility_footer(): void {
		$output = ( new Presentation_Renderer( new Reveal_Config() ) )->render_blocks(
			'<section></section>',
			array(),
			null,
			'https://example.com/talk?a=1&b=2',
			'<p class="persistent-footer">Footer</p>'
		);

		$this->assertStringContainsString(
			'<div class="slides"><section></section></div><p class="permalink"><a href="https://example.com/talk?a=1&#038;b=2">https://example.com/talk?a=1&amp;b=2</a></p><p class="persistent-footer">Footer</p></div>',
			$output
		);
	}

	/**
	 * Invalid or unsupported short URLs do not render presentation chrome.
	 *
	 * @dataProvider invalid_short_url_provider
	 *
	 * @param string $short_url Invalid short URL.
	 */
	public function test_rendered_blocks_omit_invalid_short_urls( string $short_url ): void {
		$output = ( new Presentation_Renderer( new Reveal_Config() ) )->render_blocks(
			'<section></section>',
			array(),
			null,
			$short_url
		);

		$this->assertStringNotContainsString( 'class="permalink"', $output );
	}

	/**
	 * Invalid short URLs.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_short_url_provider(): array {
		return array(
			'empty'              => array( '' ),
			'unsupported scheme' => array( 'javascript:alert(1)' ),
			'malformed'          => array( 'not a URL' ),
		);
	}

	/**
	 * Configuration text cannot terminate its application/json script element.
	 */
	public function test_configuration_json_uses_script_safe_hex_encoding(): void {
		$config   = new Reveal_Config();
		$envelope = array(
			'reveal'  => array( 'transition' => '</script><script>alert("unsafe")</script>' ),
			'plugins' => array( 'extension-plugin' ),
		);
		$encoded  = $config->encode( $envelope );

		$this->assertStringNotContainsString( '<', $encoded );
		$this->assertStringNotContainsString( '>', $encoded );
		$this->assertSame( $envelope, json_decode( $encoded, true, 512, JSON_THROW_ON_ERROR ) );
	}

	/**
	 * Legacy values become deterministic sections without storage access.
	 */
	public function test_legacy_records_render_from_values_without_database_access(): void {
		$renderer = new Presentation_Renderer( new Reveal_Config() );
		$slides   = array(
			(object) array(
				'title'   => 'Repeated title',
				'class'   => 'intro  has<script>',
				'content' => '<p>Legacy HTML sentinel</p>',
				'data'    => array(
					(object) array(
						'name'  => 'background-color',
						'value' => '#123456',
					),
					(object) array(
						'name'  => 'invalid attribute',
						'value' => 'ignored',
					),
				),
				'notes'   => array(
					'notes'    => '**Markdown notes**',
					'markdown' => true,
				),
			),
			array(
				'title'   => 'Repeated title',
				'content' => '<p>Second legacy slide</p>',
			),
		);
		$before   = wp_json_encode( $slides );
		$output   = $renderer->render_legacy( $slides );

		$this->assertSame( $before, wp_json_encode( $slides ) );
		$this->assertStringContainsString( 'id="repeated-title"', $output );
		$this->assertStringContainsString( 'id="repeated-title-2"', $output );
		$this->assertStringContainsString( 'data-background-color="#123456"', $output );
		$this->assertStringNotContainsString( 'data-invalid attribute', $output );
		$this->assertStringContainsString( '<p>Legacy HTML sentinel</p>', $output );
		$this->assertStringContainsString( '<aside class="notes" data-markdown="">**Markdown notes**</aside>', $output );
	}

	/**
	 * Unsupported configuration does not silently enter the browser runtime.
	 */
	public function test_unsupported_reveal_configuration_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		( new Reveal_Config() )->envelope( array( 'injected' => '</script>' ) );
	}

	/**
	 * A full-width margin is rejected because it collapses Reveal geometry.
	 */
	public function test_reveal_margin_must_be_less_than_one(): void {
		$this->expectException( InvalidArgumentException::class );

		( new Reveal_Config() )->envelope( array( 'margin' => 1 ) );
	}
}
