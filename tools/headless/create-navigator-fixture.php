<?php
/**
 * Create the large native deck used by local navigator headless tests.
 *
 * @package Presenter
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'The navigator fixture may only be created locally.' );
}

$slug   = 'presenter-m5-navigator-smoke';
$slides = array();

for ( $slide_number = 1; $slide_number <= 60; $slide_number++ ) {
	$slide_attributes = array(
		'anchor' => sprintf( 'navigator-slide-%02d', $slide_number ),
	);

	if ( 1 === $slide_number ) {
		$slide_attributes['label'] = 'Explicit navigator label';
	}
	if ( 58 === $slide_number ) {
		$slide_attributes['label']               = 'Legacy Nested Slides fixture';
		$slide_attributes['legacyAutoParagraph'] = true;
	}

	if ( 0 === $slide_number % 10 ) {
		$slide_attributes['hidden'] = true;
	}

	if ( 1 === $slide_number ) {
		$inner_markup = '<!-- wp:heading --><h2 class="wp-block-heading">First navigator slide heading</h2><!-- /wp:heading -->';
	} elseif ( 2 === $slide_number ) {
		$inner_markup = '<!-- wp:heading --><h2 class="wp-block-heading">Heading fallback title</h2><!-- /wp:heading -->';
	} elseif ( 3 === $slide_number ) {
		$inner_markup = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Nested paragraph fallback title</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
	} elseif ( 4 === $slide_number ) {
		$inner_markup = '';
	} elseif ( 58 === $slide_number ) {
		$inner_markup = '<!-- wp:html --><section id="legacy-nested-one"><h2>Legacy nested one</h2></section><section id="legacy-nested-two"><p>Legacy nested two</p></section><!-- /wp:html -->';
	} else {
		$inner_markup = sprintf(
			'<!-- wp:heading --><h2 class="wp-block-heading">Navigator slide %1$d</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Deterministic content for navigator slide %1$d.</p><!-- /wp:paragraph -->',
			$slide_number
		);
	}

	$slide_attributes_json = wp_json_encode( $slide_attributes );
	$slide_markup          = sprintf(
		'<!-- wp:presenter/slide %1$s -->%2$s<!-- /wp:presenter/slide -->',
		$slide_attributes_json,
		$inner_markup
	);
	$parsed_slide          = parse_blocks( $slide_markup );

	if ( 1 !== count( $parsed_slide ) || 'presenter/slide' !== $parsed_slide[0]['blockName'] ) {
		WP_CLI::error( sprintf( 'Could not create navigator slide %d.', $slide_number ) );
	}

	$slides[] = $parsed_slide[0];
}

$deck = array(
	'blockName'    => 'presenter/deck',
	'attrs'        => array( 'theme' => 'black' ),
	'innerBlocks'  => $slides,
	'innerHTML'    => '',
	'innerContent' => array_fill( 0, count( $slides ), null ),
);

$fixture_post = get_page_by_path( $slug, OBJECT, 'slideshow' );
$fixture_data = array(
	'post_content' => serialize_blocks( array( $deck ) ),
	'post_name'    => $slug,
	'post_status'  => 'publish',
	'post_title'   => 'Presenter M5 Navigator Smoke',
	'post_type'    => 'slideshow',
);

if ( $fixture_post instanceof WP_Post ) {
	$fixture_data['ID'] = $fixture_post->ID;
}

$fixture_post_id = wp_insert_post( $fixture_data, true );

if ( is_wp_error( $fixture_post_id ) ) {
	WP_CLI::error( $fixture_post_id );
}

WP_CLI::success( sprintf( 'Presenter navigator fixture %d is ready.', $fixture_post_id ) );
