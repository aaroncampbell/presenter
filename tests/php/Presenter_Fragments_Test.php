<?php
/**
 * Presenter fragment rendering tests.
 *
 * @package Presenter
 */

/**
 * Verify the server-side fragment contract and its Slide scope.
 */
class Presenter_Fragments_Test extends Presenter_Test_Case {
	/**
	 * Eligible block types receive canonical attributes and Slide context usage.
	 */
	public function test_fragment_contract_is_registered_for_content_blocks_only(): void {
		$registry  = WP_Block_Type_Registry::get_instance();
		$paragraph = $registry->get_registered( 'core/paragraph' );
		$deck      = $registry->get_registered( 'presenter/deck' );
		$slide     = $registry->get_registered( 'presenter/slide' );

		$this->assertInstanceOf( WP_Block_Type::class, $paragraph );
		$this->assertFalse( $paragraph->attributes['presenterFragment']['default'] );
		$this->assertSame( '', $paragraph->attributes['presenterFragmentEffect']['default'] );
		$this->assertSame( '', $paragraph->attributes['presenterFragmentCustomClasses']['default'] );
		$this->assertSame( array( 'type' => 'number' ), $paragraph->attributes['presenterFragmentIndex'] );
		$this->assertContains( 'presenter/insideSlide', $paragraph->uses_context );

		$this->assertInstanceOf( WP_Block_Type::class, $slide );
		$this->assertTrue( $slide->attributes['fragmentContext']['default'] );
		$this->assertSame( 'fragmentContext', $slide->provides_context['presenter/insideSlide'] );
		$this->assertArrayNotHasKey( 'presenterFragment', $slide->attributes );
		$this->assertInstanceOf( WP_Block_Type::class, $deck );
		$this->assertArrayNotHasKey( 'presenterFragment', $deck->attributes );
	}

	/**
	 * Static and nested blocks inherit Slide context and keep their own roots.
	 */
	public function test_fragments_render_on_static_and_nested_blocks_inside_slide(): void {
		$output = do_blocks(
			'<!-- wp:presenter/slide -->'
			. '<!-- wp:group {"presenterFragment":true,"presenterFragmentEffect":"zoom-in"} -->'
			. '<div class="wp-block-group"><!-- wp:paragraph {"presenterFragment":true,"presenterFragmentEffect":"fade-up"} -->'
			. '<p>Nested fragment</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
			. '<!-- /wp:presenter/slide -->'
		);

		$this->assertStringContainsString( '<div class="wp-block-group fragment zoom-in">', $output );
		$this->assertStringContainsString( '<p class="fragment fade-up wp-block-paragraph">Nested fragment</p>', $output );
		$this->assertStringNotContainsString( '<div class="fragment"><div', $output );
	}

	/**
	 * A dynamic block is rendered once and receives the same fragment contract.
	 */
	public function test_fragment_decorates_dynamic_block_root_exactly_once(): void {
		$count      = 0;
		$block_name = 'presenter-test/fragment-dynamic';
		$registered = register_block_type(
			$block_name,
			array(
				'api_version'     => 3,
				'render_callback' => static function () use ( &$count ): string {
					++$count;

					return '<ol class="dynamic-list"><li>Dynamic fragment</li></ol>';
				},
			)
		);

		$this->assertInstanceOf( WP_Block_Type::class, $registered );

		try {
			$output = do_blocks(
				'<!-- wp:presenter/slide --><!-- wp:presenter-test/fragment-dynamic '
				. '{"presenterFragment":true,"presenterFragmentEffect":"grow"} /--><!-- /wp:presenter/slide -->'
			);

			$this->assertSame( 1, $count );
			$this->assertStringContainsString( '<ol class="dynamic-list fragment grow">', $output );
		} finally {
			unregister_block_type( $block_name );
		}
	}

	/**
	 * Forged attributes never affect content outside an inherited Slide context.
	 */
	public function test_fragment_attributes_are_inert_outside_slide(): void {
		$paragraph = '<!-- wp:paragraph {"presenterFragment":true,"presenterFragmentEffect":"fade-out","presenterFragmentIndex":2} -->'
			. '<p>Outside</p><!-- /wp:paragraph -->';
		$output    = do_blocks( $paragraph );
		$deck      = do_blocks( '<!-- wp:presenter/deck -->' . $paragraph . '<!-- /wp:presenter/deck -->' );

		$this->assertSame( '<p class="wp-block-paragraph">Outside</p>', $output );
		$this->assertSame( '<p class="wp-block-paragraph">Outside</p>', $deck );
		$this->assertStringNotContainsString( 'fragment', $output );
		$this->assertStringNotContainsString( 'data-fragment-index', $deck );
	}

	/**
	 * Equal explicit indices retain shared-order markup for Reveal to group.
	 */
	public function test_shared_fragment_order_renders_equal_indices(): void {
		$output = do_blocks(
			'<!-- wp:presenter/slide -->'
			. '<!-- wp:paragraph {"presenterFragment":true,"presenterFragmentIndex":3} --><p>One</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph {"presenterFragment":true,"presenterFragmentIndex":3} --><p>Two</p><!-- /wp:paragraph -->'
			. '<!-- /wp:presenter/slide -->'
		);

		$this->assertSame( 2, substr_count( $output, 'data-fragment-index="3"' ) );
		$this->assertSame( 2, substr_count( $output, 'class="fragment wp-block-paragraph"' ) );
	}

