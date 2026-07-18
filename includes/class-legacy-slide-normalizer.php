<?php
/**
 * Legacy slide normalization service.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Projects heterogeneous Presenter 1.x values into a deterministic read model.
 */
final class Legacy_Slide_Normalizer {
	public const WARNING_EMPTY_BACKGROUND = 'legacy_empty_background_ignored';
	public const WARNING_FALSE_RECORD     = 'legacy_false_slide_preserved';

	/**
	 * Characterized source shapes with an exact compatibility representation.
	 *
	 * @var array<int, string>
	 */
	private const REPRESENTED_WARNINGS = array(
		self::WARNING_EMPTY_BACKGROUND,
		self::WARNING_FALSE_RECORD,
	);

	/**
	 * Normalize slides without modifying their source values.
	 *
	 * @param array<int, mixed> $raw_slides Raw slides in metadata source order.
	 * @return array<int, array{
	 *         sourceIndex: int,
	 *         number: int,
	 *         title: string,
	 *         content: string,
	 *         class: string,
	 *         data: array<int, array{name: string, value: string}>,
	 *         notes: array{notes: string, markdown: bool},
	 *         warnings: array<int, string>
	 *     }>
	 */
	public function normalize( array $raw_slides ): array {
		$slides = array();

		foreach ( array_values( $raw_slides ) as $source_index => $raw_slide ) {
			$warnings = array();
			if ( false === $raw_slide ) {
				$this->warn( $warnings, self::WARNING_FALSE_RECORD );
				$record = array( 'number' => $source_index + 1 );
			} else {
				$record = $this->record( $raw_slide );
			}
			if ( null === $record ) {
				$this->warn( $warnings, 'invalid_slide_record' );
				$record = array();
			}
			if ( array_key_exists( 'background', $record ) && '' === $record['background'] ) {
				$this->warn( $warnings, self::WARNING_EMPTY_BACKGROUND );
				unset( $record['background'] );
			}
			$this->warn_for_unknown_fields(
				$record,
				array( 'number', 'title', 'class', 'content', 'data', 'notes' ),
				'unknown_slide_fields',
				$warnings
			);

			$slides[] = array(
				'sourceIndex' => $source_index,
				'number'      => $this->number( $record['number'] ?? null, $source_index, $warnings ),
				'title'       => $this->string_field( $record, 'title', $warnings ),
				'class'       => $this->string_field( $record, 'class', $warnings ),
				'content'     => $this->string_field( $record, 'content', $warnings ),
				'data'        => $this->data( $record['data'] ?? array(), $warnings ),
				'notes'       => $this->notes( $record['notes'] ?? array(), $warnings ),
				'warnings'    => $warnings,
			);
		}

		usort(
			$slides,
			static function ( array $left, array $right ): int {
				return array( $left['number'], $left['sourceIndex'] ) <=> array( $right['number'], $right['sourceIndex'] );
			}
		);

		return $slides;
	}

	/**
	 * Count warnings whose source values do not have an exact representation.
	 *
	 * @param array<int, string> $warnings Normalizer warning codes.
	 * @return int Blocking warning count.
	 */
	public function blocking_warning_count( array $warnings ): int {
		return count( array_diff( $warnings, self::REPRESENTED_WARNINGS ) );
	}

	/**
	 * Convert an object or array record to a local array projection.
	 *
	 * @param mixed $value Legacy record value.
	 * @return array<string, mixed>|null Record projection or null.
	 */
	private function record( mixed $value ): ?array {
		if ( is_object( $value ) ) {
			return get_object_vars( $value );
		}

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Normalize the legacy numeric position.
	 *
	 * @param mixed              $value        Legacy number value.
	 * @param int                $source_index Zero-based source position.
	 * @param array<int, string> $warnings Warnings.
	 * @return int Positive slide number.
	 */
	private function number( mixed $value, int $source_index, array &$warnings ): int {
		if ( null === $value || '' === $value ) {
			$this->warn( $warnings, 'missing_slide_number' );

			return $source_index + 1;
		}

		if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value > 0 ) {
			return (int) $value;
		}

		$this->warn( $warnings, 'invalid_slide_number' );

		return $source_index + 1;
	}

