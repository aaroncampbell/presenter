<?php
/**
 * Atomic Presenter migration post-content writer contract.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Replaces post content only when every prepared post field still matches.
 */
interface Migration_Post_Content_Writer {
	/**
	 * Atomically replace one slideshow's exact prepared content.
	 *
	 * @param int                                                                                                                                               $post_id            Slideshow post ID.
	 * @param array{id: int, type: string, name: string, title: string, excerpt: string, menuOrder: int, status: string, password: string, postContent: string} $expected_post       Exact prepared post fields.
	 * @param string                                                                                                                                            $replacement_content Replacement post content.
	 * @return bool Whether exactly one row changed and exact readback succeeded.
	 */
	public function compare_and_swap( int $post_id, array $expected_post, string $replacement_content ): bool;
}
