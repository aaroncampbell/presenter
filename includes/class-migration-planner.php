<?php
/**
 * Pure legacy-deck migration planning.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Converts immutable legacy values into a deterministic, non-writing plan.
 */
final class Migration_Planner {
	public const BLOCKER_DATA_ATTRIBUTES      = 'legacy_data_attributes';
	public const BLOCKER_EXISTING_CONTENT     = 'legacy_post_content';
	public const BLOCKER_HTML_NOTES           = 'legacy_html_notes';
	public const BLOCKER_LEGACY_THEME         = 'legacy_theme';
	public const BLOCKER_NESTED_SECTIONS      = 'legacy_nested_sections';
	public const BLOCKER_SLIDE_WRAPPER_CLASS  = 'legacy_slide_wrapper_class';
	public const BLOCKER_SOURCE_INVALID       = 'legacy_source_invalid';
	public const WARNING_CUSTOM_HTML_FALLBACK = 'custom_html_fallback';
	public const WARNING_DUPLICATE_ANCHOR     = 'duplicate_anchor_normalized';
	public const WARNING_NORMALIZED_SOURCE    = 'legacy_source_normalized';

	/**
	 * Legacy slide normalizer.
	 *
	 * @var Legacy_Slide_Normalizer
	 */
	private Legacy_Slide_Normalizer $normalizer;

	/**
	 * Create a planner.
	 *
	 * @param Legacy_Slide_Normalizer $normalizer Pure legacy value normalizer.
	 */
	public function __construct( Legacy_Slide_Normalizer $normalizer ) {
		$this->normalizer = $normalizer;
	}

	/**
	 * Build a deterministic plan without reading or writing external state.
	 *
	 * @param Legacy_Deck_Snapshot $snapshot Immutable source snapshot.
	 * @return Migration_Plan Migration plan.
	 */
	public function plan( Legacy_Deck_Snapshot $snapshot ): Migration_Plan {
		$slides                        = $this->normalizer->normalize( $snapshot->raw_slides() );
		$blocker_codes                 = array();
		$warning_codes                 = array();
		$normalizer_warnings           = 0;
		$snapshot_warnings             = count( $snapshot->warnings() );
		$fallback_count                = 0;
		$duplicate_count               = 0;
		$used_anchors                  = array();
		$planned_slides                = array();
		$slide_reports                 = array();
		$normalizer_warnings_by_source = array();

		foreach ( $slides as $slide ) {
			$warning_count = count( $slide['warnings'] );
			if ( 0 === $warning_count ) {
				continue;
			}

			$normalizer_warnings                                   += $warning_count;
			$normalizer_warnings_by_source[ $slide['sourceIndex'] ] = $warning_count;
		}

		if ( '' !== trim( $snapshot->post_content() ) ) {
			$blocker_codes[] = self::BLOCKER_EXISTING_CONTENT;
		}

		if ( '' !== trim( $snapshot->theme() ) ) {
			$blocker_codes[] = self::BLOCKER_LEGACY_THEME;
		}

		if ( 0 < $normalizer_warnings || 0 < $snapshot_warnings ) {
			$blocker_codes[] = self::BLOCKER_SOURCE_INVALID;
			$warning_codes[] = self::WARNING_NORMALIZED_SOURCE;
		}

		foreach ( $slides as $position => $slide ) {
			$class               = trim( $slide['class'] );
			$content             = $slide['content'];
			$notes               = $slide['notes']['notes'];
			$slide_blocker_codes = array();
			$slide_warning_codes = array();

			if ( '' !== $class ) {
				$blocker_codes[]       = self::BLOCKER_SLIDE_WRAPPER_CLASS;
				$slide_blocker_codes[] = self::BLOCKER_SLIDE_WRAPPER_CLASS;
			}

			if ( array() !== $slide['data'] ) {
				$blocker_codes[]       = self::BLOCKER_DATA_ATTRIBUTES;
				$slide_blocker_codes[] = self::BLOCKER_DATA_ATTRIBUTES;
			}

			if ( $this->contains_html( $notes ) ) {
				$blocker_codes[]       = self::BLOCKER_HTML_NOTES;
				$slide_blocker_codes[] = self::BLOCKER_HTML_NOTES;
			}

			if ( preg_match( '/<\s*section\b/i', $content ) ) {
				$blocker_codes[]       = self::BLOCKER_NESTED_SECTIONS;
				$slide_blocker_codes[] = self::BLOCKER_NESTED_SECTIONS;
			}

			if ( isset( $normalizer_warnings_by_source[ $slide['sourceIndex'] ] ) ) {
				$slide_blocker_codes[] = self::BLOCKER_SOURCE_INVALID;
				$slide_warning_codes[] = self::WARNING_NORMALIZED_SOURCE;
			}

			$base_anchor = sanitize_title( $slide['title'] );
			if ( '' === $base_anchor ) {
				$base_anchor = 'slide-' . ( $position + 1 );
			}

			$anchor = $base_anchor;
			$suffix = 2;
			while ( isset( $used_anchors[ $anchor ] ) ) {
				$anchor = $base_anchor . '-' . $suffix;
				++$suffix;
			}

			if ( $anchor !== $base_anchor ) {
				++$duplicate_count;
				$warning_codes[]       = self::WARNING_DUPLICATE_ANCHOR;
				$slide_warning_codes[] = self::WARNING_DUPLICATE_ANCHOR;
			}
			$used_anchors[ $anchor ] = true;

			if ( '' !== trim( $content ) ) {
				++$fallback_count;
				$warning_codes[]       = self::WARNING_CUSTOM_HTML_FALLBACK;
				$slide_warning_codes[] = self::WARNING_CUSTOM_HTML_FALLBACK;
			}

			sort( $slide_blocker_codes, SORT_STRING );
			sort( $slide_warning_codes, SORT_STRING );
			$slide_reports[] = array(
				'position'     => $position + 1,
				'sourceIndex'  => $slide['sourceIndex'],
				'legacyNumber' => $slide['number'],
				'outcome'      => '' === trim( $content ) ? 'empty' : 'custom-html',
				'blockerCodes' => array_values( array_unique( $slide_blocker_codes ) ),
				'warningCodes' => array_values( array_unique( $slide_warning_codes ) ),
			);

			$planned_slides[] = array(
				'anchor'      => $anchor,
				'content'     => $content,
				'label'       => $slide['title'],
				'notes'       => $notes,
				'notesFormat' => $slide['notes']['markdown'] ? 'markdown' : 'plain',
			);
		}

		$blocker_codes = array_values( array_unique( $blocker_codes ) );
		$warning_codes = array_values( array_unique( $warning_codes ) );
		sort( $blocker_codes, SORT_STRING );
		sort( $warning_codes, SORT_STRING );

		$report = array(
			'status'                  => array() === $blocker_codes ? Migration_Plan::STATUS_READY : Migration_Plan::STATUS_BLOCKED,
			'postId'                  => $snapshot->post_id(),
			'slideCount'              => count( $slides ),
			'customHtmlFallbackCount' => $fallback_count,
			'duplicateAnchorCount'    => $duplicate_count,
			'normalizerWarningCount'  => $normalizer_warnings,
			'snapshotWarningCount'    => $snapshot_warnings,
			'blockerCodes'            => $blocker_codes,
			'warningCodes'            => $warning_codes,
			'slides'                  => $slide_reports,
		);

		if ( array() !== $blocker_codes ) {
			return Migration_Plan::blocked( $snapshot->fingerprint(), $report );
		}

		return Migration_Plan::ready(
			$snapshot->fingerprint(),
			$report,
			$this->serialize_deck( $planned_slides )
		);
	}

