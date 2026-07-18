<?php
/**
 * Legacy nested-section validator tests.
 *
 * @package Presenter
 */

use Presenter\Legacy_Section_Validator;

require_once dirname( __DIR__, 2 ) . '/includes/class-legacy-section-validator.php';

/**
 * Verify exact compatibility recognition for legacy Reveal stacks.
 */
final class Presenter_Legacy_Section_Validator_Test extends Presenter_Test_Case {
	/** Canonical single and multiple section fragments are accepted. */
	public function test_accepts_canonical_section_only_fragments(): void {
		$validator = new Legacy_Section_Validator();

		$this->assertTrue( $validator->is_canonical_stack( "\n<section id=\"one\"><h2>One</h2></section>\n" ) );
		$this->assertTrue( $validator->is_canonical_stack( '<section>One</section><section data-background="#000">Two</section>' ) );
		$this->assertTrue( $validator->is_canonical_stack( '<!-- stack --><section>Comment-compatible stack</section>' ) );
		$this->assertTrue( $validator->is_canonical_stack( '<section data-label="One > two">Attribute delimiter</section>' ) );
		$this->assertTrue( $validator->is_canonical_stack( '<!-- fake <section> --><section>Real stack</section>' ) );
	}

	/** Mixed top-level content cannot become a Reveal stack implicitly. */
	public function test_rejects_mixed_or_non_section_top_level_content(): void {
		$validator = new Legacy_Section_Validator();

		$this->assertFalse( $validator->is_canonical_stack( '<h2>Before</h2><section>Stack</section>' ) );
		$this->assertFalse( $validator->is_canonical_stack( '<section>Stack</section>after' ) );
		$this->assertFalse( $validator->is_canonical_stack( '<div><section>Nested in a div</section></div>' ) );
		$this->assertFalse( $validator->is_canonical_stack( '<section><section>Unsupported third level</section></section>' ) );
		$this->assertFalse( $validator->is_canonical_stack( '<p>No section</p>' ) );
	}

	/** Characterized section-only nested groups have a distinct opaque class. */
	public function test_classifies_opaque_nested_section_groups(): void {
		$validator = new Legacy_Section_Validator();
		$content   = '<section class="outer"><section id="one">One</section><!-- gap --><section id="two">Two</section></section>';

		$this->assertSame( Legacy_Section_Validator::OPAQUE_NESTED_STACK, $validator->classify( $content ) );
		$this->assertFalse( $validator->is_canonical_stack( $content ) );
		$this->assertNull( $validator->classify( '<section>Mixed<section>Child</section></section>' ) );
		$this->assertNull( $validator->classify( '<section><div><section>Indirect child</section></div></section>' ) );
		$this->assertNull( $validator->classify( '<section><section><section>Too deep</section></section></section>' ) );
		$this->assertNull( $validator->classify( '<section><section>Child</section></section><section>Second root</section>' ) );
	}

	/** Parser errors never qualify for automatic compatibility preservation. */
	public function test_rejects_malformed_section_fragments(): void {
		$validator = new Legacy_Section_Validator();

		$this->assertFalse( $validator->is_canonical_stack( '<section><p>Missing section closer' ) );
		$this->assertFalse( $validator->is_canonical_stack( '</section><section>Premature closer</section>' ) );
		$this->assertFalse( $validator->is_canonical_stack( '<!-- fake <section> only -->' ) );
	}
}
