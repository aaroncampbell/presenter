<?php
/**
 * Legacy slide normalizer tests.
 *
 * @package Presenter
 */

use Presenter\Legacy_Slide_Normalizer;

require_once dirname( __DIR__, 2 ) . '/includes/class-legacy-slide-normalizer.php';

/**
 * Verify deterministic, non-mutating legacy slide normalization.
 */
class Presenter_Legacy_Slide_Normalizer_Test extends Presenter_Test_Case {
	/** Object and array records normalize and sort stably by number. */
	public function test_normalize_projects_legacy_shapes_and_preserves_duplicate_data(): void {
		$raw    = array(
			(object) array(
				'number'  => 5,
				'title'   => 'Fifth A',
				'content' => '<p>A</p>',
				'class'   => 'intro featured',
				'data'    => array(
					(object) array(
						'name'  => 'background-color',
						'value' => '#123456',
					),
					(object) array(
						'name'  => 'background-color',
						'value' => '#654321',
					),
				),
				'notes'   => array(
					'notes'    => '**Markdown**',
					'markdown' => true,
				),
			),
			array(
				'number'  => '1',
				'title'   => 'First',
				'content' => '<p>First</p>',
			),
			array(
				'number'  => 5,
				'title'   => 'Fifth B',
				'content' => '<p>B</p>',
			),
		);
		$before = wp_json_encode( $raw );

		$slides = ( new Legacy_Slide_Normalizer() )->normalize( $raw );

		$this->assertSame( $before, wp_json_encode( $raw ), 'Normalization must not mutate source records.' );
		$this->assertSame( array( 1, 5, 5 ), array_column( $slides, 'number' ) );
		$this->assertSame( array( 1, 0, 2 ), array_column( $slides, 'sourceIndex' ) );
		$this->assertSame( array(), $slides[0]['warnings'] );
		$this->assertSame(
			array(
				'notes'    => '',
				'markdown' => false,
			),
			$slides[0]['notes']
		);
		$this->assertSame( 'intro featured', $slides[1]['class'] );
		$this->assertSame( '**Markdown**', $slides[1]['notes']['notes'] );
		$this->assertTrue( $slides[1]['notes']['markdown'] );
		$this->assertCount( 2, $slides[1]['data'] );
		$this->assertSame( '#123456', $slides[1]['data'][0]['value'] );
		$this->assertSame( '#654321', $slides[1]['data'][1]['value'] );
	}

	/** Older and malformed values receive content-free per-slide warnings. */
	public function test_normalize_defaults_older_fields_and_reports_malformed_values(): void {
		$slides = ( new Legacy_Slide_Normalizer() )->normalize(
			array(
				(object) array(
					'number'  => 2,
					'title'   => 'Older record',
					'content' => '<p>Still valid</p>',
				),
				array(
					'number'  => 'invalid',
					'unknown' => 'must not be silently discarded',
					'title'   => array( 'not a string' ),
					'content' => 123,
					'class'   => false,
					'data'    => array(
						'broken',
						array(
							'name'  => array(),
							'value' => new stdClass(),
							'extra' => 'must not be silently discarded',
						),
					),
					'notes'   => 'not a notes record',
				),
				'not a slide record',
			)
		);

		$this->assertSame( array(), $slides[0]['warnings'], 'Missing optional legacy fields are valid.' );
		$malformed = $slides[2];
		$this->assertSame( 2, $malformed['sourceIndex'] );
		$this->assertContains( 'invalid_slide_record', $malformed['warnings'] );
		$this->assertContains( 'missing_slide_number', $malformed['warnings'] );

		$malformed = $slides[1];
		$this->assertSame( 1, $malformed['sourceIndex'] );
		$this->assertSame( '', $malformed['title'] );
		$this->assertSame( '', $malformed['content'] );
		$this->assertSame( '', $malformed['class'] );
		$this->assertSame(
			array(
				'notes'    => '',
				'markdown' => false,
			),
			$malformed['notes']
		);
		$this->assertContains( 'invalid_slide_number', $malformed['warnings'] );
		$this->assertContains( 'invalid_slide_title', $malformed['warnings'] );
		$this->assertContains( 'invalid_slide_content', $malformed['warnings'] );
		$this->assertContains( 'invalid_slide_class', $malformed['warnings'] );
		$this->assertContains( 'invalid_slide_data_item', $malformed['warnings'] );
		$this->assertContains( 'invalid_slide_data_name', $malformed['warnings'] );
		$this->assertContains( 'invalid_slide_data_value', $malformed['warnings'] );
		$this->assertContains( 'unknown_slide_data_fields', $malformed['warnings'] );
		$this->assertContains( 'invalid_slide_notes', $malformed['warnings'] );
		$this->assertContains( 'unknown_slide_fields', $malformed['warnings'] );
		foreach ( $malformed['warnings'] as $warning ) {
			$this->assertMatchesRegularExpression( '/^[a-z_]+$/', $warning );
		}
	}

	/** Characterized no-op fields and false records retain their legacy result. */
	public function test_normalize_represents_characterized_no_op_source_shapes(): void {
		$normalizer = new Legacy_Slide_Normalizer();
		$slides     = $normalizer->normalize(
			array(
				(object) array(
					'number'     => 1,
					'background' => '',
					'content'    => '<p>Preserved</p>',
				),
				false,
			)
		);

		$this->assertSame( '<p>Preserved</p>', $slides[0]['content'] );
		$this->assertSame( array( Legacy_Slide_Normalizer::WARNING_EMPTY_BACKGROUND ), $slides[0]['warnings'] );
		$this->assertSame( 2, $slides[1]['number'] );
		$this->assertSame( '', $slides[1]['content'] );
		$this->assertSame( array( Legacy_Slide_Normalizer::WARNING_FALSE_RECORD ), $slides[1]['warnings'] );
		$this->assertSame( 0, $normalizer->blocking_warning_count( $slides[0]['warnings'] ) );
		$this->assertSame( 0, $normalizer->blocking_warning_count( $slides[1]['warnings'] ) );

		$unknown = $normalizer->normalize(
			array(
				array(
					'number'     => 1,
					'background' => 'not empty',
				),
			)
		)[0];
		$this->assertContains( 'unknown_slide_fields', $unknown['warnings'] );
		$this->assertSame( 1, $normalizer->blocking_warning_count( $unknown['warnings'] ) );
	}
}
