<?php
/**
 * Theme contract characterization tests.
 *
 * @package Presenter
 */

/**
 * Verify the legacy theme discovery and selection contracts.
 */
class Presenter_Theme_Contract_Test extends Presenter_Test_Case {
	/**
	 * Temporary theme directory for the current test.
	 *
	 * @var string
	 */
	private $theme_directory;

	/**
	 * Files created by the current test.
	 *
	 * @var string[]
	 */
	private $theme_files = array();

	/**
	 * Directories created below the temporary theme directory.
	 *
	 * @var string[]
	 */
	private $theme_subdirectories = array();

	/**
	 * Create a clean fixture directory and clear Presenter's theme cache.
	 */
	public function set_up(): void {
		parent::set_up();

		$temporary_file = wp_tempnam( 'presenter-theme-test' );
		$this->assertNotEmpty( $temporary_file );
		$this->assertTrue( wp_delete_file( $temporary_file ) );

		$this->theme_directory = $temporary_file;
		$this->assertTrue( wp_mkdir_p( $this->theme_directory ) );
		wp_cache_delete( 'presenter-themes', 'presenter' );
	}

	/**
	 * Remove fixture files and reset global state changed by a test.
	 */
	public function tear_down(): void {
		wp_cache_delete( 'presenter-themes', 'presenter' );
		wp_deregister_style( 'reveal-theme' );

		foreach ( array_reverse( $this->theme_files ) as $theme_file ) {
			wp_delete_file( $theme_file );
		}

		foreach ( array_reverse( $this->theme_subdirectories ) as $theme_subdirectory ) {
			rmdir( $theme_subdirectory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes an isolated test fixture directory.
		}

		rmdir( $this->theme_directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes an isolated test fixture directory.

		parent::tear_down();
	}

	/**
	 * A directory filter can replace discovery roots, and Template Name is parsed.
	 */
	public function test_theme_directories_filter_controls_discovery_roots(): void {
		$named_theme = $this->write_theme(
			'fixture-blue.css',
			"/*\nTemplate Name: Fixture Blue\n*/\n"
		);
		$this->write_theme( 'not-a-theme.css', 'body { color: blue; }' );

		$directory_filter = function ( array $directories ): array {
			$this->assertNotEmpty( $directories );

			return array( $this->theme_directory );
		};
		add_filter( 'presenter-theme-directories', $directory_filter );

		$themes = presenter::get_instance()->get_themes();

		remove_filter( 'presenter-theme-directories', $directory_filter );
		$this->assertSame( array( $named_theme => 'Fixture Blue' ), $themes );
	}

	/**
	 * Missing discovery roots are ignored without producing a false filename.
	 */
	public function test_missing_theme_directory_returns_an_empty_registry(): void {
		$missing_directory = $this->theme_directory . '/does-not-exist';
		$directory_filter  = static function () use ( $missing_directory ): array {
			return array( $missing_directory );
		};
		add_filter( 'presenter-theme-directories', $directory_filter );

		$themes = presenter::get_instance()->get_themes();

		remove_filter( 'presenter-theme-directories', $directory_filter );
		$this->assertSame( array(), $themes );
	}

	/**
	 * Reveal's historical comment header gains a filename suffix.
	 */
	public function test_reveal_theme_header_includes_filename_in_label(): void {
		$theme_file       = $this->write_theme(
			'ocean.css',
			"/**\n * Ocean theme for reveal.js.\n */\n"
		);
		$directory_filter = $this->replace_theme_directories_filter();

		$themes = presenter::get_instance()->get_themes();

		remove_filter( 'presenter-theme-directories', $directory_filter );
		$this->assertSame( array( $theme_file => 'Ocean (ocean.css)' ), $themes );
	}

	/**
	 * The themes filter may remove entries but cannot add or rename them.
	 */
	public function test_presenter_themes_filter_is_restrictive(): void {
		$alpha_theme      = $this->write_theme( 'alpha.css', "/* Template Name: Alpha */\n" );
		$beta_theme       = $this->write_theme( 'beta.css', "/* Template Name: Beta */\n" );
		$directory_filter = $this->replace_theme_directories_filter();
		$themes_filter    = function ( array $themes, $presenter ) use ( $alpha_theme, $beta_theme ): array {
			$this->assertSame( presenter::get_instance(), $presenter );

			return array(
				$alpha_theme                 => $themes[ $alpha_theme ],
				$beta_theme                  => 'Renamed Beta',
				'/synthetic/added-theme.css' => 'Added Theme',
			);
		};
		add_filter( 'presenter-themes', $themes_filter, 10, 2 );

		$themes = presenter::get_instance()->get_themes();

		remove_filter( 'presenter-themes', $themes_filter, 10 );
		remove_filter( 'presenter-theme-directories', $directory_filter );
		$this->assertSame( array( $alpha_theme => 'Alpha' ), $themes );
	}

	/**
	 * Theme discovery results remain cached until the Presenter cache is cleared.
	 */
	public function test_theme_discovery_uses_presenter_object_cache(): void {
		$first_theme      = $this->write_theme( 'first.css', "/* Template Name: First */\n" );
		$directory_filter = $this->replace_theme_directories_filter();

		$this->assertSame(
			array( $first_theme => 'First' ),
			presenter::get_instance()->get_themes()
		);

		$this->write_theme( 'second.css', "/* Template Name: Second */\n" );
		$this->assertSame(
			array( $first_theme => 'First' ),
			presenter::get_instance()->get_themes()
		);

		wp_cache_delete( 'presenter-themes', 'presenter' );
		$this->assertCount( 2, presenter::get_instance()->get_themes() );

		remove_filter( 'presenter-theme-directories', $directory_filter );
	}

	/**
	 * The bundled league theme is the default and remains filterable.
	 */
	public function test_default_theme_is_filterable(): void {
		$presenter     = presenter::get_instance();
		$bundled_theme = str_replace(
			WP_CONTENT_DIR,
			'',
			dirname( __DIR__, 2 ) . '/reveal.js/dist/theme/league.css'
		);

		$this->assertSame( $bundled_theme, $presenter->get_default_theme() );

		$default_filter = static function (): string {
			return '/plugins/companion/fixture.css';
		};
		add_filter( 'presenter-default-theme', $default_filter );

		$this->assertSame( '/plugins/companion/fixture.css', $presenter->get_default_theme() );

		remove_filter( 'presenter-default-theme', $default_filter );
	}

	/**
	 * A saved theme path is converted to a content URL and filtered at rendering.
	 */
	public function test_selected_theme_url_is_filterable_when_template_loads(): void {
		$post_id = $this->create_slideshow_without_legacy_editor_post_data(
			array(
				'post_type'   => 'slideshow',
				'post_status' => 'publish',
			)
		);
		$this->add_legacy_slide_fixture( $post_id );
		update_post_meta( $post_id, '_presenter-theme', '/plugins/companion/fixture.css' );
		$this->go_to( get_permalink( $post_id ) );

		$theme_filter = function ( string $theme_url ): string {
			$this->assertSame( content_url( '/plugins/companion/fixture.css' ), $theme_url );

			return 'https://themes.example.test/fixture.css';
		};
		add_filter( 'presenter-theme', $theme_filter );

		presenter::get_instance()->single_template( '/tmp/fallback.php' );
		$registered_theme = wp_styles()->registered['reveal-theme'];

		remove_filter( 'presenter-theme', $theme_filter );
		$this->assertSame( 'https://themes.example.test/fixture.css', $registered_theme->src );
	}

	/**
	 * Install a filter that restricts discovery to the fixture directory.
	 *
	 * @return Closure
	 */
	private function replace_theme_directories_filter(): Closure {
		$directory_filter = function (): array {
			return array( $this->theme_directory );
		};
		add_filter( 'presenter-theme-directories', $directory_filter );

		return $directory_filter;
	}

	/**
	 * Write a CSS fixture below the temporary theme directory.
	 *
	 * @param string $relative_path Relative fixture path.
	 * @param string $contents      CSS fixture contents.
	 * @return string Absolute fixture path.
	 */
	private function write_theme( string $relative_path, string $contents ): string {
		$theme_file      = $this->theme_directory . '/' . $relative_path;
		$theme_directory = dirname( $theme_file );

		if ( $theme_directory !== $this->theme_directory && ! is_dir( $theme_directory ) ) {
			$this->assertTrue( wp_mkdir_p( $theme_directory ) );
			$this->theme_subdirectories[] = $theme_directory;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes an isolated test fixture.
		$this->assertNotFalse( file_put_contents( $theme_file, $contents ) );
		$this->theme_files[] = $theme_file;

		return $theme_file;
	}
}
