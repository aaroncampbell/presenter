<?php
/**
 * Create native and legacy fixtures for the companion Chart plugin.
 *
 * @package Presenter
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'The Chart runtime fixtures may only be created locally.' );
}

if ( ! class_exists( 'AaronCampbell\\PresenterThemes\\Plugin' ) ) {
	WP_CLI::error( 'The Presenter companion theme plugin must be active.' );
}

$native_slug = 'presenter-chart-native-smoke';
$legacy_slug = 'presenter-chart-legacy-smoke';
$fragment    = '<p id="presenter-chart-fragment" class="fragment" data-fragment-graph="presenterChartFixture" data-fragment-graph-dataset="presenterChartDatasetFixture">Chart dataset fragment</p>';

$native_content = '<!-- wp:presenter/deck -->'
	. '<!-- wp:presenter/slide {"anchor":"chart-native-runtime"} -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">Native Chart runtime</h2><!-- /wp:heading -->'
	. '<!-- wp:html -->' . $fragment . '<!-- /wp:html -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- /wp:presenter/deck -->';

$native_post = get_page_by_path( $native_slug, OBJECT, 'slideshow' );
$native_data = array(
	'post_content' => $native_content,
	'post_name'    => $native_slug,
	'post_status'  => 'publish',
	'post_title'   => 'Presenter Native Chart Runtime Smoke',
	'post_type'    => 'slideshow',
);

if ( $native_post instanceof WP_Post ) {
	$native_data['ID'] = $native_post->ID;
}

$native_id = wp_insert_post( $native_data, true );
if ( is_wp_error( $native_id ) ) {
	WP_CLI::error( $native_id );
}

delete_post_meta( $native_id, '_presenter_slides' );
delete_post_meta( $native_id, \Presenter\Deck_Mode::META_KEY );
update_post_meta( $native_id, '_presenter_test_fixture', 'chart-runtime-native' );

$legacy_post = get_page_by_path( $legacy_slug, OBJECT, 'slideshow' );
$legacy_data = array(
	'post_content' => '',
	'post_name'    => $legacy_slug,
	'post_status'  => 'publish',
	'post_title'   => 'Presenter Legacy Chart Runtime Smoke',
	'post_type'    => 'slideshow',
);

if ( $legacy_post instanceof WP_Post ) {
	$legacy_data['ID'] = $legacy_post->ID;
}

$legacy_id = wp_insert_post( $legacy_data, true );
if ( is_wp_error( $legacy_id ) ) {
	WP_CLI::error( $legacy_id );
}

delete_post_meta( $legacy_id, '_presenter_slides' );
delete_post_meta( $legacy_id, \Presenter\Deck_Mode::META_KEY );
add_post_meta(
	$legacy_id,
	'_presenter_slides',
	(object) array(
		'number'  => 1,
		'title'   => 'Legacy Chart runtime',
		'content' => $fragment,
		'class'   => 'chart-legacy-runtime',
	)
);
update_post_meta( $legacy_id, '_presenter_test_fixture', 'chart-runtime-legacy' );

WP_CLI::success( 'Presenter Chart runtime fixtures are ready.' );
