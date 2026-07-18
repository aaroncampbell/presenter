<?php
/**
 * Fail closed unless the production-derived snapshot is locally safe.
 *
 * @package Presenter
 */

if ( 'cli' !== PHP_SAPI || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This helper may only run through WP-CLI.' );
}

global $wpdb;

$password       = stream_get_contents( STDIN );
$local_origin   = 'http://localhost:8890';
$local_email    = 'presenter-local@localhost.invalid';
$active_plugins = get_option( 'active_plugins', array() );
$expected       = array(
	'aarondcampbell-presenter-themes/aarondcampbell-presenter-themes.php',
	'presenter/presenter.php',
);

sort( $active_plugins );
sort( $expected );

$user = get_user_by( 'login', 'presenter-local' );

$assertions = array(
	'unexpected database prefix'              => 'NVwKgD_' === $wpdb->prefix,
	'non-local home URL'                      => 'http://localhost:8890' === get_option( 'home' ),
	'non-local site URL'                      => 'http://localhost:8890' === get_option( 'siteurl' ),
	'public indexing remains enabled'         => '0' === (string) get_option( 'blog_public' ),
	'unapproved active plugin list'           => $expected === $active_plugins,
	'snapshot safety MU plugin is not loaded' => function_exists( 'presenter_snapshot_preempt_http_request' ),
	'external HTTP is not blocked'            => defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && true === WP_HTTP_BLOCK_EXTERNAL,
	'file modifications are not blocked'      => defined( 'DISALLOW_FILE_MODS' ) && true === DISALLOW_FILE_MODS,
	'environment is not local'                => function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type(),
	'local administrator is missing'          => $user instanceof WP_User,
	'local administrator email is unexpected' => $user instanceof WP_User && $local_email === $user->user_email,
	'local administrator lacks capability'    => $user instanceof WP_User && $user->has_cap( 'manage_options' ),
	'local administrator password is invalid' => $user instanceof WP_User && is_string( $password ) && wp_check_password( $password, $user->user_pass, $user->ID ),
	'admin email is not local'                => 'presenter-local@localhost.invalid' === get_option( 'admin_email' ),
	'admin email confirmation is stale'       => time() < (int) get_option( 'admin_email_lifespan' ),
);

foreach ( $assertions as $failure => $passed ) {
	if ( ! $passed ) {
		throw new RuntimeException( 'Snapshot safety assertion failed: ' . esc_html( $failure ) . '.' );
	}
}

WP_CLI::line( 'PRESENTER_SNAPSHOT_BOOTSTRAP_OK' );
