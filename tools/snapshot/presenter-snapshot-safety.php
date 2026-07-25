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
		"Content-Security-Policy: default-src 'self'; base-uri 'self'; connect-src 'self'; font-src 'self' data:; frame-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; object-src 'none'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; worker-src 'self' blob:;",
		true
	);
}
add_action( 'send_headers', 'presenter_snapshot_send_safety_headers' );

/**
 * Replace historical external chart library URLs with snapshot-local assets.
 *
 * The production-derived decks contain these exact script URLs in their saved
 * HTML. Rewriting only the matching script src attributes keeps the snapshot's
 * restrictive CSP intact without changing deck content or production behavior.
 *
 * @param string $html Complete slideshow response HTML.
 * @return string
 */
function presenter_snapshot_rewrite_chart_script_urls( string $html ): string {
	$script_urls = array(
		'https://www.gstatic.com/charts/loader.js' => '/wp-content/presenter-snapshot-assets/google-charts/loader.js',
		'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.5.1/chart.min.js' => '/wp-content/presenter-snapshot-assets/chart.js/3.5.1/chart.min.js',
	);

	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $html;
	}

	$processor = new WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag( 'script' ) ) {
		$source = $processor->get_attribute( 'src' );

		if ( is_string( $source ) && isset( $script_urls[ $source ] ) ) {
			$processor->set_attribute( 'src', $script_urls[ $source ] );
		}
	}

	return $processor->get_updated_html();
}

/**
 * Start rewriting the final response only for slideshow front-end requests.
 */
function presenter_snapshot_start_chart_script_rewrite(): void {
	if ( ! is_singular( 'slideshow' ) ) {
		return;
	}

	ob_start( 'presenter_snapshot_rewrite_chart_script_urls' );
}
add_action( 'template_redirect', 'presenter_snapshot_start_chart_script_rewrite', 0 );

add_filter( 'wp_sitemaps_enabled', '__return_false' );
