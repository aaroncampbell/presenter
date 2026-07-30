<?php
/**
 * Content-bound legacy HTML trust tests.
 *
 * @package Presenter
 */

use Presenter\Legacy_HTML_Trust;

/**
 * Verify that raw legacy HTML trust is explicit, exact, and post-scoped.
 */
final class Presenter_Legacy_HTML_Trust_Test extends Presenter_Test_Case {
	/** Import never transfers a site-and-post-bound trust marker. */
	public function test_import_discards_legacy_html_trust_markers(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data();
		$result  = presenter::get_instance()->wp_import_post_meta(
			array(
				array(
					'key'   => Legacy_HTML_Trust::META_KEY,
					'value' => 'forged-imported-marker',
				),
			),
			$post_id,
			get_post( $post_id )
		);

		$this->assertSame( array(), $result );
		$this->assertFalse( metadata_exists( 'post', $post_id, Legacy_HTML_Trust::META_KEY ) );
	}

	/** Unmarked legacy metadata is untrusted and receives WordPress KSES. */
	public function test_unmarked_slides_are_untrusted_and_sanitized(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data();
		$slides  = $this->slides( '<script>window.exploit=true;</script><img src="x" onerror="window.eventExploit=true">' );
		$trust   = new Legacy_HTML_Trust();

		$this->assertFalse( $trust->is_trusted( $post_id, $slides ) );
		$this->assertSame( 'window.exploit=true;<img src="x">', $trust->sanitize( $slides[0]->content ) );
	}

	/** A trusted save binds its marker to the exact post and slide sequence. */
	public function test_trust_is_bound_to_exact_post_and_content(): void {
		$source_id = $this->create_slideshow_without_legacy_editor_post_data();
		$target_id = $this->create_slideshow_without_legacy_editor_post_data();
		$slides    = $this->slides( '<script>window.trusted=true;</script>' );
		$trust     = new Legacy_HTML_Trust();
		add_post_meta( $source_id, '_presenter_slides', $slides[0] );

		$trust->synchronize( $source_id, $slides, true );
		$source_marker = get_post_meta( $source_id, '_presenter_legacy_html_trust_v1', true );
		add_post_meta( $target_id, '_presenter_legacy_html_trust_v1', $source_marker );

		$this->assertTrue( $trust->is_trusted( $source_id, $slides ) );
		$this->assertFalse( $trust->is_trusted( $target_id, $slides ) );
		$changed             = $slides;
		$changed[0]          = clone $slides[0];
		$changed[0]->content = '<script>window.changed=true;</script>';
		$this->assertFalse( $trust->is_trusted( $source_id, $changed ) );
	}

	/** A filtered save clears any earlier raw-HTML trust marker. */
	public function test_filtered_save_clears_existing_trust(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data();
		$slides  = $this->slides( '<script>window.trusted=true;</script>' );
		$trust   = new Legacy_HTML_Trust();
		add_post_meta( $post_id, '_presenter_slides', $slides[0] );
		$trust->synchronize( $post_id, $slides, true );
		$this->assertTrue( $trust->is_trusted( $post_id, $slides ) );

		$trust->synchronize( $post_id, $slides, false );

		$this->assertFalse( $trust->is_trusted( $post_id, $slides ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, '_presenter_legacy_html_trust_v1' ) );
	}

	/**
	 * Build one raw legacy slide fixture.
	 *
	 * @param string $content Legacy slide HTML.
	 * @return array<int, object> Slide values.
	 */
	private function slides( string $content ): array {
		return array(
			(object) array(
				'number'  => 1,
				'title'   => 'Fixture',
				'content' => $content,
				'notes'   => array(
					'notes'    => '',
					'markdown' => false,
				),
			),
		);
	}
}
