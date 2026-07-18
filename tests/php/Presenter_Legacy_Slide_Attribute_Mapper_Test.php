<?php
/**
 * Legacy Slide advanced-attribute mapper tests.
 *
 * @package Presenter
 */

use Presenter\Legacy_Slide_Attribute_Mapper;
use Presenter\Slide_Attribute_Validator;

require_once dirname( __DIR__, 2 ) . '/includes/class-slide-attribute-validator.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-legacy-slide-attribute-mapper.php';

/**
 * Verify exact, safe mapping from Presenter 1.x Slide metadata.
 */
final class Presenter_Legacy_Slide_Attribute_Mapper_Test extends Presenter_Test_Case {
	/** Wrapper classes and generic data suffixes retain their authored order. */
	public function test_maps_classes_and_generic_data_suffixes(): void {
		$mapped = $this->mapper()->map(
			'layout-wide chart-slide',
			array(
				array(
					'name'  => 'chart',
					'value' => 'reputation',
				),
				array(
					'name'  => 'chart-color',
					'value' => '#663399',
				),
			)
		);

		$this->assertSame(
			array(
				'className'            => 'layout-wide chart-slide',
				'revealDataAttributes' => array(
					array(
						'name'  => 'data-chart',
						'value' => 'reputation',
					),
					array(
						'name'  => 'data-chart-color',
						'value' => '#663399',
					),
				),
			),
			$mapped
		);
	}

	/** Supported Reveal settings map to canonical typed Slide attributes. */
	public function test_maps_exact_typed_reveal_values(): void {
		$mapped = $this->mapper()->map(
			'',
			array(
				array(
					'name'  => 'transition',
					'value' => 'fade',
				),
				array(
					'name'  => 'background-color',
					'value' => '#663399',
				),
				array(
					'name'  => 'background-opacity',
					'value' => '0.5',
				),
				array(
					'name'  => 'background-image',
					'value' => '/wp-content/uploads/presentation/slide.jpg',
				),
				array(
					'name'  => 'background-size',
					'value' => 'auto 95%',
				),
				array(
					'name'  => 'visibility',
					'value' => 'hidden',
				),
			)
		);

		$this->assertSame(
			array(
				'transition'         => 'fade',
				'backgroundColor'    => '#663399',
				'backgroundOpacity'  => 0.5,
				'backgroundImageUrl' => '/wp-content/uploads/presentation/slide.jpg',
				'backgroundSize'     => 'auto 95%',
				'hidden'             => true,
			),
			$mapped
		);
	}

	/** Exact duplicates collapse while first-occurrence order is retained. */
	public function test_collapses_exact_duplicate_values_with_diagnostics(): void {
		$mapping = $this->mapper()->map_with_diagnostics(
			'',
			array(
				array(
					'name'  => 'state',
					'value' => 'visible-state',
				),
				array(
					'name'  => 'background-color',
					'value' => '#663399',
				),
				array(
					'name'  => 'state',
					'value' => 'visible-state',
				),
				array(
					'name'  => 'background-color',
					'value' => '#663399',
				),
			)
		);

		$this->assertNotNull( $mapping );
		$this->assertSame( 2, $mapping['exactDuplicateCount'] );
		$this->assertSame( '#663399', $mapping['attributes']['backgroundColor'] );
		$this->assertSame(
			array(
				array(
					'name'  => 'data-state',
					'value' => 'visible-state',
				),
			),
			$mapping['attributes']['revealDataAttributes']
		);
	}

	/** Legacy data-background shorthand retains its exact generic name. */
	public function test_preserves_legacy_background_shorthand(): void {
		$mapped = $this->mapper()->map(
			'',
			array(
				array(
					'name'  => 'background',
					'value' => 'https://example.test/background.jpg',
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'name'  => 'data-background',
					'value' => 'https://example.test/background.jpg',
				),
			),
			$mapped['revealDataAttributes']
		);
	}

	/** Invalid or duplicate legacy values are rejected without partial output. */
	public function test_rejects_ambiguous_values_atomically(): void {
		$this->assertNull( $this->mapper()->map( 'duplicate duplicate', array() ) );
		$this->assertNull(
			$this->mapper()->map(
				'',
				array(
					array(
						'name'  => 'chart',
						'value' => 'first',
					),
					array(
						'name'  => 'chart',
						'value' => 'second',
					),
				)
			)
		);
		$this->assertNull(
			$this->mapper()->map(
				'',
				array(
					array(
						'name'  => 'transition',
						'value' => 'fade',
					),
					array(
						'name'  => 'transition',
						'value' => 'zoom',
					),
				)
			)
		);
		$this->assertNull(
			$this->mapper()->map(
				'',
				array(
					array(
						'name'  => 'transition',
						'value' => 'spin',
					),
				)
			)
		);
	}

	/** Unsafe remote-resource protocols remain blocked. */
	public function test_rejects_unsafe_resource_urls(): void {
		$this->assertNull(
			$this->mapper()->map(
				'',
				array(
					array(
						'name'  => 'background-video',
						'value' => 'javascript:alert(1)',
					),
				)
			)
		);
	}

	/** Build the shared production mapper contract. */
	private function mapper(): Legacy_Slide_Attribute_Mapper {
		return new Legacy_Slide_Attribute_Mapper( new Slide_Attribute_Validator() );
	}
}