	/**
	 * Normalize an optional string field.
	 *
	 * @param array<string, mixed> $record   Slide record.
	 * @param string               $field    Field name.
	 * @param array<int, string>   $warnings Warnings.
	 * @return string Normalized string.
	 */
	private function string_field( array $record, string $field, array &$warnings ): string {
		if ( ! array_key_exists( $field, $record ) ) {
			return '';
		}

		if ( is_string( $record[ $field ] ) ) {
			return $record[ $field ];
		}

		$this->warn( $warnings, 'invalid_slide_' . $field );

		return '';
	}

	/**
	 * Normalize ordered legacy data attributes.
	 *
	 * @param mixed              $value    Legacy data value.
	 * @param array<int, string> $warnings Warnings.
	 * @return array<int, array{name: string, value: string}>
	 */
	private function data( mixed $value, array &$warnings ): array {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			$this->warn( $warnings, 'invalid_slide_data' );

			return array();
		}

		$data = array();
		foreach ( array_values( $value ) as $item ) {
			$record = $this->record( $item );
			if ( null === $record ) {
				$this->warn( $warnings, 'invalid_slide_data_item' );
				continue;
			}
			$this->warn_for_unknown_fields( $record, array( 'name', 'value' ), 'unknown_slide_data_fields', $warnings );

			$name  = $this->scalar_string( $record['name'] ?? null );
			$value = $this->scalar_string( $record['value'] ?? null );
			if ( null === $name ) {
				$this->warn( $warnings, 'invalid_slide_data_name' );
				$name = '';
			}
			if ( null === $value ) {
				$this->warn( $warnings, 'invalid_slide_data_value' );
				$value = '';
			}

			$data[] = array(
				'name'  => $name,
				'value' => $value,
			);
		}

		return $data;
	}

	/**
	 * Normalize an optional legacy notes record.
	 *
	 * @param mixed              $value    Legacy notes value.
	 * @param array<int, string> $warnings Warnings.
	 * @return array{notes: string, markdown: bool}
	 */
	private function notes( mixed $value, array &$warnings ): array {
		if ( null === $value || array() === $value ) {
			return array(
				'notes'    => '',
				'markdown' => false,
			);
		}

		$record = $this->record( $value );
		if ( null === $record ) {
			$this->warn( $warnings, 'invalid_slide_notes' );

			return array(
				'notes'    => '',
				'markdown' => false,
			);
		}
		$this->warn_for_unknown_fields( $record, array( 'notes', 'markdown' ), 'unknown_slide_notes_fields', $warnings );

		$content = $record['notes'] ?? '';
		if ( ! is_string( $content ) ) {
			$this->warn( $warnings, 'invalid_slide_notes_content' );
			$content = '';
		}

		return array(
			'notes'    => $content,
			'markdown' => ! empty( $record['markdown'] ),
		);
	}

	/**
	 * Convert a legacy scalar to its non-lossy string projection.
	 *
	 * @param mixed $value Legacy value.
	 * @return string|null String projection or null.
	 */
	private function scalar_string( mixed $value ): ?string {
		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * Report source fields that the canonical projection does not understand.
	 *
	 * Unknown values remain protected by the snapshot fingerprint, while this
	 * warning prevents a planner from treating their omission as lossless.
	 *
	 * @param array<string, mixed> $record   Source record.
	 * @param array<int, string>   $allowed  Recognized field names.
	 * @param string               $code     Content-free warning code.
	 * @param array<int, string>   $warnings Warnings.
	 */
	private function warn_for_unknown_fields( array $record, array $allowed, string $code, array &$warnings ): void {
		if ( array() !== array_diff( array_keys( $record ), $allowed ) ) {
			$this->warn( $warnings, $code );
		}
	}

	/**
	 * Add a content-free warning.
	 *
	 * @param array<int, string> $warnings Warnings.
	 * @param string             $code     Stable warning code.
	 */
	private function warn( array &$warnings, string $code ): void {
		$warnings[] = $code;
	}
}
