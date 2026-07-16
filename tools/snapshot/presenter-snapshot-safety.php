<?php
/**
 * Prevent production-derived snapshot data from causing external side effects.
 *
 * This file is mounted only in the private snapshot environment. It is not part
 * of the Presenter release package.
 *
 * @package Presenter
 */

declare( strict_types=1 );

/**
 * Treat mail as successfully handled without sending it.
 */
add_filter( 'pre_wp_mail', '__return_true' );

/**
 * Block server-side HTTP except for explicitly local destinations.
 *
 * @param false|array|WP_Error $response Preemptive response.
 * @param array                $arguments Request arguments.
 * @param string               $url       Request URL.
 * @return false|array|WP_Error
 */
function presenter_snapshot_preempt_http_request( $response, array $arguments, string $url ) {
	unset( $arguments );

	$host          = wp_parse_url( $url, PHP_URL_HOST );
	$allowed_hosts = array( 'localhost', '127.0.0.1', '::1' );

	if ( is_string( $host ) && in_array( strtolower( $host ), $allowed_hosts, true ) ) {
		return $response;
	}

	return new WP_Error(
		'presenter_snapshot_external_request_blocked',
		__( 'External HTTP is disabled in the Presenter snapshot environment.', 'presenter' )
	);
}
add_filter( 'pre_http_request', 'presenter_snapshot_preempt_http_request', 10, 3 );

/**
 * Prevent indexing and browser-side calls to production services.
 */
function presenter_snapshot_send_safety_headers(): void {
	if ( headers_sent() ) {
		return;
	}

	header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
	header(
		// Reveal.js 4's UMD build requires eval; this policy is snapshot-only and
		// still prevents scripts, connections, and media from external hosts.
		"Content-Security-Policy: default-src 'self'; base-uri 'self'; connect-src 'self'; font-src 'self' data:; frame-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; object-src 'none'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline';",
		true
	);
}
add_action( 'send_headers', 'presenter_snapshot_send_safety_headers' );

add_filter( 'wp_sitemaps_enabled', '__return_false' );
