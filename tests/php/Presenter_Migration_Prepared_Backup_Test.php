<?php
/**
 * Trusted prepared migration backup tests.
 *
 * @package Presenter
 */

use Presenter\Migration_Hasher;
use Presenter\Migration_Planner;
use Presenter\Migration_Prepared_Backup;
use Presenter\Native_Deck_Structure;

/**
 * Verify only exact, internally consistent prepared payloads become trusted.
 */
final class Presenter_Migration_Prepared_Backup_Test extends Presenter_Test_Case {
	/** A complete prepared payload exposes isolated private apply values. */
	public function test_valid_payload_returns_trusted_immutable_value(): void {
		$fixture = $this->fixture();
		$trusted = $this->validate( $fixture );

		$this->assertInstanceOf( Migration_Prepared_Backup::class, $trusted );
		$this->assertSame( 42, $trusted->post_id() );
		$this->assertSame( '', $trusted->original_content() );
		$this->assertSame( $fixture['payload']['targetContent'], $trusted->target_content() );
		$this->assertSame( $fixture['payload']['preconditionHash'], $trusted->precondition_hash() );
		$this->assertSame( $fixture['payload']['retainedLegacyHash'], $trusted->retained_hash() );
		$this->assertSame( $fixture['payload']['deckModeHash'], $trusted->deck_mode_hash() );
		$this->assertSame( $fixture['payload']['originalContentHash'], $trusted->original_content_hash() );
		$this->assertSame( $fixture['payload']['targetContentHash'], $trusted->target_content_hash() );
		$this->assertSame( $fixture['payload']['revisionFieldsHash'], $trusted->revision_fields_hash() );
		$this->assertSame( $fixture['payload']['preparationReference'], $trusted->preparation_reference() );
		$this->assertSame( $fixture['payload']['backupReference'], $trusted->backup_reference() );
		$this->assertSame( $fixture['context']['backupId'], $trusted->backup_id() );
		$this->assertSame( 84, $trusted->revision_id() );
		$this->assertSame( Migration_Planner::VERSION, $trusted->planner_version() );

		$legacy                                 = $trusted->legacy_meta();
		$legacy['slides']['values'][0]->content = 'mutated outside trusted value';
		$this->assertSame( '<p>Original slide</p>', $trusted->legacy_meta()['slides']['values'][0]->content );

		$post          = $trusted->post_fields();
		$post['title'] = 'mutated outside trusted value';
		$this->assertSame( 'Prepared deck', $trusted->post_fields()['title'] );
		$this->assertSame( '{}', wp_json_encode( $trusted ), 'Private properties must not serialize into JSON output.' );
	}

	/** Authored fields cannot change independently of their prepared hashes. */
	public function test_tampered_post_field_is_rejected(): void {
		$fixture                             = $this->fixture();
		$fixture['payload']['post']['title'] = 'Tampered title';

		$this->assertNull( $this->validate( $fixture ) );
	}

	/** Target content must agree with its hash and journal reference. */
	public function test_tampered_target_content_is_rejected(): void {
		$fixture                              = $this->fixture();
		$fixture['payload']['targetContent'] .= '<!-- wp:paragraph --><p>Tampered</p><!-- /wp:paragraph -->';

		$this->assertNull( $this->validate( $fixture ) );
	}

	/** A consistently hashed but malformed native tree remains untrusted. */
	public function test_invalid_native_structure_is_rejected(): void {
		$fixture = $this->fixture( '<!-- wp:paragraph --><p>Not a Presenter Deck</p><!-- /wp:paragraph -->' );

		$this->assertNull( $this->validate( $fixture ) );
	}

	/** Payload and journal schemas reject missing and unknown fields. */
	public function test_schema_changes_are_rejected(): void {
		$missing = $this->fixture();
		unset( $missing['payload']['targetContent'] );

		$extra                               = $this->fixture();
		$extra['context']['unexpectedField'] = true;

		$this->assertNull( $this->validate( $missing ) );
		$this->assertNull( $this->validate( $extra ) );
	}

	/** Backup and journal values must describe the same exact preparation. */
	public function test_journal_context_mismatch_is_rejected(): void {
		$fixture                                 = $this->fixture();
		$fixture['context']['targetContentHash'] = str_repeat( '0', 64 );

		$this->assertNull( $this->validate( $fixture ) );
	}

	/** Historical signed backups remain trustworthy for restore operations. */
	public function test_supported_historical_planner_version_is_accepted(): void {
		$fixture = $this->fixture( null, Migration_Planner::VERSION - 1 );
		$trusted = $this->validate( $fixture );

		$this->assertInstanceOf( Migration_Prepared_Backup::class, $trusted );
		$this->assertSame( Migration_Planner::VERSION - 1, $trusted->planner_version() );
	}

	/** Invalid, mismatched, and future planner versions are rejected. */
	public function test_invalid_planner_versions_are_rejected(): void {
		$zero = $this->fixture();
		$zero['payload']['plannerVersion'] = 0;
		$zero['context']['plannerVersion'] = 0;

		$future = $this->fixture( null, Migration_Planner::VERSION + 1 );

		$mismatch = $this->fixture();
		$mismatch['context']['plannerVersion'] = Migration_Planner::VERSION - 1;

		$string = $this->fixture();
		$string['payload']['plannerVersion'] = (string) Migration_Planner::VERSION;
		$string['context']['plannerVersion'] = (string) Migration_Planner::VERSION;

		$this->assertNull( $this->validate( $zero ) );
		$this->assertNull( $this->validate( $future ) );
		$this->assertNull( $this->validate( $mismatch ) );
		$this->assertNull( $this->validate( $string ) );
	}

