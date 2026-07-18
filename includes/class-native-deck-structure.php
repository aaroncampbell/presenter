<?php
/**
 * Native Presenter Deck structure validation.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Confirms serialized blocks form the flat Deck hierarchy Presenter can render.
 */
final class Native_Deck_Structure {
	/**
	 * Confirm content contains exactly one non-empty Deck with only Slide children.
	 *
	 * Editor constraints improve authoring but are not a rendering boundary;
	 * manually edited markup must pass the same structural check.
	 *
	 * @param string $content Serialized WordPress block content.
	 * @return bool Whether the content contains one valid native Deck tree.
	 */
	public function is_valid( string $content ): bool {
		$root_blocks = array_values(
			array_filter(
				parse_blocks( $content ),
				static function ( array $block ): bool {
					return null !== $block['blockName'] || '' !== trim( $block['innerHTML'] );
				}
			)
		);

		if ( 1 !== count( $root_blocks ) || 'presenter/deck' !== ( $root_blocks[0]['blockName'] ?? null ) ) {
			return false;
		}

		foreach ( $root_blocks[0]['innerContent'] as $saved_fragment ) {
			if ( is_string( $saved_fragment ) && '' !== trim( $saved_fragment ) ) {
				return false;
			}
		}

		$slides = $root_blocks[0]['innerBlocks'];
		if ( array() === $slides ) {
			return false;
		}

		foreach ( $slides as $slide ) {
			if ( 'presenter/slide' !== ( $slide['blockName'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}
}
