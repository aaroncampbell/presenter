<?php
/**
 * Presenter migration WP-CLI commands.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Exposes bounded, explicit migration operations to WP-CLI.
 */
final class Migration_CLI implements Hook_Provider {
	/**
	 * Legacy deck snapshot service.
	 *
	 * @var Legacy_Deck_Snapshotter
	 */
	private Legacy_Deck_Snapshotter $snapshotter;

	/**
	 * Bounded, filter-independent legacy deck inventory.
	 *
	 * @var Legacy_Deck_Inventory
	 */
	private Legacy_Deck_Inventory $inventory;

	/**
	 * Pure migration planner.
	 *
	 * @var Migration_Planner
	 */
	private Migration_Planner $planner;

	/**
	 * Explicit safety-artifact preparation service.
	 *
	 * @var Migration_Preparer
	 */
	private Migration_Preparer $preparer;

	/**
	 * Verified native-content apply service.
	 *
	 * @var Migration_Applier
	 */
	private Migration_Applier $applier;

	/**
	 * Verified legacy restore service.
	 *
	 * @var Migration_Restorer
	 */
	private Migration_Restorer $restorer;

	/**
	 * Zero-write migration status service.
	 *
	 * @var Migration_Status_Service
	 */
	private Migration_Status_Service $status;

	/**
	 * Create the CLI adapter.
	 *
	 * @param Legacy_Deck_Inventory    $inventory   Legacy deck inventory.
	 * @param Legacy_Deck_Snapshotter  $snapshotter Legacy deck snapshot service.
	 * @param Migration_Planner        $planner     Pure migration planner.
	 * @param Migration_Preparer       $preparer    Explicit preparation service.
	 * @param Migration_Applier        $applier     Verified content apply service.
	 * @param Migration_Restorer       $restorer   Verified legacy restore service.
	 * @param Migration_Status_Service $status     Zero-write status service.
	 */
	public function __construct( Legacy_Deck_Inventory $inventory, Legacy_Deck_Snapshotter $snapshotter, Migration_Planner $planner, Migration_Preparer $preparer, Migration_Applier $applier, Migration_Restorer $restorer, Migration_Status_Service $status ) {
		$this->inventory   = $inventory;
		$this->snapshotter = $snapshotter;
		$this->planner     = $planner;
		$this->preparer    = $preparer;
		$this->applier     = $applier;
		$this->restorer    = $restorer;
		$this->status      = $status;
	}