	/**
	 * Detect HTML that cannot be represented by the plain/Markdown note schema.
	 *
	 * @param string $notes Legacy notes.
	 * @return bool Whether notes contain HTML.
	 */
	private function contains_html( string $notes ): bool {
		return wp_strip_all_tags( $notes ) !== $notes;
	}

	/**
	 * Serialize one Deck containing the planned Slides.
	 *
	 * @param array<int, array<string, mixed>> $slides Planned slide values.
	 * @return string Serialized block markup.
	 */
	private function serialize_deck( array $slides ): string {
		$slide_blocks = array();

		foreach ( $slides as $slide ) {
			$inner_blocks = array();
			if ( '' !== trim( $slide['content'] ) ) {
				$inner_blocks[] = array(
					'blockName'    => 'core/html',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => $slide['content'],
					'innerContent' => array( $slide['content'] ),
				);
			}

			$slide_blocks[] = $this->container_block(
				'presenter/slide',
				array(
					'label'       => $slide['label'],
					'anchor'      => $slide['anchor'],
					'notes'       => $slide['notes'],
					'notesFormat' => $slide['notesFormat'],
				),
				$inner_blocks
			);
		}

		return serialize_block( $this->container_block( 'presenter/deck', array(), $slide_blocks ) );
	}

	/**
	 * Build the parsed-block shape expected by serialize_block().
	 *
	 * @param string                   $name         Block name.
	 * @param array<string, mixed>     $attributes   Block attributes.
	 * @param array<int, array<mixed>> $inner_blocks Child blocks.
	 * @return array<string, mixed> Parsed block structure.
	 */
	private function container_block( string $name, array $attributes, array $inner_blocks ): array {
		$inner_content = array( "\n" );
		foreach ( $inner_blocks as $unused ) {
			$inner_content[] = null;
			$inner_content[] = "\n";
		}

		return array(
			'blockName'    => $name,
			'attrs'        => $attributes,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => '',
			'innerContent' => $inner_content,
		);
	}
}
