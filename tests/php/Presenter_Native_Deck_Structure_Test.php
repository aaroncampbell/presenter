<?php
/**
 * Native Presenter Deck structure tests.
 *
 * @package Presenter
 */

use Presenter\Native_Deck_Structure;

/**
 * Verify the reusable native Deck rendering boundary.
 */
final class Presenter_Native_Deck_Structure_Test extends Presenter_Test_Case {
	/** A Deck with one or more Slide children is structurally valid. */
	public function test_valid_flat_deck_is_accepted(): void {
		$content = "\n<!-- wp:presenter/deck -->\n"
			. '<!-- wp:presenter/slide --><!-- wp:paragraph --><p>First</p><!-- /wp:paragraph --><!-- /wp:presenter/slide -->'
			. '<!-- wp:presenter/slide --><!-- wp:html --><p>Second</p><!-- /wp:html --><!-- /wp:presenter/slide -->'
			. "\n<!-- /wp:presenter/deck -->\n";

		$this->assertTrue( ( new Native_Deck_Structure() )->is_valid( $content ) );
	}

	/**
	 * Malformed root and child structures remain outside the native boundary.
	 *
	 * @dataProvider invalid_structures
	 *
	 * @param string $content Candidate serialized block content.
	 */
	public function test_invalid_structure_is_rejected( string $content ): void {
		$this->assertFalse( ( new Native_Deck_Structure() )->is_valid( $content ) );
	}

	/** Provide every structure rejected by the template router contract. */
	public function invalid_structures(): array {
		$slide = '<!-- wp:presenter/slide --><!-- wp:paragraph --><p>Slide</p><!-- /wp:paragraph --><!-- /wp:presenter/slide -->';
		$deck  = '<!-- wp:presenter/deck -->' . $slide . '<!-- /wp:presenter/deck -->';

		return array(
			'empty content'         => array( '' ),
			'empty Deck'            => array( '<!-- wp:presenter/deck --><!-- /wp:presenter/deck -->' ),
			'two root Decks'        => array( $deck . $deck ),
			'stray root block'      => array( '<!-- wp:paragraph --><p>Stray</p><!-- /wp:paragraph -->' . $deck ),
			'root freeform HTML'    => array( '<p>Stray</p>' . $deck ),
			'non-Slide Deck child'  => array( '<!-- wp:presenter/deck --><!-- wp:paragraph --><p>Invalid</p><!-- /wp:paragraph --><!-- /wp:presenter/deck -->' ),
			'nested Deck child'     => array( '<!-- wp:presenter/deck -->' . $deck . '<!-- /wp:presenter/deck -->' ),
			'saved Deck wrapper'    => array( '<!-- wp:presenter/deck --><div>' . $slide . '</div><!-- /wp:presenter/deck -->' ),
			'freeform Deck content' => array( '<!-- wp:presenter/deck --><p>Stray</p>' . $slide . '<!-- /wp:presenter/deck -->' ),
		);
	}
}
