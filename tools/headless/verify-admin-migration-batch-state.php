<?php
/**
 * Verify the persisted result of the admin Prepare queue gate.
 *
 * @package Presenter
 */

use Presenter\Deck_Mode;
use Presenter\Migration_Backup_Store;
use Presenter\Migration_Hasher;
use Presenter\Migration_Journal;
use Presenter\Migration_Secret;

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'The admin migration batch fixture may only be verified locally.' );
}

$prepared_slugs = array( 'presenter-admin-batch-one', 'presenter-admin-batch-two', 'presenter-admin-batch-three' );
$secret         = ( new Migration_Secret() )->read();
if ( null === $secret ) {
	WP_CLI::error( 'The selected batch did not create a migration secret.' );
}
$journal = new Migration_Journal( new Migration_Hasher( $secret ) );
foreach ( $prepared_slugs as $slug ) {
	$fixture_post = get_page_by_path( $slug, OBJECT, 'slideshow' );
	if ( ! $fixture_post instanceof WP_Post ) {
		WP_CLI::error( 'A selected admin migration batch fixture is missing.' );
	}

	$journal_status = $journal->inspect( $fixture_post->ID );
	if (
		Migration_Journal::STATE_APPLY_PREPARED !== $journal_status['state']
		|| true !== $journal_status['valid']
		|| '' !== $fixture_post->post_content
		|| empty( get_post_meta( $fixture_post->ID, Migration_Backup_Store::META_KEY, false ) )
		|| empty( get_post_meta( $fixture_post->ID, Migration_Journal::META_KEY, false ) )
		|| metadata_exists( 'post', $fixture_post->ID, Deck_Mode::META_KEY )
	) {
		WP_CLI::error( 'A selected deck did not reach the exact verified prepared state.' );
	}
}

$neighbor = get_page_by_path( 'presenter-admin-batch-neighbor', OBJECT, 'slideshow' );
if ( ! $neighbor instanceof WP_Post ) {
	WP_CLI::error( 'The unselected admin migration batch fixture is missing.' );
}
if (
	metadata_exists( 'post', $neighbor->ID, Migration_Backup_Store::META_KEY )
	|| metadata_exists( 'post', $neighbor->ID, Migration_Journal::META_KEY )
	|| metadata_exists( 'post', $neighbor->ID, Deck_Mode::META_KEY )
) {
	WP_CLI::error( 'The unselected neighboring deck was mutated.' );
}

WP_CLI::success( 'Presenter admin migration batch state is verified.' );
