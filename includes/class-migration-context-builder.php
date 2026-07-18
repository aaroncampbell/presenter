<?php
/**
 * Presenter migration preparation context builder.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Builds persistent private hashes from one ready, freshly captured plan.
 */
final class Migration_Context_Builder {
	/**
	 * Create the builder.
	 *
	 * @param Legacy_Deck_Snapshotter   $snapshotter Legacy deck snapshot service.
	 * @param Migration_Planner         $planner     Pure migration planner.
	 * @param Migration_Deck_Mode_Store $deck_mode Exact private marker storage.
	 */
	public function __construct(
		private Legacy_Deck_Snapshotter $snapshotter,
		private Migration_Planner $planner,
		private Migration_Deck_Mode_Store $deck_mode
	) {}

	/**
	 * Build one ready context without writing WordPress state.
	 *
	 * @param int              $post_id Slideshow post ID.
	 * @param Migration_Hasher $hasher  Persistent site-keyed hasher.
	 * @return Migration_Preparation_Context|null Ready context, or null.
	 */
	public function build( int $post_id, Migration_Hasher $hasher ): ?Migration_Preparation_Context {
		$snapshot = $this->snapshotter->capture( $post_id );
		if ( null === $snapshot ) {
			return null;
		}

		$plan = $this->planner->plan( $snapshot );
		if ( ! $plan->is_ready() || null === $plan->generated_content() ) {
			return null;
		}

		$legacy_meta = Legacy_Meta_Payload::capture( $post_id );
		$mode_meta   = $this->deck_mode->capture( $post_id );
		if ( null === $legacy_meta || array() !== $mode_meta ) {
			return null;
		}

		$post_source    = array(
			'id'          => $snapshot->post_id(),
			'type'        => $snapshot->post_type(),
			'name'        => $snapshot->slug(),
			'title'       => $snapshot->title(),
			'excerpt'     => $snapshot->excerpt(),
			'menuOrder'   => $snapshot->menu_order(),
			'status'      => $snapshot->status(),
			'password'    => $snapshot->password(),
			'postContent' => $snapshot->post_content(),
		);
		$target_content = $plan->generated_content();
		$source_hash    = $hasher->hash(
			'preparation-source',
			array(
				'post'         => $post_source,
				'legacyMeta'   => $legacy_meta->to_array(),
				'deckModeMeta' => $mode_meta,
			)
		);
		$retained_hash  = $hasher->hash( 'retained-legacy', $legacy_meta->to_array() );
		$deck_mode_hash = $hasher->hash( 'deck-mode-meta', $mode_meta );
		$original_hash  = $hasher->hash( 'post-content', $snapshot->post_content() );
		$target_hash    = $hasher->hash( 'post-content', $target_content );
		$revision_hash  = $hasher->hash(
			'revision-fields',
			array(
				'title'   => $snapshot->title(),
				'content' => $snapshot->post_content(),
				'excerpt' => $snapshot->excerpt(),
			)
		);
		$reference      = $hasher->hash(
			'preparation-reference',
			array(
				'postId'            => $post_id,
				'plannerVersion'    => Migration_Planner::VERSION,
				'preconditionHash'  => $source_hash,
				'targetContentHash' => $target_hash,
			)
		);

		return new Migration_Preparation_Context(
			$snapshot,
			$legacy_meta,
			$mode_meta,
			$target_content,
			$source_hash,
			$retained_hash,
			$deck_mode_hash,
			$original_hash,
			$target_hash,
			$revision_hash,
			$reference
		);
	}
}
