<?php
/**
 * Trusted prepared migration backup.
 *
 * @package Presenter
 */

namespace Presenter;

use Throwable;

/**
 * Validates and carries the private source and target for one prepared apply.
 *
 * This value must remain inside migration orchestration. It deliberately does
 * not implement any serialization or public status contract.
 */
final class Migration_Prepared_Backup {
	/**
	 * Create an already validated immutable value.
	 *
	 * @param int                  $post_id              Slideshow post ID.
	 * @param array<string, mixed> $post                 Exact source post fields.
	 * @param array<string, mixed> $legacy_meta          Exact retained legacy metadata.
	 * @param string               $target_content       Verified native target content.
	 * @param string               $precondition_hash    Full prepared-source hash.
	 * @param string               $retained_hash        Retained legacy metadata hash.
	 * @param string               $deck_mode_hash       Empty cutover-marker hash.
	 * @param string               $original_hash        Original content hash.
	 * @param string               $target_hash          Target content hash.
	 * @param string               $revision_fields_hash Revision fields hash.
	 * @param string               $preparation_reference Deterministic preparation reference.
	 * @param string               $backup_reference     Revision-bound backup reference.
	 * @param string               $backup_id            Verified backup envelope UUID.
	 * @param int                  $revision_id           Verified source revision ID.
	 */
	private function __construct(
		private int $post_id,
		private array $post,
		private array $legacy_meta,
		private string $target_content,
		private string $precondition_hash,
		private string $retained_hash,
		private string $deck_mode_hash,
		private string $original_hash,
		private string $target_hash,
		private string $revision_fields_hash,
		private string $preparation_reference,
		private string $backup_reference,
		private string $backup_id,
		private int $revision_id
	) {}

	/**
	 * Validate one verified payload against its verified journal context.
	 *
	 * @param int                   $post_id   Expected slideshow post ID.
	 * @param array<string, mixed>  $payload   Verified backup payload.
	 * @param array<string, mixed>  $context   Verified journal context.
	 * @param Migration_Hasher      $hasher    Persistent site-keyed hasher.
	 * @param Native_Deck_Structure $structure Shared native Deck validator.
	 * @return self|null Trusted private value, or null when any invariant fails.
	 */
	public static function from_verified(
		int $post_id,
		array $payload,
		array $context,
		Migration_Hasher $hasher,
		Native_Deck_Structure $structure
	): ?self {
		if (
			$post_id < 1 ||
			! self::has_exact_keys( $payload, self::payload_keys() ) ||
			! self::has_exact_keys( $context, self::context_keys() ) ||
			! self::valid_hash_fields( $payload ) ||
			! self::valid_hash_fields( $context ) ||
			! self::valid_scalar_fields( $post_id, $payload, $context )
		) {
			return null;
		}

		$post        = $payload['post'];
		$legacy_meta = $payload['legacyMeta'];
		if (
			! is_array( $post ) ||
			! self::valid_post( $post_id, $post ) ||
			! is_array( $legacy_meta ) ||
			! self::valid_legacy_meta( $legacy_meta ) ||
			! is_array( $payload['deckModeMeta'] ) ||
			array() !== $payload['deckModeMeta'] ||
			! is_string( $payload['targetContent'] ) ||
			! $structure->is_valid( $payload['targetContent'] )
		) {
			return null;
		}

		foreach ( self::shared_context_keys() as $key ) {
			if ( $payload[ $key ] !== $context[ $key ] ) {
				return null;
			}
		}

		try {
			if ( ! self::hashes_agree( $post_id, $payload, $post, $legacy_meta, $hasher ) ) {
				return null;
			}
		} catch ( Throwable ) {
			return null;
		}

		return new self(
			$post_id,
			self::copy_array( $post ),
			self::copy_array( $legacy_meta ),
			$payload['targetContent'],
			$payload['preconditionHash'],
			$payload['retainedLegacyHash'],
			$payload['deckModeHash'],
			$payload['originalContentHash'],
			$payload['targetContentHash'],
			$payload['revisionFieldsHash'],
			$payload['preparationReference'],
			$payload['backupReference'],
			$context['backupId'],
			$payload['revisionId']
		);
	}

	/** Get the source slideshow ID. */
	public function post_id(): int {
		return $this->post_id;
	}

	/** Get the exact original post content. */
	public function original_content(): string {
		return $this->post['postContent'];
	}

	/** Get the exact verified native target content. */
	public function target_content(): string {
		return $this->target_content;
	}

	/** Get an isolated copy of the exact source post fields. */
	public function post_fields(): array {
		return self::copy_array( $this->post );
	}

	/** Get an isolated copy of the exact retained legacy metadata. */
	public function legacy_meta(): array {
		return self::copy_array( $this->legacy_meta );
	}

