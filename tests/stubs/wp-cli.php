<?php

/**
 * Static surface used by Presenter's optional WP-CLI integration.
 *
 * This file is read only by PHPStan. WP-CLI provides the runtime class.
 */
class WP_CLI {
	/** Register a command. */
	public static function add_command( string $name, callable|object|string $callable ): bool {}

	/** Stop a command with an error. */
	public static function error( string|WP_Error $message ): never {}

	/** Write one output line. */
	public static function line( string $message ): void {}

	/** Write a success message. */
	public static function success( string $message ): void {}
}
