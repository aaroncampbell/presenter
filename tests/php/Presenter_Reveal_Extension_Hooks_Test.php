<?php
/**
 * Reveal 6 extension hook contract tests.
 *
 * @package Presenter
 */

/**
 * Verify public filters can configure the native Reveal runtime.
 */
class Presenter_Reveal_Extension_Hooks_Test extends Presenter_Test_Case {
	/**
	 * Configuration and plugin filters receive the deck and feed the JSON envelope.
	 */
	public function test_native_template_filters_feed_the_reveal_configuration_envelope(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'    => 'slideshow',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:presenter/deck --><!-- wp:presenter/slide --><!-- wp:paragraph --><p>Extension hook fixture</p><!-- /wp:paragraph --><!-- /wp:presenter/slide --><!-- /wp:presenter/deck -->',
			)
		);
		$this->prepare_frontend_request( $post_id );

		$config_post    = null;
		$plugins_post   = null;
		$config_filter  = static function ( array $settings, WP_Post $post ) use ( &$config_post ): array {
			$config_post          = $post;
			$settings['controls'] = false;
			$settings['width']    = 1776;

			return $settings;
		};
		$plugins_filter = static function ( mixed $plugins, WP_Post $post ) use ( &$plugins_post ): array {
			$plugins_post = $post;

			return array( 'notes', 'extension-plugin', 'notes' );
		};

		add_filter( 'presenter_reveal_config', $config_filter, 20, 2 );
		add_filter( 'presenter_reveal_plugins', $plugins_filter, 10, 2 );
		$script_state = array(
			'queue' => wp_scripts()->queue,
			'to_do' => wp_scripts()->to_do,
			'done'  => wp_scripts()->done,
		);
		$style_state  = array(
			'queue' => wp_styles()->queue,
			'to_do' => wp_styles()->to_do,
			'done'  => wp_styles()->done,
		);
		$template = apply_filters( 'single_template', '/tmp/presenter-theme-fallback.php' );
		$output   = $this->render_template( $template );
		remove_filter( 'presenter_reveal_config', $config_filter, 20 );
		remove_filter( 'presenter_reveal_plugins', $plugins_filter, 10 );

		// wp_head() mutates the shared dependency objects. Restore their request
		// state so later tests observe the same queues that this test received.
		foreach ( wp_scripts()->queue as $handle ) {
			wp_dequeue_script( $handle );
		}
		foreach ( $script_state['queue'] as $handle ) {
			wp_enqueue_script( $handle );
		}
		foreach ( wp_styles()->queue as $handle ) {
			wp_dequeue_style( $handle );
		}
		foreach ( $style_state['queue'] as $handle ) {
			wp_enqueue_style( $handle );
		}
		wp_scripts()->to_do = $script_state['to_do'];
		wp_scripts()->done  = $script_state['done'];
		wp_styles()->to_do  = $style_state['to_do'];
		wp_styles()->done   = $style_state['done'];
		wp_dequeue_script( 'presenter-frontend' );
		wp_dequeue_style( 'reveal-theme' );
		wp_dequeue_style( 'presenter-reveal-6' );

		$this->assertInstanceOf( WP_Post::class, $config_post );
		$this->assertInstanceOf( WP_Post::class, $plugins_post );
		$this->assertSame( $post_id, $config_post->ID );
		$this->assertSame( $post_id, $plugins_post->ID );

		preg_match(
			'/<script type="application\/json" data-presenter-reveal-config>(.*?)<\/script>/',
			$output,
			$matches
		);
		$this->assertArrayHasKey( 1, $matches );
		$envelope = json_decode( $matches[1], true, 512, JSON_THROW_ON_ERROR );

		$this->assertFalse( $envelope['reveal']['controls'] );
		$this->assertSame( 1776, $envelope['reveal']['width'] );
		$this->assertSame( array( 'notes', 'extension-plugin' ), $envelope['plugins'] );
	}

	/**
	 * Establish the singular slideshow request used by template routing.
	 *
	 * @param int $post_id Slideshow post ID.
	 */
	private function prepare_frontend_request( int $post_id ): void {
		global $post;

		$this->go_to( get_permalink( $post_id ) );
		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Template routing requires the current global post.
		setup_postdata( $post );
		do_action( 'init' );
	}

	/**
	 * Render an already-selected plugin template.
	 *
	 * @param string $template Absolute template path.
	 * @return string
	 */
	private function render_template( string $template ): string {
		ob_start();
		include $template;

		return (string) ob_get_clean();
	}
}
