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
	private const META_KEY = '_presenter_migration_backup_v1';

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
		if ( $post_id < 1 || ! wp_is_uuid( $backup_id, 4 ) ) {
			return false;
		}

		$matches = array_values(
			array_filter(
				get_post_meta( $post_id, self::META_KEY, false ),
				static fn( mixed $record ): bool => is_array( $record )
					&& ( $record['backupId'] ?? null ) === $backup_id
			)
		);

		if ( 1 !== count( $matches ) ) {
			return false;
		}

		$envelope = $matches[0];
		if ( ! $this->has_valid_shape( $envelope ) ) {
			return false;
		}

		$stored_hash = $envelope['envelopeHash'];
		unset( $envelope['envelopeHash'] );

		return hash_equals( $stored_hash, $this->hasher->hash( 'backup-envelope', $envelope ) );
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
}
