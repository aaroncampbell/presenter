<?php
/**
 * Presenter native block tests.
 *
 * @package Presenter
 */

/**
 * Verify the server-side Deck and Slide block contract.
 */
class Presenter_Native_Blocks_Test extends Presenter_Test_Case {
	/**
	 * Metadata registers API v3 blocks with structural constraints.
	 */
	public function test_blocks_register_from_api_v3_metadata(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$deck     = $registry->get_registered( 'presenter/deck' );
		$slide    = $registry->get_registered( 'presenter/slide' );

		$this->assertInstanceOf( WP_Block_Type::class, $deck );
		$this->assertInstanceOf( WP_Block_Type::class, $slide );
		$this->assertSame( 3, $deck->api_version );
		$this->assertSame( 3, $slide->api_version );
		$this->assertSame( array( 'presenter/slide' ), $deck->allowed_blocks );
		$this->assertSame( array( 'presenter/deck' ), $slide->parent );
		$this->assertFalse( $deck->supports['inserter'] );
		$this->assertSame( 1280, $deck->attributes['width']['default'] );
		$this->assertSame( 720, $deck->attributes['height']['default'] );
		$this->assertSame( '16:9', $deck->attributes['aspectRatio']['default'] );
		$this->assertSame( 0.04, $deck->attributes['margin']['default'] );
		$this->assertTrue( $deck->attributes['controls']['default'] );
		$this->assertTrue( $deck->attributes['progress']['default'] );
		$this->assertTrue( $deck->attributes['hash']['default'] );
		$this->assertTrue( $deck->attributes['center']['default'] );
		$this->assertTrue( $deck->attributes['keyboard']['default'] );
		$this->assertSame( 'slide', $deck->attributes['transition']['default'] );
		$this->assertSame( 'fade', $deck->attributes['backgroundTransition']['default'] );
		$this->assertSame( '', $deck->attributes['theme']['default'] );
		$this->assertSame( '', $slide->attributes['label']['default'] );
		$this->assertSame( '', $slide->attributes['transition']['default'] );
		$this->assertSame( '', $slide->attributes['backgroundColor']['default'] );
		$this->assertSame( '', $slide->attributes['backgroundImageUrl']['default'] );
	}

	/**
	 * Both block types use the single explicitly registered editor bundle.
	 */
	public function test_blocks_share_the_built_editor_script_handle(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$deck     = $registry->get_registered( 'presenter/deck' );
		$slide    = $registry->get_registered( 'presenter/slide' );
		$script   = wp_scripts()->query( 'presenter-block-editor', 'registered' );
		$asset    = require dirname( __DIR__, 2 ) . '/build/index.asset.php';

		$this->assertInstanceOf( WP_Block_Type::class, $deck );
		$this->assertInstanceOf( WP_Block_Type::class, $slide );
		$this->assertSame( array( 'presenter-block-editor' ), $deck->editor_script_handles );
		$this->assertSame( $deck->editor_script_handles, $slide->editor_script_handles );
		$this->assertInstanceOf( _WP_Dependency::class, $script );
		$this->assertSame(
			plugins_url( 'build/index.js', dirname( __DIR__, 2 ) . '/presenter.php' ),
			$script->src
		);
		$this->assertSame( $asset['dependencies'], $script->deps );
		$this->assertSame( $asset['version'], $script->ver );
	}

	/**
	 * New slideshows start with one locked Deck and an editable Slide.
	 */
	public function test_post_type_supplies_the_locked_native_deck_template(): void {
		$post_type = get_post_type_object( 'slideshow' );

		$this->assertInstanceOf( WP_Post_Type::class, $post_type );
		$this->assertSame( 'all', $post_type->template_lock );
		$this->assertSame( 'presenter/deck', $post_type->template[0][0] );
		$this->assertSame( 'presenter/slide', $post_type->template[0][2][0][0] );
		$this->assertSame( 'core/heading', $post_type->template[0][2][0][2][0][0] );
		$this->assertSame( 'core/paragraph', $post_type->template[0][2][0][2][1][0] );
	}