	/**
	 * Register the command only inside WP-CLI.
	 */
	public function register_hooks(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'presenter migration dry-run', array( $this, 'dry_run' ) );
		\WP_CLI::add_command( 'presenter migration prepare', array( $this, 'prepare' ) );
		\WP_CLI::add_command( 'presenter migration apply', array( $this, 'apply' ) );
		\WP_CLI::add_command( 'presenter migration restore', array( $this, 'restore' ) );
		\WP_CLI::add_command( 'presenter migration status', array( $this, 'status' ) );
	}

	/**
	 * Restore one applied deck to its verified legacy representation.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : Restore one applied slideshow.
	 *
	 * [--yes]
	 * : Skip the interactive confirmation.
	 *
	 * [--discard-native-edits]
	 * : Preserve modified native content in a revision, then restore the signed legacy source.
	 *
	 * ## EXAMPLES
	 *
	 *     wp presenter migration restore 123 --yes
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function restore( array $args, array $assoc_args ): void {
		if ( ! isset( $args[0] ) ) {
			\WP_CLI::error( 'post-id is required.' );
		}

		$post_id              = $this->required_post_id( $args[0] );
		$discard_native_edits = isset( $assoc_args['discard-native-edits'] ) && false !== $assoc_args['discard-native-edits'];
		\WP_CLI::confirm(
			$discard_native_edits
				? sprintf( 'Preserve the current native content in a revision, discard it, and restore verified legacy slideshow %d?', $post_id )
				: sprintf( 'Restore verified legacy content and routing for slideshow %d?', $post_id ),
			$assoc_args
		);

		$result = $this->restorer->restore( $post_id, $discard_native_edits );
		$this->write_json( $result );

		if (
			Migration_Journal::STATE_RESTORED !== $result['journal']['state']
			|| ( ! in_array( 'restored', $result['codes'], true ) && ! in_array( 'already_restored', $result['codes'], true ) )
		) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Apply one prepared deck and cut over only after exact verification.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : Apply one prepared legacy slideshow.
	 *
	 * [--yes]
	 * : Skip the interactive confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp presenter migration apply 123 --yes
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function apply( array $args, array $assoc_args ): void {
		if ( ! isset( $args[0] ) ) {
			\WP_CLI::error( 'post-id is required.' );
		}

		$post_id = $this->required_post_id( $args[0] );
		\WP_CLI::confirm(
			sprintf( 'Apply prepared native content and cut over slideshow %d?', $post_id ),
			$assoc_args
		);

		$result = $this->applier->apply( $post_id );
		$this->write_json( $result );

		if (
			Migration_Journal::STATE_APPLIED !== $result['journal']['state']
			|| ( ! in_array( 'applied', $result['codes'], true ) && ! in_array( 'already_applied', $result['codes'], true ) )
		) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Create verified safety artifacts for one ready deck without applying it.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : Prepare one legacy slideshow.
	 *
	 * [--yes]
	 * : Skip the interactive confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp presenter migration prepare 123 --yes
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 */
	public function prepare( array $args, array $assoc_args ): void {
		if ( ! isset( $args[0] ) ) {
			\WP_CLI::error( 'post-id is required.' );
		}

		$post_id = $this->required_post_id( $args[0] );
		\WP_CLI::confirm(
			sprintf( 'Prepare migration safety artifacts for slideshow %d?', $post_id ),
			$assoc_args
		);

		$result = $this->preparer->prepare( $post_id );
		$this->write_json( $result );

		if (
			! $result['capabilities']['canApply'] ||
			( ! in_array( 'prepared', $result['codes'], true ) && ! in_array( 'already_prepared', $result['codes'], true ) )
		) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Inspect one deck's migration state without writing WordPress state.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : Inspect one slideshow.
	 *
	 * ## EXAMPLES
	 *
	 *     wp presenter migration status 123
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		if ( ! isset( $args[0] ) ) {
			\WP_CLI::error( 'post-id is required.' );
		}

		$this->write_json( $this->status->inspect( $this->required_post_id( $args[0] ) ) );
	}

	/**
	 * Produce deterministic migration plans without writing to WordPress.
	 *
	 * ## OPTIONS
	 *
	 * [<post-id>]
	 * : Inspect one slideshow post. Omit to inspect a bounded batch.
	 *
	 * [--limit=<limit>]
	 * : Maximum batch size when post-id is omitted. Defaults to 20; maximum 100.
	 *
	 * [--offset=<offset>]
	 * : Zero-based batch offset when post-id is omitted. Defaults to 0.
	 *
	 * ## EXAMPLES
	 *
	 *     wp presenter migration dry-run 123
	 *     wp presenter migration dry-run --limit=50 --offset=0
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function dry_run( array $args, array $assoc_args ): void {
		$post_ids = isset( $args[0] )
			? array( $this->required_post_id( $args[0] ) )
			: $this->inventory->ids(
				$this->batch_limit( $assoc_args['limit'] ?? '20' ),
				$this->batch_offset( $assoc_args['offset'] ?? '0' )
			);

		$reports = array();
		foreach ( $post_ids as $post_id ) {
			$snapshot = $this->snapshotter->capture( $post_id );
			if ( null === $snapshot ) {
				\WP_CLI::error( sprintf( 'Post %d is not a legacy Presenter slideshow.', $post_id ) );
			}

			$reports[] = $this->planner->plan( $snapshot )->report();
		}

		$this->write_json(
			array(
				'schemaVersion' => 1,
				'mode'          => 'dry-run',
				'count'         => count( $reports ),
				'reports'       => $reports,
			)
		);
	}

	/**
	 * Write one JSON response without exposing serialization failures.
	 *
	 * @param array<string, mixed> $value Response value.
	 */
	private function write_json( array $value ): void {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			\WP_CLI::error( 'Presenter could not encode the migration response.' );
		}

		\WP_CLI::line( $json );
	}

	/**
	 * Validate one explicit slideshow post ID.
	 *
	 * @param string $value Candidate ID.
	 * @return int Valid ID.
	 */
	private function required_post_id( string $value ): int {
		$post_id = absint( $value );
		if ( $post_id < 1 || (string) $post_id !== $value ) {
			\WP_CLI::error( 'post-id must be a positive integer.' );
		}

		return $post_id;
	}

	/**
	 * Validate the bounded batch size.
	 *
	 * @param string $value Candidate limit.
	 * @return int Valid limit.
	 */
	private function batch_limit( string $value ): int {
		$limit = absint( $value );
		if ( $limit < 1 || $limit > Legacy_Deck_Inventory::MAX_BATCH_SIZE || (string) $limit !== $value ) {
			\WP_CLI::error( 'limit must be an integer from 1 through 100.' );
		}

		return $limit;
	}

	/**
	 * Validate a non-negative batch offset.
	 *
	 * @param string $value Candidate offset.
	 * @return int Valid offset.
	 */
	private function batch_offset( string $value ): int {
		$offset = absint( $value );
		if ( (string) $offset !== $value ) {
			\WP_CLI::error( 'offset must be a non-negative integer.' );
		}

		return $offset;
	}
}
