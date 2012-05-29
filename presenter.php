<?php
/**
 * Plugin Name: Presenter
 * Plugin URI: http://bluedogwebservices.com/
 * Description: Present slideshows on WordPress using deck.js
 * Version: 0.0.1
 * Author: Aaron D. Campbell
 * Author URI: http://bluedogwebservices.com/
 * Text Domain: presenter
 */

/**
 * presenter is the class that handles ALL of the plugin functionality, helping
 * avoid name collisions
 */
class presenter  {
	/**
	 * @var presenter - Static property to hold our singleton instance
	 */
	static $instance = false;

	/**
	 * @var array Plugin settings
	 */
	protected $_settings;

	/**
	 * @var string - The filename for the main plugin file
	 */
	protected $_file = '';

	/**
	 * @var string - The options page name used in the URL
	 */
	protected $_hook = 'presenter';

	/**
	 * @var string - The plugin slug used on WordPress.org
	 */
	protected $_slug = 'presenter';

	/**
	 * @var array Posts Processed
	 */
	private $_processedPosts = array();

	/**
	 * Function to instantiate our class and make it a singleton
	 */
	public static function getInstance() {
		if ( !self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	 * This is our constructor, which is private to force the use of getInstance()
	 * @return void
	 */
	private function __construct() {
		$this->_file = plugin_basename( __FILE__ );
		$this->_pageTitle = __( 'Presenter', $this->_slug );
		$this->_menuTitle = __( 'Presenter', $this->_slug );
		$this->_accessLevel = 'manage_options';
		$this->_optionGroup = 'presenter-options';
		$this->_optionNames = array('presenter');
		$this->_optionCallbacks = array();
		$this->_slug = 'presenter';

		/**
		 * Add filters and actions
		 */
		add_action( 'init', array( $this, 'init' ) );
		add_filter( 'template_include', array( $this, 'template_include' ) );
		add_action( 'wp_ajax_order-slides', array( $this, 'ajax_order_slides' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_filter( 'manage_slide_posts_columns', array( $this, 'manage_posts_columns' ) );
		add_action( 'manage_slide_posts_custom_column', array( $this, 'manage_posts_custom_column' ), null, 2 );
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
	}

	public function init() {
		$labels = array(
			'name'               => _x( 'Slides', 'post type general name', $this->_slug ),
			'singular_name'      => _x( 'Slide', 'post type singular name', $this->_slug ),
			'add_new'            => _x( 'Add New', 'post', $this->_slug ),
			'add_new_item'       => __( 'Add New Slide', $this->_slug ),
			'edit_item'          => __( 'Edit Slide', $this->_slug ),
			'new_item'           => __( 'New Slide', $this->_slug ),
			'view_item'          => __( 'View Slide', $this->_slug ),
			'search_items'       => __( 'Search Slides', $this->_slug ),
			'not_found'          => __( 'No slides found.', $this->_slug ),
			'not_found_in_trash' => __( 'No slides found in Trash.', $this->_slug ),
			'all_items'          => __( 'All Slides', $this->_slug ),
		);
		$args = array(
			'labels'          => $labels,
			'description'     => __( 'Slides', $this->_slug ),
			'has_archive'     => 'slides',
			'rewrite'         => array(
				'feeds'	=> true
			),
			'public'          => true,
			'supports'        => array(
				'thumbnail',
				'excerpt',
				'custom-fields',
				'revisions',
				'title',
				'editor'
			),
			'show_in_nav_menus' => true,
		);
		register_post_type( 'slide', $args );

		// Add Slideshows Taxonomy
		$labels = array(
			'name'                       => _x( 'Slideshows', 'taxonomy general name', $this->_slug ),
			'singular_name'              => _x( 'Slideshow', 'taxonomy singular name', $this->_slug ),
			'search_items'               => __( 'Search Slideshows', $this->_slug ),
			'popular_items'              => __( 'Popular Slideshows', $this->_slug ),
			'all_items'                  => __( 'All Slideshows', $this->_slug ),
			'edit_item'                  => __( 'Edit Slideshow', $this->_slug ),
			'view_item'                  => __( 'View Slideshow', $this->_slug ),
			'update_item'                => __( 'Update Slideshow', $this->_slug ),
			'add_new_item'               => __( 'Add New Slideshow', $this->_slug ),
			'new_item_name'              => __( 'New Slideshow Name', $this->_slug ),
			'separate_items_with_commas' => __( 'Separate slideshows with commas', $this->_slug ),
			'add_or_remove_items'        => __( 'Add or remove slideshows', $this->_slug ),
			'choose_from_most_used'      => __( 'Choose from the most used slideshows', $this->_slug ),
		);

		register_taxonomy(
			'slideshow',
			array( 'slide' ),
			array(
				'labels' => $labels,
			)
		);
	}

	public function pre_get_posts( $query ) {
		if ( ! is_admin() && is_tax( 'slideshow' ) ) {
			set_query_var( 'nopaging', true );
			set_query_var( 'orderby', 'menu_order' );
			set_query_var( 'order', 'ASC' );
			return;
		}
	}

	public function wp_enqueue_scripts() {
		global $wp_styles;
		foreach ( $wp_styles->queue as $s ) {
			wp_deregister_style( $s );
		}
		wp_enqueue_style( 'deck', plugin_dir_url( __FILE__ ) . 'deck.js/core/deck.core.css', array(), '20120508' );
		wp_enqueue_style( 'deck-ext-goto', plugin_dir_url( __FILE__ ) . 'deck.js/extensions/goto/deck.goto.css', array( 'deck' ), '20120508' );
		wp_enqueue_style( 'deck-ext-menu', plugin_dir_url( __FILE__ ) . 'deck.js/extensions/menu/deck.menu.css', array( 'deck' ), '20120508' );
		wp_enqueue_style( 'deck-ext-status', plugin_dir_url( __FILE__ ) . 'deck.js/extensions/status/deck.status.css', array( 'deck' ), '20120508' );
		wp_enqueue_style( 'deck-ext-hash', plugin_dir_url( __FILE__ ) . 'deck.js/extensions/hash/deck.hash.css', array( 'deck' ), '20120508' );
		wp_enqueue_style( 'deck-ext-scale', plugin_dir_url( __FILE__ ) . 'deck.js/extensions/scale/deck.scale.css', array( 'deck' ), '20120508' );
		//wp_enqueue_style( 'vise-all', plugin_dir_url( __FILE__ ) . 'deck.js/extensions/vise/jquery.vise.all.css', array(), '20120508' );
		//wp_enqueue_style( 'deck-ext-vise', plugin_dir_url( __FILE__ ) . 'deck.js/extensions/vise/jquery.vise.deck.css', array( 'deck', 'vise-all' ), '20120508' );
		wp_enqueue_style( 'deck-theme', plugin_dir_url( __FILE__ ) . 'deck.js/themes/style/range/rwd.theme.css', array( 'deck' ), '20120508' );
		wp_enqueue_style( 'deck-trans', plugin_dir_url( __FILE__ ) . 'deck.js/themes/transition/horizontal-slide.css', array( 'deck' ), '20120508' );
	}

	public function wp_print_scripts() {
		global $wp_scripts;
		foreach ( $wp_scripts->queue as $s ) {
			wp_deregister_script( $s );
		}
		wp_enqueue_script( 'modernizr', plugin_dir_url( __FILE__ ) . 'deck.js/modernizr.custom.js', array(), '20120508' );
		wp_enqueue_script( 'deck', plugin_dir_url( __FILE__ ) . 'deck.js/core/deck.core.js', array( 'jquery', 'modernizr' ), '20120508', true );
		wp_enqueue_script( 'deck-ext-hash', plugin_dir_url( __FILE__ ) . 'deck.js/extensions/hash/deck.hash.js', array( 'deck' ), '20120508', true );
		wp_enqueue_script( 'deck-ext-menu', plugin_dir_url( __FILE__ ) . 'deck.js/extensions/menu/deck.menu.js', array( 'deck' ), '20120508', true );
		wp_enqueue_script( 'deck-ext-goto', plugin_dir_url( __FILE__ ) . 'deck.js/extensions/goto/deck.goto.js', array( 'deck' ), '20120508', true );
		wp_enqueue_script( 'deck-ext-status', plugin_dir_url( __FILE__ ) . 'deck.js/extensions/status/deck.status.js', array( 'deck' ), '20120508', true );
		wp_enqueue_script( 'deck-ext-scale', plugin_dir_url( __FILE__ ) . 'deck.js/extensions/scale/deck.scale.js', array( 'deck' ), '20120508', true );
		//wp_enqueue_script( 'vise-all', plugin_dir_url( __FILE__ ) . 'deck.js/extensions/vise/jquery.vise.all.js', array( 'jquery' ), '20120508', true );
		//wp_enqueue_script( 'deck-ext-vise', plugin_dir_url( __FILE__ ) . 'deck.js/extensions/vise/jquery.vise.deck.js', array( 'deck', 'vise-all' ), '20120508', true );
	}

	public function manage_posts_columns( $columns ) {
		$columns['menu_order'] = 'Order';
		return $columns;
	}

	public function manage_posts_custom_column( $column_name, $post_id ) {
		if ( 'menu_order' == $column_name ) {
			$p = get_post( $post_id );
			echo esc_html( $p->menu_order );
		}
	}

	public function manage_sortable_columns( $columns ) {
		$columns['menu_order'] = 'menu_order';
		return $columns;
	}

	public function ajax_order_slides() {
		if ( ! current_user_can('edit_others_pages') || empty( $_POST['id'] ) || ( ! isset( $_POST['previd'] ) && ! isset( $_POST['nextid'] ) ) )
			die(-1);

		if ( ! $post = get_post( $_POST['id'] ) )
			die(-1);

		$previd = isset( $_POST['previd'] ) ? $_POST['previd'] : false;
		$nextid = isset( $_POST['nextid'] ) ? $_POST['nextid'] : false;
		$new_pos = array(); // store new positions for ajax

		$slideshows = wp_list_pluck( get_the_terms( $_POST['id'], 'slideshow' ), 'term_id' );

		$siblings = get_posts(array(
			'depth' => 1,
			'numberposts' => -1,
			'post_type' => $post->post_type,
			'post_status' => 'publish,pending,draft,future,private',
			'post_parent' => $post->post_parent,
			'orderby' => 'menu_order title',
			'order' => 'ASC',
			'exclude' => $post->ID,
			'tax_query' => array(
				array(
					'taxonomy' => 'slideshow',
					'terms' => $slideshows,
					'field' => 'term_id',
				)
			)
		)); // fetch all the siblings (relative ordering)

		$menu_order = 0;

		foreach( $siblings as $sibling ) :

			// if this is the post that comes after our repositioned post, set our repositioned post position and increment menu order
			if ( $nextid == $sibling->ID ) {
				wp_update_post(array( 'ID' => $post->ID, 'menu_order' => $menu_order ));
				$new_pos[$post->ID] = $menu_order;
				$menu_order++;
			}

			// if repositioned post has been set, and new items are already in the right order, we can stop
			if ( isset( $new_pos[$post->ID] ) && $sibling->menu_order >= $menu_order )
				break;

			// set the menu order of the current sibling and increment the menu order
			wp_update_post(array( 'ID' => $sibling->ID, 'menu_order' => $menu_order ));
			$new_pos[$sibling->ID] = $menu_order;
			$menu_order++;

			if ( ! $nextid && $previd == $sibling->ID ) {
				wp_update_post(array( 'ID' => $post->ID, 'menu_order' => $menu_order ));
				$new_pos[$post->ID] = $menu_order;
				$menu_order++;
			}

		endforeach;

		header("Content-Type: application/json; charset=UTF-8");
		die( json_encode($new_pos) );
	}

	public function admin_enqueue_scripts( $hook ) {
		if ( 'edit.php' == $hook && 'slide' == get_post_type() ) {
			wp_enqueue_script( 'reorder-slides', plugin_dir_url( __FILE__ ) . 'slideshow-order.js', array( 'jquery-ui-sortable' ), '0.0.1', true );
			add_filter( 'manage_' . get_current_screen()->id . '_sortable_columns', array( $this, 'manage_sortable_columns' ) );
		}
	}

	private function _get_slideshow_template() {
		$term = get_queried_object();
		$taxonomy = $term->taxonomy;

		$templates = array();

		$templates[] = "taxonomy-$taxonomy-{$term->slug}.php";
		$templates[] = "taxonomy-$taxonomy.php";

		return get_query_template( 'taxonomy', $templates );
	}

	public function template_include( $template ) {
		if ( is_tax( 'slideshow' ) ) {
			$template = $this->_get_slideshow_template();
			if ( empty( $template ) )
				$template = plugin_dir_path( __FILE__ ) . 'taxonomy-slideshow.php';

			// Turn off the admin bar
			add_filter( 'show_admin_bar' , '__return_false' );
			remove_action( 'wp_head', '_admin_bar_bump_cb' );
			remove_action( 'wp_head', 'wp_admin_bar_header' );

			add_action( 'wp_enqueue_scripts', array( $this, 'wp_enqueue_scripts' ), 11 );
			add_action( 'wp_print_scripts', array( $this, 'wp_print_scripts') , 11 );
		}
		return $template;
	}
}

// Instantiate our class
$presenter = presenter::getInstance();
