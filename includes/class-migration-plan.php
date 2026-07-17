<?php
/**
 * Immutable legacy-deck migration plan.
 *
 * @package Presenter
 */

namespace Presenter;

use InvalidArgumentException;

/**
 * Carries a content-free migration report and, only when safe, generated blocks.
 */
final class Migration_Plan {
	public const STATUS_BLOCKED = 'blocked';
	public const STATUS_READY   = 'ready';

	/**
	 * Plan status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Fingerprint of the immutable migration source.
	 *
	 * @var string
	 */
	private string $source_fingerprint;

	/**
	 * Serialized blocks, available only for a ready plan.
	 *
	 * @var string|null
	 */
	private ?string $generated_content;

	/**
	 * Content-free migration report.
	 *
	 * @var array<string, mixed>
	 */
	private array $report;

	/**
	 * Create a migration plan.
	 *
	 * @param string               $status             Plan status.
	 * @param string               $source_fingerprint Source fingerprint.
	 * @param array<string, mixed> $report             Content-free report.
	 * @param string|null          $generated_content  Generated block markup.
	 * @throws InvalidArgumentException When status and content invariants conflict.
	 */
	private function __construct(
		string $status,
		string $source_fingerprint,
		array $report,
		?string $generated_content
	) {
		if ( ! in_array( $status, array( self::STATUS_BLOCKED, self::STATUS_READY ), true ) ) {
			throw new InvalidArgumentException( 'Unknown migration plan status.' );
		}

		if ( self::STATUS_READY === $status && null === $generated_content ) {
			throw new InvalidArgumentException( 'A ready migration plan requires generated content.' );
		}

		if ( self::STATUS_BLOCKED === $status && null !== $generated_content ) {
			throw new InvalidArgumentException( 'A blocked migration plan cannot expose generated content.' );
		}

		$this->status             = $status;
		$this->source_fingerprint = $source_fingerprint;
		$this->report             = $report;
		$this->generated_content  = $generated_content;
	}

	/**
	 * Create a ready plan.
	 *
	 * @param string               $source_fingerprint Source fingerprint.
	 * @param array<string, mixed> $report             Content-free report.
	 * @param string               $generated_content  Generated block markup.
	 * @return self Ready plan.
	 */
	public static function ready( string $source_fingerprint, array $report, string $generated_content ): self {
		return new self( self::STATUS_READY, $source_fingerprint, $report, $generated_content );
	}

	/**
	 * Create a blocked plan.
	 *
	 * @param string               $source_fingerprint Source fingerprint.
	 * @param array<string, mixed> $report             Content-free report.
	 * @return self Blocked plan.
	 */
	public static function blocked( string $source_fingerprint, array $report ): self {
		return new self( self::STATUS_BLOCKED, $source_fingerprint, $report, null );
	}

	/**
	 * Read the status.
	 *
	 * @return string Plan status.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Determine whether the plan can be applied losslessly.
	 *
	 * @return bool Whether the plan is ready.
	 */
	public function is_ready(): bool {
		return self::STATUS_READY === $this->status;
	}

	/**
	 * Read generated block markup.
	 *
	 * Blocked plans deliberately return null so callers cannot accidentally
	 * apply a partial conversion.
	 *
	 * @return string|null Generated content for a ready plan.
	 */
	public function generated_content(): ?string {
		return $this->generated_content;
	}

	/**
	 * Read the immutable source fingerprint.
	 *
	 * @return string Source fingerprint.
	 */
	public function source_fingerprint(): string {
		return $this->source_fingerprint;
	}

	/**
	 * Read the content-free report.
	 *
	 * @return array<string, mixed> Migration report.
	 */
	public function report(): array {
		return $this->report;
	}
}
