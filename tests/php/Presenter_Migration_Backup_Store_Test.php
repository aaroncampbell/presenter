<?php
/**
 * Immutable Presenter migration backup store tests.
 *
 * @package Presenter
 */

use Presenter\Migration_Backup_Store;
use Presenter\Migration_Hasher;

require_once dirname( __DIR__, 2 ) . '/includes/class-migration-value-encoder.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-migration-hasher.php';

/**
 * Verify backup envelopes remain append-only, private, and tamper evident.
 */
final class Presenter_Migration_Backup_Store_Test extends Presenter_Test_Case {
	/** Private repeated metadata key owned by the backup store. */
	private const META_KEY = '_presenter_migration_backup_v1';

	/** Stable test secret that never leaves the test process. */
	private const TEST_SECRET = 'presenter-backup-store-test-secret';

	/** Invalid post IDs cannot create or verify a backup. */
	public function test_invalid_post_id_is_rejected(): void {
		$store = $this->store();

		$this->assertNull( $store->create( 0, array( 'content' => 'ignored' ) ) );
		$this->assertNull( $store->create( -1, array( 'content' => 'ignored' ) ) );
		$this->assertFalse( $store->verify( 0, wp_generate_uuid4() ) );
		$this->assertFalse( $store->verify( -1, wp_generate_uuid4() ) );
	}

	/** Creating a backup persists one complete envelope and returns a safe summary. */
	public function test_create_persists_verifiable_envelope_and_returns_redacted_summary(): void {
		$post_id  = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$authored = 'private-authored-content-sentinel';
		$summary  = $this->store()->create(
			$post_id,
			array(
				'postContent' => $authored,
				'legacyMeta'  => array( 'slide content' ),
			)
		);

		$this->assertIsArray( $summary );
		$this->assertSame( array( 'backupId', 'schemaVersion', 'createdAt' ), array_keys( $summary ) );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$summary['backupId']
		);
		$this->assertSame( 1, $summary['schemaVersion'] );
		$this->assertSame( 0, ( new DateTimeImmutable( $summary['createdAt'] ) )->getOffset() );

		$records = get_post_meta( $post_id, self::META_KEY, false );
		$this->assertCount( 1, $records );
		$this->assertEqualsCanonicalizing(
			array( 'backupId', 'schemaVersion', 'createdAt', 'payload', 'envelopeHash' ),
			array_keys( $records[0] )
		);
		$this->assertSame( $summary['backupId'], $records[0]['backupId'] );
		$this->assertSame( $summary['createdAt'], $records[0]['createdAt'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $records[0]['envelopeHash'] );

		$public_summary = wp_json_encode( $summary );
		$this->assertIsString( $public_summary );
		$this->assertStringNotContainsString( $authored, $public_summary );
		$this->assertStringNotContainsString( $records[0]['envelopeHash'], $public_summary );
		$this->assertTrue( $this->store()->verify( $post_id, $summary['backupId'] ) );
	}

	/** Repeated creates append records without changing the original envelope. */
	public function test_repeated_create_is_append_only_and_preserves_prior_backup(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$store   = $this->store();
		$first   = $store->create( $post_id, array( 'version' => 'first' ) );
		$before  = get_post_meta( $post_id, self::META_KEY, false );
		$second  = $store->create( $post_id, array( 'version' => 'second' ) );
		$after   = get_post_meta( $post_id, self::META_KEY, false );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertNotSame( $first['backupId'], $second['backupId'] );
		$this->assertCount( 1, $before );
		$this->assertCount( 2, $after );
		$this->assertSame( $before[0], $after[0] );
		$this->assertTrue( $store->verify( $post_id, $first['backupId'] ) );
		$this->assertTrue( $store->verify( $post_id, $second['backupId'] ) );
	}

