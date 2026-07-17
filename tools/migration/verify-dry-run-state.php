<?php
/**
 * Prove the migration dry-run command did not change its source decks.
 *
 * @package Presenter
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Migration fixtures may only be verified locally.' );
}

$expectations  = array(
	'presenter-m7-ready'   => array(
		'postContent' => '',
		'slideCount'  => 2,
		'theme'       => '',
	),
	'presenter-m7-blocked' => array(
		'postContent' => '<p>Preserved legacy post content.</p>',
		'slideCount'  => 1,
		'theme'       => '/plugins/presenter-themes/legacy-theme.css',
	),
);
$fixture_state = get_option( 'presenter_migration_dry_run_fixtures' );
if ( ! is_array( $fixture_state ) || 1 !== ( $fixture_state['schemaVersion'] ?? null ) ) {
	WP_CLI::error( 'Migration fixture state is missing.' );
}

foreach ( $expectations as $slug => $expected ) {
	$fixture_post = get_page_by_path( $slug, 'OBJECT', 'slideshow' );
	if ( ! $fixture_post instanceof WP_Post ) {
		WP_CLI::error( 'A migration dry-run fixture is missing.' );
	}
	$fixture_record = $fixture_state['fixtures'][ $slug ] ?? null;
	$snapshot       = ( new Presenter\Legacy_Deck_Snapshotter( new Presenter\WordPress_Legacy_Slide_Source() ) )->capture( $fixture_post->ID );

	if (
		! is_array( $fixture_record )
		|| ( $fixture_record['postId'] ?? null ) !== $fixture_post->ID
		|| null === $snapshot
		|| $snapshot->fingerprint() !== ( $fixture_record['fingerprint'] ?? null )
		|| $fixture_post->post_content !== $expected['postContent']
		|| count( get_post_meta( $fixture_post->ID, '_presenter_slides', false ) ) !== $expected['slideCount']
		|| (string) get_post_meta( $fixture_post->ID, '_presenter-theme', true ) !== $expected['theme']
		|| has_block( 'presenter/deck', $fixture_post )
	) {
		WP_CLI::error( 'Migration dry-run changed a source fixture.' );
	}
}

WP_CLI::success( 'Migration dry-run left every source fixture unchanged.' );
