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

$content = '<!-- wp:presenter/deck {"aspectRatio":"custom","width":1440,"height":810,"margin":0.1,"controls":false,"progress":false,"center":false,"transition":"convex","backgroundTransition":"zoom","theme":"white"} -->'
	. '<!-- wp:presenter/slide {"anchor":"first-native","transition":"fade","backgroundColor":"#123456","backgroundImageUrl":"http://localhost:8888/wp-includes/images/w-logo-blue-white-bg.png"} -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">First native slide</h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>Native paragraph content</p><!-- /wp:paragraph -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- wp:presenter/stack {"anchor":"native-nested","label":"Native nested case study"} -->'
	. '<!-- wp:presenter/slide {"anchor":"nested-native-one"} -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">Nested native one</h2><!-- /wp:heading -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- wp:presenter/slide {"anchor":"nested-native-two"} -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">Nested native two</h2><!-- /wp:heading -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- /wp:presenter/stack -->'
	. '<!-- wp:presenter/slide {"anchor":"second-native","notes":"Speaker note","notesFormat":"markdown","transition":"zoom","backgroundColor":"#654321"} -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">Second native slide</h2><!-- /wp:heading -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- wp:presenter/slide {"anchor":"hidden-native","hidden":true} -->'
	. '<!-- wp:paragraph --><p>Hidden native slide</p><!-- /wp:paragraph -->'
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
