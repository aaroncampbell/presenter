<?php
/**
 * Collect content-free assertions for the private snapshot preflight.
 *
 * This file is executed only with `wp eval-file` in the isolated local
 * snapshot. Its output deliberately contains counts and fixed enums only.
 *
 * @package Presenter
 */

declare( strict_types=1 );

const PRESENTER_SNAPSHOT_ORIGIN = 'http://localhost:8890';
const PRESENTER_SNAPSHOT_PREFIX = 'NVwKgD_';

/**
 * Stop with a fixed, content-free failure code.
 *
 * @param string $code Stable failure code.
 * @return never
 */
function presenter_snapshot_preflight_fail( string $code ): never {
	echo wp_json_encode(
		array(
			'status' => 'fail',
			'code'   => $code,
		)
	);
	exit( 1 );
}

global $wpdb;

if (
	PRESENTER_SNAPSHOT_PREFIX !== $wpdb->prefix ||
	PRESENTER_SNAPSHOT_PREFIX !== $wpdb->base_prefix
) {
	presenter_snapshot_preflight_fail( 'database_prefix' );
}

if (
	PRESENTER_SNAPSHOT_ORIGIN !== get_option( 'home' ) ||
	PRESENTER_SNAPSHOT_ORIGIN !== get_option( 'siteurl' ) ||
	'local' !== wp_get_environment_type()
) {
	presenter_snapshot_preflight_fail( 'local_environment' );
}

if ( '0' !== (string) get_option( 'blog_public' ) ) {
	presenter_snapshot_preflight_fail( 'public_indexing' );
}

$expected_plugins = array(
	'aarondcampbell-presenter-themes/aarondcampbell-presenter-themes.php',
	'presenter/presenter.php',
);
$active_plugins   = get_option( 'active_plugins', array() );

if ( ! is_array( $active_plugins ) ) {
	presenter_snapshot_preflight_fail( 'active_plugins' );
}

sort( $active_plugins, SORT_STRING );
if ( $expected_plugins !== $active_plugins ) {
	presenter_snapshot_preflight_fail( 'active_plugins' );
}

$mu_plugins = get_mu_plugins();
if (
	1 !== count( $mu_plugins ) ||
	! isset( $mu_plugins['presenter-snapshot-safety.php'] )
) {
	presenter_snapshot_preflight_fail( 'mu_plugin' );
}

$required_true_constants = array(
	'AUTOMATIC_UPDATER_DISABLED',
	'DISABLE_WP_CRON',
	'DISALLOW_FILE_EDIT',
	'DISALLOW_FILE_MODS',
	'SCRIPT_DEBUG',
	'WP_DEBUG',
	'WP_DEBUG_LOG',
	'WP_HTTP_BLOCK_EXTERNAL',
);

foreach ( $required_true_constants as $constant_name ) {
	if ( ! defined( $constant_name ) || true !== constant( $constant_name ) ) {
		presenter_snapshot_preflight_fail( 'configuration' );
	}
}

if (
	! defined( 'WP_AUTO_UPDATE_CORE' ) ||
	false !== WP_AUTO_UPDATE_CORE ||
	! defined( 'WP_ACCESSIBLE_HOSTS' ) ||
	'localhost,127.0.0.1,::1' !== WP_ACCESSIBLE_HOSTS ||
	! defined( 'WP_DEBUG_DISPLAY' ) ||
	false !== WP_DEBUG_DISPLAY ||
	! defined( 'WP_DEVELOPMENT_MODE' ) ||
	'plugin' !== WP_DEVELOPMENT_MODE
) {
	presenter_snapshot_preflight_fail( 'configuration' );
}

if (
	false === has_filter( 'pre_wp_mail', '__return_true' ) ||
	false === has_filter( 'pre_http_request', 'presenter_snapshot_preempt_http_request' ) ||
	false === has_filter( 'wp_sitemaps_enabled', '__return_false' ) ||
	false === has_action( 'send_headers', 'presenter_snapshot_send_safety_headers' )
) {
	presenter_snapshot_preflight_fail( 'safety_filters' );
}

$mail_result       = apply_filters(
	'pre_wp_mail',
	null,
	array(
		'to'          => array( 'preflight@example.invalid' ),
		'subject'     => 'preflight',
		'message'     => 'preflight',
		'headers'     => array(),
		'attachments' => array(),
	)
);
$http_result       = apply_filters(
	'pre_http_request',
	false,
	array(),
	'https://preflight.example.invalid/'
);
$local_http_result = apply_filters(
	'pre_http_request',
	false,
	array(),
	PRESENTER_SNAPSHOT_ORIGIN . '/'
);

