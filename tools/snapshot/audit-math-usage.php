<?php
/**
 * Audit the private snapshot for authored math markers without exposing content.
 *
 * This file is executed only with `wp eval-file` in the isolated local
 * snapshot. Its output deliberately contains aggregate counts and fixed keys.
 *
 * @package Presenter
 */

/**
 * Determine whether text contains a complete delimiter pair.
 *
 * @param string $text  Text to inspect.
 * @param string $open  Opening delimiter.
 * @param string $close Closing delimiter.
 * @return bool
 */
function presenter_snapshot_has_math_delimiters( string $text, string $open, string $close ): bool {
	$open_position = strpos( $text, $open );
	if ( false === $open_position ) {
		return false;
	}

	return false !== strpos( $text, $close, $open_position + strlen( $open ) );
}

/**
 * Detect strong evidence that authored content expects a math renderer.
 *
 * @param string $text Text to inspect.
 * @return bool
 */
function presenter_snapshot_has_strong_math_marker( string $text ): bool {
	return presenter_snapshot_has_math_delimiters( $text, '\\(', '\\)' )
		|| presenter_snapshot_has_math_delimiters( $text, '\\[', '\\]' )
		|| presenter_snapshot_has_math_delimiters( $text, '$$', '$$' )
		|| false !== stripos( $text, '<math' )
		|| false !== stripos( $text, 'MathJax' )
		|| false !== stripos( $text, 'KaTeX' );
}

/**
 * Detect paired single-dollar delimiters separately from stronger markers.
 *
 * Dollar pairs can also be ordinary prose containing prices, so this signal
 * is reported independently and is not treated as confirmed math usage.
 *
 * @param string $text Text to inspect.
 * @return bool
 */
function presenter_snapshot_has_dollar_pair( string $text ): bool {
	return 1 === preg_match( '/(?<!\\\\)\$[^$\r\n]{1,200}(?<!\\\\)\$/', $text );
}

/**
 * Convert one legacy slide record into auditable text in memory.
 *
 * The returned value is never written or emitted.
 *
 * @param mixed $slide Legacy slide value.
 * @return string
 */
function presenter_snapshot_math_audit_text( $slide ): string {
	if ( ! is_object( $slide ) ) {
		return '';
	}

	$text = isset( $slide->content ) ? (string) $slide->content : '';
	if ( ! isset( $slide->notes ) ) {
		return $text;
	}

	if ( is_array( $slide->notes ) && isset( $slide->notes['notes'] ) ) {
		return $text . "\n" . (string) $slide->notes['notes'];
	}

	if ( is_object( $slide->notes ) && isset( $slide->notes->notes ) ) {
		return $text . "\n" . (string) $slide->notes->notes;
	}

	if ( is_string( $slide->notes ) ) {
		return $text . "\n" . $slide->notes;
	}

	return $text;
}

if (
	'local' !== wp_get_environment_type()
	|| 'http://localhost:8890' !== get_option( 'home' )
	|| 'http://localhost:8890' !== get_option( 'siteurl' )
) {
	echo wp_json_encode(
		array(
			'status' => 'fail',
			'code'   => 'local_snapshot_required',
		)
	);
	exit( 1 );
}

$post_ids = get_posts(
	array(
		'fields'           => 'ids',
		'numberposts'      => -1,
		'post_status'      => 'any',
		'post_type'        => 'slideshow',
		'suppress_filters' => false,
	)
);

$legacy_slide_records      = 0;
$legacy_decks              = 0;
$legacy_strong_slides      = 0;
$legacy_strong_decks       = 0;
$legacy_dollar_pair_slides = 0;
$legacy_dollar_pair_decks  = 0;
$post_content_strong_decks = 0;
$post_content_dollar_decks = 0;

foreach ( $post_ids as $slideshow_post_id ) {
	$legacy_slides = get_post_meta( $slideshow_post_id, '_presenter_slides', false );
	if ( array() !== $legacy_slides ) {
		++$legacy_decks;
	}

	$deck_has_strong_marker = false;
	$deck_has_dollar_pair   = false;

	foreach ( $legacy_slides as $legacy_slide ) {
		++$legacy_slide_records;
		$text = presenter_snapshot_math_audit_text( $legacy_slide );

		if ( presenter_snapshot_has_strong_math_marker( $text ) ) {
			++$legacy_strong_slides;
			$deck_has_strong_marker = true;
		}

		if ( presenter_snapshot_has_dollar_pair( $text ) ) {
			++$legacy_dollar_pair_slides;
			$deck_has_dollar_pair = true;
		}
	}

	if ( $deck_has_strong_marker ) {
		++$legacy_strong_decks;
	}

	if ( $deck_has_dollar_pair ) {
		++$legacy_dollar_pair_decks;
	}

	$post_content = (string) get_post_field( 'post_content', $slideshow_post_id );
	if ( presenter_snapshot_has_strong_math_marker( $post_content ) ) {
		++$post_content_strong_decks;
	}

	if ( presenter_snapshot_has_dollar_pair( $post_content ) ) {
		++$post_content_dollar_decks;
	}
}

echo wp_json_encode(
	array(
		'status'                         => 'ok',
		'slideshow_posts'                => count( $post_ids ),
		'legacy_decks'                   => $legacy_decks,
		'legacy_slide_records'           => $legacy_slide_records,
		'legacy_strong_math_decks'       => $legacy_strong_decks,
		'legacy_strong_math_slides'      => $legacy_strong_slides,
		'legacy_dollar_pair_decks'       => $legacy_dollar_pair_decks,
		'legacy_dollar_pair_slides'      => $legacy_dollar_pair_slides,
		'post_content_strong_math_decks' => $post_content_strong_decks,
		'post_content_dollar_pair_decks' => $post_content_dollar_decks,
	)
);
