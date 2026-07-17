<?php
/**
 * Pure migration planner tests.
 *
 * @package Presenter
 */

use Presenter\Legacy_Deck_Snapshot;
use Presenter\Legacy_Slide_Normalizer;
use Presenter\Migration_Plan;
use Presenter\Migration_Planner;

require_once dirname( __DIR__, 2 ) . '/includes/class-legacy-deck-snapshot.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-legacy-slide-normalizer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-migration-plan.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-migration-planner.php';

/**
 * Verify deterministic, read-only planning before any migration writer exists.
 */
final class Presenter_Migration_Planner_Test extends Presenter_Test_Case {
	/**
	 * Ready plans contain one valid Deck with ordered, lossless HTML Slides.
	 */
	public function test_ready_plan_serializes_ordered_slides_and_unique_anchors(): void {
		$planner  = new Migration_Planner( new Legacy_Slide_Normalizer() );
		$snapshot = $this->snapshot(
			array(
				array(
					'number'  => 2,
					'title'   => 'Repeated title',
					'content' => '<p>Second source record</p>',
					'notes'   => array(
						'notes'    => 'Plain speaker notes',
						'markdown' => false,
					),
				),
				array(
					'number'  => 1,
					'title'   => 'Repeated title',
					'content' => '<h2>First after sorting</h2>',
					'notes'   => array(
						'notes'    => '**Markdown speaker notes**',
						'markdown' => true,
					),
				),
			)
		);

		$plan = $planner->plan( $snapshot );

		$this->assertTrue( $plan->is_ready() );
		$this->assertSame( Migration_Plan::STATUS_READY, $plan->status() );
		$this->assertSame( 'source-fingerprint', $plan->source_fingerprint() );
		$this->assertNotNull( $plan->generated_content() );

		$blocks = parse_blocks( $plan->generated_content() );
		$this->assertCount( 1, $blocks );
		$this->assertSame( 'presenter/deck', $blocks[0]['blockName'] );
		$this->assertCount( 2, $blocks[0]['innerBlocks'] );

		$first  = $blocks[0]['innerBlocks'][0];
		$second = $blocks[0]['innerBlocks'][1];
		$this->assertSame( 'presenter/slide', $first['blockName'] );
		$this->assertSame( 'repeated-title', $first['attrs']['anchor'] );
		$this->assertSame( 'repeated-title-2', $second['attrs']['anchor'] );
		$this->assertSame( '**Markdown speaker notes**', $first['attrs']['notes'] );
		$this->assertSame( 'markdown', $first['attrs']['notesFormat'] );
		$this->assertSame( 'Plain speaker notes', $second['attrs']['notes'] );
		$this->assertSame( 'plain', $second['attrs']['notesFormat'] );
		$this->assertSame( '<h2>First after sorting</h2>', $first['innerBlocks'][0]['innerHTML'] );
		$this->assertSame( '<p>Second source record</p>', $second['innerBlocks'][0]['innerHTML'] );
		$this->assertSame( $plan->generated_content(), serialize_blocks( $blocks ) );

		$report = $plan->report();
		$this->assertSame( 2, $report['slideCount'] );
		$this->assertSame( 2, $report['customHtmlFallbackCount'] );
		$this->assertSame( 1, $report['duplicateAnchorCount'] );
		$this->assertSame( array(), $report['blockerCodes'] );
		$this->assertContains( Migration_Planner::WARNING_CUSTOM_HTML_FALLBACK, $report['warningCodes'] );
		$this->assertContains( Migration_Planner::WARNING_DUPLICATE_ANCHOR, $report['warningCodes'] );
		$this->assertSame(
			array(
				'position'     => 1,
				'sourceIndex'  => 1,
				'legacyNumber' => 1,
				'outcome'      => 'custom-html',
				'blockerCodes' => array(),
				'warningCodes' => array( Migration_Planner::WARNING_CUSTOM_HTML_FALLBACK ),
			),
			$report['slides'][0]
		);
		$this->assertSame( 0, $report['slides'][1]['sourceIndex'] );
		$this->assertContains( Migration_Planner::WARNING_DUPLICATE_ANCHOR, $report['slides'][1]['warningCodes'] );
	}

