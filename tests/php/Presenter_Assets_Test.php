<?php
/**
 * Presenter modern asset registration tests.
 *
 * @package Presenter
 */

use Presenter\Assets;
use Presenter\Plugin_Context;

/**
 * Verify modern asset handles are registered once per service instance.
 */
class Presenter_Assets_Test extends Presenter_Test_Case {
	/**
	 * Repeated defensive calls do not reload generated metadata files.
	 */
	public function test_asset_registration_is_idempotent(): void {
		$original_scripts = clone wp_scripts();
		$original_styles  = clone wp_styles();
		$temporary_file   = wp_tempnam( 'presenter-assets-test' );
		$this->assertNotEmpty( $temporary_file );
		$this->assertTrue( wp_delete_file( $temporary_file ) );

		$plugin_directory = $temporary_file;
		$build_directory  = $plugin_directory . '/build';
		$this->assertTrue( wp_mkdir_p( $build_directory ) );
		$GLOBALS['presenter_test_asset_metadata_reads'] = array();

		try {
			foreach ( array( 'frontend', 'index', 'admin-migration' ) as $entrypoint ) {
				$metadata_file = $build_directory . '/' . $entrypoint . '.asset.php';
				$metadata      = "<?php\n"
					. '$GLOBALS[\'presenter_test_asset_metadata_reads\'][\'' . $entrypoint . '\'] = '
					. "1 + ( \$GLOBALS['presenter_test_asset_metadata_reads']['" . $entrypoint . "'] ?? 0 );\n"
					. "return array( 'dependencies' => array(), 'version' => 'fixture-version' );\n";
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes an isolated generated-metadata fixture.
				$this->assertNotFalse( file_put_contents( $metadata_file, $metadata ) );
			}

			$assets = new Assets(
				new Plugin_Context( $plugin_directory . '/presenter.php', 'fixture-version' )
			);
			$assets->register();
			$assets->register();
			$assets->enqueue_admin_migration();
			$assets->enqueue_presentation( 'https://themes.example.test/fixture.css' );

			$this->assertSame(
				array(
					'frontend'        => 1,
					'index'           => 1,
					'admin-migration' => 1,
				),
				$GLOBALS['presenter_test_asset_metadata_reads']
			);
			$this->assertTrue( wp_script_is( 'presenter-admin-migration', 'enqueued' ) );
			$this->assertTrue( wp_script_is( 'presenter-frontend', 'enqueued' ) );
			$this->assertTrue( wp_style_is( 'presenter-frontend', 'enqueued' ) );
		} finally {
			$GLOBALS['wp_scripts'] = $original_scripts; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the complete pre-test registry.
			$GLOBALS['wp_styles']  = $original_styles; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the complete pre-test registry.

			foreach ( array( 'frontend', 'index', 'admin-migration' ) as $entrypoint ) {
				wp_delete_file( $build_directory . '/' . $entrypoint . '.asset.php' );
			}
			rmdir( $build_directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes an isolated test fixture directory.
			rmdir( $plugin_directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes an isolated test fixture directory.
			unset( $GLOBALS['presenter_test_asset_metadata_reads'] );
		}
	}
}
