<?php
/**
 * Legacy Slide advanced-attribute mapping.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Maps Presenter 1.x wrapper classes and data suffixes to native Slide attrs.
 */
final class Legacy_Slide_Attribute_Mapper {
	/**
	 * Create the mapper.
	 *
	 * @param Slide_Attribute_Validator $validator Shared native attribute validator.
	 */
	public function __construct( private Slide_Attribute_Validator $validator ) {}

	/**
	 * Map one normalized legacy Slide without rewriting ambiguous values.
	 *
	 * @param string                                         $classes     Legacy wrapper class list.
	 * @param array<int, array{name: string, value: string}> $legacy_data Legacy data-name suffixes and values.
	 * @return array<string, mixed>|null Native Slide attrs, or null when unsafe.
	 */
	public function map( string $classes, array $legacy_data ): ?array {
		$validated_classes = $this->validator->classes( $classes );
		if ( null === $validated_classes ) {
			return null;
		}

		$attributes = array();
		if ( '' !== $validated_classes ) {
			$attributes['className'] = $validated_classes;
		}

		$generic = array();
		$seen    = array();
		foreach ( $legacy_data as $item ) {
			$name = 'data-' . $item['name'];
			if ( isset( $seen[ $name ] ) ) {
				return null;
			}
			$seen[ $name ] = true;

			$typed = $this->typed_attribute( $name, $item['value'] );
			if ( false === $typed ) {
				return null;
			}
			if ( is_array( $typed ) ) {
				$attributes = array_merge( $attributes, $typed );
				continue;
			}

			$generic[] = array(
				'name'  => $name,
				'value' => $item['value'],
			);
		}

		if ( null === $this->validator->reveal_data( $generic ) ) {
			return null;
		}
		if ( array() !== $generic ) {
			$attributes['revealDataAttributes'] = $generic;
		}

		return $attributes;
	}

	/**
	 * Map a typed Reveal data attribute when its value is exactly representable.
	 *
	 * @param string $name  Rendered data attribute name.
	 * @param string $value Legacy value.
	 * @return array<string, mixed>|false|null Attrs, false when invalid, or null when generic.
	 */
	private function typed_attribute( string $name, string $value ): array|false|null {
		$transitions = array( 'none', 'fade', 'slide', 'convex', 'concave', 'zoom' );

		return match ( $name ) {
			'data-transition'            => ( in_array( $value, $transitions, true ) ? array( 'transition' => $value ) : false ),
			'data-background-transition' => ( in_array( $value, $transitions, true ) ? array( 'backgroundTransition' => $value ) : false ),
			'data-background-color'      => ( 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? array( 'backgroundColor' => $value ) : false ),
			'data-background-image'      => ( $this->validator->resource_url( $value ) ? array( 'backgroundImageUrl' => $value ) : false ),
			'data-background-size'       => ( $this->validator->background_size( $value ) ? array( 'backgroundSize' => $value ) : false ),
			'data-background-position'   => ( in_array( $value, array( 'center', 'top', 'top right', 'right', 'bottom right', 'bottom', 'bottom left', 'left', 'top left' ), true ) ? array( 'backgroundPosition' => $value ) : false ),
			'data-background-repeat'     => ( in_array( $value, array( 'no-repeat', 'repeat', 'repeat-x', 'repeat-y' ), true ) ? array( 'backgroundRepeat' => $value ) : false ),
			'data-background-opacity'    => $this->opacity( $value ),
			'data-auto-animate'          => ( '' === $value ? array( 'autoAnimate' => true ) : false ),
			'data-auto-animate-id'       => ( 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $value ) ? array(
				'autoAnimate'   => true,
				'autoAnimateId' => $value,
			) : false ),
			'data-auto-animate-restart'  => ( '' === $value ? array(
				'autoAnimate'        => true,
				'autoAnimateRestart' => true,
			) : false ),
			'data-visibility'            => ( 'hidden' === $value ? array( 'hidden' => true ) : false ),
			default                      => null,
		};
	}

	/**
	 * Map an exact Reveal opacity value.
	 *
	 * @param string $value Legacy opacity.
	 * @return array{backgroundOpacity: float}|false
	 */
	private function opacity( string $value ): array|false {
		if ( 1 !== preg_match( '/^(?:0(?:\.\d+)?|1(?:\.0+)?)$/', $value ) ) {
			return false;
		}

		$opacity = (float) $value;

		return $opacity >= 0 && $opacity <= 1 ? array( 'backgroundOpacity' => $opacity ) : false;
	}
}
