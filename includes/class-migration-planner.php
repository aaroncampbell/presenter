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
	/** Deterministic migration planning contract version. */
	public const VERSION = 5;

	public const BLOCKER_DATA_ATTRIBUTES      = 'legacy_data_attributes';
	public const BLOCKER_EXISTING_CONTENT     = 'legacy_post_content';
	public const BLOCKER_HTML_NOTES           = 'legacy_html_notes';
	public const BLOCKER_LEGACY_THEME         = 'legacy_theme';
	public const BLOCKER_NESTED_SECTIONS      = 'legacy_nested_sections';
	public const BLOCKER_SLIDE_WRAPPER_CLASS  = 'legacy_slide_wrapper_class';
	public const BLOCKER_SOURCE_INVALID       = 'legacy_source_invalid';
	public const WARNING_CUSTOM_HTML_FALLBACK = 'custom_html_fallback';
	public const WARNING_DUPLICATE_DATA       = 'duplicate_data_attribute_normalized';
	public const WARNING_DUPLICATE_ANCHOR     = 'duplicate_anchor_normalized';
	public const WARNING_LEGACY_STACK         = 'legacy_section_stack_preserved';
	public const WARNING_OPAQUE_NESTED_STACK  = 'legacy_opaque_nested_sections_preserved';
	public const WARNING_NORMALIZED_SOURCE    = 'legacy_source_normalized';
	public const WARNING_NATIVE_CONVERSION    = 'legacy_content_converted_to_native_blocks';

	/**
	 * Legacy slide normalizer.
	 *
	 * @var Legacy_Slide_Normalizer
	 */
	private Legacy_Slide_Normalizer $normalizer;

	/**
	 * Legacy Slide attribute mapper.
	 *
	 * @var Legacy_Slide_Attribute_Mapper
	 */
	private Legacy_Slide_Attribute_Mapper $slide_attributes;

	/**
	 * Legacy Reveal stack compatibility validator.
	 *
	 * @var Legacy_Section_Validator
	 */
	private Legacy_Section_Validator $sections;

	/**
	 * Speaker notes representation policy.
	 *
	 * @var Speaker_Notes
	 */
	private Speaker_Notes $speaker_notes;

	/**
	 * Stable legacy theme resolver.
	 *
	 * @var Legacy_Theme_Resolver
	 */
	private Legacy_Theme_Resolver $themes;

	/**
	 * Create a planner.
	 *
	 * @param Legacy_Slide_Normalizer       $normalizer      Pure legacy value normalizer.
	 * @param Legacy_Slide_Attribute_Mapper $slide_attributes Legacy Slide attribute mapper.
	 * @param Legacy_Section_Validator      $sections        Legacy stack compatibility validator.
	 * @param Speaker_Notes                 $speaker_notes   Speaker notes representation policy.
	 * @param Legacy_Theme_Resolver         $themes          Stable legacy theme resolver.
	 */
	public function __construct( Legacy_Slide_Normalizer $normalizer, Legacy_Slide_Attribute_Mapper $slide_attributes, Legacy_Section_Validator $sections, Speaker_Notes $speaker_notes, Legacy_Theme_Resolver $themes ) {
		$this->normalizer       = $normalizer;
		$this->slide_attributes = $slide_attributes;
		$this->sections         = $sections;
		$this->speaker_notes    = $speaker_notes;
		$this->themes           = $themes;
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
		$blocking_normalizer_warnings  = 0;
		$snapshot_warnings             = count( $snapshot->warnings() );
		$fallback_count                = 0;
		$native_conversion_count       = 0;
		$duplicate_count               = 0;
		$duplicate_data_count          = 0;
		$used_anchors                  = array();
		$planned_slides                = array();
		$slide_reports                 = array();
		$normalizer_warnings_by_source = array();
		$normalizer_blockers_by_source = array();

		foreach ( $slides as $slide ) {
			$warning_count = count( $slide['warnings'] );
			if ( 0 === $warning_count ) {
				continue;
			}

			$normalizer_warnings                                   += $warning_count;
			$normalizer_warnings_by_source[ $slide['sourceIndex'] ] = $warning_count;
			$blocking_count = $this->normalizer->blocking_warning_count( $slide['warnings'] );
			if ( 0 < $blocking_count ) {
				$blocking_normalizer_warnings                          += $blocking_count;
				$normalizer_blockers_by_source[ $slide['sourceIndex'] ] = $blocking_count;
			}
		}

		if ( '' !== trim( $snapshot->post_content() ) ) {
			$blocker_codes[] = self::BLOCKER_EXISTING_CONTENT;
		}

		$theme_id = '' === trim( $snapshot->theme() )
			? ''
			: $this->themes->resolve_legacy_theme_id( $snapshot->theme() );
		if ( null === $theme_id ) {
			$blocker_codes[] = self::BLOCKER_LEGACY_THEME;
		}

		if ( 0 < $blocking_normalizer_warnings || 0 < $snapshot_warnings ) {
			$blocker_codes[] = self::BLOCKER_SOURCE_INVALID;
		}

		if ( 0 < $normalizer_warnings || 0 < $snapshot_warnings ) {
			$warning_codes[] = self::WARNING_NORMALIZED_SOURCE;
		}

		foreach ( $slides as $position => $slide ) {
			$class               = trim( $slide['class'] );
			$content             = $slide['content'];
			$notes               = $slide['notes']['notes'];
			$attribute_mapping   = $this->slide_attributes->map_with_diagnostics( $class, $slide['data'] );
			$mapped_attributes   = $attribute_mapping['attributes'] ?? null;
			$slide_blocker_codes = array();
			$slide_warning_codes = array();
			$notes_have_html     = $this->speaker_notes->contains_html( $notes );

			if ( null !== $attribute_mapping && 0 < $attribute_mapping['exactDuplicateCount'] ) {
				$duplicate_data_count += $attribute_mapping['exactDuplicateCount'];
				$warning_codes[]       = self::WARNING_DUPLICATE_DATA;
				$slide_warning_codes[] = self::WARNING_DUPLICATE_DATA;
			}

			if ( null === $mapped_attributes && null === $this->slide_attributes->map( $class, array() ) ) {
				$blocker_codes[]       = self::BLOCKER_SLIDE_WRAPPER_CLASS;
				$slide_blocker_codes[] = self::BLOCKER_SLIDE_WRAPPER_CLASS;
			}

			if ( null === $mapped_attributes && null === $this->slide_attributes->map( '', $slide['data'] ) ) {
				$blocker_codes[]       = self::BLOCKER_DATA_ATTRIBUTES;
				$slide_blocker_codes[] = self::BLOCKER_DATA_ATTRIBUTES;
			}

			if ( $notes_have_html && ! $this->speaker_notes->allows_lossless_html( $notes ) ) {
				$blocker_codes[]       = self::BLOCKER_HTML_NOTES;
				$slide_blocker_codes[] = self::BLOCKER_HTML_NOTES;
			}

			if ( preg_match( '/<\s*section\b/i', $content ) ) {
				$section_classification = $this->sections->classify( $content );
				if ( Legacy_Section_Validator::CANONICAL_STACK === $section_classification ) {
					$warning_codes[]       = self::WARNING_LEGACY_STACK;
					$slide_warning_codes[] = self::WARNING_LEGACY_STACK;
				} elseif ( Legacy_Section_Validator::OPAQUE_NESTED_STACK === $section_classification ) {
					$warning_codes[]       = self::WARNING_OPAQUE_NESTED_STACK;
					$slide_warning_codes[] = self::WARNING_OPAQUE_NESTED_STACK;
				} else {
					$blocker_codes[]       = self::BLOCKER_NESTED_SECTIONS;
					$slide_blocker_codes[] = self::BLOCKER_NESTED_SECTIONS;
				}
			}

			if ( isset( $normalizer_warnings_by_source[ $slide['sourceIndex'] ] ) ) {
				$slide_warning_codes[] = self::WARNING_NORMALIZED_SOURCE;
			}
			if ( isset( $normalizer_blockers_by_source[ $slide['sourceIndex'] ] ) ) {
				$slide_blocker_codes[] = self::BLOCKER_SOURCE_INVALID;
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

			$content_blocks = $this->convert_content_to_blocks( $content, $slide, $position );
			if ( '' !== trim( $content ) ) {
				if ( null === $content_blocks ) {
					++$fallback_count;
					$warning_codes[]       = self::WARNING_CUSTOM_HTML_FALLBACK;
					$slide_warning_codes[] = self::WARNING_CUSTOM_HTML_FALLBACK;
				} else {
					++$native_conversion_count;
					$warning_codes[]       = self::WARNING_NATIVE_CONVERSION;
					$slide_warning_codes[] = self::WARNING_NATIVE_CONVERSION;
				}
			}

			sort( $slide_blocker_codes, SORT_STRING );
			sort( $slide_warning_codes, SORT_STRING );
			$slide_reports[] = array(
				'position'     => $position + 1,
				'sourceIndex'  => $slide['sourceIndex'],
				'legacyNumber' => $slide['number'],
				'outcome'      => '' === trim( $content ) ? 'empty' : ( null === $content_blocks ? 'custom-html' : 'native-blocks' ),
				'blockerCodes' => array_values( array_unique( $slide_blocker_codes ) ),
				'warningCodes' => array_values( array_unique( $slide_warning_codes ) ),
			);

			$planned_slides[] = array_merge(
				is_array( $mapped_attributes ) ? $mapped_attributes : array(),
				array(
					'anchor'                => $anchor,
					'content'               => $content,
					'contentBlocks'         => $content_blocks,
					'label'                 => $slide['title'],
					'legacyAutoParagraph'   => null === $content_blocks,
					'legacyNotesProcessing' => true,
					'notes'                 => $notes,
					'notesFormat'           => $this->notes_format( $notes_have_html, $slide['notes']['markdown'] ),
				)
			);
		}

		$blocker_codes = array_values( array_unique( $blocker_codes ) );
		$warning_codes = array_values( array_unique( $warning_codes ) );
		sort( $blocker_codes, SORT_STRING );
		sort( $warning_codes, SORT_STRING );

		$report = array(
			'status'                         => array() === $blocker_codes ? Migration_Plan::STATUS_READY : Migration_Plan::STATUS_BLOCKED,
			'postId'                         => $snapshot->post_id(),
			'slideCount'                     => count( $slides ),
			'customHtmlFallbackCount'        => $fallback_count,
			'nativeContentConversionCount'   => $native_conversion_count,
			'duplicateAnchorCount'           => $duplicate_count,
			'duplicateDataAttributeCount'    => $duplicate_data_count,
			'normalizerWarningCount'         => $normalizer_warnings,
			'blockingNormalizerWarningCount' => $blocking_normalizer_warnings,
			'snapshotWarningCount'           => $snapshot_warnings,
			'blockerCodes'                   => $blocker_codes,
			'warningCodes'                   => $warning_codes,
			'slides'                         => $slide_reports,
		);

		if ( array() !== $blocker_codes ) {
			return Migration_Plan::blocked( $snapshot->fingerprint(), $report );
		}

		return Migration_Plan::ready(
			$snapshot->fingerprint(),
			$report,
			$this->serialize_deck( $planned_slides, $theme_id )
		);
	}

	/**
	 * Select the lossless notes representation for a legacy value.
	 *
	 * @param bool $has_html Whether the notes contain recognized HTML.
	 * @param bool $markdown Whether legacy Markdown processing was enabled.
	 * @return string Stable Slide notes format.
	 */
	private function notes_format( bool $has_html, bool $markdown ): string {
		if ( $has_html ) {
			return $markdown ? 'markdown-html' : 'html';
		}

		return $markdown ? 'markdown' : 'plain';
	}

	/**
	 * Serialize one Deck containing the planned Slides.
	 *
	 * @param array<int, array<string, mixed>> $slides   Planned slide values.
	 * @param string                           $theme_id Stable theme ID or empty for site default.
	 * @return string Serialized block markup.
	 */
	private function serialize_deck( array $slides, string $theme_id ): string {
		$slide_blocks = array();

		foreach ( $slides as $slide ) {
			$inner_blocks = is_array( $slide['contentBlocks'] ) ? $slide['contentBlocks'] : array();
			if ( array() === $inner_blocks && '' !== trim( $slide['content'] ) ) {
				$inner_blocks[] = array(
					'blockName'    => 'core/html',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => $slide['content'],
					'innerContent' => array( $slide['content'] ),
				);
			}

			$slide_attributes = $slide;
			unset( $slide_attributes['content'] );
			unset( $slide_attributes['contentBlocks'] );
			$slide_blocks[] = $this->container_block(
				'presenter/slide',
				$slide_attributes,
				$inner_blocks,
				false
			);
		}

		$deck_attributes = array(
			'aspectRatio' => 'custom',
			'height'      => 700,
			'width'       => 960,
		);
		if ( '' !== $theme_id ) {
			$deck_attributes['theme'] = $theme_id;
		}

		return serialize_block(
			$this->container_block(
				'presenter/deck',
				$deck_attributes,
				$slide_blocks
			)
		);
	}

	/**
	 * Let extensions replace one complete legacy slide with canonical blocks.
	 *
	 * A converter must claim the whole content value. Returning null leaves the
	 * existing lossless Custom HTML fallback untouched. The all-or-nothing
	 * contract prevents a detached script or target element from being dropped.
	 *
	 * @param string               $content  Complete legacy slide HTML.
	 * @param array<string, mixed> $slide    Normalized legacy slide record.
	 * @param int                  $position Zero-based normalized position.
	 * @return array<int, array<string, mixed>>|null Canonical parsed blocks.
	 */
	private function convert_content_to_blocks( string $content, array $slide, int $position ): ?array {
		if ( '' === trim( $content ) ) {
			return array();
		}

		/**
		 * Filters a complete legacy slide into parsed WordPress blocks.
		 *
		 * Return null when the content is not recognized. Converters must not
		 * return a partial result; Presenter removes the source HTML only after a
		 * converter returns a valid, round-trippable block list.
		 *
		 * @param array<int, array<string, mixed>>|null $blocks   Converted blocks.
		 * @param string                                $content  Complete slide HTML.
		 * @param array<string, mixed>                  $slide    Normalized slide.
		 * @param int                                   $position Zero-based position.
		 */
		$blocks = apply_filters( 'presenter_migration_slide_blocks', null, $content, $slide, $position );
		if ( ! is_array( $blocks ) || array() === $blocks ) {
			return null;
		}

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ! is_string( $block['blockName'] ?? null ) || '' === $block['blockName'] ) {
				return null;
			}
		}

		$serialized = serialize_blocks( $blocks );
		if ( '' === $serialized || serialize_blocks( parse_blocks( $serialized ) ) !== $serialized ) {
			return null;
		}

		return $blocks;
	}

	/**
	 * Build the parsed-block shape expected by serialize_block().
	 *
	 * @param string                   $name                  Block name.
	 * @param array<string, mixed>     $attributes            Block attributes.
	 * @param array<int, array<mixed>> $inner_blocks          Child blocks.
	 * @param bool                     $format_inner_blocks    Whether to add readable line breaks around children.
	 * @return array<string, mixed> Parsed block structure.
	 */
	private function container_block( string $name, array $attributes, array $inner_blocks, bool $format_inner_blocks = true ): array {
		$separator     = $format_inner_blocks ? "\n" : '';
		$inner_content = array( $separator );
		foreach ( $inner_blocks as $unused ) {
			$inner_content[] = null;
			$inner_content[] = $separator;
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