	/** Preparation never trusts an existing or malformed cutover marker row. */
	public function test_nonempty_deck_mode_metadata_is_rejected(): void {
		$fixture                            = $this->fixture();
		$fixture['payload']['deckModeMeta'] = array( 'native' );

		$this->assertNull( $this->validate( $fixture ) );
	}

	/** Legacy metadata retains exact entry names, types, and ordered values. */
	public function test_malformed_legacy_metadata_is_rejected(): void {
		$fixture = $this->fixture();
		$fixture['payload']['legacyMeta']['slides']['exists'] = 'yes';

		$this->assertNull( $this->validate( $fixture ) );
	}

	/**
	 * Build a complete internally consistent private fixture.
	 *
	 * @param string|null $target_content Optional native target override.
	 * @param int|null    $planner_version Optional planner version override.
	 * @return array<string, array<string, mixed>> Fixture payload and context.
	 */
	private function fixture( ?string $target_content = null, ?int $planner_version = null ): array {
		$hasher              = $this->hasher();
		$planner_version     = $planner_version ?? Migration_Planner::VERSION;
		$target_content      = $target_content ?? '<!-- wp:presenter/deck --><!-- wp:presenter/slide --><!-- wp:paragraph --><p>Native</p><!-- /wp:paragraph --><!-- /wp:presenter/slide --><!-- /wp:presenter/deck -->';
		$post                = array(
			'id'          => 42,
			'type'        => 'slideshow',
			'name'        => 'prepared-deck',
			'title'       => 'Prepared deck',
			'excerpt'     => 'Prepared excerpt',
			'menuOrder'   => 3,
			'status'      => 'publish',
			'password'    => '',
			'postContent' => '',
		);
		$slide               = new stdClass();
		$slide->content      = '<p>Original slide</p>';
		$legacy_meta         = array(
			'slides'   => array(
				'exists' => true,
				'values' => array( $slide ),
			),
			'theme'    => array(
				'exists' => true,
				'values' => array( '/presenter/css/theme/default.css' ),
			),
			'shortUrl' => array(
				'exists' => false,
				'values' => array(),
			),
		);
		$precondition        = $hasher->hash(
			'preparation-source',
			array(
				'post'         => $post,
				'legacyMeta'   => $legacy_meta,
				'deckModeMeta' => array(),
			)
		);
		$target_hash         = $hasher->hash( 'post-content', $target_content );
		$preparation         = $hasher->hash(
			'preparation-reference',
			array(
				'postId'            => 42,
				'plannerVersion'    => $planner_version,
				'preconditionHash'  => $precondition,
				'targetContentHash' => $target_hash,
			)
		);
		$backup              = $hasher->hash(
			'backup-reference',
			array(
				'preparationReference' => $preparation,
				'revisionId'           => 84,
			)
		);
		$payload             = array(
			'plannerVersion'       => $planner_version,
			'preparationReference' => $preparation,
			'backupReference'      => $backup,
			'preconditionHash'     => $precondition,
			'retainedLegacyHash'   => $hasher->hash( 'retained-legacy', $legacy_meta ),
			'deckModeHash'         => $hasher->hash( 'deck-mode-meta', array() ),
			'originalContentHash'  => $hasher->hash( 'post-content', '' ),
			'targetContentHash'    => $target_hash,
			'targetContent'        => $target_content,
			'revisionFieldsHash'   => $hasher->hash(
				'revision-fields',
				array(
					'title'   => $post['title'],
					'content' => $post['postContent'],
					'excerpt' => $post['excerpt'],
				)
			),
			'legacyFingerprint'    => $this->legacy_fingerprint( $post, $legacy_meta ),
			'revisionId'           => 84,
			'post'                 => $post,
			'legacyMeta'           => $legacy_meta,
			'deckModeMeta'         => array(),
		);
		$context             = array_intersect_key(
			$payload,
			array_flip(
				array(
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
				)
			)
		);
		$context['backupId'] = '11111111-1111-4111-8111-111111111111';

		return array(
			'payload' => $payload,
			'context' => $context,
		);
	}

	/**
	 * Validate a fixture through the production boundary.
	 *
	 * @param array<string, array<string, mixed>> $fixture Payload and context.
	 * @return Migration_Prepared_Backup|null Trusted value, or null.
	 */
	private function validate( array $fixture ): ?Migration_Prepared_Backup {
		return Migration_Prepared_Backup::from_verified(
			42,
			$fixture['payload'],
			$fixture['context'],
			$this->hasher(),
			new Native_Deck_Structure()
		);
	}

	/** Build the fixture's persistent hasher. */
	private function hasher(): Migration_Hasher {
		return new Migration_Hasher( 'prepared-backup-test-secret' );
	}

	/**
	 * Recreate the snapshot fingerprint included in a prepared backup.
	 *
	 * @param array<string, mixed> $post        Exact post fields.
	 * @param array<string, mixed> $legacy_meta Exact legacy metadata.
	 * @return string Snapshot fingerprint.
	 */
	private function legacy_fingerprint( array $post, array $legacy_meta ): string {
		return hash_hmac(
			'sha256',
			maybe_serialize(
				array(
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
				)
			),
			wp_salt( 'auth' )
		);
	}
}
