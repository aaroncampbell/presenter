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
}
