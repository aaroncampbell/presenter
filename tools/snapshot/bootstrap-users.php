<?php
/**
 * Replace imported credentials with local-only credentials.
 *
 * The administrator password is read from standard input so it never appears
 * in a process argument or WP-CLI's command output.
 *
 * @package Presenter
 */

if ( 'cli' !== PHP_SAPI || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This helper may only run through WP-CLI.' );
}

global $wpdb;

if ( 'NVwKgD_' !== $wpdb->prefix ) {
	throw new RuntimeException( 'Refusing to modify users under an unexpected table prefix.' );
}

if ( 'http://localhost:8890' !== get_option( 'home' ) || 'http://localhost:8890' !== get_option( 'siteurl' ) ) {
	throw new RuntimeException( 'Refusing to modify users before local URLs are established.' );
}

$password = stream_get_contents( STDIN );
if ( false === $password || 16 > strlen( $password ) ) {
	throw new RuntimeException( 'A local administrator password of at least 16 bytes is required on standard input.' );
}

$user_ids = get_users( array( 'fields' => 'ID' ) );
foreach ( $user_ids as $user_id ) {
	wp_set_password( wp_generate_password( 64, true, true ), (int) $user_id );
}

$login = 'presenter-local';
$email = 'presenter-local@localhost.invalid';
$user  = get_user_by( 'login', $login );

if ( false === $user ) {
	$user_id = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => $password,
			'user_email'   => $email,
			'display_name' => 'Presenter Local',
			'role'         => 'administrator',
		)
	);

	if ( is_wp_error( $user_id ) ) {
		throw new RuntimeException( 'Unable to create the local administrator.' );
	}

	$user = get_user_by( 'id', (int) $user_id );
} else {
	$result = wp_update_user(
		array(
			'ID'           => $user->ID,
			'user_email'   => $email,
			'display_name' => 'Presenter Local',
		)
	);

	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( 'Unable to reset the local administrator.' );
	}

	wp_set_password( $password, $user->ID );
	$user = get_user_by( 'id', $user->ID );
}

if ( false === $user ) {
	throw new RuntimeException( 'Unable to load the local administrator.' );
}

$user->set_role( 'administrator' );
update_option( 'admin_email', $email );
delete_option( 'new_admin_email' );
$six_months_in_seconds = 6 * 30 * 24 * 60 * 60;
update_option( 'admin_email_lifespan', time() + $six_months_in_seconds );

WP_CLI::line( 'PRESENTER_SNAPSHOT_USERS_READY' );
