<?php
/**
 * Plugin context value object.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Holds paths and version information shared by runtime services.
 */
final class Plugin_Context {
	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Presenter runtime version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Create the plugin context.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @param string $version     Presenter runtime version.
	 */
	public function __construct( string $plugin_file, string $version ) {
		$this->plugin_file = $plugin_file;
		$this->version     = $version;
	}

	/**
	 * Get the absolute path to the main plugin file.
	 *
	 * @return string Plugin file path.
	 */
	public function plugin_file(): string {
		return $this->plugin_file;
	}

	/**
	 * Get the absolute plugin directory path without a trailing separator.
	 *
	 * @return string Plugin directory path.
	 */
	public function directory(): string {
		return dirname( $this->plugin_file );
	}

	/**
	 * Get the plugin's public base URL.
	 *
	 * @return string Plugin base URL with a trailing slash.
	 */
	public function url(): string {
		return plugin_dir_url( $this->plugin_file );
	}

	/**
	 * Get the Presenter runtime version.
	 *
	 * @return string Runtime version.
	 */
	public function version(): string {
		return $this->version;
	}
}
