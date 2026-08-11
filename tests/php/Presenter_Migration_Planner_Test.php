<?php
/**
 * Pure migration planner tests.
 *
 * @package Presenter
 */

use Presenter\Legacy_Deck_Snapshot;
use Presenter\Legacy_Slide_Normalizer;
use Presenter\Legacy_Slide_Attribute_Mapper;
use Presenter\Legacy_Section_Validator;
use Presenter\Legacy_Theme_Resolver;
use Presenter\Migration_Plan;
use Presenter\Migration_Planner;
use Presenter\Slide_Attribute_Validator;
use Presenter\Speaker_Notes;

require_once dirname( __DIR__, 2 ) . '/includes/class-legacy-deck-snapshot.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-legacy-slide-normalizer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-slide-attribute-validator.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-legacy-slide-attribute-mapper.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-legacy-section-validator.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-speaker-notes.php';
require_once dirname( __DIR__, 2 ) . '/includes/interface-legacy-theme-resolver.php';
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
		$planner  = $this->planner();
		$snapshot = $this->snapshot(
			array(
				array(
					'number'  => 2,
					'title'   => 'Repeated title',
					'class'   => 'legacy-layout',
					'content' => '<p>Second source record</p>',
					'data'    => array(
						array(
							'name'  => 'chart',
							'value' => 'reputation',
						),
					),
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
		$this->assertSame( 'custom', $blocks[0]['attrs']['aspectRatio'] );
		$this->assertSame( 960, $blocks[0]['attrs']['width'] );
		$this->assertSame( 700, $blocks[0]['attrs']['height'] );
		$this->assertCount( 2, $blocks[0]['innerBlocks'] );

		$first  = $blocks[0]['innerBlocks'][0];
		$second = $blocks[0]['innerBlocks'][1];
		$this->assertSame( 'presenter/slide', $first['blockName'] );
		$this->assertTrue( $first['attrs']['legacyAutoParagraph'] );
		$this->assertTrue( $second['attrs']['legacyAutoParagraph'] );
		$this->assertTrue( $first['attrs']['legacyNotesProcessing'] );
		$this->assertTrue( $second['attrs']['legacyNotesProcessing'] );
		$this->assertSame( 'repeated-title', $first['attrs']['anchor'] );
		$this->assertSame( 'repeated-title-2', $second['attrs']['anchor'] );
		$this->assertSame( '**Markdown speaker notes**', $first['attrs']['notes'] );
		$this->assertSame( 'markdown', $first['attrs']['notesFormat'] );
		$this->assertSame( 'Plain speaker notes', $second['attrs']['notes'] );
		$this->assertSame( 'plain', $second['attrs']['notesFormat'] );
		$this->assertSame( 'legacy-layout', $second['attrs']['className'] );
		$this->assertSame(
			array(
				array(
					'name'  => 'data-chart',
					'value' => 'reputation',
				),
			),
			$second['attrs']['revealDataAttributes']
		);
		$this->assertSame( '<h2>First after sorting</h2>', $first['innerBlocks'][0]['innerHTML'] );
		$this->assertSame( '<p>Second source record</p>', $second['innerBlocks'][0]['innerHTML'] );
		$this->assertSame( $plan->generated_content(), serialize_blocks( $blocks ) );

		$report = $plan->report();
		$this->assertSame( 2, $report['slideCount'] );
		$this->assertSame( 2, $report['customHtmlFallbackCount'] );
		$this->assertSame( 1, $report['duplicateAnchorCount'] );
		$this->assertTrue( $report['legacyHtmlTrusted'] );
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

	/** A complete extension conversion replaces only the Custom HTML fallback. */
	public function test_complete_slide_converter_can_supply_native_blocks(): void {
		$converter = static function ( mixed $blocks, string $content ): mixed {
			if ( '<p>Convert me</p>' !== $content ) {
				return $blocks;
			}

			return array(
				array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => '<p>Convert me</p>',
					'innerContent' => array( '<p>Convert me</p>' ),
				),
			);
		};
		add_filter( 'presenter_migration_slide_blocks', $converter, 10, 2 );

		try {
			$plan = $this->planner()->plan(
				$this->snapshot(
					array(
						array(
							'number'  => 1,
							'title'   => 'Converted',
							'content' => '<p>Convert me</p>',
						),
					)
				)
			);
		} finally {
			remove_filter( 'presenter_migration_slide_blocks', $converter, 10 );
		}

		$slide = parse_blocks( $plan->generated_content() )[0]['innerBlocks'][0];
		$this->assertSame( 'core/paragraph', $slide['innerBlocks'][0]['blockName'] );
		$this->assertSame( 1, $plan->report()['nativeContentConversionCount'] );
		$this->assertSame( 0, $plan->report()['customHtmlFallbackCount'] );
		$this->assertSame( 'native-blocks', $plan->report()['slides'][0]['outcome'] );
		$this->assertFalse( $slide['attrs']['legacyAutoParagraph'] );
		$this->assertTrue( $slide['attrs']['legacyNotesProcessing'] );
	}

	/** Untrusted active HTML cannot enter a Custom HTML fallback. */
	public function test_untrusted_active_html_blocks_custom_html_migration(): void {
		$plan = $this->planner()->plan(
			$this->snapshot(
				array(
					array(
						'number'  => 1,
						'title'   => 'Untrusted script',
						'content' => '<script>window.exploit=true;</script><p onclick="window.eventExploit=true">Visible</p>',
					),
				),
				'',
				'',
				array(),
				false
			)
		);

		$this->assertFalse( $plan->is_ready() );
		$this->assertNull( $plan->generated_content() );
		$this->assertFalse( $plan->report()['legacyHtmlTrusted'] );
		$this->assertContains( Migration_Planner::BLOCKER_UNTRUSTED_ACTIVE_HTML, $plan->report()['blockerCodes'] );
		$this->assertContains( Migration_Planner::BLOCKER_UNTRUSTED_ACTIVE_HTML, $plan->report()['slides'][0]['blockerCodes'] );
		$this->assertStringNotContainsString( 'window.exploit', wp_json_encode( $plan->report() ) );
	}

	/** A complete converter may safely replace untrusted active source HTML. */
	public function test_untrusted_active_html_may_convert_to_safe_native_blocks(): void {
		$source    = '<div data-chart-target></div><script>window.legacyChart=true;</script>';
		$converter = static function ( mixed $blocks, string $content ) use ( $source ): mixed {
			if ( $source !== $content ) {
				return $blocks;
			}

			return array(
				array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => '<p>Converted chart</p>',
					'innerContent' => array( '<p>Converted chart</p>' ),
				),
			);
		};
		add_filter( 'presenter_migration_slide_blocks', $converter, 10, 2 );

		try {
			$plan = $this->planner()->plan(
				$this->snapshot(
					array(
						array(
							'number'  => 1,
							'content' => $source,
						),
					),
					'',
					'',
					array(),
					false
				)
			);
		} finally {
			remove_filter( 'presenter_migration_slide_blocks', $converter, 10 );
		}

		$this->assertTrue( $plan->is_ready() );
		$this->assertNotNull( $plan->generated_content() );
		$this->assertNotContains( Migration_Planner::BLOCKER_UNTRUSTED_ACTIVE_HTML, $plan->report()['blockerCodes'] );
		$slide = parse_blocks( $plan->generated_content() )[0]['innerBlocks'][0];
		$this->assertSame( 'core/paragraph', $slide['innerBlocks'][0]['blockName'] );
	}

	/** Legacy Reveal image shorthand is serialized as an editable background. */
	public function test_ready_plan_types_legacy_background_image_shorthand(): void {
		$plan = $this->planner()->plan(
			$this->snapshot(
				array(
					array(
						'number' => 1,
						'title'  => 'Gorillas',
						'data'   => array(
							array(
								'name'  => 'background',
								'value' => '//example.test/gorillas.png',
							),
						),
					),
				)
			)
		);

		$this->assertTrue( $plan->is_ready() );
		$slide = parse_blocks( $plan->generated_content() )[0]['innerBlocks'][0];
		$this->assertSame( '//example.test/gorillas.png', $slide['attrs']['backgroundImageUrl'] );
		$this->assertArrayNotHasKey( 'revealDataAttributes', $slide['attrs'] );
	}

	/** Safe HTML notes and canonical Reveal stacks have lossless representations. */
	public function test_ready_plan_preserves_html_notes_and_canonical_section_stacks(): void {
		$stack        = '<section id="first"><h2>First</h2></section><section data-background="#000">Second</section>';
		$opaque_stack = '<section><section id="nested-one">One</section><section id="nested-two">Two</section></section>';
		$plan         = $this->planner()->plan(
			$this->snapshot(
				array(
					array(
						'number'  => 1,
						'title'   => 'HTML notes',
						'content' => $stack,
						'notes'   => array(
							'notes'    => '<p>Tell <strong>this</strong>.</p>',
							'markdown' => false,
						),
					),
					array(
						'number' => 2,
						'title'  => 'Markdown HTML notes',
						'notes'  => array(
							'notes'    => '<blockquote>Quoted **Markdown**</blockquote>',
							'markdown' => true,
						),
					),
					array(
						'number' => 3,
						'title'  => 'Angle bracket prose',
						'notes'  => array(
							'notes'    => 'Return Array<string, int>.',
							'markdown' => false,
						),
					),
					array(
						'number'  => 4,
						'title'   => 'Opaque nested compatibility',
						'content' => $opaque_stack,
					),
				)
			)
		);

		$this->assertTrue( $plan->is_ready() );
		$deck   = parse_blocks( $plan->generated_content() )[0];
		$slides = $deck['innerBlocks'];
		$this->assertSame( 'html', $slides[0]['attrs']['notesFormat'] );
		$this->assertSame( 'markdown-html', $slides[1]['attrs']['notesFormat'] );
		$this->assertSame( 'plain', $slides[2]['attrs']['notesFormat'] );
		$this->assertSame( $stack, $slides[0]['innerBlocks'][0]['innerHTML'] );
		$this->assertSame( $opaque_stack, $slides[3]['innerBlocks'][0]['innerHTML'] );
		$this->assertSame( $plan->generated_content(), serialize_blocks( array( $deck ) ) );
		$this->assertContains( Migration_Planner::WARNING_LEGACY_STACK, $plan->report()['warningCodes'] );
		$this->assertContains( Migration_Planner::WARNING_LEGACY_STACK, $plan->report()['slides'][0]['warningCodes'] );
		$this->assertContains( Migration_Planner::WARNING_OPAQUE_NESTED_STACK, $plan->report()['warningCodes'] );
		$this->assertContains( Migration_Planner::WARNING_OPAQUE_NESTED_STACK, $plan->report()['slides'][3]['warningCodes'] );
		$this->assertNotContains( Migration_Planner::BLOCKER_HTML_NOTES, $plan->report()['blockerCodes'] );
		$this->assertNotContains( Migration_Planner::BLOCKER_NESTED_SECTIONS, $plan->report()['blockerCodes'] );
	}

	/** Characterized section-only Slides become native Nested Slides. */
	public function test_ready_plan_converts_canonical_section_stack_to_nested_slides(): void {
		$content = '<section id="case-overview" class="legacy-child" data-background-color="#112233"><h2>Overview</h2></section><section id="case-results"></section>';
		$plan    = $this->planner()->plan(
			$this->snapshot(
				array(
					array(
						'number'  => 1,
						'title'   => 'Case study',
						'content' => $content,
					),
				)
			)
		);

		$this->assertTrue( $plan->is_ready() );
		$stack = parse_blocks( $plan->generated_content() )[0]['innerBlocks'][0];
		$this->assertSame( 'presenter/stack', $stack['blockName'] );
		$this->assertSame( 'case-study', $stack['attrs']['anchor'] );
		$this->assertSame( 'Case study', $stack['attrs']['label'] );
		$this->assertCount( 2, $stack['innerBlocks'] );
		$this->assertSame( 'presenter/slide', $stack['innerBlocks'][0]['blockName'] );
		$this->assertSame( 'case-overview', $stack['innerBlocks'][0]['attrs']['anchor'] );
		$this->assertSame( 'legacy-child', $stack['innerBlocks'][0]['attrs']['className'] );
		$this->assertSame( '#112233', $stack['innerBlocks'][0]['attrs']['backgroundColor'] );
		$this->assertSame( '<h2>Overview</h2>', $stack['innerBlocks'][0]['innerBlocks'][0]['innerHTML'] );
		$this->assertSame( 'case-results', $stack['innerBlocks'][1]['attrs']['anchor'] );
		$this->assertSame( 'native-nested-slides', $plan->report()['slides'][0]['outcome'] );
		$this->assertSame( 1, $plan->report()['customHtmlFallbackCount'] );
		$this->assertContains( Migration_Planner::WARNING_NATIVE_STACK, $plan->report()['warningCodes'] );
		$this->assertNotContains( Migration_Planner::WARNING_LEGACY_STACK, $plan->report()['warningCodes'] );
		$this->assertSame( $plan->generated_content(), serialize_blocks( parse_blocks( $plan->generated_content() ) ) );
	}

	/** Stack conversion retains ambiguous outer Slide behavior losslessly. */
	public function test_stack_with_outer_behavior_remains_custom_html(): void {
		$content = '<section id="one">One</section><section id="two">Two</section>';
		$plan    = $this->planner()->plan(
			$this->snapshot(
				array(
					array(
						'number'  => 1,
						'title'   => 'Styled stack',
						'class'   => 'outer-layout',
						'content' => $content,
					),
				)
			)
		);

		$this->assertTrue( $plan->is_ready() );
		$slide = parse_blocks( $plan->generated_content() )[0]['innerBlocks'][0];
		$this->assertSame( 'presenter/slide', $slide['blockName'] );
		$this->assertSame( $content, $slide['innerBlocks'][0]['innerHTML'] );
		$this->assertContains( Migration_Planner::WARNING_LEGACY_STACK, $plan->report()['warningCodes'] );
		$this->assertNotContains( Migration_Planner::WARNING_NATIVE_STACK, $plan->report()['warningCodes'] );
	}

	/** Empty legacy content retains the whole-section paragraph stage for notes. */
	public function test_empty_slide_retains_legacy_auto_paragraph_processing(): void {
		$plan = $this->planner()->plan(
			$this->snapshot(
				array(
					array(
						'number' => 1,
						'title'  => 'Notes only',
						'notes'  => array(
							'notes'    => "First note.\n\nSecond note.",
							'markdown' => false,
						),
					),
				)
			)
		);

		$this->assertTrue( $plan->is_ready() );
		$slide = parse_blocks( $plan->generated_content() )[0]['innerBlocks'][0];
		$this->assertTrue( $slide['attrs']['legacyAutoParagraph'] );
		$this->assertTrue( $slide['attrs']['legacyNotesProcessing'] );
	}

	/**
	 * Every currently unrepresentable source feature blocks generated output.
	 */
	public function test_unresolved_features_create_content_free_blockers(): void {
		$planner  = $this->planner();
		$snapshot = $this->snapshot(
			array(
				array(
					'number'  => 1,
					'title'   => 'Private title sentinel',
					'class'   => 'private-wrapper-sentinel private-wrapper-sentinel',
					'content' => '<p>Private mixed content sentinel</p><section>Private nested content sentinel</section>',
					'data'    => array(
						array(
							'name'  => 'transition',
							'value' => 'private-data-value',
						),
					),
					'notes'   => array(
						'notes'    => '<strong onclick="private-handler">Private note sentinel</strong>',
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
		$planner  = $this->planner();
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
		$this->assertSame( 1, $first->report()['blockingNormalizerWarningCount'] );
		$this->assertContains( Migration_Planner::BLOCKER_SOURCE_INVALID, $first->report()['blockerCodes'] );
		$this->assertContains( Migration_Planner::WARNING_NORMALIZED_SOURCE, $first->report()['warningCodes'] );
		$this->assertContains( Migration_Planner::BLOCKER_SOURCE_INVALID, $first->report()['slides'][0]['blockerCodes'] );
	}

	/** Characterized no-op source warnings remain visible without blocking. */
	public function test_represented_source_warnings_do_not_block_a_lossless_plan(): void {
		$plan = $this->planner()->plan(
			$this->snapshot(
				array(
					(object) array(
						'number'     => 1,
						'background' => '',
					),
					false,
				)
			)
		);

		$this->assertTrue( $plan->is_ready() );
		$this->assertSame( 2, $plan->report()['normalizerWarningCount'] );
		$this->assertSame( 0, $plan->report()['blockingNormalizerWarningCount'] );
		$this->assertContains( Migration_Planner::WARNING_NORMALIZED_SOURCE, $plan->report()['warningCodes'] );
		$this->assertNotContains( Migration_Planner::BLOCKER_SOURCE_INVALID, $plan->report()['blockerCodes'] );
		$this->assertSame( array(), $plan->report()['slides'][0]['blockerCodes'] );
		$this->assertSame( array(), $plan->report()['slides'][1]['blockerCodes'] );
	}

	/** Exact duplicate data attributes are collapsed and reported without values. */
	public function test_exact_duplicate_data_attributes_are_collapsed_and_reported(): void {
		$plan = $this->planner()->plan(
			$this->snapshot(
				array(
					array(
						'number' => 1,
						'data'   => array(
							array(
								'name'  => 'state',
								'value' => 'private-state-sentinel',
							),
							array(
								'name'  => 'state',
								'value' => 'private-state-sentinel',
							),
						),
					),
				)
			)
		);

		$this->assertTrue( $plan->is_ready() );
		$this->assertSame( 1, $plan->report()['duplicateDataAttributeCount'] );
		$this->assertContains( Migration_Planner::WARNING_DUPLICATE_DATA, $plan->report()['warningCodes'] );
		$this->assertContains( Migration_Planner::WARNING_DUPLICATE_DATA, $plan->report()['slides'][0]['warningCodes'] );
		$this->assertNotContains( Migration_Planner::BLOCKER_DATA_ATTRIBUTES, $plan->report()['blockerCodes'] );
		$this->assertStringNotContainsString( 'private-state-sentinel', wp_json_encode( $plan->report() ) );

		$deck       = parse_blocks( $plan->generated_content() )[0];
		$attributes = $deck['innerBlocks'][0]['attrs']['revealDataAttributes'];
		$this->assertCount( 1, $attributes );
	}

	/** Deck-level capture warnings prevent a falsely lossless plan. */
	public function test_snapshot_warnings_block_generated_content(): void {
		$planner = $this->planner();
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

	/** A resolved legacy theme is stored as its stable Deck theme ID. */
	public function test_resolved_legacy_theme_is_preserved_by_stable_id(): void {
		$plan = $this->planner(
			array( '/plugins/presenter/reveal.js/dist/theme/league.css' => 'league' )
		)->plan(
			$this->snapshot(
				array( array( 'number' => 1 ) ),
				'',
				'/plugins/presenter/reveal.js/dist/theme/league.css'
			)
		);

		$this->assertTrue( $plan->is_ready() );
		$this->assertNotNull( $plan->generated_content() );
		$blocks = parse_blocks( $plan->generated_content() );
		$this->assertSame( 'league', $blocks[0]['attrs']['theme'] );
		$this->assertNotContains( Migration_Planner::BLOCKER_LEGACY_THEME, $plan->report()['blockerCodes'] );
	}

	/**
	 * Build a deterministic legacy theme resolver for planner tests.
	 *
	 * @param array<string, string> $aliases Legacy values keyed to stable IDs.
	 * @return Migration_Planner Planner fixture.
	 */
	private function planner( array $aliases = array() ): Migration_Planner {
		$themes = new class( $aliases ) implements Legacy_Theme_Resolver {
			/**
			 * Create the resolver fixture.
			 *
			 * @param array<string, string> $aliases Legacy values keyed to stable IDs.
			 */
			public function __construct( private array $aliases ) {}

			/**
			 * Resolve a fixture legacy theme.
			 *
			 * @param string $legacy_theme Legacy theme value.
			 * @return string|null Stable ID or null.
			 */
			public function resolve_legacy_theme_id( string $legacy_theme ): ?string {
				return $this->aliases[ $legacy_theme ] ?? null;
			}
		};

		$validator = new Slide_Attribute_Validator();

		return new Migration_Planner(
			new Legacy_Slide_Normalizer(),
			new Legacy_Slide_Attribute_Mapper( $validator ),
			new Legacy_Section_Validator(),
			new Speaker_Notes(),
			$themes
		);
	}

	/**
	 * Build an immutable source snapshot fixture.
	 *
	 * @param array<int, mixed>  $slides       Raw legacy slides.
	 * @param string             $post_content Existing legacy post content.
	 * @param string             $theme        Explicit legacy theme.
	 * @param array<int, string> $warnings     Snapshot warning codes.
	 * @param bool               $html_trusted Whether the exact raw HTML is trusted.
	 * @return Legacy_Deck_Snapshot Snapshot fixture.
	 */
	private function snapshot( array $slides, string $post_content = '', string $theme = '', array $warnings = array(), bool $html_trusted = true ): Legacy_Deck_Snapshot {
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
			$warnings,
			$html_trusted
		);
	}
}
