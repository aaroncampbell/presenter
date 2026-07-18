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
	 * Maximum decks accepted by one dry-run invocation.
	 *
	 * @var int
	 */
	private const MAX_BATCH_SIZE = 100;

	/**
	 * Legacy deck snapshot service.
	 *
	 * @var Legacy_Deck_Snapshotter
	 */
	private Legacy_Deck_Snapshotter $snapshotter;

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
	 * Zero-write migration status service.
	 *
	 * @var Migration_Status_Service
	 */
	private Migration_Status_Service $status;

	/**
	 * Create the CLI adapter.
	 *
	 * @param Legacy_Deck_Snapshotter  $snapshotter Legacy deck snapshot service.
	 * @param Migration_Planner        $planner     Pure migration planner.
	 * @param Migration_Preparer       $preparer    Explicit preparation service.
	 * @param Migration_Applier        $applier     Verified content apply service.
	 * @param Migration_Status_Service $status     Zero-write status service.
	 */
	public function __construct( Legacy_Deck_Snapshotter $snapshotter, Migration_Planner $planner, Migration_Preparer $preparer, Migration_Applier $applier, Migration_Status_Service $status ) {
		$this->snapshotter = $snapshotter;
		$this->planner     = $planner;
		$this->preparer    = $preparer;
		$this->applier     = $applier;
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
		\WP_CLI::add_command( 'presenter migration status', array( $this, 'status' ) );
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
			: $this->legacy_post_ids(
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
	 * Find a bounded, deterministic batch of legacy slideshow IDs.
	 *
	 * @param int $limit  Maximum result count.
	 * @param int $offset Result offset.
	 * @return array<int, int> Post IDs.
	 */
	private function legacy_post_ids( int $limit, int $offset ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'slideshow',
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'meta_key'               => '_presenter_slides', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Explicit bounded maintenance command.
				'meta_compare'           => 'EXISTS', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_compare -- Explicit bounded maintenance command.
				'no_found_rows'          => true,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_map( 'intval', $query->posts );
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
		if ( $limit < 1 || $limit > self::MAX_BATCH_SIZE || (string) $limit !== $value ) {
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
