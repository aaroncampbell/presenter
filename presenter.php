<?php
/**
 * Plugin Name: Presenter
 * Plugin URI: http://aarondcampbell.com/wordpress-plugins/presenter/
 * Description: Presenter
 * Version: 1.4.0
 * Author: Aaron D. Campbell
 * Author URI: http://aarondcampbell.com/
 * Text Domain: presenter
 */

 /**
  * @todo Help Tabs (get_current_screen()->add_help_tab(), see edit-form-advanced.php)
  * @todo JS to undo removing a slide? Use detach() instead of remove()?
  * @todo previews for each slide?
  */

/**
 * presenter is the class that handles ALL of the plugin functionality.
 * It helps us avoid name collisions
 * http://codex.wordpress.org/Writing_a_Plugin#Avoiding_Function_Name_Collisions
 */
class presenter {
	/**
	 * @var presenter - Static property to hold our singleton instance
	 */
	static $instance = false;

	private $importing = false;

	/**
	 * @var int - Plugin version used to trigger upgrade routines. Only update if an upgrade routine is needed.
	 */
	private $_version = 20170706;

	/**
	 * @var array Posts Processed
	 */
	private $_processedPosts = array();

	/**
	 * This is our constructor, which is protected to force the use of get_instance()
	 * @return void
	 */
	protected function __construct() {
		$this->_slug = 'presenter';

		$this->importing = false;

		/**
		 * Add filters and actions
		 */
		add_action( 'plugins_loaded',                   array( $this, 'upgrade_check'         )          );
		add_action( 'after_setup_theme',                array( $this, 'after_setup_theme'     )          );
		add_filter( 'single_template',                  array( $this, 'single_template'       )          );
		add_action( 'save_post_slideshow',              array( $this, 'save_post_slideshow'   ), null, 3 );
		add_action( 'presenter-head',                   array( $this, 'head'                  )          );
		add_action( 'presenter-head',                  'wp_generator'                                    );
		add_action( 'presenter-head',                  'rel_canonical'                                   );
		add_action( 'presenter-head',                  'wp_shortlink_wp_head',                   10, 0   );
		add_action( 'presenter-head',                  'wp_custom_css_cb',                       101     );
		add_action( 'presenter-head',                  'wp_site_icon',                           99      );
		add_action( 'presenter-footer',                 array( $this, 'footer'                )          );
		add_action( 'enqueue_block_editor_assets',      array( $this, 'enqueue_editor_assets' )          );
		add_action( 'import_start',                     array( $this, 'import_start'          )          );
		add_action( 'import_end',                       array( $this, 'import_end'            )          );
		add_filter( 'wp_import_post_meta',              array( $this, 'wp_import_post_meta'   ), null, 3 );
		add_action( 'init',                             array( $this, 'init_locale'           )          );
		add_action( 'init',                             array( $this, 'register_block'        )          );

		add_shortcode( 'presenter-url',                 array( $this, 'url_shortcode'         )          );
	}

	public function init_locale() {
		load_plugin_textdomain( $this->_slug, false, basename( __DIR__ ) . '/languages' );
	}

	/**
	 * Registers all block assets so that they can be enqueued through Gutenberg in
	 * the corresponding context.
	 *
	 * Passes translations to JavaScript.
	 */
	public function register_block() {

		// automatically load dependencies and version
		$asset_file = include( plugin_dir_path( __FILE__ ) . 'build/index.asset.php');

		wp_register_script(
			'presenter',
			plugins_url( 'build/index.js', __FILE__ ),
			$asset_file['dependencies'],
			$asset_file['version']
		);

		$theme = get_post_meta( get_the_ID(), '_presenter-theme', true );
		if ( empty( $theme ) ) {
			$theme = $this->get_default_theme();
		}

		/**
		 * Filters the theme loaded for slideshow
		 *
		 * @since 1.4.0
		 *
		 * @param string     $theme   URL to CSS file of theme
		 */
		wp_register_style(
			'presenter',
			plugins_url( 'editor-style.css', __FILE__ ),
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . 'editor-style.css' )
		);