	/** The store never updates or deletes backup metadata during create or verify. */
	public function test_store_uses_add_only_metadata_operations(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$store   = $this->store();
		$updates = 0;
		$deletes = 0;
		$updated = static function () use ( &$updates ): void {
			++$updates;
		};
		$deleted = static function () use ( &$deletes ): void {
			++$deletes;
		};
		add_action( 'updated_post_meta', $updated );
		add_action( 'deleted_post_meta', $deleted );

		$summary = $store->create( $post_id, array( 'content' => 'append-only' ) );
		$this->assertIsArray( $summary );
		$this->assertTrue( $store->verify( $post_id, $summary['backupId'] ) );

		remove_action( 'updated_post_meta', $updated );
		remove_action( 'deleted_post_meta', $deleted );

		$this->assertSame( 0, $updates );
		$this->assertSame( 0, $deletes );
	}

	/** Verification rejects ambiguity when a backup ID appears more than once. */
	public function test_duplicate_backup_id_is_rejected(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$store   = $this->store();
		$summary = $store->create( $post_id, array( 'content' => 'original' ) );
		$record  = get_post_meta( $post_id, self::META_KEY, true );

		$this->assertIsArray( $summary );
		$this->assertIsArray( $record );
		$this->assertNotFalse( add_post_meta( $post_id, self::META_KEY, $record, false ) );
		$this->assertFalse( $store->verify( $post_id, $summary['backupId'] ) );
		$this->assertNull( $store->read_verified_payload( $post_id, $summary['backupId'] ) );
	}

	/** A verified payload is available only through the explicit internal reader. */
	public function test_verified_payload_reader_returns_an_isolated_copy(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$store   = $this->store();
		$object  = (object) array( 'nested' => array( 'value' => 'original' ) );
		$summary = $store->create(
			$post_id,
			array(
				'postContent' => 'private authored content',
				'legacyMeta'  => array( 'slide' => $object ),
			)
		);

		$this->assertIsArray( $summary );
		$payload = $store->read_verified_payload( $post_id, $summary['backupId'] );
		$this->assertIsArray( $payload );
		$this->assertSame( 'private authored content', $payload['postContent'] );
		$this->assertNotSame( $object, $payload['legacyMeta']['slide'] );

		$payload['legacyMeta']['slide']->nested['value'] = 'changed';
		$reread = $store->read_verified_payload( $post_id, $summary['backupId'] );
		$this->assertIsArray( $reread );
		$this->assertSame( 'original', $reread['legacyMeta']['slide']->nested['value'] );
	}

	/** Missing IDs and a valid ID paired with the wrong post fail closed. */
	public function test_verified_payload_reader_rejects_missing_and_wrong_post(): void {
		$post_id    = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$other_post = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$store      = $this->store();
		$summary    = $store->create( $post_id, array( 'content' => 'private' ) );

		$this->assertIsArray( $summary );
		$this->assertNull( $store->read_verified_payload( $post_id, wp_generate_uuid4() ) );
		$this->assertNull( $store->read_verified_payload( $other_post, $summary['backupId'] ) );
		$this->assertNull( $store->read_verified_payload( 0, $summary['backupId'] ) );
	}

	/**
	 * Any changed envelope field invalidates the stored backup.
	 *
	 * @dataProvider envelope_tampering
	 *
	 * @param Closure $tamper Envelope mutation.
	 */
	public function test_tampering_is_detected( Closure $tamper ): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$store   = $this->store();
		$summary = $store->create( $post_id, array( 'content' => 'untampered' ) );
		$record  = get_post_meta( $post_id, self::META_KEY, true );

