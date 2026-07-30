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
		return null !== $this->deck_block( $content );
	}

	/**
	 * Return the one validated, meaningful native Deck root.
	 *
	 * WordPress represents leading or trailing freeform whitespace as synthetic
	 * null blocks. Keeping that normalization here ensures routing and theme
	 * resolution interpret the same serialized structure.
	 *
	 * @param string $content Serialized WordPress block content.
	 * @return array<string, mixed>|null Valid Deck block, or null.
	 */
	public function deck_block( string $content ): ?array {
		$root_blocks = array_values(
			array_filter(
				parse_blocks( $content ),
				static function ( array $block ): bool {
					return null !== $block['blockName'] || '' !== trim( $block['innerHTML'] );
				}
			)
		);

		if ( 1 !== count( $root_blocks ) || 'presenter/deck' !== ( $root_blocks[0]['blockName'] ?? null ) ) {
			return null;
		}

		foreach ( $root_blocks[0]['innerContent'] as $saved_fragment ) {
			if ( is_string( $saved_fragment ) && '' !== trim( $saved_fragment ) ) {
				return null;
			}
		}

		$slides = $root_blocks[0]['innerBlocks'];
		if ( array() === $slides ) {
			return null;
		}

		foreach ( $slides as $slide ) {
			if ( 'presenter/slide' !== ( $slide['blockName'] ?? null ) ) {
				return null;
			}
		}

		return $root_blocks[0];
	}
}