		register_block_type( 'presenter/slide', array(
			'api_version' => 2,
			'editor_script' => 'presenter',
			'editor_style'  => 'presenter',
		) );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'presenter', $this->_slug, plugin_dir_path( __FILE__ ) . 'languages' );
		}
	}

	public function wp_import_post_meta( $postmeta, $post_id, $post ) {
		foreach ( $postmeta as $meta_num=>$meta ) {
			$key = apply_filters( 'import_post_meta_key', $meta['key'], $post_id, $post );

			// Only parse post meta starting with '_presenter'
			if ( '_presenter' != substr( $key, 0, 10 ) ) {
				continue;
			}

			// export gets meta straight from the DB so could have a serialized string
			$value = maybe_unserialize( $meta['value'] );
			// For some reason strings seem to serialize with \r\n and later become \n, messing up the character count and not unserializing
			if ( false === $value ) {
				$meta['value'] = str_replace( array("\r", "\n"), "\r\n", $meta['value'] );
				$value = maybe_unserialize( $meta['value'] );
			}

			add_post_meta( $post_id, $key, $value );

			// We processed it, don't make the importer do it too.
			unset( $postmeta[ $meta_num ] );
		}
		return $postmeta;
	}

	public function upgrade_check() {
		$current_version = get_site_option( 'presenter_version', 0 );
		if ( $this->_version > $current_version ) {
			$this->_upgrade( $current_version );
		}
	}

	private function _upgrade( $current_version ) {
		if ( $current_version < 20150406 ) {
			$this->_upgrade_20150406();
		}

		if ( $current_version < 20170706 ) {
			$this->_upgrade_20170706();
		}



		// We are now up to date
		update_site_option( 'presenter_version', $this->_version );
	}

	private function _upgrade_20150406() {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return false;
		}

		// Grab all slideshow posts
		$args = array(
			'post_type'     => 'slideshow',
			'nopaging'      => true,
			'cache_results' => false,
			'no_found_rows' => false,
		);
		$posts = new WP_Query( $args );

		while( $posts->have_posts() ) {
			$post = $posts->next_post();

			// If there's no content...then we don't care
			if ( empty( $post->post_content ) ) {
				continue;
			}

			// Fake that this is a full document.
			$html = '<!DOCTYPE html><html><head></head><body id="body">' . $post->post_content . '</body></html>';
			$document = new DOMDocument;
			@$document->loadHTML( $html );
			$body = $document->getElementById( 'body' );

			$xpath = new DOMXPath( $document );
			$slide_nodes = $xpath->query( '/html/body/section' );

			$slide_num = 0;
			foreach ( $slide_nodes as $slide_node ) {
				$slide = new stdClass();
				$slide->number = ++$slide_num;
				$slide->content = $document->saveHTML( $slide_node );
				$slide->class = 'slide-' . $slide->number;
				$slide->title = 'Slide ' . $slide->number;

				// Save the slide
				add_post_meta( $post->ID, '_presenter_slides', $slide );
				// Remove it from the dom
				$body->removeChild( $slide_node );
			}

			// Make sure to keep any left over content in post_content
			$new_post_content = '';
			if ( $body->hasChildNodes() ) {
				foreach ( $body->childNodes as $leftover_node ) {
					$new_post_content .= $document->saveHTML( $leftover_node );
				}
			}

			// Generate HTML from slides and store it in the post content
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_content' => $new_post_content ), array( 'ID' => $post->ID ) );
		}
	}

	private function _upgrade_20170706() {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return false;
		}

		// Grab all slideshow posts
		$args = array(
			'post_type'     => 'slideshow',
			'nopaging'      => true,
			'cache_results' => false,
			'no_found_rows' => false,
		);

		global $wpdb;

		// Query to grab all slides that might have notes
		$query = 'SELECT * FROM ' . $wpdb->postmeta . ' WHERE `meta_key` = "_presenter_slides" && `meta_value` REGEXP "<aside[^>]+notes"';

		$slides = $wpdb->get_results( $query );
		foreach ( $slides as $slide ) {
			$slide->meta_value = maybe_unserialize( $slide->meta_value );

			$html = '<!DOCTYPE html><html><head></head><body id="slide">' . $slide->meta_value->content . '</body></html>';
			$document = new DOMDocument;
			@$document->loadHTML( $html );
			$body = $document->getElementById( 'slide' );

			$xpath = new DOMXPath( $document );
			$note_nodes = $xpath->query( '/html/body/aside[@class="notes"]' );


			foreach ( $note_nodes as $note_node ) {
				$slide->meta_value->notes = array( 'notes' => '', 'markdown' => false );

				if ( $note_node->hasChildNodes() ) {
					foreach ( $note_node->childNodes as $note_content ) {
						$slide->meta_value->notes['notes'] .= $document->saveHTML( $note_content );
					}
				}
				if ( $note_node->hasAttribute( 'data-markdown' ) ) {
					$slide->meta_value->notes['markdown'] = true;
				}

				// Remove it from the dom
				$body->removeChild( $note_node );
			}

			// Create slide content without notes
			$slide->meta_value->content = '';
			if ( $body->hasChildNodes() ) {
				foreach ( $body->childNodes as $leftover_node ) {
					$slide->meta_value->content .= $document->saveHTML( $leftover_node );
				}
			}

			update_metadata_by_mid( 'post', $slide->meta_id, $slide->meta_value );
		}
	}

	public function after_setup_theme() {
		/**
		 * Plugins
		 */
		$labels = array(
			'name'               => _x( 'Slideshows', 'post type general name', $this->_slug ),
			'singular_name'      => _x( 'Slideshow', 'post type singular name', $this->_slug ),
			'add_new'            => _x( 'Add New', 'post', $this->_slug ),
			'add_new_item'       => __( 'Add New Slideshow', $this->_slug ),
			'edit_item'          => __( 'Edit Slideshow', $this->_slug ),
			'new_item'           => __( 'New Slideshow', $this->_slug ),
			'view_item'          => __( 'View Slideshow', $this->_slug ),
			'search_items'       => __( 'Search Slideshows', $this->_slug ),
			'not_found'          => __( 'No slideshows found.', $this->_slug ),
			'not_found_in_trash' => __( 'No slideshows found in Trash.', $this->_slug ),
			'all_items'          => __( 'All Slideshows', $this->_slug ),
		);
		$args = array(
			'labels'          => $labels,
			'description'     => __( 'Slideshows', $this->_slug ),
			'public'          => true,
			'has_archive'     => 'slideshows',
			'supports'        => array(
				'excerpt',
				'page-attributes',
				'custom-fields',
				'revisions',
				'title',
				'editor',
				'author',
				'thumbnail',
			),
			'show_in_rest'    => true,
			'menu_icon'       => 'dashicons-slides',
		);
		register_post_type( 'slideshow', $args );

		register_post_meta( 'slideshow', '_presenter-theme', [
			'show_in_rest' => true,
			'single' => true,
			'type' => 'string',
			'auth_callback' => '__return_true',
		] );

		register_post_meta( 'slideshow', '_presenter-short-url', [
			'show_in_rest' => true,
			'single' => true,
			'type' => 'string',
			'auth_callback' => '__return_true',
		] );
	}

	private function _get_html_from_slides( $slides ) {
		$html = '';
		foreach ( $slides as $slide ) {
			if ( empty( $slide->title ) ) {
				$slide->title = 'Slide ' . $slide->number;
			}
			$id = sanitize_title_with_dashes( $slide->title );
			if ( ! empty( $slide->class ) ) {
				$slide->class = ' class="' . esc_attr( $slide->class ) . '"';
			}

			$data_attributes = '';
			if ( ! empty( $slide->data ) ) {
				foreach ( $slide->data as $data ) {
					$data_attributes .= sprintf( ' data-%1$s="%2$s"', esc_attr( $data->name ), esc_attr( $data->value ) );
				}
			}
			$notes = '';
			if ( ! empty( $slide->notes['notes'] ) ) {
				$notes = sprintf('<aside class="notes"%1$s>%2$s</aside>', $slide->notes['markdown']? ' data-markdown=""':'', $slide->notes['notes'] );
			}
			$html .= "<section id='{$id}'{$slide->class}{$data_attributes}>{$slide->content}{$notes}</section>";
		}

		return $html;
	}

	private function _get_slides_from_post_data() {
		$slides = array();
		$slide_num = 0;
		foreach ( $_POST['slide-title'] as $num => $slide_title ) {
			// Ignore the empty slide we use to create new slides from
			if ( '__new__' === $num ) {
				continue;
			}
			$slide = new stdClass();
			$slide->number = ++$slide_num;
			$slide->content = $_POST['slide-content'][$num];
			$slide->notes = $_POST['slide-notes'][$num];
			$slide->notes['markdown'] = isset( $slide->notes['markdown'] )? (bool) $slide->notes['markdown'] : false;
			$slide->class = $_POST['slide-classes'][$num];
			$slide->data = array();
			if ( array_key_exists( 'slide-data', $_POST ) && array_key_exists( $num, $_POST['slide-data'] ) ) {
				foreach ( $_POST['slide-data'][$num] as $data_num => $name ) {
					if ( ! empty( $name ) ) {
						$data = new stdClass();
						$data->name = $name;
						$data->value = $_POST['slide-data-value'][$num][$data_num];
						$slide->data[] = $data;
					}
				}
			}
			$slide->title = $slide_title;
			$slides[] = $slide;
		}

		return $slides;
	}

	public function save_post_slideshow( $post_id, $post, $update ) {
		/**
		 * @todo handle autosaves in some way?
		 */
		// Don't process for autosaves or during doing_ajax
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}

		if ( false !== wp_is_post_revision( $post_id ) || in_array( $post->post_status, array( 'auto-draft', 'trash' ) )  || $this->importing ) {
			return;
		}

		$themes = $this->get_themes();

		if ( empty( $_POST['presenter_theme'] ) || ! isset( $themes[$_POST['presenter_theme']] ) ) {
			$_POST['presenter_theme'] = '';
		}
		update_post_meta( $post_id, '_presenter-theme', $_POST['presenter_theme'] );

		if ( ! isset( $_POST['presenter_short_url'] ) ) {
			$_POST['presenter_short_url'] = '';
		} else {
			$_POST['presenter_short_url'] = filter_var( $_POST['presenter_short_url'], FILTER_SANITIZE_URL );
			if ( ! filter_var( $_POST['presenter_short_url'], FILTER_VALIDATE_URL ) ) {
				$_POST['presenter_short_url'] = '';
			}
		}
		update_post_meta( $post_id, '_presenter-short-url', $_POST['presenter_short_url'] );

		// Remove old slides
		delete_post_meta( $post->ID, '_presenter_slides' );

		$slides = $this->_get_slides_from_post_data();

		// Add slides
		foreach ( $slides as $slide ) {
			add_post_meta( $post_id, '_presenter_slides', $slide );
		}
	}

	public function head() {
		global $SyntaxHighlighter;
		if ( is_a( $SyntaxHighlighter, 'SyntaxHighlighter' ) && is_callable( array( $SyntaxHighlighter, 'output_header_placeholder' ) ) ) {
			$SyntaxHighlighter->output_header_placeholder();
		}
	}

	public function syntaxhighlighter_cssthemeurl( $src ) {
		return plugins_url( '/css/syntaxhighlighter-presenter.css', __FILE__ );
	}

	public function footer() {
		global $SyntaxHighlighter;
		if ( is_a( $SyntaxHighlighter, 'SyntaxHighlighter' ) ) {
			add_filter( 'syntaxhighlighter_cssthemeurl', array( $this, 'syntaxhighlighter_cssthemeurl' ) );
			$SyntaxHighlighter->maybe_output_scripts();
		}
		wp_print_scripts( array( 'reveal' ) );

		// Default settings to be passed to Reveal.initialize
		$reveal_initialize_object = (object) [
			'controls' => true,
			'progress' => true,
			'history'  => true,
			'center'   => true,
			'plugins'  => wp_scripts()->query( 'reveal' )->deps
		];

		/**
		 * Filters the object passed to Reveal.initialize
		 *
		 * @since 1.4.0
		 *
		 * @param object     $reveal_initialize_object   Object of settings
		 */
		$reveal_initialize_object = apply_filters( 'presenter-init-object', $reveal_initialize_object );
		if ( $reveal_initialize_object->plugins ) {
			$reveal_plugins = $reveal_initialize_object->plugins;
			$reveal_initialize_object->plugins = 'presenter-' . uniqid();
		}
		?>
		<script>

			// Full list of configuration options available here:
			// https://github.com/hakimel/reveal.js#configuration
			Reveal.initialize(<?php echo str_replace( '"' . $reveal_initialize_object->plugins . '"', '[' . implode( ',', $reveal_plugins ) . ']', json_encode( $reveal_initialize_object ) ); ?>);

		</script>
		<?php
	}


	/**
	 * Used to sort to make sure slides are in order by slide number.
	 *
	 * Used by usort() as a callback, should not be used directly.
	 *
	 * @access private
	 *
	 * @param object $slide1
	 * @param object $slide2
	 * @return int
	 */
	private function sort_slides( $slide1, $slide2 ) {
		if ( $slide1->number == $slide2->number ) {
			return 0;
		}
		return ( $slide1->number > $slide2->number )? 1 : -1;
	}

	public function slideshow_attributes_meta_box( $post ) {
		?>
		<p>
			<strong><?php _e( 'Slideshow Theme', $this->_slug ); ?></strong>
		</p>
		<label class="screen-reader-text" for="presenter_theme">
			<?php _e( 'Slideshow Theme', $this->_slug ); ?>
		</label>
		<select name="presenter_theme" id="presenter_theme">
			<option value='default'><?php _e( 'Default Template', $this->_slug ); ?></option>
			<?php $this->_presenter_themes_dropdown_options( get_post_meta( $post->ID, '_presenter-theme', true ) ); ?>
		</select>
		<p>
			<strong><?php _e( 'Order', $this->_slug ); ?></strong>
		</p>
		<p>
			<label class="screen-reader-text" for="menu_order">
				<?php _e( 'Order', $this->_slug ); ?>
			</label>
			<input name="menu_order" type="text" size="4" id="menu_order" value="<?php echo esc_attr( $post->menu_order ) ?>" />
		</p>
		<p>
			<strong><?php _e( 'Short Url', $this->_slug ); ?></strong>
		</p>
		<p>
			<label class="screen-reader-text" for="presenter_short_url">
				<?php _e( 'Order', $this->_slug ); ?>
			</label>
			<input name="presenter_short_url" type="text" id="presenter_short_url" value="<?php echo esc_attr( get_post_meta( $post->ID, '_presenter-short-url', true ) ) ?>" />
		</p>
	<?php
	}

	public function get_themes() {
	    $presenter_theme_directories = [ plugin_dir_path( __FILE__ ) . 'reveal.js/dist/theme' ];
		if ( file_exists( get_stylesheet_directory() . '/presenter' ) ) {
			$presenter_theme_directories[] = get_stylesheet_directory() . '/presenter';
		}
		if ( is_child_theme() && file_exists( get_template_directory() . '/presenter' ) ) {
			$presenter_theme_directories[] = get_template_directory() . '/presenter';
		}
		$presenter_theme_directories = apply_filters( 'presenter-theme-directories', $presenter_theme_directories );

		$files = [];
		foreach ( $presenter_theme_directories as $presenter_theme_directory ) {
			$files += (array) $this->_scandir( $presenter_theme_directory );
		}

		$presenter_themes = $this->_cache_get( 'themes' );

		if ( ! is_array( $presenter_themes ) ) {
			$presenter_themes = [];
			foreach ( $files as $file => $full_path ) {
				// Handles the distributed themes...even though it's a lame way to do it
				if ( ! preg_match( '|([^\*]*)theme for reveal.js|mi', file_get_contents( $full_path ), $header ) ) {
					// Better way, using WordPress style headers
					if ( ! preg_match( '|Template Name:(.*)$|mi', file_get_contents( $full_path ), $header ) ) {
						continue;
					}
				} else {
					// The distributed files don't all have unique names, so add the filename
					$header[1] = _cleanup_header_comment( $header[1] ) . ' (' . basename( $full_path ) . ')';
				}
				$full_path = str_replace( WP_CONTENT_DIR, '', $full_path );
				array_push( $presenter_themes, (object)[
					'label' => _cleanup_header_comment( $header[1] ),
					'value' => $full_path,
					'url'   => content_url( $full_path ),
				] );

				//$presenter_themes[ str_replace( WP_CONTENT_DIR, '', $full_path ) ] = _cleanup_header_comment( $header[1] );
			}

			$this->_cache_add( 'themes', $presenter_themes );
		}

		/**
		 * Filter list of Presenter themes.
		 *
		 * Adding themes via this filter is not recommended and can have unexpected results. Instead use `presenter-theme-directories`
		 *
		 * @param array    $presenter_themes Array of objects representing themes. Theme object has label, value, and url.
		 * @param WP_Theme $this             The Presenter object.
		 */
		$presenter_themes = apply_filters( 'presenter-themes', $presenter_themes, $this );

		usort( $presenter_themes, array( $this, '_alphabetize_themes' ) );
		return $presenter_themes;
	}

	private function _alphabetize_themes( $a, $b ) {
		return strcmp( $a->label, $b->label );
	}

	public function get_default_theme() {
		return apply_filters( 'presenter-default-theme', str_replace( WP_CONTENT_DIR, '', plugin_dir_path( __FILE__ ) . 'reveal.js/dist/theme/league.css' ) );
	}

	private function _presenter_themes_dropdown_options( $selected_theme = '' ) {
		$themes = [];
		foreach ( $this->get_themes() as $theme ) {
			$themes[ $theme->label ] = $theme;
		}

		echo '<pre>';
		var_dump( $selected_theme );
		var_dump( $themes );
		echo '</pre>';
		asort( $themes );

		foreach ( $themes as $name => $theme ) {
			$selected = selected( $selected_theme, $theme->value, false );
			printf( '<option value="%1$s" data-stylesheet-url="%2$s"%3$s>%4$s</option>', esc_attr( $theme->value ), esc_attr( $theme->url ), $selected, esc_html( $name ) );
		}
	}

	/**
	 * Adds theme data to cache.
	 *
	 * @access private
	 *
	 * @param string $key Name of data to store
	 * @param string $data Data to store
	 * @return bool Return value from wp_cache_add()
	 */
	private function _cache_add( $key, $data ) {
		return wp_cache_add( 'presenter-' . $key, $data, 'presenter', 1800 );
	}

	/**
	 * Gets data from cache.
	 *
	 * @access private
	 *
	 * @param string $key Name of data to retrieve
	 * @return mixed Retrieved data
	 */
	private function _cache_get( $key ) {
		return wp_cache_get( 'presenter-' . $key, 'presenter' );
	}

	/**
	 * Scans a directory for files of a certain extension.
	 *
	 * @access private
	 *
	 * @param string $path Absolute path to search.
	 * @param mixed  Array of extensions to find, string of a single extension, or null for all extensions.
	 * @param int $depth How deep to search for files. Optional, defaults to 1 (specified directory and all directories in it). 0 depth is a flat scan. -1 depth is infinite.
	 * @param string $relative_path The basename of the absolute path. Used to control the returned path
	 * 	for the found files, particularly when this function recurses to lower depths.
	 */
	private function _scandir( $path, $extensions = 'css', $depth = 1, $relative_path = '' ) {
		if ( ! is_dir( $path ) )
			return false;

		if ( $extensions ) {
			$extensions = (array) $extensions;
			$_extensions = implode( '|', $extensions );
		}

		$relative_path = trailingslashit( $relative_path );
		if ( '/' == $relative_path )
			$relative_path = '';

		$results = scandir( $path );
		$files = array();

		foreach ( $results as $result ) {
			if ( '.' == $result[0] )
				continue;
			if ( is_dir( $path . '/' . $result ) ) {
				if ( ! $depth || 'CVS' == $result )
					continue;
				$found = $this->_scandir( $path . '/' . $result, $extensions, $depth - 1 , $relative_path . $result );
				$files = array_merge_recursive( $files, $found );
			} elseif ( ! $extensions || preg_match( '~\.(' . $_extensions . ')$~', $result ) ) {
				$files[ $relative_path . $result ] = $path . '/' . $result;
			}
		}

		return $files;
	}

	/**
	 * Function to instantiate our class and make it a singleton
	 */
	public static function get_instance() {
		if ( !self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	public function single_template( $template ) {
		if ( is_singular( 'slideshow' ) ) {
			$template = plugin_dir_path( __FILE__ ) . 'templates/index.php';

			global $wp_scripts;
			$wp_scripts->add_data( 'html5shiv', 'conditional', 'lt IE 9' );

			/**
			 * Reveal.js plugins as dependencies
			 */
			wp_register_script( 'RevealMarkdown', plugins_url( 'reveal.js/plugin/markdown/markdown.js', __FILE__ ), array(), '4.1.2', true );
			wp_register_script( 'RevealSearch', plugins_url( 'reveal.js/plugin/search/search.js', __FILE__ ), array(), '4.1.2', true );
			wp_register_script( 'RevealNotes', plugins_url( 'reveal.js/plugin/notes/notes.js', __FILE__ ), array(), '4.1.2', true );
			wp_register_script( 'RevealMath', plugins_url( 'reveal.js/plugin/math/math.js', __FILE__ ), array(), '4.1.2', true );
			wp_register_script( 'RevealZoom', plugins_url( 'reveal.js/plugin/zoom/zoom.js', __FILE__ ), array(), '4.1.2', true );
			$reveal_js_dependencies = array( 'RevealMarkdown', 'RevealSearch', 'RevealNotes', 'RevealMath', 'RevealZoom' );
			$reveal_css_dependencies = array();

			// Only load highlight.js if SyntaxHighlighter isn't active
			global $SyntaxHighlighter;
			if ( ! is_a( $SyntaxHighlighter, 'SyntaxHighlighter' ) ) {
				wp_register_style( 'RevealHighlightStyle', plugins_url( 'reveal.js/plugin/highlight/monokai.css', __FILE__ ), array(), '4.1.2' );
				wp_register_script( 'RevealHighlight', plugins_url( 'reveal.js/plugin/highlight/highlight.js', __FILE__ ), array(), '4.1.2', true );
				$reveal_js_dependencies[] = 'RevealHighlight';
				$reveal_css_dependencies[] = 'RevealHighlightStyle';
			}
			$reveal_js_dependencies = apply_filters( 'presenter-reveal-js-dependencies', $reveal_js_dependencies );
			$reveal_css_dependencies = apply_filters( 'presenter-reveal-css-dependencies', $reveal_css_dependencies );
			wp_register_script( 'reveal', plugins_url( 'reveal.js/dist/reveal.js', __FILE__ ), $reveal_js_dependencies, '4.1.2', true );

			wp_register_style( 'presenter', plugins_url( 'css/presenter.css', __FILE__ ) );
			wp_register_style( 'reveal', plugins_url( 'reveal.js/dist/reveal.css', __FILE__ ), $reveal_css_dependencies, '4.1.2' );
			$theme = get_post_meta( get_the_ID(), '_presenter-theme', true );
			if ( empty( $theme ) ) {
				$theme = $this->get_default_theme();
			}

			/**
			 * Filters the theme loaded for slideshow
			 *
			 * @since 1.4.0
			 *
			 * @param string     $theme   URL to CSS file of theme
			 */
			wp_register_style( 'reveal-theme', apply_filters( 'presenter-theme', content_url( $theme ) ) );

		}
		return $template;
	}

    /**
	 * Replace our shortCode with the "widget"
	 *
	 * @param array $attr - array of attributes from the short code
	 * @param string $content - Content of the short code
	 * @return string - url
	 */
	public function url_shortcode( $attr, $content = '' ) {
		return $this->_get_presentation_url();
	}

	private function _get_presentation_url() {
		$url = filter_var( get_post_meta( get_the_ID(), '_presenter-short-url', true ), FILTER_SANITIZE_URL );
		if ( empty( $url ) ) {
			$url = get_permalink();
		}
		return $url;
	}

	public function enqueue_editor_assets() {
		if ( 'slideshow' == get_current_screen()->post_type ) {
			wp_register_style(
				'presenter-editor',
				plugins_url( 'css/edit-slide-admin.css', __FILE__ ),
				array( 'dashicons' ),
				filemtime( plugin_dir_path( __FILE__ ) . 'css/edit-slide-admin.css' )
			);

			// automatically load dependencies and version
			$asset_file = include( plugin_dir_path( __FILE__ ) . 'build/editor.asset.php');

			wp_enqueue_script(
				'presenter-editor',
				plugins_url( 'build/editor.js', __FILE__ ),
				$asset_file['dependencies'],
				$asset_file['version']
			);

			$theme = get_post_meta( get_the_ID(), '_presenter-theme', true );
			if ( empty( $theme ) || !$this->_theme_exists( $theme ) ) {
				$theme = $this->get_default_theme();
			}
	
			wp_localize_script( 'presenter-editor', 'presenterData', [ 'themes' => $this->get_themes(), 'theme' => $theme, 'short_url' => get_post_meta( get_the_ID(), '_presenter-short-url', true ) ] );
		}
	}

	private function _theme_exists( $theme ) {
		foreach ( $this->get_themes() as $t ) {
			if ( $t->value == $theme ) {
				return true;
			}
		}
		return false;
	}

	public function import_start() {
		$this->importing = true;
	}

	public function import_end() {
		$this->importing = false;
	}
}

// Instantiate our class
$presenter = presenter::get_instance();
