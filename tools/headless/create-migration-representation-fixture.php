<?php
/**
 * Create the migration representation runtime fixture.
 *
 * @package Presenter
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'The migration representation fixture may only be created locally.' );
}

$slug    = 'presenter-migration-representation-smoke';
$content = '<!-- wp:presenter/deck -->'
	. '<!-- wp:presenter/slide {"anchor":"legacy-stack"} -->'
	. '<!-- wp:html --><section id="vertical-one" class="legacy-vertical" data-background="#112233"><h2>Vertical one</h2></section><section id="vertical-two" data-background-size="contain"><h2>Vertical two</h2></section><!-- /wp:html -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- wp:presenter/slide {"anchor":"html-notes","notes":"<p>Safe <strong>HTML</strong> notes.</p>","notesFormat":"html"} -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">HTML notes</h2><!-- /wp:heading -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- wp:presenter/slide {"anchor":"markdown-html-notes","notes":"<blockquote>Safe **Markdown** HTML notes.</blockquote>","notesFormat":"markdown-html"} -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">Markdown HTML notes</h2><!-- /wp:heading -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- /wp:presenter/deck -->';

$fixture_post = get_page_by_path( $slug, OBJECT, 'slideshow' );
$data         = array(
	'post_content' => $content,
	'post_name'    => $slug,
	'post_status'  => 'publish',
	'post_title'   => 'Presenter Migration Representation Smoke',
	'post_type'    => 'slideshow',
);

if ( $fixture_post instanceof WP_Post ) {
	$data['ID'] = $fixture_post->ID;
}

$fixture_post_id = wp_insert_post( $data, true );
if ( is_wp_error( $fixture_post_id ) ) {
	WP_CLI::error( $fixture_post_id );
}

WP_CLI::success( 'Presenter migration representation fixture is ready.' );
