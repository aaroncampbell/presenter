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