	/**
	 * Dynamic rendering produces the strict Reveal hierarchy and keeps blocks.
	 */
	public function test_deck_renders_direct_slide_sections_and_inner_blocks(): void {
		$markup = '<!-- wp:presenter/deck {"width":1600,"height":900} -->'
			. '<!-- wp:presenter/slide {"anchor":"opening-slide","hidden":true,"notes":"Use &lt;script&gt; safely","notesFormat":"markdown"} -->'
			. '<!-- wp:paragraph --><p>Native paragraph sentinel</p><!-- /wp:paragraph -->'
			. '<!-- /wp:presenter/slide -->'
			. '<!-- /wp:presenter/deck -->';
		$output = do_blocks( $markup );

		$this->assertStringStartsWith( '<section ', $output );
		$this->assertStringContainsString( 'class="wp-block-presenter-slide"', $output );
		$this->assertStringContainsString( 'id="opening-slide"', $output );
		$this->assertStringContainsString( 'data-visibility="hidden"', $output );
		$this->assertStringContainsString( '>Native paragraph sentinel</p>', $output );
		$this->assertStringContainsString(
			'<aside class="notes" data-markdown="">Use &lt;script&gt; safely</aside>',
			$output
		);
		$this->assertStringEndsWith( '</section>', $output );
	}

	/**
	 * Each native child is rendered exactly once through WordPress.
	 */
	public function test_deck_does_not_rerender_dynamic_children(): void {
		$markup = '<!-- wp:presenter/deck -->'
			. '<!-- wp:presenter/slide --><!-- wp:paragraph --><p>Rendered once</p><!-- /wp:paragraph --><!-- /wp:presenter/slide -->'
			. '<!-- /wp:presenter/deck -->';
		$count  = 0;
		$filter = static function ( string $content, array $block ) use ( &$count ): string {
			if ( 'core/paragraph' === ( $block['blockName'] ?? null ) ) {
				++$count;
			}

			return $content;
		};
		add_filter( 'render_block', $filter, 10, 2 );
		$output = do_blocks( $markup );
		remove_filter( 'render_block', $filter, 10 );

		$this->assertSame( 1, $count );
		$this->assertStringContainsString( '<section class="wp-block-presenter-slide"><p class="wp-block-paragraph">Rendered once</p></section>', $output );
	}

	/**
	 * A dynamic child block's render callback runs exactly once inside a Deck.
	 */
	public function test_deck_renders_dynamic_child_callback_exactly_once(): void {
		$count      = 0;
		$block_name = 'presenter-test/dynamic-once';
		$registered = register_block_type(
			$block_name,
			array(
				'api_version'     => 3,
				'render_callback' => static function () use ( &$count ): string {
					++$count;

					return '<p>Dynamic child sentinel</p>';
				},
			)
		);

		$this->assertInstanceOf( WP_Block_Type::class, $registered );

		try {
			$output = do_blocks(
				'<!-- wp:presenter/deck -->'
				. '<!-- wp:presenter/slide -->'
				. '<!-- wp:presenter-test/dynamic-once /-->'
				. '<!-- /wp:presenter/slide -->'
				. '<!-- /wp:presenter/deck -->'
			);

			$this->assertSame( 1, $count );
			$this->assertSame(
				'<section class="wp-block-presenter-slide"><p>Dynamic child sentinel</p></section>',
				$output
			);
		} finally {
			unregister_block_type( $block_name );
		}
	}

	/**
	 * Invalid public anchors are omitted instead of silently changing URLs.
	 */
	public function test_slide_rejects_invalid_anchor_and_note_format(): void {
		$markup = '<!-- wp:presenter/slide {"anchor":"Not A Stable Anchor","notes":"Plain &amp; safe","notesFormat":"html"} -->'
			. '<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->'
			. '<!-- /wp:presenter/slide -->';
		$output = do_blocks( $markup );

		$this->assertStringNotContainsString( ' id=', $output );
		$this->assertStringNotContainsString( 'data-markdown', $output );
		$this->assertStringContainsString( '<aside class="notes">Plain &amp; safe</aside>', $output );
	}