	/**
	 * Every bundled Reveal 6 effect is accepted.
	 *
	 * @dataProvider reveal_fragment_effects
	 *
	 * @param string $effect Supported effect class.
	 */
	public function test_reveal_six_fragment_effect_is_rendered( string $effect ): void {
		$output = do_blocks(
			'<!-- wp:presenter/slide --><!-- wp:paragraph '
			. wp_json_encode(
				array(
					'presenterFragment'       => true,
					'presenterFragmentEffect' => $effect,
				)
			)
			. ' --><p>Effect</p><!-- /wp:paragraph --><!-- /wp:presenter/slide -->'
		);

		$this->assertStringContainsString( 'class="fragment ' . $effect . ' wp-block-paragraph"', $output );
	}

	/**
	 * Reveal 6 fragment effect fixtures.
	 *
	 * @return array<string, array{string}>
	 */
	public function reveal_fragment_effects(): array {
		$effects = array(
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

		return array_combine( $effects, array_map( static fn ( string $effect ): array => array( $effect ), $effects ) );
	}

	/**
	 * Valid custom theme classes are deduplicated without disturbing root classes.
	 */
	public function test_custom_fragment_classes_are_validated_and_deduplicated(): void {
		$output = do_blocks(
			'<!-- wp:presenter/slide --><!-- wp:paragraph '
			. '{"presenterFragment":true,"presenterFragmentEffect":"custom","presenterFragmentCustomClasses":"swap-in block swap-in always-visible"} -->'
			. '<p class="existing">Custom</p><!-- /wp:paragraph --><!-- /wp:presenter/slide -->'
		);

		$this->assertStringContainsString(
			'class="existing fragment swap-in block always-visible wp-block-paragraph"',
			$output
		);
	}

	/**
	 * Invalid effects and custom tokens leave the rendered root untouched.
	 *
	 * @dataProvider invalid_fragment_effects
	 *
	 * @param string $attributes Serialized fragment attributes.
	 */
	public function test_invalid_fragment_effect_is_inert( string $attributes ): void {
		$output = do_blocks(
			'<!-- wp:presenter/slide --><!-- wp:paragraph ' . $attributes
			. ' --><p>Invalid</p><!-- /wp:paragraph --><!-- /wp:presenter/slide -->'
		);

		$this->assertStringContainsString( '<p class="wp-block-paragraph">Invalid</p>', $output );
		$this->assertStringNotContainsString( ' fragment', $output );
	}

	/**
	 * Invalid fragment effect fixtures.
	 *
	 * @return array<string, array{string}>
	 */
	public function invalid_fragment_effects(): array {
		return array(
			'unknown effect'  => array( '{"presenterFragment":true,"presenterFragmentEffect":"spin"}' ),
			'empty custom'    => array( '{"presenterFragment":true,"presenterFragmentEffect":"custom"}' ),
			'reserved custom' => array( '{"presenterFragment":true,"presenterFragmentEffect":"custom","presenterFragmentCustomClasses":"swap-in visible"}' ),
			'invalid token'   => array( '{"presenterFragment":true,"presenterFragmentEffect":"custom","presenterFragmentCustomClasses":"swap-in bad.class"}' ),
		);
	}

	/**
	 * Invalid indices are omitted without disabling otherwise valid fragments.
	 *
	 * @dataProvider invalid_fragment_indices
	 *
	 * @param string $index Invalid JSON index value.
	 */
	public function test_invalid_fragment_index_is_omitted( string $index ): void {
		$output = do_blocks(
			'<!-- wp:presenter/slide --><!-- wp:paragraph '
			. '{"presenterFragment":true,"presenterFragmentIndex":' . $index . '} -->'
			. '<p>Index</p><!-- /wp:paragraph --><!-- /wp:presenter/slide -->'
		);

		$this->assertStringContainsString( 'class="fragment wp-block-paragraph"', $output );
		$this->assertStringNotContainsString( 'data-fragment-index', $output );
	}

	/**
	 * Invalid fragment order fixtures.
	 *
	 * @return array<string, array{string}>
	 */
	public function invalid_fragment_indices(): array {
		return array(
			'negative'   => array( '-1' ),
			'too large'  => array( '10000' ),
			'fractional' => array( '1.5' ),
			'string'     => array( '"2"' ),
		);
	}

	/**
	 * Empty and text-only dynamic output is never wrapped to create a root.
	 */
	public function test_fragment_does_not_wrap_output_without_an_element_root(): void {
		$block_name = 'presenter-test/fragment-no-root';
		register_block_type(
			$block_name,
			array(
				'api_version'     => 3,
				'render_callback' => static fn (): string => 'Text-only output',
			)
		);

		try {
			$output = do_blocks(
				'<!-- wp:presenter/slide --><!-- wp:presenter-test/fragment-no-root '
				. '{"presenterFragment":true} /--><!-- /wp:presenter/slide -->'
			);

			$this->assertSame(
				'<section class="wp-block-presenter-slide">Text-only output</section>',
				$output
			);
		} finally {
			unregister_block_type( $block_name );
		}
	}
}
