<?php
/**
 * Verified internal migration preparation context.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Carries authored source values and private hashes only inside orchestration.
 */
final class Migration_Preparation_Context {
	/**
	 * Create a verified context.
	 *
	 * @param Legacy_Deck_Snapshot $snapshot              Captured legacy deck.
	 * @param Legacy_Meta_Payload  $legacy_meta           Exact retained metadata.
	 * @param array<int, mixed>    $deck_mode_meta        Exact pre-cutover marker rows.
	 * @param string               $target_content        Planned native blocks.
	 * @param string               $source_hash           Persistent full-source hash.
	 * @param string               $retained_hash         Retained metadata hash.
	 * @param string               $deck_mode_hash        Pre-cutover marker hash.
	 * @param string               $original_content_hash Original content hash.
	 * @param string               $target_content_hash   Planned content hash.
	 * @param string               $revision_fields_hash  Revisioned fields hash.
	 * @param string               $preparation_reference Deterministic preparation reference.
	 */
	public function __construct(
		private Legacy_Deck_Snapshot $snapshot,
		private Legacy_Meta_Payload $legacy_meta,
		private array $deck_mode_meta,
		private string $target_content,
		private string $source_hash,
		private string $retained_hash,
		private string $deck_mode_hash,
		private string $original_content_hash,
		private string $target_content_hash,
		private string $revision_fields_hash,
		private string $preparation_reference
	) {}

	/** Get the source post ID. */
	public function post_id(): int {
		return $this->snapshot->post_id();
	}

	/** Get the planned native content for internal hashing and later apply. */
	public function target_content(): string {
		return $this->target_content;
	}

	/** Get the deterministic private preparation reference. */
	public function preparation_reference(): string {
		return $this->preparation_reference;
	}

	/** Get the persistent retained legacy metadata hash. */
	public function retained_hash(): string {
		return $this->retained_hash;
	}

	/**
	 * Build the complete immutable backup payload.
	 *
	 * @param int    $revision_id Verified revision ID.
	 * @param string $backup_reference Revision-bound backup reference.
	 * @return array<string, mixed> Private backup payload.
	 */
	public function backup_payload( int $revision_id, string $backup_reference ): array {
		return array(
			'plannerVersion'       => Migration_Planner::VERSION,
			'preparationReference' => $this->preparation_reference,
			'backupReference'      => $backup_reference,
			'preconditionHash'     => $this->source_hash,
			'retainedLegacyHash'   => $this->retained_hash,
			'deckModeHash'         => $this->deck_mode_hash,
			'originalContentHash'  => $this->original_content_hash,
			'targetContentHash'    => $this->target_content_hash,
			'revisionFieldsHash'   => $this->revision_fields_hash,
			'legacyFingerprint'    => $this->snapshot->fingerprint(),
			'revisionId'           => $revision_id,
			'post'                 => array(
				'id'          => $this->snapshot->post_id(),
				'type'        => $this->snapshot->post_type(),
				'name'        => $this->snapshot->slug(),
				'title'       => $this->snapshot->title(),
				'excerpt'     => $this->snapshot->excerpt(),
				'menuOrder'   => $this->snapshot->menu_order(),
				'status'      => $this->snapshot->status(),
				'password'    => $this->snapshot->password(),
				'postContent' => $this->snapshot->post_content(),
			),
			'legacyMeta'           => $this->legacy_meta->to_array(),
			'deckModeMeta'         => $this->deck_mode_meta,
		);
	}

	/**
	 * Build the private journal context for one prepared attempt.
	 *
	 * @param string $backup_id  Verified backup UUID.
	 * @param int    $revision_id Verified revision ID.
	 * @return array<string, mixed> Private journal context.
	 */
	public function journal_context( string $backup_id, int $revision_id ): array {
		return array(
			'plannerVersion'       => Migration_Planner::VERSION,
			'preparationReference' => $this->preparation_reference,
			'preconditionHash'     => $this->source_hash,
			'retainedLegacyHash'   => $this->retained_hash,
			'deckModeHash'         => $this->deck_mode_hash,
			'originalContentHash'  => $this->original_content_hash,
			'targetContentHash'    => $this->target_content_hash,
			'revisionFieldsHash'   => $this->revision_fields_hash,
			'backupId'             => $backup_id,
			'revisionId'           => $revision_id,
		);
	}

	/**
	 * Confirm a stored prepared context describes this exact source and plan.
	 *
	 * @param array<string, mixed> $stored Stored journal context.
	 * @return bool Whether every deterministic preparation field matches.
	 */
	public function matches_preparation( array $stored ): bool {
		$expected = $this->journal_context( '', 0 );
		unset( $expected['backupId'], $expected['revisionId'] );

		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $stored ) || $stored[ $key ] !== $value ) {
				return false;
			}
		}

		return true;
	}
}