	/**
	 * Valid visual settings become only their allow-listed Reveal attributes.
	 */
	public function test_slide_renders_valid_visual_settings(): void {
		$markup = '<!-- wp:presenter/slide {"label":"Private editor label","transition":"zoom","backgroundColor":"#aBc123","backgroundImageUrl":"https://images.example.test/slide.jpg?size=large&amp;crop=1"} -->'
			. '<!-- wp:paragraph --><p>Visual settings</p><!-- /wp:paragraph -->'
			. '<!-- /wp:presenter/slide -->';
		$output = do_blocks( $markup );

		$this->assertStringContainsString( 'data-transition="zoom"', $output );
		$this->assertStringContainsString( 'data-background-color="#aBc123"', $output );
		$this->assertStringContainsString( 'data-background-image="https://images.example.test/slide.jpg?size=large&amp;crop=1"', $output );
		$this->assertStringContainsString( 'aria-label="Private editor label"', $output );

		$local_output = do_blocks(
			'<!-- wp:presenter/slide {"backgroundImageUrl":"http://localhost:8888/local.jpg"} --><p>Local</p><!-- /wp:presenter/slide -->'
		);
		$this->assertStringContainsString( 'data-background-image="http://localhost:8888/local.jpg"', $local_output );
	}

	/**
	 * A nonempty Slide label becomes an escaped accessible name.
	 */
	public function test_slide_renders_nonempty_label_as_escaped_aria_label(): void {
		$labeled   = do_blocks(
			'<!-- wp:presenter/slide {"label":"Opening &amp; \u0022overview\u0022 &lt;script&gt;"} --><p>Content</p><!-- /wp:presenter/slide -->'
		);
		$unlabeled = do_blocks(
			'<!-- wp:presenter/slide {"label":""} --><p>Content</p><!-- /wp:presenter/slide -->'
		);

		$this->assertStringContainsString(
			'aria-label="Opening &amp; &quot;overview&quot; &lt;script&gt;"',
			$labeled
		);
		$this->assertStringNotContainsString( 'aria-label=', $unlabeled );
	}

	/**
	 * Invalid or dangerous visual values never reach presentation markup.
	 *
	 * @dataProvider invalid_slide_visual_settings
	 *
	 * @param string $attributes Serialized block attributes.
	 */
	public function test_slide_rejects_invalid_visual_settings( string $attributes ): void {
		$output = do_blocks(
			'<!-- wp:presenter/slide ' . $attributes . ' --><p>Safe</p><!-- /wp:presenter/slide -->'
		);

		$this->assertStringNotContainsString( 'data-transition=', $output );
		$this->assertStringNotContainsString( 'data-background-color=', $output );
		$this->assertStringNotContainsString( 'data-background-image=', $output );
	}

	/**
	 * Invalid Slide setting fixtures.
	 *
	 * @return array<string, array{string}>
	 */
	public function invalid_slide_visual_settings(): array {
		return array(
			'unknown transition' => array( '{"transition":"spin"}' ),
			'short hex'          => array( '{"backgroundColor":"#fff"}' ),
			'CSS injection'      => array( '{"backgroundColor":"red; background:url(javascript:alert(1))"}' ),
			'script URL'         => array( '{"backgroundImageUrl":"javascript:alert(1)"}' ),
			'data URL'           => array( '{"backgroundImageUrl":"data:image/svg+xml,<svg onload=alert(1)>"}' ),
		);
	}

	/**
	 * Valid revisioned Deck dimensions feed the Reveal configuration.
	 */
	public function test_deck_dimensions_are_validated_for_reveal_configuration(): void {
		$post = new WP_Post(
			(object) array(
				'ID'           => 123,
				'post_content' => '<!-- wp:presenter/deck {"width":1920,"height":1080} --><!-- /wp:presenter/deck -->',
			)
		);
		$this->assertSame(
			array(
				'width'  => 1920,
				'height' => 1080,
			),
			apply_filters( 'presenter_reveal_config', array(), $post )
		);

		$post->post_content = '<!-- wp:presenter/deck {"width":0,"height":10001} --><!-- /wp:presenter/deck -->';

		$this->assertSame( array(), apply_filters( 'presenter_reveal_config', array(), $post ) );
	}

