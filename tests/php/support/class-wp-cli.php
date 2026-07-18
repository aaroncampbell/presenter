<?php
/**
 * Capturing WP-CLI surface for migration adapter integration tests.
 *
 * @package Presenter
 */

/**
 * Records command adapter interactions without terminating PHPUnit.
 */
class WP_CLI {
	/**
	 * Registered commands.
	 *
	 * @var array<string, callable|object|string>
	 */
	public static array $commands = array();

	/**
	 * Confirmation requests.
	 *
	 * @var array<int, array{question: string, assocArgs: array<string, mixed>}>
	 */
	public static array $confirmations = array();

	/**
	 * Output lines.
	 *
	 * @var array<int, string>
	 */
	public static array $lines = array();

	/**
	 * Explicit halt statuses.
	 *
	 * @var array<int, int>
	 */
	public static array $halts = array();

	/** Reset every captured interaction. */
	public static function reset(): void {
		self::$commands      = array();
		self::$confirmations = array();
		self::$lines         = array();
		self::$halts         = array();
	}

	/**
	 * Capture one command registration.
	 *
	 * @param string                 $name             Command name.
	 * @param callable|object|string $command_callable Command handler.
	 */
	public static function add_command( string $name, callable|object|string $command_callable ): bool {
		self::$commands[ $name ] = $command_callable;
		return true;
	}

	/**
	 * Throw in place of terminating the process with an error.
	 *
	 * @param string|WP_Error $message Error message.
	 * @throws RuntimeException Always, to replace process termination.
	 */
	public static function error( string|WP_Error $message ): never {
		unset( $message );
		throw new RuntimeException( 'wp_cli_error' );
	}

	/**
	 * Capture a confirmation request.
	 *
	 * @param string               $question   Confirmation question.
	 * @param array<string, mixed> $assoc_args Named command arguments.
	 */
	public static function confirm( string $question, array $assoc_args = array() ): void {
		self::$confirmations[] = array(
			'question'  => $question,
			'assocArgs' => $assoc_args,
		);
	}

	/**
	 * Capture the status and throw in place of exiting PHPUnit.
	 *
	 * @param int $status Exit status.
	 * @throws RuntimeException Always, to replace process termination.
	 */
	public static function halt( int $status ): never {
		self::$halts[] = $status;
		throw new RuntimeException( 'wp_cli_halt' );
	}

	/**
	 * Capture one output line.
	 *
	 * @param string $message Output line.
	 */
	public static function line( string $message ): void {
		self::$lines[] = $message;
	}

	/**
	 * Capture success as a normal output line.
	 *
	 * @param string $message Success message.
	 */
	public static function success( string $message ): void {
		self::$lines[] = $message;
	}
}
