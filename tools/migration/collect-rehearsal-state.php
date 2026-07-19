<?php
/**
 * Collect a private, content-free migration rehearsal fingerprint.
 *
 * This read-only `wp eval-file` helper emits independently keyed HMACs rather
 * than authored values or migration integrity references. The result is safe
 * to compare across apply and restore, but must still remain local.
 *
 * @package Presenter
 */

declare( strict_types=1 );

use Presenter\Deck_Mode;
use Presenter\Migration_Backup_Store;
use Presenter\Migration_Hasher;
use Presenter\Migration_Journal;
use Presenter\Migration_Secret;
use Presenter\Migration_Value_Encoder;
use Presenter\Native_Deck_Structure;

const PRESENTER_REHEARSAL_SCHEMA_VERSION = 1;

/**
 * Stop with a fixed, content-free failure.
 *
 * @param string $code Stable failure code.
 * @return never
 */
function presenter_rehearsal_fail( string $code ): never {
	$allowed = array(
		'internal_error',
		'invalid_environment',
		'invalid_output',
		'invalid_target',
		'key_unavailable',
		'query_failed',
	);
	$safe    = in_array( $code, $allowed, true ) ? $code : 'internal_error';

	echo wp_json_encode(
		array(
			'schemaVersion' => PRESENTER_REHEARSAL_SCHEMA_VERSION,
			'status'        => 'fail',
			'code'          => $safe,
		)
	);
	exit( 1 );
}

/**
 * Produce a domain-separated HMAC over an exact PHP value.
 *
 * @param string $key     Private acceptance key.
 * @param string $purpose Stable purpose label.
 * @param mixed  $value   Value to protect.
 * @return string Lowercase hexadecimal HMAC-SHA256.
 */
function presenter_rehearsal_hmac( string $key, string $purpose, mixed $value ): string {
	return hash_hmac(
		'sha256',
		'presenter-rehearsal:' . PRESENTER_REHEARSAL_SCHEMA_VERSION . ':' . $purpose . "\0" . Migration_Value_Encoder::encode( $value ),
		$key
	);
}

/**
 * Fetch ordered postmeta rows without exposing metadata IDs.
 *
 * @param int $post_id Parent post ID.
 * @return array<int, array{meta_id: string, meta_key: string, meta_value: string}>|null Exact rows, or null on query failure.
 */