	/** Get the prepared full-source hash. */
	public function precondition_hash(): string {
		return $this->precondition_hash;
	}

	/** Get the retained legacy metadata hash. */
	public function retained_hash(): string {
		return $this->retained_hash;
	}

	/** Get the empty pre-cutover marker hash. */
	public function deck_mode_hash(): string {
		return $this->deck_mode_hash;
	}

	/** Get the original content hash. */
	public function original_content_hash(): string {
		return $this->original_hash;
	}

	/** Get the target content hash. */
	public function target_content_hash(): string {
		return $this->target_hash;
	}

	/** Get the source revision-fields hash. */
	public function revision_fields_hash(): string {
		return $this->revision_fields_hash;
	}

	/** Get the deterministic preparation reference. */
	public function preparation_reference(): string {
		return $this->preparation_reference;
	}

	/** Get the revision-bound backup reference. */
	public function backup_reference(): string {
		return $this->backup_reference;
	}

	/** Get the verified backup envelope UUID. */
	public function backup_id(): string {
		return $this->backup_id;
	}

	/** Get the verified source revision ID. */
	public function revision_id(): int {
		return $this->revision_id;
	}

	/** Get the exact allowed payload keys. */
	private static function payload_keys(): array {
		return array(
			'plannerVersion',
			'preparationReference',
			'backupReference',
			'preconditionHash',
			'retainedLegacyHash',
			'deckModeHash',
			'originalContentHash',
			'targetContentHash',
			'targetContent',
			'revisionFieldsHash',
			'legacyFingerprint',
			'revisionId',
			'post',
			'legacyMeta',
			'deckModeMeta',
		);
	}

	/** Get the exact allowed journal-context keys. */
	private static function context_keys(): array {
		return array_merge( self::shared_context_keys(), array( 'backupId' ) );
	}

	/** Get fields that must be byte-for-byte equal across backup and journal. */
	private static function shared_context_keys(): array {
		return array(
			'plannerVersion',
			'preparationReference',
			'backupReference',
			'preconditionHash',
			'retainedLegacyHash',
			'deckModeHash',
			'originalContentHash',
			'targetContentHash',
			'revisionFieldsHash',
			'revisionId',
		);
	}

	/**
	 * Determine whether an array contains exactly the allowed string keys.
	 *
	 * @param array<array-key, mixed> $value    Candidate array.
	 * @param array<int, string>      $expected Expected keys.
	 * @return bool Whether the keys match exactly.
	 */
	private static function has_exact_keys( array $value, array $expected ): bool {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );

		return $actual === $expected;
	}

	/**
	 * Validate typed scalar identifiers and versions.
	 *
	 * @param int                  $post_id Expected post ID.
	 * @param array<string, mixed> $payload Backup payload.
	 * @param array<string, mixed> $context Journal context.
	 * @return bool Whether all scalar fields are valid.
	 */
	private static function valid_scalar_fields( int $post_id, array $payload, array $context ): bool {
		return Migration_Planner::VERSION === $payload['plannerVersion']
			&& Migration_Planner::VERSION === $context['plannerVersion']
			&& is_int( $payload['revisionId'] )
			&& 0 < $payload['revisionId']
			&& $payload['revisionId'] === $context['revisionId']
			&& is_string( $context['backupId'] )
			&& wp_is_uuid( $context['backupId'], 4 )
			&& isset( $payload['legacyFingerprint'] )
			&& is_string( $payload['legacyFingerprint'] )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', $payload['legacyFingerprint'] )
			&& $post_id > 0;
	}

	/**
	 * Validate every migration HMAC field.
	 *
	 * @param array<string, mixed> $value Candidate payload or context.
	 * @return bool Whether every required hash is well formed.
	 */
	private static function valid_hash_fields( array $value ): bool {
		$keys = array(
			'preparationReference',
			'backupReference',
			'preconditionHash',
			'retainedLegacyHash',
			'deckModeHash',
			'originalContentHash',
			'targetContentHash',
			'revisionFieldsHash',
		);

		foreach ( $keys as $key ) {
			if ( ! isset( $value[ $key ] ) || ! is_string( $value[ $key ] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $value[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate exact source post field names and types.
	 *
	 * @param int                  $post_id Expected post ID.
	 * @param array<string, mixed> $post    Stored post fields.
	 * @return bool Whether the post fields are exact and typed.
	 */
	private static function valid_post( int $post_id, array $post ): bool {
		return self::has_exact_keys( $post, array( 'id', 'type', 'name', 'title', 'excerpt', 'menuOrder', 'status', 'password', 'postContent' ) )
			&& $post_id === $post['id']
			&& 'slideshow' === $post['type']
			&& is_string( $post['name'] )
			&& is_string( $post['title'] )
			&& is_string( $post['excerpt'] )
			&& is_int( $post['menuOrder'] )
			&& is_string( $post['status'] )
			&& is_string( $post['password'] )
			&& is_string( $post['postContent'] );
	}

	/**
	 * Validate exact legacy metadata entry shapes without coercing values.
	 *
	 * @param array<string, mixed> $legacy_meta Stored legacy metadata.
	 * @return bool Whether the metadata shape is exact.
	 */
	private static function valid_legacy_meta( array $legacy_meta ): bool {
		if ( ! self::has_exact_keys( $legacy_meta, array( 'slides', 'theme', 'shortUrl' ) ) ) {
			return false;
		}

		foreach ( $legacy_meta as $entry ) {
			if (
				! is_array( $entry ) ||
				! self::has_exact_keys( $entry, array( 'exists', 'values' ) ) ||
				! is_bool( $entry['exists'] ) ||
				! is_array( $entry['values'] ) ||
				array_values( $entry['values'] ) !== $entry['values']
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Verify all derived hashes and deterministic references.
	 *
	 * @param int                  $post_id     Expected post ID.
	 * @param array<string, mixed> $payload     Backup payload.
	 * @param array<string, mixed> $post        Exact post fields.
	 * @param array<string, mixed> $legacy_meta Exact legacy metadata.
	 * @param Migration_Hasher     $hasher      Persistent site-keyed hasher.
	 * @return bool Whether every derived value agrees.
	 */
	private static function hashes_agree( int $post_id, array $payload, array $post, array $legacy_meta, Migration_Hasher $hasher ): bool {
		$checks = array(
			'preconditionHash'    => $hasher->hash(
				'preparation-source',
				array(
					'post'         => $post,
					'legacyMeta'   => $legacy_meta,
					'deckModeMeta' => array(),
				)
			),
			'retainedLegacyHash'  => $hasher->hash( 'retained-legacy', $legacy_meta ),
			'deckModeHash'        => $hasher->hash( 'deck-mode-meta', array() ),
			'originalContentHash' => $hasher->hash( 'post-content', $post['postContent'] ),
			'targetContentHash'   => $hasher->hash( 'post-content', $payload['targetContent'] ),
			'revisionFieldsHash'  => $hasher->hash(
				'revision-fields',
				array(
					'title'   => $post['title'],
					'content' => $post['postContent'],
					'excerpt' => $post['excerpt'],
				)
			),
		);

		foreach ( $checks as $key => $expected ) {
			if ( ! hash_equals( $payload[ $key ], $expected ) ) {
				return false;
			}
		}

		$preparation_reference = $hasher->hash(
			'preparation-reference',
			array(
				'postId'            => $post_id,
				'plannerVersion'    => Migration_Planner::VERSION,
				'preconditionHash'  => $payload['preconditionHash'],
				'targetContentHash' => $payload['targetContentHash'],
			)
		);
		$backup_reference      = $hasher->hash(
			'backup-reference',
			array(
				'preparationReference' => $preparation_reference,
				'revisionId'           => $payload['revisionId'],
			)
		);

		return hash_equals( $payload['preparationReference'], $preparation_reference )
			&& hash_equals( $payload['backupReference'], $backup_reference )
			&& hash_equals( $payload['legacyFingerprint'], self::legacy_fingerprint( $post, $legacy_meta ) );
	}

	/**
	 * Recreate the read-only snapshot fingerprint stored during preparation.
	 *
	 * @param array<string, mixed> $post        Exact post fields.
	 * @param array<string, mixed> $legacy_meta Exact legacy metadata.
	 * @return string Snapshot fingerprint.
	 */
	private static function legacy_fingerprint( array $post, array $legacy_meta ): string {
		$source = array(
			'post'        => array(
				'id'           => $post['id'],
				'post_type'    => $post['type'],
				'slug'         => $post['name'],
				'title'        => $post['title'],
				'excerpt'      => $post['excerpt'],
				'menu_order'   => $post['menuOrder'],
				'status'       => $post['status'],
				'password'     => $post['password'],
				'post_content' => $post['postContent'],
			),
			'legacy_meta' => $legacy_meta,
		);

		return hash_hmac( 'sha256', maybe_serialize( $source ), wp_salt( 'auth' ) );
	}

	/**
	 * Recursively isolate arrays and objects held by the trusted value.
	 *
	 * @param array<array-key, mixed> $value Value to copy.
	 * @return array<array-key, mixed> Isolated copy.
	 */
	private static function copy_array( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::copy_array( $item );
			} elseif ( is_object( $item ) ) {
				$copy = clone $item;
				foreach ( get_object_vars( $copy ) as $property => $property_value ) {
					$copy->{$property} = is_array( $property_value )
						? self::copy_array( $property_value )
						: ( is_object( $property_value ) ? self::copy_array( array( $property_value ) )[0] : $property_value );
				}
				$value[ $key ] = $copy;
			}
		}

		return $value;
	}
}
