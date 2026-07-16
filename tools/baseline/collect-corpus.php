<?php
/**
 * Produce a content-free structural manifest for the private acceptance corpus.
 *
 * This file is executed only with `wp eval-file` in the isolated local snapshot.
 * It must never print presentation content or unhashed private values.
 *
 * @package Presenter
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This collector must run through WP-CLI.' );
}

$home       = wp_parse_url( home_url( '/' ) );
$local_host = isset( $home['host'] ) && in_array( $home['host'], array( 'localhost', '127.0.0.1' ), true );
$local_port = isset( $home['port'] ) && 8890 === (int) $home['port'];

if ( ! $local_host || ! $local_port || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Refusing to inspect anything except the port 8890 local snapshot.' );
}

$repository_root = dirname( __DIR__, 2 );
$corpus_file     = $repository_root . '/local/acceptance-corpus/corpus.json';
$key_file        = $repository_root . '/local/acceptance-corpus/.hmac-key';

if ( ! is_readable( $corpus_file ) || ! is_readable( $key_file ) ) {
	throw new RuntimeException( 'The ignored corpus manifest and HMAC key are required.' );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local ignored file; this is not an HTTP request.
$key_hex = trim( (string) file_get_contents( $key_file ) );
$key     = ctype_xdigit( $key_hex ) && 64 === strlen( $key_hex ) ? hex2bin( $key_hex ) : false;

if ( false === $key || 32 !== strlen( $key ) ) {
	throw new RuntimeException( 'The ignored HMAC key is invalid.' );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local ignored file; this is not an HTTP request.
$corpus = json_decode( (string) file_get_contents( $corpus_file ), true, 32, JSON_THROW_ON_ERROR );

if ( empty( $corpus['decks'] ) || ! is_array( $corpus['decks'] ) ) {
	throw new RuntimeException( 'The ignored corpus manifest has no decks.' );
}

/**
 * Return an HMAC that cannot be used for offline guesses without the local key.
 *
 * @param mixed  $value Private value to authenticate.
 * @param string $key   Binary HMAC key.
 * @return string
 */
function presenter_corpus_hmac( $value, string $key ): string {
	return hash_hmac( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_SLASHES ), $key );
}

/**
 * Safely read a legacy slide field from either an object or array.
 *
 * @param mixed  $slide Legacy slide value.
 * @param string $field Field name.
 * @param mixed  $fallback Default value.
 * @return mixed
 */
function presenter_corpus_field( $slide, string $field, $fallback = '' ) {
	if ( is_object( $slide ) && isset( $slide->{$field} ) ) {
		return $slide->{$field};
	}

	if ( is_array( $slide ) && isset( $slide[ $field ] ) ) {
		return $slide[ $field ];
	}

	return $fallback;
}

$results = array();

foreach ( $corpus['decks'] as $selection ) {
	$deck_id   = isset( $selection['postId'] ) ? absint( $selection['postId'] ) : 0;
	$deck_post = $deck_id ? get_post( $deck_id ) : null;

	if ( ! $deck_post instanceof WP_Post || 'slideshow' !== $deck_post->post_type ) {
		$results[] = array(
			'postId' => $deck_id,
			'exists' => false,
		);
		continue;
	}

	$slides               = get_post_meta( $deck_id, '_presenter_slides', false );
	$notes_count          = 0;
	$markdown_notes_count = 0;
	$data_attribute_count = 0;
	$slide_text           = array();
	$notes_text           = array();

	foreach ( $slides as $slide ) {
		$content        = (string) presenter_corpus_field( $slide, 'content' );
		$class_name     = (string) presenter_corpus_field( $slide, 'class' );
		$data           = presenter_corpus_field( $slide, 'data', array() );
		$notes          = presenter_corpus_field( $slide, 'notes', array() );
		$notes_value    = is_array( $notes ) && isset( $notes['notes'] ) ? (string) $notes['notes'] : '';
		$notes_markdown = is_array( $notes ) && ! empty( $notes['markdown'] );

		$slide_text[]          = $content . "\n" . $class_name . "\n" . wp_json_encode( $data );
		$notes_text[]          = $notes_value;
		$notes_count          += '' !== trim( $notes_value ) ? 1 : 0;
		$markdown_notes_count += $notes_markdown ? 1 : 0;
		$data_attribute_count += is_array( $data ) || is_object( $data ) ? count( (array) $data ) : 0;
	}

	$searchable = implode( "\n", $slide_text );
	$theme      = (string) get_post_meta( $deck_id, '_presenter-theme', true );
	$short_url  = (string) get_post_meta( $deck_id, '_presenter-short-url', true );

	$results[] = array(
		'postId'     => $deck_id,
		'exists'     => true,
		'status'     => $deck_post->post_status,
		'protected'  => '' !== $deck_post->post_password,
		'slideCount' => count( $slides ),
		'counts'     => array(
			'backgroundVideoReferences' => preg_match_all( '/background-video/i', $searchable ),
			'chartReferences'           => preg_match_all( '/(?:chart\.js|<canvas\b|data-chart)/i', $searchable ),
			'codeElements'              => preg_match_all( '/<code\b/i', $searchable ),
			'customDataAttributes'      => $data_attribute_count,
			'fragmentReferences'        => preg_match_all( '/(?:^|[\s"\'])fragment(?:[\s"\']|$)/i', $searchable ),
			'imageElements'             => preg_match_all( '/<img\b/i', $searchable ),
			'markdownNotes'             => $markdown_notes_count,
			'nestedSections'            => preg_match_all( '/<section\b/i', $searchable ),
			'notes'                     => $notes_count,
		),
		'flags'      => array(
			'hasExplicitTheme' => '' !== $theme,
			'hasShortUrl'      => '' !== $short_url,
		),
		'digests'    => array(
			'content'  => presenter_corpus_hmac( $deck_post->post_content, $key ),
			'notes'    => presenter_corpus_hmac( $notes_text, $key ),
			'shortUrl' => presenter_corpus_hmac( $short_url, $key ),
			'slides'   => presenter_corpus_hmac( $slides, $key ),
			'theme'    => presenter_corpus_hmac( $theme, $key ),
			'title'    => presenter_corpus_hmac( $deck_post->post_title, $key ),
		),
	);
}

$output = array(
	'schemaVersion' => 1,
	'keyId'         => substr( hash( 'sha256', $key ), 0, 16 ),
	'decks'         => $results,
);

echo 'PRESENTER_CORPUS_JSON:' . wp_json_encode( $output, JSON_UNESCAPED_SLASHES ) . PHP_EOL;