function presenter_rehearsal_meta_rows( int $post_id ): ?array {
	global $wpdb;

	$query = $wpdb->prepare(
		'SELECT meta_id, meta_key, meta_value FROM %i WHERE post_id = %d ORDER BY meta_id ASC',
		$wpdb->postmeta,
		$post_id
	);
	if ( ! is_string( $query ) ) {
		return null;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- A byte-exact, order-sensitive rehearsal fingerprint requires persisted rows; the query is prepared immediately above.
	$rows = $wpdb->get_results( $query, 'ARRAY_A' );
	if ( ! is_array( $rows ) ) {
		return null;
	}

	$result = array();
	foreach ( $rows as $row ) {
		if ( ! isset( $row['meta_id'], $row['meta_key'], $row['meta_value'] ) || ! is_string( $row['meta_id'] ) || ! is_string( $row['meta_key'] ) || ! is_string( $row['meta_value'] ) ) {
			return null;
		}

		$result[] = array(
			'meta_id'    => $row['meta_id'],
			'meta_key'   => $row['meta_key'],
			'meta_value' => $row['meta_value'],
		);
	}

	return $result;
}

/**
 * Read one persisted post row with stable database-column ordering.
 *
 * @param int $post_id Post ID.
 * @return array<string, string|null>|null Exact row, or null on failure.
 */
function presenter_rehearsal_post_row( int $post_id ): ?array {
	global $wpdb;

	$query = $wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d LIMIT 1', $wpdb->posts, $post_id );
	if ( ! is_string( $query ) ) {
		return null;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Full persisted-row equality is required for the private restore fingerprint; the query is prepared immediately above.
	$row = $wpdb->get_row( $query, 'ARRAY_A' );

	return is_array( $row ) ? $row : null;
}

/**
 * Read exact taxonomy relationships without returning raw term IDs.
 *
 * @param int $post_id Object ID.
 * @return array<int, array<string, string|null>>|null Ordered rows, or null on failure.
 */
function presenter_rehearsal_taxonomy_rows( int $post_id ): ?array {
	global $wpdb;

	$query = $wpdb->prepare(
		'SELECT relationships.term_taxonomy_id, relationships.term_order,
			taxonomy.term_id, taxonomy.taxonomy, taxonomy.description, taxonomy.parent, taxonomy.count,
			terms.name, terms.slug, terms.term_group
		FROM %i AS relationships
		INNER JOIN %i AS taxonomy ON taxonomy.term_taxonomy_id = relationships.term_taxonomy_id
		INNER JOIN %i AS terms ON terms.term_id = taxonomy.term_id
		WHERE relationships.object_id = %d
		ORDER BY relationships.term_taxonomy_id ASC',
		$wpdb->term_relationships,
		$wpdb->term_taxonomy,
		$wpdb->terms,
		$post_id
	);
	if ( ! is_string( $query ) ) {
		return null;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Exact taxonomy state belongs in the authored restore fingerprint; the query is prepared immediately above.
	$rows = $wpdb->get_results( $query, 'ARRAY_A' );

	return is_array( $rows ) ? $rows : null;
}

/**
 * Read every persisted revision row and its complete ordered metadata.
 *
 * @param int $post_id Parent slideshow ID.
 * @return array<int, array{row: array<string, string|null>, meta: array<int, array{meta_id: string, meta_key: string, meta_value: string}>}>|null Revisions, or null on failure.
 */
function presenter_rehearsal_revision_rows( int $post_id ): ?array {
	global $wpdb;

	$query = $wpdb->prepare(
		'SELECT * FROM %i WHERE post_parent = %d AND post_type = %s ORDER BY ID ASC',
		$wpdb->posts,
		$post_id,
		'revision'
	);
	if ( ! is_string( $query ) ) {
		return null;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Rehearsal restore must distinguish baseline and migration revisions byte for byte; the query is prepared immediately above.
	$rows = $wpdb->get_results( $query, 'ARRAY_A' );
	if ( ! is_array( $rows ) ) {
		return null;
	}

	$result = array();
	foreach ( $rows as $row ) {
		if ( ! isset( $row['ID'] ) || ! is_numeric( $row['ID'] ) ) {
			return null;
		}

		$meta = presenter_rehearsal_meta_rows( (int) $row['ID'] );
		if ( null === $meta ) {
			return null;
		}

		$result[] = array(
			'row'  => $row,
			'meta' => $meta,
		);
	}

	return $result;
}

/**
 * Summarize one exact ordered metadata set.
 *
 * @param string                                                                   $key     Acceptance HMAC key.
 * @param string                                                                   $purpose HMAC purpose.
 * @param array<int, array{meta_id: string, meta_key: string, meta_value: string}> $rows Exact metadata rows.
 * @return array{exists: bool, rowCount: int, valuesDigest: string} Content-free summary.
 */
function presenter_rehearsal_meta_summary( string $key, string $purpose, array $rows ): array {
	return array(
		'exists'       => array() !== $rows,
		'rowCount'     => count( $rows ),
		'valuesDigest' => presenter_rehearsal_hmac( $key, $purpose, array_column( $rows, 'meta_value' ) ),
	);
}

/**
 * Recursively count persisted blocks and structural Presenter names.
 *
 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
 * @return array{total: int, deck: int, slide: int} Counts.
 */
function presenter_rehearsal_block_counts( array $blocks ): array {
	$counts = array(
		'total' => 0,
		'deck'  => 0,
		'slide' => 0,
	);

	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? null;
		$html = $block['innerHTML'] ?? '';
		if ( null !== $name || ( is_string( $html ) && '' !== trim( $html ) ) ) {
			++$counts['total'];
		}
		if ( 'presenter/deck' === $name ) {
			++$counts['deck'];
		} elseif ( 'presenter/slide' === $name ) {
			++$counts['slide'];
		}

		$children = $block['innerBlocks'] ?? array();
		if ( is_array( $children ) ) {
			$child_counts     = presenter_rehearsal_block_counts( $children );
			$counts['total'] += $child_counts['total'];
			$counts['deck']  += $child_counts['deck'];
			$counts['slide'] += $child_counts['slide'];
		}
	}

	return $counts;
}

/**
 * Classify a block name without emitting third-party or malformed names.
 *
 * @param mixed $name Parsed block name.
 * @return string Fixed classification.
 */
function presenter_rehearsal_block_name( mixed $name ): string {
	if ( 'presenter/deck' === $name ) {
		return 'presenter/deck';
	}
	if ( 'presenter/slide' === $name ) {
		return 'presenter/slide';
	}

	return null === $name ? 'none' : 'other';
}

/**
 * Validate the complete success envelope before output.
 *
 * @param array<string, mixed> $state Candidate envelope.
 * @return bool Whether the public schema is exact and content-free.
 */
function presenter_rehearsal_valid_output( array $state ): bool {
	$digest = static fn( mixed $value ): bool => is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value );
	$keys   = static fn( array $value, array $expected ): bool => array_keys( $value ) === $expected;

	if (
		! $keys( $state, array( 'schemaVersion', 'status', 'postId', 'accessClass', 'post', 'legacyMeta', 'nonMigrationMeta', 'taxonomy', 'migrationArtifacts', 'journal', 'marker', 'lock', 'revisions', 'blocks', 'authoredStateDigest' ) )
		|| PRESENTER_REHEARSAL_SCHEMA_VERSION !== $state['schemaVersion']
		|| 'pass' !== $state['status']
		|| ! is_int( $state['postId'] )
		|| $state['postId'] < 1
		|| ! in_array( $state['accessClass'], array( 'public', 'protected', 'nonpublic' ), true )
		|| ! is_array( $state['post'] )
		|| ! is_array( $state['legacyMeta'] )
		|| ! is_array( $state['migrationArtifacts'] )
		|| ! $digest( $state['authoredStateDigest'] )
	) {
		return false;
	}

	if (
		! $keys( $state['post'], array( 'rowDigest', 'fields', 'timestamps' ) )
		|| ! $digest( $state['post']['rowDigest'] )
		|| ! is_array( $state['post']['fields'] )
		|| ! $keys( $state['post']['fields'], array( 'contentDigest', 'titleDigest', 'excerptDigest', 'nameDigest', 'statusDigest', 'passwordDigest', 'menuOrderDigest' ) )
		|| ! is_array( $state['post']['timestamps'] )
		|| ! $keys( $state['post']['timestamps'], array( 'modifiedDigest', 'modifiedGmtDigest' ) )
	) {
		return false;
	}
	foreach ( array( 'fields', 'timestamps' ) as $section ) {
		if ( ! is_array( $state['post'][ $section ] ) ) {
			return false;
		}
		foreach ( $state['post'][ $section ] as $value ) {
			if ( ! $digest( $value ) ) {
				return false;
			}
		}
	}

	if ( ! $keys( $state['legacyMeta'], array( 'slides', 'theme', 'shortUrl' ) ) ) {
		return false;
	}
	foreach ( array( 'slides', 'theme', 'shortUrl' ) as $name ) {
		$summary = $state['legacyMeta'][ $name ] ?? null;
		if ( ! is_array( $summary ) || ! $keys( $summary, array( 'exists', 'rowCount', 'valuesDigest' ) ) || ! is_bool( $summary['exists'] ) || ! is_int( $summary['rowCount'] ) || $summary['rowCount'] < 0 || ( 0 < $summary['rowCount'] ) !== $summary['exists'] || ! $digest( $summary['valuesDigest'] ) ) {
			return false;
		}
	}

	foreach ( array( 'nonMigrationMeta', 'taxonomy' ) as $name ) {
		$summary  = $state[ $name ];
		$expected = 'nonMigrationMeta' === $name ? array( 'rowCount', 'keyCount', 'digest' ) : array( 'rowCount', 'digest' );
		if ( ! is_array( $summary ) || ! $keys( $summary, $expected ) || ! is_int( $summary['rowCount'] ?? null ) || $summary['rowCount'] < 0 || ! $digest( $summary['digest'] ?? null ) ) {
			return false;
		}
	}
	if ( ! is_int( $state['nonMigrationMeta']['keyCount'] ?? null ) || $state['nonMigrationMeta']['keyCount'] < 0 || $state['nonMigrationMeta']['keyCount'] > $state['nonMigrationMeta']['rowCount'] ) {
		return false;
	}

	if ( ! $keys( $state['migrationArtifacts'], array( 'backup', 'journal', 'marker' ) ) ) {
		return false;
	}
	foreach ( array( 'backup', 'journal', 'marker' ) as $name ) {
		$summary = $state['migrationArtifacts'][ $name ] ?? null;
		if ( ! is_array( $summary ) || ! $keys( $summary, array( 'rowCount', 'digest' ) ) || ! is_int( $summary['rowCount'] ?? null ) || $summary['rowCount'] < 0 || ! $digest( $summary['digest'] ?? null ) ) {
			return false;
		}
	}

	return is_array( $state['journal'] )
		&& $keys( $state['journal'], array( 'state', 'sequence' ) )
		&& in_array( $state['journal']['state'] ?? null, array( 'none', 'apply_prepared', 'applied', 'apply_rolled_back', 'restore_prepared', 'restored', 'recovery_required', 'invalid' ), true )
		&& is_int( $state['journal']['sequence'] ?? null )
		&& 0 <= $state['journal']['sequence']
		&& is_array( $state['marker'] )
		&& $keys( $state['marker'], array( 'state', 'rowCount' ) )
		&& in_array( $state['marker']['state'] ?? null, array( 'absent', 'native', 'invalid' ), true )
		&& is_int( $state['marker']['rowCount'] ?? null )
		&& 0 <= $state['marker']['rowCount']
		&& $state['marker']['rowCount'] === $state['migrationArtifacts']['marker']['rowCount']
		&& is_array( $state['lock'] )
		&& $keys( $state['lock'], array( 'presence' ) )
		&& in_array( $state['lock']['presence'] ?? null, array( 'absent', 'present' ), true )
		&& is_array( $state['revisions'] )
		&& $keys( $state['revisions'], array( 'count', 'records', 'recordsDigest' ) )
		&& is_int( $state['revisions']['count'] ?? null )
		&& 0 <= $state['revisions']['count']
		&& is_array( $state['revisions']['records'] ?? null )
		&& count( $state['revisions']['records'] ) === $state['revisions']['count']
		&& array_reduce(
			$state['revisions']['records'],
			static fn( bool $valid, mixed $value ): bool => $valid
				&& is_array( $value )
				&& array_keys( $value ) === array( 'idDigest', 'recordDigest' )
				&& $digest( $value['idDigest'] )
				&& $digest( $value['recordDigest'] ),
			true
		)
		&& $digest( $state['revisions']['recordsDigest'] ?? null )
		&& is_array( $state['blocks'] )
		&& $keys( $state['blocks'], array( 'parseState', 'rootCount', 'deckCount', 'slideCount', 'totalCount', 'rootName', 'slideName' ) )
		&& in_array( $state['blocks']['parseState'] ?? null, array( 'empty', 'legacy', 'native', 'invalid' ), true )
		&& in_array( $state['blocks']['rootName'] ?? null, array( 'none', 'other', 'presenter/deck', 'presenter/slide' ), true )
		&& in_array( $state['blocks']['slideName'] ?? null, array( 'none', 'other', 'presenter/slide' ), true )
		&& is_int( $state['blocks']['rootCount'] ?? null )
		&& is_int( $state['blocks']['deckCount'] ?? null )
		&& is_int( $state['blocks']['slideCount'] ?? null )
		&& is_int( $state['blocks']['totalCount'] ?? null )
		&& 0 <= $state['blocks']['rootCount']
		&& 0 <= $state['blocks']['deckCount']
		&& 0 <= $state['blocks']['slideCount']
		&& 0 <= $state['blocks']['totalCount'];
}

try {
	$post_id_raw = getenv( 'PRESENTER_REHEARSAL_POST_ID' );
	if ( ! is_string( $post_id_raw ) || 1 !== preg_match( '/^[1-9][0-9]*$/', $post_id_raw ) ) {
		presenter_rehearsal_fail( 'invalid_target' );
	}
	$target_post_id = (int) $post_id_raw;
	$target_post    = get_post( $target_post_id );
	if ( ! $target_post instanceof WP_Post || 'slideshow' !== $target_post->post_type ) {
		presenter_rehearsal_fail( 'invalid_target' );
	}

	if ( 'local' !== wp_get_environment_type() ) {
		presenter_rehearsal_fail( 'invalid_environment' );
	}

	$key_data = file_get_contents( 'php://stdin' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The private key is piped from storage outside the web-mounted repository and is never displayed.
	$key      = is_string( $key_data ) ? rtrim( $key_data, "\r\n" ) : '';
	if ( strlen( $key ) < 32 ) {
		presenter_rehearsal_fail( 'key_unavailable' );
	}

	$post_row      = presenter_rehearsal_post_row( $target_post_id );
	$meta_rows     = presenter_rehearsal_meta_rows( $target_post_id );
	$taxonomy_rows = presenter_rehearsal_taxonomy_rows( $target_post_id );
	$revisions     = presenter_rehearsal_revision_rows( $target_post_id );
	if ( null === $post_row || null === $meta_rows || null === $taxonomy_rows || null === $revisions ) {
		presenter_rehearsal_fail( 'query_failed' );
	}

	$legacy_keys   = array(
		'slides'   => '_presenter_slides',
		'theme'    => '_presenter-theme',
		'shortUrl' => '_presenter-short-url',
	);
	$artifact_keys = array(
		'backup'  => Migration_Backup_Store::META_KEY,
		'journal' => Migration_Journal::META_KEY,
		'marker'  => Deck_Mode::META_KEY,
	);
	$legacy_rows   = array_fill_keys( array_keys( $legacy_keys ), array() );
	$artifact_rows = array_fill_keys( array_keys( $artifact_keys ), array() );
	$authored_meta = array();

	foreach ( $meta_rows as $row ) {
		$legacy_name = array_search( $row['meta_key'], $legacy_keys, true );
		if ( is_string( $legacy_name ) ) {
			$legacy_rows[ $legacy_name ][] = $row;
		}

		$artifact_name = array_search( $row['meta_key'], $artifact_keys, true );
		if ( is_string( $artifact_name ) ) {
			$artifact_rows[ $artifact_name ][] = $row;
			continue;
		}

		$authored_meta[] = $row;
	}

	$marker_values = array_column( $artifact_rows['marker'], 'meta_value' );
	$marker_state  = array() === $marker_values
		? 'absent'
		: ( array( Deck_Mode::NATIVE ) === $marker_values ? 'native' : 'invalid' );

	$journal_state    = 'none';
	$journal_sequence = 0;
	if ( array() !== $artifact_rows['journal'] ) {
		$secret = ( new Migration_Secret() )->read();
		if ( null === $secret ) {
			$journal_state = 'invalid';
		} else {
			$journal = ( new Migration_Journal( new Migration_Hasher( $secret ) ) )->inspect( $target_post_id );
			if ( $journal['valid'] && is_string( $journal['state'] ) ) {
				$journal_state    = $journal['state'];
				$journal_sequence = $journal['sequence'];
			} else {
				$journal_state = 'invalid';
			}
		}
	}

	$parsed          = parse_blocks( $target_post->post_content );
	$effective_roots = array_values(
		array_filter(
			$parsed,
			static fn( array $block ): bool => null !== $block['blockName'] || '' !== trim( $block['innerHTML'] )
		)
	);
	$block_counts    = presenter_rehearsal_block_counts( $effective_roots );
	$root            = $effective_roots[0] ?? null;
	$root_children   = is_array( $root ) ? $root['innerBlocks'] : array();
	$root_name       = presenter_rehearsal_block_name( is_array( $root ) ? ( $root['blockName'] ?? null ) : null );
	$slide_name      = presenter_rehearsal_block_name( $root_children[0]['blockName'] ?? null );
	$has_deck        = 0 < $block_counts['deck'];
	$parse_state     = '' === trim( $target_post->post_content )
		? 'empty'
		: ( ( new Native_Deck_Structure() )->is_valid( $target_post->post_content ) ? 'native' : ( $has_deck ? 'invalid' : 'legacy' ) );

	$access_class      = 'publish' !== $target_post->post_status
		? 'nonpublic'
		: ( '' !== $target_post->post_password ? 'protected' : 'public' );
	$revision_records  = array_map(
		static fn( array $revision ): array => array(
			'idDigest'     => presenter_rehearsal_hmac( $key, 'revision-id', $revision['row']['ID'] ?? null ),
			'recordDigest' => presenter_rehearsal_hmac( $key, 'revision-record', $revision ),
		),
		$revisions
	);
	$nonmigration_keys = array_values( array_unique( array_column( $authored_meta, 'meta_key' ) ) );

	$authored_state = array(
		'post'     => $post_row,
		'meta'     => $authored_meta,
		'taxonomy' => $taxonomy_rows,
	);

	$state = array(
		'schemaVersion'       => PRESENTER_REHEARSAL_SCHEMA_VERSION,
		'status'              => 'pass',
		'postId'              => $target_post_id,
		'accessClass'         => $access_class,
		'post'                => array(
			'rowDigest'  => presenter_rehearsal_hmac( $key, 'post-row', $post_row ),
			'fields'     => array(
				'contentDigest'   => presenter_rehearsal_hmac( $key, 'post-content', $post_row['post_content'] ?? null ),
				'titleDigest'     => presenter_rehearsal_hmac( $key, 'post-title', $post_row['post_title'] ?? null ),
				'excerptDigest'   => presenter_rehearsal_hmac( $key, 'post-excerpt', $post_row['post_excerpt'] ?? null ),
				'nameDigest'      => presenter_rehearsal_hmac( $key, 'post-name', $post_row['post_name'] ?? null ),
				'statusDigest'    => presenter_rehearsal_hmac( $key, 'post-status', $post_row['post_status'] ?? null ),
				'passwordDigest'  => presenter_rehearsal_hmac( $key, 'post-password', $post_row['post_password'] ?? null ),
				'menuOrderDigest' => presenter_rehearsal_hmac( $key, 'post-menu-order', $post_row['menu_order'] ?? null ),
			),
			'timestamps' => array(
				'modifiedDigest'    => presenter_rehearsal_hmac( $key, 'post-modified', $post_row['post_modified'] ?? null ),
				'modifiedGmtDigest' => presenter_rehearsal_hmac( $key, 'post-modified-gmt', $post_row['post_modified_gmt'] ?? null ),
			),
		),
		'legacyMeta'          => array(
			'slides'   => presenter_rehearsal_meta_summary( $key, 'legacy-slides', $legacy_rows['slides'] ),
			'theme'    => presenter_rehearsal_meta_summary( $key, 'legacy-theme', $legacy_rows['theme'] ),
			'shortUrl' => presenter_rehearsal_meta_summary( $key, 'legacy-short-url', $legacy_rows['shortUrl'] ),
		),
		'nonMigrationMeta'    => array(
			'rowCount' => count( $authored_meta ),
			'keyCount' => count( $nonmigration_keys ),
			'digest'   => presenter_rehearsal_hmac( $key, 'non-migration-meta', $authored_meta ),
		),
		'taxonomy'            => array(
			'rowCount' => count( $taxonomy_rows ),
			'digest'   => presenter_rehearsal_hmac( $key, 'taxonomy', $taxonomy_rows ),
		),
		'migrationArtifacts'  => array(
			'backup'  => array(
				'rowCount' => count( $artifact_rows['backup'] ),
				'digest'   => presenter_rehearsal_hmac( $key, 'migration-backup', $artifact_rows['backup'] ),
			),
			'journal' => array(
				'rowCount' => count( $artifact_rows['journal'] ),
				'digest'   => presenter_rehearsal_hmac( $key, 'migration-journal', $artifact_rows['journal'] ),
			),
			'marker'  => array(
				'rowCount' => count( $artifact_rows['marker'] ),
				'digest'   => presenter_rehearsal_hmac( $key, 'migration-marker', $artifact_rows['marker'] ),
			),
		),
		'journal'             => array(
			'state'    => $journal_state,
			'sequence' => $journal_sequence,
		),
		'marker'              => array(
			'state'    => $marker_state,
			'rowCount' => count( $marker_values ),
		),
		'lock'                => array(
			'presence' => null === get_option( 'presenter_migration_lock_' . $target_post_id, null ) ? 'absent' : 'present',
		),
		'revisions'           => array(
			'count'         => count( $revisions ),
			'records'       => $revision_records,
			'recordsDigest' => presenter_rehearsal_hmac( $key, 'revisions', $revisions ),
		),
		'blocks'              => array(
			'parseState' => $parse_state,
			'rootCount'  => count( $effective_roots ),
			'deckCount'  => $block_counts['deck'],
			'slideCount' => $block_counts['slide'],
			'totalCount' => $block_counts['total'],
			'rootName'   => $root_name,
			'slideName'  => 'presenter/deck' === $slide_name ? 'other' : $slide_name,
		),
		'authoredStateDigest' => presenter_rehearsal_hmac( $key, 'authored-state', $authored_state ),
	);

	if ( ! presenter_rehearsal_valid_output( $state ) ) {
		presenter_rehearsal_fail( 'invalid_output' );
	}

	$json = wp_json_encode( $state, JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $json ) ) {
		presenter_rehearsal_fail( 'invalid_output' );
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This is schema-validated, content-free JSON for a local CLI consumer, not HTML.
	echo $json;
} catch ( Throwable ) {
	presenter_rehearsal_fail( 'internal_error' );
}