	/**
	 * Every currently unrepresentable source feature blocks generated output.
	 */
	public function test_unresolved_features_create_content_free_blockers(): void {
		$planner  = new Migration_Planner( new Legacy_Slide_Normalizer() );
		$snapshot = $this->snapshot(
			array(
				array(
					'number'  => 1,
					'title'   => 'Private title sentinel',
					'class'   => 'private-wrapper-sentinel',
					'content' => '<section>Private nested content sentinel</section>',
					'data'    => array(
						array(
							'name'  => 'private-data-name',
							'value' => 'private-data-value',
						),
					),
					'notes'   => array(
						'notes'    => '<strong>Private note sentinel</strong>',
						'markdown' => false,
					),
				),
			),
			'Private old post content sentinel',
			'/private/legacy-theme-sentinel.css'
		);

		$plan   = $planner->plan( $snapshot );
		$report = $plan->report();

		$this->assertFalse( $plan->is_ready() );
		$this->assertSame( Migration_Plan::STATUS_BLOCKED, $plan->status() );
		$this->assertNull( $plan->generated_content() );
		$this->assertEqualsCanonicalizing(
			array(
				Migration_Planner::BLOCKER_DATA_ATTRIBUTES,
				Migration_Planner::BLOCKER_EXISTING_CONTENT,
				Migration_Planner::BLOCKER_HTML_NOTES,
				Migration_Planner::BLOCKER_LEGACY_THEME,
				Migration_Planner::BLOCKER_NESTED_SECTIONS,
				Migration_Planner::BLOCKER_SLIDE_WRAPPER_CLASS,
			),
			$report['blockerCodes']
		);

		$encoded_report = wp_json_encode( $report );
		$this->assertIsString( $encoded_report );
		$this->assertStringNotContainsString( 'Private', $encoded_report );
		$this->assertStringNotContainsString( 'private-', $encoded_report );
	}

	/**
	 * Equivalent source snapshots always produce equivalent plans and reports.
	 */
	public function test_plan_is_deterministic_and_reports_normalizer_warnings_by_count_only(): void {
		$planner  = new Migration_Planner( new Legacy_Slide_Normalizer() );
		$snapshot = $this->snapshot(
			array(
				array(
					'number'  => 'invalid-number',
					'title'   => '',
					'content' => '',
				),
			)
		);

		$first  = $planner->plan( $snapshot );
		$second = $planner->plan( $snapshot );

		$this->assertSame( $first->status(), $second->status() );
		$this->assertSame( $first->generated_content(), $second->generated_content() );
		$this->assertSame( $first->report(), $second->report() );
		$this->assertFalse( $first->is_ready() );
		$this->assertNull( $first->generated_content() );
		$this->assertSame( 1, $first->report()['normalizerWarningCount'] );
		$this->assertContains( Migration_Planner::BLOCKER_SOURCE_INVALID, $first->report()['blockerCodes'] );
		$this->assertContains( Migration_Planner::WARNING_NORMALIZED_SOURCE, $first->report()['warningCodes'] );
		$this->assertContains( Migration_Planner::BLOCKER_SOURCE_INVALID, $first->report()['slides'][0]['blockerCodes'] );
	}

	/** Deck-level capture warnings prevent a falsely lossless plan. */
	public function test_snapshot_warnings_block_generated_content(): void {
		$planner = new Migration_Planner( new Legacy_Slide_Normalizer() );
		$plan    = $planner->plan(
			$this->snapshot(
				array( array( 'number' => 1 ) ),
				'',
				'',
				array( 'invalid_legacy_theme_meta' )
			)
		);

		$this->assertFalse( $plan->is_ready() );
		$this->assertNull( $plan->generated_content() );
		$this->assertSame( 1, $plan->report()['snapshotWarningCount'] );
		$this->assertContains( Migration_Planner::BLOCKER_SOURCE_INVALID, $plan->report()['blockerCodes'] );
	}

	/**
	 * Build an immutable source snapshot fixture.
	 *
	 * @param array<int, mixed>  $slides       Raw legacy slides.
	 * @param string             $post_content Existing legacy post content.
	 * @param string             $theme        Explicit legacy theme.
	 * @param array<int, string> $warnings     Snapshot warning codes.
	 * @return Legacy_Deck_Snapshot Snapshot fixture.
	 */
	private function snapshot( array $slides, string $post_content = '', string $theme = '', array $warnings = array() ): Legacy_Deck_Snapshot {
		return new Legacy_Deck_Snapshot(
			123,
			'fixture-deck',
			'Fixture deck',
			'',
			0,
			'publish',
			'',
			$post_content,
			$theme,
			'',
			$slides,
			'source-fingerprint',
			$warnings
		);
	}
}
