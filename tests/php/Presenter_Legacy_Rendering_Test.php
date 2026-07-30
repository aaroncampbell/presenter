<?php
/**
 * Legacy slide rendering characterization tests.
 *
 * @package Presenter
 */

/**
 * Characterize Presenter 1.x slide output before migration replaces it.
 */
class Presenter_Legacy_Rendering_Test extends Presenter_Test_Case {
	/** Previously stored untrusted scripts are filtered without changing source. */
	public function test_untrusted_stored_html_is_sanitized_only_at_render_time(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);
		$slide   = (object) array(
			'number'  => 1,
			'title'   => 'Untrusted',
			'content' => '<script>window.slideExploit=true;</script><img src="x" onerror="window.eventExploit=true">',
			'notes'   => array(
				'notes'    => '<script>window.notesExploit=true;</script><p onclick="window.notesClick=true">Safe note</p>',
				'markdown' => false,
			),
		);
		add_post_meta( $post_id, '_presenter_slides', $slide );
		$before = get_post_meta( $post_id, '_presenter_slides', false );

		$this->go_to( get_permalink( $post_id ) );
		$output = apply_filters( 'the_content', 'FALLBACK-CONTENT' );

		$this->assertStringNotContainsString( '<script>window.slideExploit', $output );
		$this->assertStringNotContainsString( '<script>window.notesExploit', $output );
		$this->assertStringNotContainsString( 'onerror', $output );
		$this->assertStringNotContainsString( 'onclick', $output );
		$this->assertStringContainsString( 'src="x"', $output );
		$this->assertStringContainsString( '<p>Safe note</p>', $output );
		$this->assertSame( maybe_serialize( $before ), maybe_serialize( get_post_meta( $post_id, '_presenter_slides', false ) ) );
	}

	/** Exact content saved by a trusted user may retain intentional raw HTML. */
	public function test_content_bound_trust_preserves_exact_legacy_html(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);
		$slide   = (object) array(
			'number'  => 1,
			'title'   => 'Trusted',
			'content' => '<script>window.trustedSlide=true;</script>',
			'notes'   => array(
				'notes'    => '<script>window.trustedNotes=true;</script>',
				'markdown' => false,
			),
		);
		add_post_meta( $post_id, '_presenter_slides', $slide );
		$stored = get_post_meta( $post_id, '_presenter_slides', false );
		presenter_get_runtime()->legacy_html_trust()->synchronize( $post_id, $stored, true );

		$this->go_to( get_permalink( $post_id ) );
		$output = apply_filters( 'the_content', 'FALLBACK-CONTENT' );

		$this->assertStringContainsString( $slide->content, $output );
		$this->assertStringContainsString( $slide->notes['notes'], $output );
	}

	/**
	 * Legacy slides retain numeric order, HTML, classes, data, and notes.
	 */
	public function test_legacy_slides_preserve_order_and_reveal_attributes(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);

		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 20,
				'title'   => 'Last Slide',
				'content' => '<p>LAST-CONTENT</p>',
				'class'   => 'wide custom-class',
				'data'    => array(
					(object) array(
						'name'  => 'background-color',
						'value' => '#442266',
					),
				),
				'notes'   => array(
					'notes'    => '**Markdown note**',
					'markdown' => true,
				),
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => 'First Slide',
				'content' => '<p>FIRST-CONTENT</p>',
				'class'   => '',
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 2,
				'title'   => '',
				'content' => '<section>NESTED-SECTION</section>',
				'class'   => '',
				'data'    => array(),
				'notes'   => array(
					'notes'    => 'Plain note',
					'markdown' => false,
				),
			)
		);

		$this->go_to( get_permalink( $post_id ) );
		$output = apply_filters( 'the_content', 'FALLBACK-CONTENT' );

		$this->assertLessThan( strpos( $output, 'NESTED-SECTION' ), strpos( $output, 'FIRST-CONTENT' ) );
		$this->assertLessThan( strpos( $output, 'LAST-CONTENT' ), strpos( $output, 'NESTED-SECTION' ) );
		$this->assertStringContainsString( "<section id='first-slide'>", $output );
		$this->assertStringContainsString( "<section id='slide-2'>", $output );
		$this->assertStringContainsString( '<section>NESTED-SECTION</section>', $output );
		$this->assertStringContainsString( 'class="wide custom-class"', $output );
		$this->assertStringContainsString( 'data-background-color="#442266"', $output );
		$this->assertStringContainsString( '<aside class="notes">Plain note</aside>', $output );
		$this->assertStringContainsString( '<aside class="notes" data-markdown="">**Markdown note**</aside>', $output );
	}

	/**
	 * False records render as empty Slides in stable source order on PHP 8.3.
	 */
	public function test_false_slide_record_renders_empty_without_changing_stored_meta(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 3,
				'title'   => 'Third',
				'content' => 'THIRD-CONTENT',
				'class'   => '',
			)
		);
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reproduce a serialized boolean row from the production snapshot; the metadata API coerces false on write.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => '_presenter_slides',
				'meta_value' => 'b:0;',
			),
			array( '%d', '%s', '%s' )
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 2,
				'title'   => 'Duplicate Two',
				'content' => 'DUPLICATE-CONTENT',
				'class'   => '',
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			array(
				'title'   => 'Missing Number',
				'content' => 'MISSING-NUMBER-CONTENT',
				'class'   => '',
			)
		);
		wp_cache_delete( $post_id, 'post_meta' );
		$before = get_post_meta( $post_id, '_presenter_slides', false );

		$this->go_to( get_permalink( $post_id ) );
		$output = apply_filters( 'the_content', 'FALLBACK-CONTENT' );

		$empty_position     = strpos( $output, "<section id='slide-2'></section>" );
		$duplicate_position = strpos( $output, "<section id='duplicate-two'>" );
		$third_position     = strpos( $output, "<section id='third'>" );
		$missing_position   = strpos( $output, "<section id='missing-number'>" );
		$this->assertNotFalse( $empty_position );
		$this->assertNotFalse( $duplicate_position );
		$this->assertNotFalse( $third_position );
		$this->assertNotFalse( $missing_position );
		$this->assertLessThan( $duplicate_position, $empty_position );
		$this->assertLessThan( $third_position, $duplicate_position );
		$this->assertLessThan( $missing_position, $third_position );
		$this->assertSame( maybe_serialize( $before ), maybe_serialize( get_post_meta( $post_id, '_presenter_slides', false ) ) );
	}

	/**
	 * Protected slides and notes are not exposed before authentication.
	 */
	public function test_protected_slideshow_does_not_render_legacy_slide_secrets(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'     => 'slideshow',
				'post_status'   => 'publish',
				'post_content'  => 'PASSWORD-FORM-CONTEXT',
				'post_password' => 'local-secret',
			)
		);
		add_post_meta(
			$post_id,
			'_presenter_slides',
			(object) array(
				'number'  => 1,
				'title'   => 'Protected',
				'content' => 'PRIVATE-SLIDE-SECRET',
				'class'   => '',
				'data'    => array(),
				'notes'   => array(
					'notes'    => 'PRIVATE-NOTE-SECRET',
					'markdown' => false,
				),
			)
		);

		$this->go_to( get_permalink( $post_id ) );
		$output = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );

		$this->assertStringNotContainsString( 'PRIVATE-SLIDE-SECRET', $output );
		$this->assertStringNotContainsString( 'PRIVATE-NOTE-SECRET', $output );
		$this->assertStringContainsString( 'PASSWORD-FORM-CONTEXT', $output );
	}
}
