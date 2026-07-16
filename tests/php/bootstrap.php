<?php
/**
 * Bootstrap the WordPress integration test suite.
 *
 * @package Presenter
 */

$presenter_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $presenter_tests_dir ) {
	$presenter_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $presenter_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded yet.
	fwrite( STDERR, "WordPress test library not found. Set WP_TESTS_DIR.\n" );
	exit( 1 );
}

require_once $presenter_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/presenter.php';
	}
);

require $presenter_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/PresenterTestCase.php';
