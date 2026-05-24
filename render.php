<?php
/**
 * Server-side render for the presenter/slide block.
 *
 * Wraps the block's inner content in a reveal.js <section> element and adds the
 * relevant reveal.js data attributes (background, transition, visibility, etc.).
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Rendered InnerBlocks HTML.
 * @var WP_Block $block      Block instance.
 *
 * @package Presenter
 */

$presenter_attrs = wp_parse_args(
	$attributes,
	array(
		'title'        => '',
		'speakerNotes' => '',
		'hidden'       => false,
		'bgColor'      => '',
		'bgImageUrl'   => '',
		'bgVideoUrl'   => '',
		'transition'   => '',
		'autoAnimate'  => false,
		'extraClass'   => '',
		'extraData'    => array(),
	)
);

$presenter_section_attr = array();

// Slide id / name. reveal.js uses this as the slide's URL hash.
$presenter_slide_id = ( '' !== $presenter_attrs['title'] ) ? sanitize_title( $presenter_attrs['title'] ) : '';
if ( '' !== $presenter_slide_id ) {
	$presenter_section_attr['id'] = $presenter_slide_id;
}

// Classes.
$presenter_classes = 'wp-block-presenter-slide';
if ( ! empty( $presenter_attrs['extraClass'] ) ) {
	$presenter_classes .= ' ' . $presenter_attrs['extraClass'];
}
$presenter_section_attr['class'] = $presenter_classes;

// reveal.js background and behaviour data attributes.
if ( ! empty( $presenter_attrs['bgColor'] ) ) {
	$presenter_section_attr['data-background-color'] = $presenter_attrs['bgColor'];
}
if ( ! empty( $presenter_attrs['bgImageUrl'] ) ) {
	$presenter_section_attr['data-background-image'] = $presenter_attrs['bgImageUrl'];
}
if ( ! empty( $presenter_attrs['bgVideoUrl'] ) ) {
	$presenter_section_attr['data-background-video'] = $presenter_attrs['bgVideoUrl'];
}
if ( ! empty( $presenter_attrs['transition'] ) ) {
	$presenter_section_attr['data-transition'] = $presenter_attrs['transition'];
}
if ( ! empty( $presenter_attrs['autoAnimate'] ) ) {
	$presenter_section_attr['data-auto-animate'] = '';
}
if ( ! empty( $presenter_attrs['hidden'] ) ) {
	// reveal.js skips any slide flagged with data-visibility="hidden".
	$presenter_section_attr['data-visibility'] = 'hidden';
}

// Arbitrary data-* attributes (power users and the legacy slide importer).
if ( ! empty( $presenter_attrs['extraData'] ) && is_array( $presenter_attrs['extraData'] ) ) {
	foreach ( $presenter_attrs['extraData'] as $presenter_data ) {
		if ( empty( $presenter_data['name'] ) ) {
			continue;
		}
		$presenter_data_name = preg_replace( '/[^a-z0-9_-]/', '', strtolower( $presenter_data['name'] ) );
		if ( '' === $presenter_data_name ) {
			continue;
		}
		$presenter_section_attr[ 'data-' . $presenter_data_name ] = isset( $presenter_data['value'] ) ? $presenter_data['value'] : '';
	}
}

// Build the attribute string.
$presenter_attr_string = '';
foreach ( $presenter_section_attr as $presenter_name => $presenter_value ) {
	if ( '' === $presenter_value ) {
		$presenter_attr_string .= ' ' . esc_attr( $presenter_name );
	} else {
		$presenter_attr_string .= sprintf( ' %s="%s"', esc_attr( $presenter_name ), esc_attr( $presenter_value ) );
	}
}

// Speaker notes (rendered by the reveal.js notes plugin).
$presenter_notes = '';
if ( ! empty( $presenter_attrs['speakerNotes'] ) ) {
	$presenter_notes = '<aside class="notes">' . wp_kses_post( $presenter_attrs['speakerNotes'] ) . '</aside>';
}

printf(
	'<section%1$s>%2$s%3$s</section>',
	$presenter_attr_string, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while building above.
	$content, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted InnerBlocks output.
	$presenter_notes // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
);
