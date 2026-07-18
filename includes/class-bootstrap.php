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
require_once __DIR__ . '/class-plugin-context.php';
require_once __DIR__ . '/class-wordpress-legacy-slide-source.php';
require_once __DIR__ . '/class-post-type.php';
require_once __DIR__ . '/class-meta.php';
require_once __DIR__ . '/class-assets.php';
require_once __DIR__ . '/class-theme.php';
require_once __DIR__ . '/class-theme-registry.php';
require_once __DIR__ . '/class-editor-integration.php';
require_once __DIR__ . '/class-reveal-config.php';
require_once __DIR__ . '/class-presentation-renderer.php';
require_once __DIR__ . '/class-slide-attribute-validator.php';
require_once __DIR__ . '/class-blocks.php';
require_once __DIR__ . '/class-template-router.php';
require_once __DIR__ . '/class-legacy-deck-snapshot.php';
require_once __DIR__ . '/class-legacy-deck-snapshotter.php';
require_once __DIR__ . '/class-legacy-slide-normalizer.php';
require_once __DIR__ . '/class-legacy-slide-attribute-mapper.php';
require_once __DIR__ . '/class-migration-plan.php';
require_once __DIR__ . '/class-migration-planner.php';
require_once __DIR__ . '/class-migration-cli.php';
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
		$context       = new Plugin_Context( $plugin_file, self::VERSION );
		$legacy_slides = new WordPress_Legacy_Slide_Source();
		$themes        = new Theme_Registry( $context );
		$renderer      = new Presentation_Renderer( new Reveal_Config() );
		$assets        = new Assets( $context );
		$slide_attrs   = new Slide_Attribute_Validator();
		$snapshotter   = new Legacy_Deck_Snapshotter( $legacy_slides );
		$planner       = new Migration_Planner(
			new Legacy_Slide_Normalizer(),
			new Legacy_Slide_Attribute_Mapper( $slide_attrs ),
			$themes
		);

		return new Application(
			$context,
			$legacy_slides,
			$themes,
			$renderer,
			new Post_Type(),
			new Meta(),
			$assets,
			new Blocks( $context, $slide_attrs ),
			new Editor_Integration( $themes ),
			new Template_Router( $context, $legacy_slides, $assets, $themes ),
			new Migration_CLI( $snapshotter, $planner )
		);
	}
}