if (
	true !== $mail_result ||
	! is_wp_error( $http_result ) ||
	'presenter_snapshot_external_request_blocked' !== $http_result->get_error_code() ||
	false !== $local_http_result
) {
	presenter_snapshot_preflight_fail( 'side_effect_preemption' );
}

$administrator = get_user_by( 'login', 'presenter-local' );
if (
	false === $administrator ||
	! in_array( 'administrator', (array) $administrator->roles, true )
) {
	presenter_snapshot_preflight_fail( 'local_administrator' );
}

$administrator_count = count(
	get_users(
		array(
			'fields' => 'ID',
			'role'   => 'administrator',
		)
	)
);

$corpus_path = dirname( __DIR__, 2 ) . '/local/acceptance-corpus/corpus.json';
$corpus_json = file_get_contents( $corpus_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local, mounted fixture only.
$corpus      = is_string( $corpus_json ) ? json_decode( $corpus_json, true ) : null;

if ( ! is_array( $corpus ) || ! isset( $corpus['decks'] ) || ! is_array( $corpus['decks'] ) ) {
	presenter_snapshot_preflight_fail( 'corpus_manifest' );
}

$post_ids = array();
foreach ( $corpus['decks'] as $deck ) {
	if ( ! is_array( $deck ) || ! isset( $deck['postId'] ) || ! is_int( $deck['postId'] ) ) {
		presenter_snapshot_preflight_fail( 'corpus_manifest' );
	}

	$post_ids[] = $deck['postId'];
}

$post_ids = array_values( array_unique( $post_ids ) );
if ( count( $post_ids ) !== count( $corpus['decks'] ) || array() === $post_ids ) {
	presenter_snapshot_preflight_fail( 'corpus_manifest' );
}

$legacy_query = $wpdb->prepare(
	'SELECT DISTINCT posts.ID
	FROM %i AS posts
	INNER JOIN %i AS postmeta ON postmeta.post_id = posts.ID
	WHERE posts.post_type = %s
		AND posts.post_status NOT IN (%s, %s, %s)
		AND postmeta.meta_key = %s
	ORDER BY posts.ID ASC',
	$wpdb->posts,
	$wpdb->postmeta,
	'slideshow',
	'auto-draft',
	'inherit',
	'trash',
	'_presenter_slides'
);
if ( ! is_string( $legacy_query ) ) {
	presenter_snapshot_preflight_fail( 'legacy_corpus' );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Snapshot preflight must bypass third-party front-end filters that intentionally hide password-protected decks; the query is prepared immediately above.
$legacy_post_ids = array_map( 'intval', $wpdb->get_col( $legacy_query ) );

if (
	65 !== count( $legacy_post_ids ) ||
	array_diff( $post_ids, $legacy_post_ids )
) {
	presenter_snapshot_preflight_fail( 'legacy_corpus' );
}

$migration_meta_keys = array(
	'_presenter_deck_mode',
	'_presenter_migration_backup_v1',
	'_presenter_migration_journal_v1',
);
$artifact_count      = 0;
$lock_count          = 0;

foreach ( $legacy_post_ids as $corpus_post_id ) {
	foreach ( $migration_meta_keys as $meta_key ) {
		if ( metadata_exists( 'post', $corpus_post_id, $meta_key ) ) {
			++$artifact_count;
		}
	}

	if ( false !== get_option( 'presenter_migration_lock_' . $corpus_post_id, false ) ) {
		++$lock_count;
	}
}

if ( 0 !== $artifact_count || 0 !== $lock_count ) {
	presenter_snapshot_preflight_fail( 'migration_footprint' );
}

echo wp_json_encode(
	array(
		'status' => 'pass',
		'checks' => array(
			'configuration'  => 'pass',
			'database'       => 'pass',
			'environment'    => 'pass',
			'migrationState' => 'clean',
			'plugins'        => 'allowlisted',
			'sideEffects'    => 'preempted',
		),
		'counts' => array(
			'administrators'     => $administrator_count,
			'legacyCorpusDecks'  => count( $legacy_post_ids ),
			'migrationArtifacts' => $artifact_count + $lock_count,
			'muPlugins'          => count( $mu_plugins ),
			'regularPlugins'     => count( $active_plugins ),
		),
	)
);
