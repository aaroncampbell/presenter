<?php
/**
 * Create the representative core-block deck used by local headless tests.
 *
 * @package Presenter
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'The core-block compatibility fixture may only be created locally.' );
}

$slug          = 'presenter-m4-core-block-compatibility';
$category_slug = 'presenter-m4-dynamic-fixture';
$category      = get_category_by_slug( $category_slug );

if ( ! $category instanceof WP_Term ) {
	$category_result = wp_insert_term(
		'Presenter M4 Dynamic Fixture',
		'category',
		array( 'slug' => $category_slug )
	);

	if ( is_wp_error( $category_result ) ) {
		WP_CLI::error( $category_result );
	}

	$category = get_term( $category_result['term_id'], 'category' );
}

$dynamic_post = get_page_by_path( 'presenter-dynamic-post-sentinel', OBJECT, 'post' );
$dynamic_data = array(
	'post_category' => array( $category->term_id ),
	'post_content'  => 'Deterministic dynamic block fixture.',
	'post_name'     => 'presenter-dynamic-post-sentinel',
	'post_status'   => 'publish',
	'post_title'    => 'Presenter dynamic post sentinel',
	'post_type'     => 'post',
);

if ( $dynamic_post instanceof WP_Post ) {
	$dynamic_data['ID'] = $dynamic_post->ID;
}

$dynamic_post_id = wp_insert_post( $dynamic_data, true );

if ( is_wp_error( $dynamic_post_id ) ) {
	WP_CLI::error( $dynamic_post_id );
}

$latest_posts_attributes = wp_json_encode(
	array(
		'categories'      => array( array( 'id' => $category->term_id ) ),
		'displayPostDate' => true,
		'postsToShow'     => 1,
	)
);

$content = '<!-- wp:presenter/deck -->'
	. '<!-- wp:presenter/slide {"anchor":"core-static","label":"Static and nested core blocks"} -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">Core block compatibility</h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>Read the <a href="https://wordpress.org/">WordPress project</a>.</p><!-- /wp:paragraph -->'
	. '<!-- wp:group --><div class="wp-block-group">'
	. '<!-- wp:columns --><div class="wp-block-columns">'
	. '<!-- wp:column --><div class="wp-block-column">'
	. '<!-- wp:list --><ul class="wp-block-list">'
	. '<!-- wp:list-item --><li>Alpha sentinel</li><!-- /wp:list-item -->'
	. '<!-- wp:list-item --><li>Beta sentinel</li><!-- /wp:list-item -->'
	. '</ul><!-- /wp:list -->'
	. '</div><!-- /wp:column -->'
	. '<!-- wp:column --><div class="wp-block-column">'
	. '<!-- wp:code --><pre class="wp-block-code"><code>const presenter = true;</code></pre><!-- /wp:code -->'
	. '</div><!-- /wp:column -->'
	. '</div><!-- /wp:columns -->'
	. '</div><!-- /wp:group -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- wp:presenter/slide {"anchor":"core-media-interactive","label":"Media and interactive core blocks"} -->'
	. '<!-- wp:image {"sizeSlug":"full","linkDestination":"none"} -->'
	. '<figure class="wp-block-image size-full"><img src="http://localhost:8888/wp-includes/images/w-logo-blue-white-bg.png" alt="WordPress logo compatibility sentinel"/></figure><!-- /wp:image -->'
	. '<!-- wp:buttons --><div class="wp-block-buttons">'
	. '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#core-static">Return to static blocks</a></div><!-- /wp:button -->'
	. '</div><!-- /wp:buttons -->'
	. '<!-- wp:accordion -->'
	. '<div class="wp-block-accordion">'
	. '<!-- wp:accordion-item -->'
	. '<div class="wp-block-accordion-item">'
	. '<!-- wp:accordion-heading {"level":3} -->'
	. '<h3 class="wp-block-accordion-heading"><button class="wp-block-accordion-heading__toggle"><span class="wp-block-accordion-heading__toggle-title">Reveal compatibility details</span><span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span></button></h3><!-- /wp:accordion-heading -->'
	. '<!-- wp:accordion-panel -->'
	. '<div class="wp-block-accordion-panel"><!-- wp:paragraph --><p>Interactive panel sentinel</p><!-- /wp:paragraph --></div><!-- /wp:accordion-panel -->'
	. '</div><!-- /wp:accordion-item -->'
	. '</div><!-- /wp:accordion -->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- wp:presenter/slide {"anchor":"core-server","label":"Server-rendered core blocks"} -->'
	. '<!-- wp:shortcode -->[presenter-url]<!-- /wp:shortcode -->'
	. '<!-- wp:latest-posts ' . $latest_posts_attributes . ' /-->'
	. '<!-- /wp:presenter/slide -->'
	. '<!-- /wp:presenter/deck -->';

$fixture_post = get_page_by_path( $slug, OBJECT, 'slideshow' );
$fixture_data = array(
	'post_content' => $content,
	'post_name'    => $slug,
	'post_status'  => 'publish',
	'post_title'   => 'Presenter M4 Core Block Compatibility',
	'post_type'    => 'slideshow',
);

if ( $fixture_post instanceof WP_Post ) {
	$fixture_data['ID'] = $fixture_post->ID;
}

$fixture_post_id = wp_insert_post( $fixture_data, true );

if ( is_wp_error( $fixture_post_id ) ) {
	WP_CLI::error( $fixture_post_id );
}

WP_CLI::success( 'Presenter core-block compatibility fixture is ready.' );
