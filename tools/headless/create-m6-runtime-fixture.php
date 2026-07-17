<?php
/**
 * Create the native Milestone 6 runtime fixture.
 *
 * @package Presenter
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'The Milestone 6 runtime fixture may only be created locally.' );
}

$slug = 'presenter-m6-runtime-smoke';

// Give the dynamic Latest Posts block deterministic content to render.
$source_post = get_page_by_path( 'presenter-m6-dynamic-source', OBJECT, 'post' );
$source_data = array(
	'post_content' => '<!-- wp:paragraph --><p>Dynamic fragment source.</p><!-- /wp:paragraph -->',
	'post_name'    => 'presenter-m6-dynamic-source',
	'post_status'  => 'publish',
	'post_title'   => 'Presenter M6 Dynamic Fragment Source',
	'post_type'    => 'post',
);

if ( $source_post instanceof WP_Post ) {
	$source_data['ID'] = $source_post->ID;
}

$source_post_id = wp_insert_post( $source_data, true );
if ( is_wp_error( $source_post_id ) ) {
	WP_CLI::error( $source_post_id );
}

$content = '<!-- wp:presenter/deck -->'
	. '<!-- wp:presenter/slide {"anchor":"fragment-runtime","notes":"**Milestone 6 speaker notes**","notesFormat":"markdown"} -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">Fragment runtime</h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph {"presenterFragment":true,"presenterFragmentEffect":"fade-up","presenterFragmentIndex":0} --><p>Static shared fragment</p><!-- /wp:paragraph -->'
	. '<!-- wp:latest-posts {"postsToShow":1,"presenterFragment":true,"presenterFragmentEffect":"grow","presenterFragmentIndex":0} /-->'
	. '<!-- wp:paragraph {"presenterFragment":true,"presenterFragmentEffect":"custom","presenterFragmentCustomClasses":"aaron-pop","presenterFragmentIndex":1} --><p>Custom fragment</p><!-- /wp:paragraph -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- wp:presenter/slide {"anchor":"advanced-runtime","backgroundColor":"#24324a","backgroundSize":"contain","backgroundPosition":"top right","backgroundRepeat":"repeat-x","backgroundOpacity":0.45,"backgroundTransition":"zoom","autoAnimate":true,"autoAnimateId":"m6-sequence","autoAnimateRestart":true} -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">Advanced slide one</h2><!-- /wp:heading -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- wp:presenter/slide {"anchor":"auto-animate-runtime","autoAnimate":true,"autoAnimateId":"m6-sequence"} -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">Advanced slide two</h2><!-- /wp:heading -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- /wp:presenter/deck -->';

$fixture_post = get_page_by_path( $slug, OBJECT, 'slideshow' );
$data         = array(
	'post_content' => $content,
	'post_name'    => $slug,
	'post_status'  => 'publish',
	'post_title'   => 'Presenter M6 Runtime Smoke',
	'post_type'    => 'slideshow',
);

if ( $fixture_post instanceof WP_Post ) {
	$data['ID'] = $fixture_post->ID;
}

$fixture_post_id = wp_insert_post( $data, true );
if ( is_wp_error( $fixture_post_id ) ) {
	WP_CLI::error( $fixture_post_id );
}

WP_CLI::success( 'Presenter Milestone 6 runtime fixture is ready.' );
