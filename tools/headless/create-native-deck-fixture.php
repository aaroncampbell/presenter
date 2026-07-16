<?php
/**
 * Create the content-free native deck used by the local headless smoke test.
 *
 * @package Presenter
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'The native deck fixture may only be created locally.' );
}

$slug = 'presenter-m4-native-smoke';

$content = '<!-- wp:presenter/deck {"width":1440,"height":810} -->'
	. '<!-- wp:presenter/slide {"anchor":"first-native"} -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">First native slide</h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>Native paragraph content</p><!-- /wp:paragraph -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- wp:presenter/slide {"anchor":"second-native","notes":"Speaker note","notesFormat":"markdown"} -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">Second native slide</h2><!-- /wp:heading -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- /wp:presenter/deck -->';

$fixture_post = get_page_by_path( $slug, OBJECT, 'slideshow' );

$data = array(
	'post_content' => $content,
	'post_name'    => $slug,
	'post_status'  => 'publish',
	'post_title'   => 'Presenter M4 Native Smoke',
	'post_type'    => 'slideshow',
);

if ( $fixture_post instanceof WP_Post ) {
	$data['ID'] = $fixture_post->ID;
}

$fixture_post_id = wp_insert_post( $data, true );

if ( is_wp_error( $fixture_post_id ) ) {
	WP_CLI::error( $fixture_post_id );
}

WP_CLI::success( 'Native Presenter smoke fixture is ready.' );
