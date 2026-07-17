<?php
/**
 * Immutable legacy deck snapshot.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Preserves the source values required to plan and verify a legacy migration.
 */
final class Legacy_Deck_Snapshot {
	/**
	 * Legacy slide values in source order.
	 *
	 * @var array<int, mixed>
	 */
	private array $raw_slides;

	/**
	 * Create a snapshot.
	 *
	 * @param int                $post_id      Slideshow post ID.
	 * @param string             $slug         Slideshow post slug.
	 * @param string             $title        Slideshow post title.
	 * @param string             $excerpt      Slideshow post excerpt.
	 * @param int                $menu_order   Slideshow menu order.
	 * @param string             $status       Slideshow post status.
	 * @param string             $password     Slideshow post password hash/value.
	 * @param string             $post_content Original post content.
	 * @param string             $theme        Legacy theme path.
	 * @param string             $short_url    Optional short URL.
	 * @param array<int, mixed>  $raw_slides   Raw legacy slides in source order.
	 * @param string             $fingerprint  Site-keyed source fingerprint.
	 * @param array<int, string> $warnings    Content-free capture warnings.
	 */
	public function __construct(
		private int $post_id,
		private string $slug,
		private string $title,
		private string $excerpt,
		private int $menu_order,
		private string $status,
		private string $password,
		private string $post_content,
		private string $theme,
		private string $short_url,
		array $raw_slides,
		private string $fingerprint,
		private array $warnings = array()
	) {
		$this->raw_slides = self::copy_values( array_values( $raw_slides ) );
	}

	/** Get the source post ID. */
	public function post_id(): int {
		return $this->post_id;
	}

	/** Get the source post type. */
	public function post_type(): string {
		return 'slideshow';
	}

	/** Get the source post slug. */
	public function slug(): string {
		return $this->slug;
	}

	/** Get the source post title. */
	public function title(): string {
		return $this->title;
	}

	/** Get the source post excerpt. */
	public function excerpt(): string {
		return $this->excerpt;
	}

	/** Get the source menu order. */
	public function menu_order(): int {
		return $this->menu_order;
	}

	/** Get the source post status. */
	public function status(): string {
		return $this->status;
	}

	/** Get the source post password. */
	public function password(): string {
		return $this->password;
	}

	/** Determine whether the source deck is password protected. */
	public function is_password_protected(): bool {
		return '' !== $this->password;
	}

	/** Get the source post content. */
	public function post_content(): string {
		return $this->post_content;
	}

	/** Get the source legacy theme path. */
	public function theme(): string {
		return $this->theme;
	}

	/** Get the source short URL. */
	public function short_url(): string {
		return $this->short_url;
	}

	/**
	 * Get an isolated copy of the raw slides in source order.
	 *
	 * @return array<int, mixed> Raw legacy values.
	 */
	public function raw_slides(): array {
		return self::copy_values( $this->raw_slides );
	}

	/** Get the number of source slide records. */
	public function slide_count(): int {
		return count( $this->raw_slides );
	}

	/** Get the site-keyed source fingerprint. */
	public function fingerprint(): string {
		return $this->fingerprint;
	}

	/**
	 * Get content-free warnings found while capturing deck-level values.
	 *
	 * @return array<int, string> Warning codes.
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Recursively copy legacy values so returned objects cannot mutate state.
	 *
	 * Presenter 1.x records are stdClass values, but the recursion also retains
	 * arrays and scalar values from older or externally imported records.
	 *
	 * @param array<int, mixed> $values Values to copy.
	 * @return array<int, mixed> Isolated values.
	 */
	private static function copy_values( array $values ): array {
		foreach ( $values as $index => $value ) {
			if ( is_array( $value ) ) {
				$values[ $index ] = self::copy_array( $value );
			} elseif ( is_object( $value ) ) {
				$copy = clone $value;
				foreach ( get_object_vars( $copy ) as $property => $property_value ) {
					$copy->{$property} = self::copy_value( $property_value );
				}
				$values[ $index ] = $copy;
			}
		}

		return $values;
	}

	/**
	 * Copy an associative or indexed nested array without changing its keys.
	 *
	 * @param array<array-key, mixed> $value Value to copy.
	 * @return array<array-key, mixed> Isolated value.
	 */
	private static function copy_array( array $value ): array {
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::copy_value( $item );
		}

		return $value;
	}

	/**
	 * Copy one nested legacy value.
	 *
	 * @param mixed $value Value to copy.
	 * @return mixed Isolated value.
	 */
	private static function copy_value( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return self::copy_array( $value );
		}

		if ( is_object( $value ) ) {
			return self::copy_values( array( $value ) )[0];
		}

		return $value;
	}
}
