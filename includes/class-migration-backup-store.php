<?php
/**
 * Immutable Presenter migration backup storage.
 *
 * @package Presenter
 */

namespace Presenter;

use WP_Post;

/**
 * Persists checksummed, append-only backup envelopes in private post metadata.
 */
final class Migration_Backup_Store {
	/** Backup envelope schema version. */
	private const SCHEMA_VERSION = 1;

	/** Append-only private backup metadata key. */
	public const META_KEY = '_presenter_migration_backup_v1';

	/**
	 * Create the backup store.
	 *
	 * @param Migration_Hasher $hasher Site-keyed migration hasher.
	 */
	public function __construct( private Migration_Hasher $hasher ) {}

	/**
	 * Create and immediately verify one immutable backup envelope.
	 *
	 * The returned summary deliberately omits the payload and integrity hash.
	 *
	 * @param int                  $post_id Slideshow post ID.
	 * @param array<string, mixed> $payload Complete backup payload.
	 * @return array{backupId: string, schemaVersion: int, createdAt: string}|null Content-free reference, or null on failure.
	 */
	public function create( int $post_id, array $payload ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'slideshow' !== $post->post_type || array() === $payload ) {
			return null;
		}

		$envelope = array(
			'schemaVersion' => self::SCHEMA_VERSION,
			'backupId'      => wp_generate_uuid4(),
			'createdAt'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'payload'       => $payload,
		);

		$envelope['envelopeHash'] = $this->hasher->hash( 'backup-envelope', $envelope );

		if ( false === add_post_meta( $post_id, self::META_KEY, $envelope, false ) ) {
			return null;
		}

		if ( ! $this->verify( $post_id, $envelope['backupId'] ) ) {
			return null;
		}

		return array(
			'backupId'      => $envelope['backupId'],
			'schemaVersion' => self::SCHEMA_VERSION,
			'createdAt'     => $envelope['createdAt'],
		);
	}

	/**
	 * Verify that exactly one matching immutable envelope remains intact.
	 *
	 * @param int    $post_id  Slideshow post ID.
	 * @param string $backup_id Backup UUID.
	 * @return bool Whether one intact envelope exists.
	 */
	public function verify( int $post_id, string $backup_id ): bool {
		return null !== $this->verified_envelope( $post_id, $backup_id );
	}

	/**
	 * Read an isolated payload from one completely verified backup envelope.
	 *
	 * This trusted recovery API is strictly internal. Callers must never expose
	 * its authored values through status, CLI output, logs, or exceptions.
	 *
	 * @internal Migration recovery orchestration only.
	 *
	 * @param int    $post_id  Slideshow post ID.
	 * @param string $backup_id Exact backup UUID.
	 * @return array<string, mixed>|null Isolated verified payload, or null.
	 */
	public function read_verified_payload( int $post_id, string $backup_id ): ?array {
		$envelope = $this->verified_envelope( $post_id, $backup_id );

		return null === $envelope ? null : $this->copy_array( $envelope['payload'] );
	}

	/**
	 * Read and verify exactly one complete backup envelope.
	 *
	 * @param int    $post_id  Slideshow post ID.
	 * @param string $backup_id Exact backup UUID.
	 * @return array<string, mixed>|null Verified envelope, or null.
	 */
	private function verified_envelope( int $post_id, string $backup_id ): ?array {
		if ( $post_id < 1 || ! wp_is_uuid( $backup_id, 4 ) ) {
			return null;
		}

		$matches = array_values(
			array_filter(
				get_post_meta( $post_id, self::META_KEY, false ),
				static fn( mixed $record ): bool => is_array( $record )
					&& ( $record['backupId'] ?? null ) === $backup_id
			)
		);

		if ( 1 !== count( $matches ) ) {
			return null;
		}

		$envelope = $matches[0];
		if ( ! $this->has_valid_shape( $envelope ) ) {
			return null;
		}

		$stored_hash = $envelope['envelopeHash'];
		$unsigned    = $envelope;
		unset( $unsigned['envelopeHash'] );

		return hash_equals( $stored_hash, $this->hasher->hash( 'backup-envelope', $unsigned ) )
			? $envelope
			: null;
	}

	/**
	 * Find one intact backup created for a deterministic preparation reference.
	 *
	 * The returned summary omits both the reference and the stored payload. More
	 * than one matching envelope is ambiguous and fails closed.
	 *
	 * @param int    $post_id              Slideshow post ID.
	 * @param string $preparation_reference Site-keyed preparation reference.
	 * @return array{backupId: string, schemaVersion: int, createdAt: string}|null Content-free reference.
	 */
	public function find_verified_reference( int $post_id, string $preparation_reference ): ?array {
		if ( $post_id < 1 || 1 !== preg_match( '/^[a-f0-9]{64}$/', $preparation_reference ) ) {
			return null;
		}

		$matches = array();
		foreach ( get_post_meta( $post_id, self::META_KEY, false ) as $record ) {
			if (
				! is_array( $record ) ||
				! isset( $record['backupId'], $record['payload'] ) ||
				! is_string( $record['backupId'] ) ||
				! is_array( $record['payload'] ) ||
				( $record['payload']['backupReference'] ?? $record['payload']['preparationReference'] ?? null ) !== $preparation_reference ||
				! $this->verify( $post_id, $record['backupId'] )
			) {
				continue;
			}

			$matches[] = array(
				'backupId'      => $record['backupId'],
				'schemaVersion' => $record['schemaVersion'],
				'createdAt'     => $record['createdAt'],
			);
		}

		return 1 === count( $matches ) ? $matches[0] : null;
	}

	/**
	 * Validate one stored envelope before hashing it.
	 *
	 * @param array<string, mixed> $envelope Stored envelope.
	 * @return bool Whether all required fields have the expected type.
	 */
	private function has_valid_shape( array $envelope ): bool {
		return self::SCHEMA_VERSION === ( $envelope['schemaVersion'] ?? null )
			&& isset( $envelope['backupId'] )
			&& is_string( $envelope['backupId'] )
			&& wp_is_uuid( $envelope['backupId'], 4 )
			&& isset( $envelope['createdAt'] )
			&& is_string( $envelope['createdAt'] )
			&& isset( $envelope['payload'] )
			&& is_array( $envelope['payload'] )
			&& isset( $envelope['envelopeHash'] )
			&& is_string( $envelope['envelopeHash'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $envelope['envelopeHash'] );
	}

	/**
	 * Recursively isolate a verified payload from the metadata cache value.
	 *
	 * @param array<array-key, mixed> $value Verified payload value.
	 * @return array<array-key, mixed> Detached payload copy.
	 */
	private function copy_array( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = $this->copy_array( $item );
			} elseif ( is_object( $item ) ) {
				$copy = clone $item;
				foreach ( get_object_vars( $copy ) as $property => $property_value ) {
					if ( is_array( $property_value ) ) {
						$copy->{$property} = $this->copy_array( $property_value );
					} elseif ( is_object( $property_value ) ) {
						$copy->{$property} = $this->copy_array( array( $property_value ) )[0];
					}
				}
				$value[ $key ] = $copy;
			}
		}

		return $value;
	}
}