		$this->assertIsArray( $summary );
		$this->assertIsArray( $record );
		$this->assertNotFalse(
			update_post_meta( $post_id, self::META_KEY, $tamper( $record ), $record )
		);
		$this->assertFalse( $store->verify( $post_id, $summary['backupId'] ) );
		$this->assertNull( $store->read_verified_payload( $post_id, $summary['backupId'] ) );
	}

	/**
	 * Provide mutations for every integrity-protected envelope field.
	 *
	 * @return array<string, array{callable(array<string, mixed>): array<string, mixed>}>
	 */
	public function envelope_tampering(): array {
		return array(
			'schema version' => array(
				static function ( array $record ): array {
					$record['schemaVersion'] = 2;
					return $record;
				},
			),
			'creation time'  => array(
				static function ( array $record ): array {
					$record['createdAt'] = '2000-01-01T00:00:00Z';
					return $record;
				},
			),
			'payload'        => array(
				static function ( array $record ): array {
					$record['payload']['content'] = 'tampered';
					return $record;
				},
			),
			'envelope hash'  => array(
				static function ( array $record ): array {
					$record['envelopeHash'] = str_repeat( '0', 64 );
					return $record;
				},
			),
		);
	}

	/** An orphaned preparation can recover its one intact redacted backup reference. */
	public function test_find_verified_reference_returns_redacted_orphan_summary(): void {
		$post_id               = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$preparation_reference = hash( 'sha256', 'successful-orphan-reference' );
		$authored              = 'orphan-authored-content-sentinel';
		$store                 = $this->store();
		$created               = $store->create(
			$post_id,
			array(
				'preparationReference' => $preparation_reference,
				'postContent'          => $authored,
			)
		);

		$this->assertIsArray( $created );

		$found = $store->find_verified_reference( $post_id, $preparation_reference );

		$this->assertSame( $created, $found );
		$this->assertSame( array( 'backupId', 'schemaVersion', 'createdAt' ), array_keys( $found ) );
		$public_summary = wp_json_encode( $found );
		$this->assertIsString( $public_summary );
		$this->assertStringNotContainsString( $authored, $public_summary );
		$this->assertStringNotContainsString( $preparation_reference, $public_summary );
	}

	/** Missing or invalid preparation references fail closed. */
	public function test_find_verified_reference_returns_null_when_missing(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$store   = $this->store();
		$store->create( $post_id, array( 'postContent' => 'No preparation reference' ) );

		$this->assertNull(
			$store->find_verified_reference( $post_id, hash( 'sha256', 'missing-reference' ) )
		);
		$this->assertNull( $store->find_verified_reference( $post_id, '' ) );
		$this->assertNull(
			$store->find_verified_reference( 0, hash( 'sha256', 'invalid-post-reference' ) )
		);
	}

	/** A matching reference inside a tampered envelope cannot be recovered. */
	public function test_find_verified_reference_rejects_tampered_envelope(): void {
		$post_id               = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$preparation_reference = hash( 'sha256', 'tampered-reference' );
		$store                 = $this->store();
		$summary               = $store->create(
			$post_id,
			array(
				'preparationReference' => $preparation_reference,
				'postContent'          => 'Original content',
			)
		);
		$record                = get_post_meta( $post_id, self::META_KEY, true );

		$this->assertIsArray( $summary );
		$this->assertIsArray( $record );
		$record['payload']['postContent'] = 'Tampered content';
		$this->assertNotFalse( update_post_meta( $post_id, self::META_KEY, $record ) );
		$this->assertNull( $store->find_verified_reference( $post_id, $preparation_reference ) );
	}

	/** Two intact backups for one preparation reference are intentionally ambiguous. */
	public function test_find_verified_reference_rejects_duplicate_reference(): void {
		$post_id               = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$preparation_reference = hash( 'sha256', 'ambiguous-reference' );
		$store                 = $this->store();
		$first                 = $store->create(
			$post_id,
			array(
				'preparationReference' => $preparation_reference,
				'postContent'          => 'First intact backup',
			)
		);
		$second                = $store->create(
			$post_id,
			array(
				'preparationReference' => $preparation_reference,
				'postContent'          => 'Second intact backup',
			)
		);

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertTrue( $store->verify( $post_id, $first['backupId'] ) );
		$this->assertTrue( $store->verify( $post_id, $second['backupId'] ) );
		$this->assertNull( $store->find_verified_reference( $post_id, $preparation_reference ) );
	}

	/** A different site secret cannot verify an otherwise intact envelope. */
	public function test_wrong_hashing_secret_cannot_verify_backup(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data( array( 'post_type' => 'slideshow' ) );
		$summary = $this->store()->create( $post_id, array( 'content' => 'private' ) );

		$this->assertIsArray( $summary );
		$this->assertFalse(
			( new Migration_Backup_Store( new Migration_Hasher( 'different-secret' ) ) )
				->verify( $post_id, $summary['backupId'] )
		);
	}

	/** Create a store with a deterministic test-only signing secret. */
	private function store(): Migration_Backup_Store {
		return new Migration_Backup_Store( new Migration_Hasher( self::TEST_SECRET ) );
	}
}
