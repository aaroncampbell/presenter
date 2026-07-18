<?php
/**
 * Legacy nested-section compatibility validation.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Recognizes canonical Reveal vertical-stack fragments without rewriting them.
 */
final class Legacy_Section_Validator {
	public const CANONICAL_STACK     = 'canonical-stack';
	public const OPAQUE_NESTED_STACK = 'opaque-nested-stack';

	/**
	 * Determine whether content consists only of top-level section elements.
	 *
	 * Inner markup, nested sections, attributes, and whitespace are retained
	 * verbatim in the Custom HTML fallback. Mixed top-level content and parser
	 * errors remain blocked because wrapping them would change Reveal structure.
	 *
	 * @param string $content Legacy Slide HTML.
	 * @return bool Whether the content is a canonical Reveal stack fragment.
	 */
	public function is_canonical_stack( string $content ): bool {
		return self::CANONICAL_STACK === $this->classify( $content );
	}

	/**
	 * Classify an exactly preservable legacy section fragment.
	 *
	 * Canonical stacks contain one or more top-level sections without section
	 * descendants. Opaque nested stacks contain exactly one top-level section
	 * whose direct content consists only of child sections, with no deeper
	 * sections. Both retain their exact source bytes in Custom HTML.
	 *
	 * @param string $content Legacy Slide HTML.
	 * @return string|null Stable classification, or null when unsupported.
	 */
	public function classify( string $content ): ?string {
		if ( ! $this->has_balanced_section_markup( $content ) ) {
			return null;
		}

		$processor = \WP_HTML_Processor::create_fragment( $content );
		if ( ! $processor instanceof \WP_HTML_Processor ) {
			return null;
		}

		$child_sections           = 0;
		$outer_has_direct_content = false;
		$top_level_sections       = 0;
		while ( $processor->next_token() ) {
			$breadcrumbs   = $processor->get_breadcrumbs();
			$section_depth = count( array_filter( array_slice( $breadcrumbs, 2 ), static fn ( string $tag ): bool => 'SECTION' === $tag ) );
			$token_type    = $processor->get_token_type();

			if ( '#tag' === $token_type ) {
				$tag = $processor->get_tag();
				if ( 'SECTION' === $tag && ! $processor->is_tag_closer() ) {
					if ( 1 === $section_depth ) {
						++$top_level_sections;
					} elseif ( 2 === $section_depth ) {
						++$child_sections;
					} else {
						return null;
					}
				}

				if ( 0 === $section_depth && ! ( 'SECTION' === $tag && $processor->is_tag_closer() ) ) {
					return null;
				}
				if ( 1 === $section_depth && 'SECTION' !== $tag ) {
					$outer_has_direct_content = true;
				}
				continue;
			}

			if ( '#comment' === $token_type ) {
				continue;
			}
			if ( '#text' === $token_type ) {
				if ( 0 === $section_depth && '' !== trim( $processor->get_modifiable_text() ) ) {
					return null;
				}
				if ( 1 === $section_depth && '' !== trim( $processor->get_modifiable_text() ) ) {
					$outer_has_direct_content = true;
				}
				continue;
			}
			if ( 0 === $section_depth ) {
				return null;
			}
		}

		if ( null !== $processor->get_last_error() || 0 === $top_level_sections ) {
			return null;
		}
		if ( 0 === $child_sections ) {
			return self::CANONICAL_STACK;
		}

		return 1 === $top_level_sections && ! $outer_has_direct_content
			? self::OPAQUE_NESTED_STACK
			: null;
	}

	/**
	 * Require explicit balanced section tags in the authored source.
	 *
	 * @param string $content Legacy Slide HTML.
	 * @return bool Whether section openers and closers are balanced.
	 */
	private function has_balanced_section_markup( string $content ): bool {
		$processor = new \WP_HTML_Tag_Processor( $content );
		$depth     = 0;
		$found     = false;
		while (
			$processor->next_tag(
				array(
					'tag_name'    => 'SECTION',
					'tag_closers' => 'visit',
				)
			)
		) {
			$found  = true;
			$depth += $processor->is_tag_closer() ? -1 : 1;
			if ( $depth < 0 ) {
				return false;
			}
		}

		return $found && 0 === $depth;
	}
}
