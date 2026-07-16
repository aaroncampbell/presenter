<?php
/**
 * Slideshow post type registration.
 *
 * @package Presenter
 */

namespace Presenter;

/**
 * Registers the slideshow content type for the block editor and REST API.
 */
final class Post_Type implements Hook_Provider {
	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the slideshow post type.
	 */
	public function register(): void {
		$labels = array(
			'name'               => _x( 'Slideshows', 'post type general name', 'presenter' ),
			'singular_name'      => _x( 'Slideshow', 'post type singular name', 'presenter' ),
			'add_new'            => _x( 'Add New', 'slideshow', 'presenter' ),
			'add_new_item'       => __( 'Add New Slideshow', 'presenter' ),
			'edit_item'          => __( 'Edit Slideshow', 'presenter' ),
			'new_item'           => __( 'New Slideshow', 'presenter' ),
			'view_item'          => __( 'View Slideshow', 'presenter' ),
			'view_items'         => __( 'View Slideshows', 'presenter' ),
			'search_items'       => __( 'Search Slideshows', 'presenter' ),
			'not_found'          => __( 'No slideshows found.', 'presenter' ),
			'not_found_in_trash' => __( 'No slideshows found in Trash.', 'presenter' ),
			'all_items'          => __( 'All Slideshows', 'presenter' ),
		);

		register_post_type(
			'slideshow',
			array(
				'labels'          => $labels,
				'description'     => __( 'Slide presentations.', 'presenter' ),
				'public'          => true,
				'show_in_rest'    => true,
				'has_archive'     => 'slideshows',
				'rewrite'         => array( 'slug' => 'slideshow' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => array(
					'title',
					'editor',
					'excerpt',
					'page-attributes',
					'custom-fields',
					'revisions',
				),
				'template'        => array(
					array(
						'presenter/deck',
						array(),
						array(
							array(
								'presenter/slide',
								array(),
								array(
									array( 'core/heading' ),
									array( 'core/paragraph' ),
								),
							),
						),
					),
				),
				'template_lock'   => 'all',
				'menu_icon'       => 'dashicons-slides',
			)
		);
	}
}
