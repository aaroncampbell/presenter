<?php
/**
 * Presenter runtime bootstrap.
 *
 * @package Presenter
 */

namespace Presenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface-hook-provider.php';
require_once __DIR__ . '/interface-legacy-slide-source.php';
require_once __DIR__ . '/interface-legacy-theme-resolver.php';
require_once __DIR__ . '/interface-migration-post-content-writer.php';
require_once __DIR__ . '/interface-migration-apply-observer.php';
require_once __DIR__ . '/interface-migration-restore-observer.php';
require_once __DIR__ . '/class-plugin-context.php';
require_once __DIR__ . '/class-wordpress-legacy-slide-source.php';
require_once __DIR__ . '/class-deck-mode.php';
require_once __DIR__ . '/class-migration-deck-mode-store.php';
require_once __DIR__ . '/class-post-type.php';
require_once __DIR__ . '/class-meta.php';
require_once __DIR__ . '/class-assets.php';
require_once __DIR__ . '/class-theme.php';
require_once __DIR__ . '/class-theme-registry.php';
require_once __DIR__ . '/class-editor-integration.php';
require_once __DIR__ . '/class-reveal-config.php';
require_once __DIR__ . '/class-presentation-renderer.php';
require_once __DIR__ . '/class-slide-attribute-validator.php';
require_once __DIR__ . '/class-speaker-notes.php';
require_once __DIR__ . '/class-blocks.php';
require_once __DIR__ . '/class-native-deck-structure.php';
require_once __DIR__ . '/class-template-router.php';
require_once __DIR__ . '/class-legacy-deck-snapshot.php';
require_once __DIR__ . '/class-legacy-deck-snapshotter.php';
require_once __DIR__ . '/class-legacy-deck-inventory.php';
require_once __DIR__ . '/class-legacy-slide-normalizer.php';
require_once __DIR__ . '/class-legacy-slide-attribute-mapper.php';
require_once __DIR__ . '/class-legacy-section-validator.php';
require_once __DIR__ . '/class-migration-plan.php';
require_once __DIR__ . '/class-migration-planner.php';
require_once __DIR__ . '/class-legacy-meta-payload.php';
require_once __DIR__ . '/class-migration-value-encoder.php';
require_once __DIR__ . '/class-migration-secret.php';
require_once __DIR__ . '/class-migration-hasher.php';
require_once __DIR__ . '/class-migration-backup-store.php';
require_once __DIR__ . '/class-migration-journal.php';
require_once __DIR__ . '/class-migration-revision.php';
require_once __DIR__ . '/class-migration-preparation-context.php';
require_once __DIR__ . '/class-migration-prepared-backup.php';
require_once __DIR__ . '/class-migration-context-builder.php';
require_once __DIR__ . '/class-migration-status-service.php';
require_once __DIR__ . '/class-migration-preparer.php';
require_once __DIR__ . '/class-atomic-migration-post-content-writer.php';
require_once __DIR__ . '/class-null-migration-apply-observer.php';
require_once __DIR__ . '/class-migration-applier.php';
require_once __DIR__ . '/class-null-migration-restore-observer.php';
require_once __DIR__ . '/class-migration-restorer.php';
require_once __DIR__ . '/class-migration-lock-handle.php';
require_once __DIR__ . '/class-migration-lock.php';
require_once __DIR__ . '/class-migration-cli.php';
require_once __DIR__ . '/class-migration-admin.php';
require_once __DIR__ . '/class-application.php';

/**
 * Builds the Presenter application and its dependencies.
 */
final class Bootstrap {
	/**
	 * Runtime version for cache busting and migrations.
	 *
	 * @var string
	 */
	private const VERSION = '2.0.0-dev';

	/**
	 * Create the Presenter application.
	 *
	 * Hook providers will be added here as each Presenter 2.0 subsystem becomes
	 * ready to replace its characterized legacy counterpart.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @return Application Presenter application.
	 */
	public static function create( string $plugin_file ): Application {
		$context            = new Plugin_Context( $plugin_file, self::VERSION );
		$legacy_slides      = new WordPress_Legacy_Slide_Source();
		$deck_mode          = new Deck_Mode( $legacy_slides );
		$themes             = new Theme_Registry( $context );
		$renderer           = new Presentation_Renderer( new Reveal_Config() );
		$assets             = new Assets( $context );
		$deck_structure     = new Native_Deck_Structure();
		$slide_attrs        = new Slide_Attribute_Validator();
		$speaker_notes      = new Speaker_Notes();
		$snapshotter        = new Legacy_Deck_Snapshotter( $legacy_slides );
		$legacy_inventory   = new Legacy_Deck_Inventory();
		$planner            = new Migration_Planner(
			new Legacy_Slide_Normalizer(),
			new Legacy_Slide_Attribute_Mapper( $slide_attrs ),
			new Legacy_Section_Validator(),
			$speaker_notes,
			$themes
		);
		$migration_secret   = new Migration_Secret();
		$migration_lock     = new Migration_Lock();
		$migration_revision = new Migration_Revision();
		$migration_mode     = new Migration_Deck_Mode_Store();
		$context_builder    = new Migration_Context_Builder( $snapshotter, $planner, $migration_mode );
		$migration_status   = new Migration_Status_Service(
			$snapshotter,
			$planner,
			$migration_secret,
			$migration_lock,
			$migration_revision,
			$deck_mode,
			$migration_mode
		);
		$migration_preparer = new Migration_Preparer(
			$context_builder,
			$migration_secret,
			$migration_lock,
			$migration_revision,
			$migration_status,
			$deck_mode,
			$migration_mode
		);
		$migration_applier  = new Migration_Applier(
			$context_builder,
			$migration_secret,
			$migration_lock,
			$migration_revision,
			$migration_status,
			$migration_mode,
			$deck_structure,
			new Atomic_Migration_Post_Content_Writer(),
			new Null_Migration_Apply_Observer()
		);
		$migration_restorer = new Migration_Restorer(
			$migration_secret,
			$migration_lock,
			$migration_revision,
			$migration_status,
			$migration_mode,
			$deck_structure,
			new Atomic_Migration_Post_Content_Writer(),
			new Null_Migration_Restore_Observer()
		);

		return new Application(
			$context,
			$legacy_slides,
			$deck_mode,
			$themes,
			$renderer,
			new Post_Type(),
			new Meta(),
			$assets,
			new Blocks( $context, $slide_attrs, $speaker_notes ),
			new Editor_Integration( $themes ),
			new Template_Router( $context, $deck_mode, $assets, $themes, $deck_structure ),
			new Migration_Admin( $legacy_inventory, $migration_status, $migration_preparer ),
			new Migration_CLI( $legacy_inventory, $snapshotter, $planner, $migration_preparer, $migration_applier, $migration_restorer, $migration_status )
		);
	}
}
