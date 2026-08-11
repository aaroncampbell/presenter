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

	/** A Deck may contain one bounded Stack with direct Slide children. */
	public function test_valid_nested_deck_is_accepted(): void {
		$slide   = '<!-- wp:presenter/slide --><!-- wp:paragraph --><p>Nested</p><!-- /wp:paragraph --><!-- /wp:presenter/slide -->';
		$content = '<!-- wp:presenter/deck -->'
			. '<!-- wp:presenter/slide /-->'
			. '<!-- wp:presenter/stack -->' . $slide . $slide . '<!-- /wp:presenter/stack -->'
			. '<!-- /wp:presenter/deck -->';

		$this->assertTrue( ( new Native_Deck_Structure() )->is_valid( $content ) );
		$this->assertTrue(
			( new Native_Deck_Structure() )->is_valid(
				'<!-- wp:presenter/deck --><!-- wp:presenter/stack -->' . $slide . '<!-- /wp:presenter/stack --><!-- /wp:presenter/deck -->'
			)
		);
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
		$stack = '<!-- wp:presenter/stack -->' . $slide . '<!-- /wp:presenter/stack -->';

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
			'empty Stack'           => array( '<!-- wp:presenter/deck --><!-- wp:presenter/stack --><!-- /wp:presenter/stack --><!-- /wp:presenter/deck -->' ),
			'non-Slide Stack child' => array( '<!-- wp:presenter/deck --><!-- wp:presenter/stack --><!-- wp:paragraph --><p>Invalid</p><!-- /wp:paragraph --><!-- /wp:presenter/stack --><!-- /wp:presenter/deck -->' ),
			'nested Stack child'    => array( '<!-- wp:presenter/deck --><!-- wp:presenter/stack -->' . $stack . '<!-- /wp:presenter/stack --><!-- /wp:presenter/deck -->' ),
			'saved Stack wrapper'   => array( '<!-- wp:presenter/deck --><!-- wp:presenter/stack --><div>' . $slide . '</div><!-- /wp:presenter/stack --><!-- /wp:presenter/deck -->' ),
		);
	}
}