	/**
	 * Every supported Deck runtime setting is mapped after validation.
	 */
	public function test_deck_runtime_settings_are_validated_for_reveal_configuration(): void {
		$post = new WP_Post(
			(object) array(
				'ID'           => 124,
				'post_content' => '<!-- wp:presenter/deck {"aspectRatio":"custom","width":1024,"height":768,"margin":0.1,"controls":false,"progress":false,"hash":false,"center":false,"keyboard":false,"transition":"convex","backgroundTransition":"zoom","theme":"white"} --><!-- /wp:presenter/deck -->',
			)
		);

		$this->assertSame(
			array(
				'width'                => 1024,
				'height'               => 768,
				'margin'               => 0.1,
				'controls'             => false,
				'progress'             => false,
				'hash'                 => false,
				'center'               => false,
				'keyboard'             => true,
				'transition'           => 'convex',
				'backgroundTransition' => 'zoom',
			),
			apply_filters( 'presenter_reveal_config', array(), $post )
		);
	}

	/**
	 * Server rendering preserves at least one navigation method.
	 */
	public function test_deck_runtime_settings_allow_either_navigation_method_to_be_disabled(): void {
		$post = new WP_Post(
			(object) array(
				'ID'           => 126,
				'post_content' => '<!-- wp:presenter/deck {"controls":false,"keyboard":true} --><!-- /wp:presenter/deck -->',
			)
		);

		$this->assertSame(
			array(
				'controls' => false,
				'keyboard' => true,
			),
			apply_filters( 'presenter_reveal_config', array(), $post )
		);

		$post->post_content = '<!-- wp:presenter/deck {"controls":true,"keyboard":false} --><!-- /wp:presenter/deck -->';

		$this->assertSame(
			array(
				'controls' => true,
				'keyboard' => false,
			),
			apply_filters( 'presenter_reveal_config', array(), $post )
		);
	}

	/**
	 * Invalid Deck values are ignored without overwriting earlier settings.
	 */
	public function test_invalid_deck_runtime_settings_are_ignored(): void {
		$post = new WP_Post(
			(object) array(
				'ID'           => 125,
				'post_content' => '<!-- wp:presenter/deck {"width":"1280","height":-1,"margin":1,"controls":"false","progress":1,"hash":null,"center":[],"keyboard":{},"transition":"javascript:alert(1)","backgroundTransition":"spin","theme":"../../evil"} --><!-- /wp:presenter/deck -->',
			)
		);

		$this->assertSame(
			array( 'controls' => true ),
			apply_filters( 'presenter_reveal_config', array( 'controls' => true ), $post )
		);
	}

	/**
	 * Revision restoration restores Deck and Slide settings as authored content.
	 */
	public function test_revision_restores_native_presentation_settings(): void {
		$version_a = '<!-- wp:presenter/deck {"theme":"white","width":960,"height":720,"transition":"fade"} -->'
			. '<!-- wp:presenter/slide {"label":"Version A","transition":"zoom","backgroundColor":"#123ABC","backgroundImageUrl":"https://images.example.test/a.jpg"} -->'
			. '<p>Version A</p><!-- /wp:presenter/slide --><!-- /wp:presenter/deck -->';
		$version_b = '<!-- wp:presenter/deck {"theme":"black","width":1280,"height":720,"transition":"slide"} -->'
			. '<!-- wp:presenter/slide {"label":"Version B","backgroundColor":"#FFFFFF"} -->'
			. '<p>Version B</p><!-- /wp:presenter/slide --><!-- /wp:presenter/deck -->';
		$post_id   = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_status'  => 'publish',
				'post_content' => $version_a,
			)
		);
		$revision  = _wp_put_post_revision( $post_id );

		$this->assertIsInt( $revision );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $version_b,
			)
		);
		$this->assertSame( $post_id, wp_restore_post_revision( $revision ) );

		$restored = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $restored );
		$deck  = parse_blocks( $restored->post_content )[0];
		$slide = $deck['innerBlocks'][0];

		$this->assertSame( 'white', $deck['attrs']['theme'] );
		$this->assertSame( 'Version A', $slide['attrs']['label'] );
		$this->assertSame( '#123ABC', $slide['attrs']['backgroundColor'] );
		$this->assertSame(
			array(
				'width'      => 960,
				'height'     => 720,
				'transition' => 'fade',
			),
			apply_filters( 'presenter_reveal_config', array(), $restored )
		);

		$output = do_blocks( $restored->post_content );
		$this->assertStringContainsString( 'data-transition="zoom"', $output );
		$this->assertStringContainsString( 'data-background-color="#123ABC"', $output );
		$this->assertStringContainsString( 'data-background-image="https://images.example.test/a.jpg"', $output );
	}
}
