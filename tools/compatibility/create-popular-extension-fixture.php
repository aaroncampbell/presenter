<?php
/**
 * Create the native deck used by the popular-extension compatibility audit.
 *
 * @package Presenter
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'The popular-extension fixture may only be created locally.' );
}

$slug    = 'presenter-popular-extension-compatibility';
$content = '<!-- wp:presenter/deck {"width":1280,"height":720,"margin":0.08,"controls":false,"progress":false,"theme":"white"} -->'
	. '<!-- wp:presenter/slide {"anchor":"compatibility-baseline","backgroundColor":"#f5f2ff"} -->'
	. '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Compatibility baseline</h1><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>Standard WordPress hooks should not disturb this presentation.</p><!-- /wp:paragraph -->'
	. '<!-- wp:list --><ul class="wp-block-list"><li>Stable typography</li><li>Stable geometry</li></ul><!-- /wp:list -->'
	. '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Third-party output stays measurable.</p><cite>Presenter</cite></blockquote><!-- /wp:quote -->'
	. '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Example action</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- /wp:presenter/deck -->';

$fixture_post = get_page_by_path( $slug, OBJECT, 'slideshow' );
$data         = array(
	'post_content' => $content,
	'post_name'    => $slug,
	'post_status'  => 'publish',
	'post_title'   => 'Presenter Popular Extension Compatibility',
	'post_type'    => 'slideshow',
);

if ( $fixture_post instanceof WP_Post ) {
	$data['ID'] = $fixture_post->ID;
}

$fixture_post_id = wp_insert_post( $data, true );

if ( is_wp_error( $fixture_post_id ) ) {
	WP_CLI::error( $fixture_post_id );
}

WP_CLI::success( 'Popular-extension compatibility fixture is ready.' );
