<?php
/**
 * Native Presenter Deck structure validation.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Confirms serialized blocks form the bounded Deck hierarchy Presenter can render.
 */
final class Native_Deck_Structure {
	/**
	 * Confirm content contains exactly one non-empty Deck with Slides or Stacks.
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

		$items = $root_blocks[0]['innerBlocks'];
		if ( array() === $items ) {
			return null;
		}

		foreach ( $items as $item ) {
			$name = $item['blockName'] ?? null;
			if ( 'presenter/slide' === $name ) {
				continue;
			}

			if ( 'presenter/stack' !== $name || ! $this->is_valid_stack( $item ) ) {
				return null;
			}
		}

		return $root_blocks[0];
	}

	/**
	 * Confirm a Stack has no saved wrapper and contains one or more direct Slides.
	 *
	 * Singleton Stacks are accepted defensively; editor transforms normally
	 * unwrap them. Empty, recursively nested, and freeform structures fail closed.
	 *
	 * @param array<string, mixed> $stack Parsed Stack block.
	 * @return bool Whether the Stack is structurally valid.
	 */
	private function is_valid_stack( array $stack ): bool {
		foreach ( $stack['innerContent'] ?? array() as $saved_fragment ) {
			if ( is_string( $saved_fragment ) && '' !== trim( $saved_fragment ) ) {
				return false;
			}
		}

		$slides = $stack['innerBlocks'] ?? array();
		if ( ! is_array( $slides ) || array() === $slides ) {
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
