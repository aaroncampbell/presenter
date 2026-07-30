<?php
/**
 * Plugin Name: Presenter
 * Plugin URI: http://aarondcampbell.com/wordpress-plugins/presenter/
 * Description: Presenter
 * Version: 2.0.0
 * Author: Aaron D. Campbell
 * Author URI: http://aarondcampbell.com/
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: presenter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * Action used to protect legacy slideshow editor saves.
	 *
	 * @var string
	 */
	private const SAVE_NONCE_ACTION = 'presenter_save_slideshow';

	/** Current plugin version used by retained legacy assets. */
	private const VERSION = '2.0.0';

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
		$this->importing = false;

		/**
		 * Add filters and actions
		 */
		add_action( 'plugins_loaded',                   array( $this, 'upgrade_check'         )          );
		add_action( 'after_setup_theme',                array( $this, 'after_setup_theme'     )          );
		add_filter( 'single_template',                  array( $this, 'single_template'       )          );
		add_action( 'save_post_slideshow',              array( $this, 'save_post_slideshow'   ), null, 3 );
		add_action( 'add_meta_boxes_slideshow',         array( $this, 'register_legacy_meta_boxes' )     );
		add_action( 'presenter-head',                   array( $this, 'head'                  )          );
		add_action( 'presenter-head',                  'wp_generator'                                    );
		add_action( 'presenter-head',                  'rel_canonical'                                   );
		add_action( 'presenter-head',                  'wp_shortlink_wp_head',                   10, 0   );
		add_action( 'presenter-head',                  'wp_custom_css_cb',                       101     );
		add_action( 'presenter-head',                  'wp_site_icon',                           99      );
		add_action( 'presenter-footer',                 array( $this, 'footer'                )          );
		add_action( 'admin_print_styles-post-new.php',  array( $this, 'print_editor_styles'   )          );
		add_action( 'admin_print_styles-post.php',      array( $this, 'print_editor_styles'   )          );
		add_action( 'admin_print_scripts-post-new.php', array( $this, 'print_editor_scripts'  )          );
		add_action( 'admin_print_scripts-post.php',     array( $this, 'print_editor_scripts'  )          );
		add_action( 'the_content',                      array( $this, 'the_content'           ), null, 1 );
		add_action( 'import_start',                     array( $this, 'import_start'          )          );
		add_action( 'import_end',                       array( $this, 'import_end'            )          );
		add_filter( 'wp_import_post_meta',              array( $this, 'wp_import_post_meta'   ), null, 3 );
		add_shortcode( 'presenter-url',                 array( $this, 'url_shortcode'         )          );
	}

	public function wp_import_post_meta( $postmeta, $post_id, $post ) {
		foreach ( $postmeta as $meta_num=>$meta ) {
			$key = apply_filters( 'import_post_meta_key', $meta['key'], $post_id, $post ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress Importer core hook.

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
			$wpdb->update( $wpdb->posts, array( 'post_content' => $new_post_content ), array( 'ID' => $post->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Historical one-time upgrade intentionally avoids save hooks and queried with caching disabled.
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

		// Query to grab all slides that might have notes.
		// This one-time upgrade query intentionally bypasses object caching.
		$slides = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time versioned migration.
			$wpdb->prepare(
				'SELECT * FROM %i WHERE meta_key = %s AND meta_value REGEXP %s',
				$wpdb->postmeta,
				'_presenter_slides',
				'<aside[^>]+notes'
			)
		);
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
			'name'               => _x( 'Slideshows', 'post type general name', 'presenter' ),
			'singular_name'      => _x( 'Slideshow', 'post type singular name', 'presenter' ),
			'add_new'            => _x( 'Add New', 'post', 'presenter' ),
			'add_new_item'       => __( 'Add New Slideshow', 'presenter' ),
			'edit_item'          => __( 'Edit Slideshow', 'presenter' ),
			'new_item'           => __( 'New Slideshow', 'presenter' ),
			'view_item'          => __( 'View Slideshow', 'presenter' ),
			'search_items'       => __( 'Search Slideshows', 'presenter' ),
			'not_found'          => __( 'No slideshows found.', 'presenter' ),
			'not_found_in_trash' => __( 'No slideshows found in Trash.', 'presenter' ),
			'all_items'          => __( 'All Slideshows', 'presenter' ),
		);
		$args = array(
			'labels'          => $labels,
			'description'     => __( 'Slideshows', 'presenter' ),
			'public'          => true,
			'has_archive'     => 'slideshows',
			'supports'        => array(
				'excerpt',
				'page-attributes',
				'custom-fields',
				'revisions',
				'title',
				'editor',
			),
			'menu_icon'       => 'dashicons-slides',
		);
		register_post_type( 'slideshow', $args );
	}

	/**
	 * Render normalized legacy slides under the content-bound HTML policy.
	 *
	 * @param array $slides       Normalized legacy slides.
	 * @param bool  $trusted_html Whether raw slide and note HTML is trusted.
	 * @return string Legacy section markup.
	 */
	private function _get_html_from_slides( $slides, $trusted_html = false ) {
		$html  = '';
		$trust = presenter_get_runtime()->legacy_html_trust();
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
				$notes_content = $trusted_html ? $slide->notes['notes'] : $trust->sanitize( $slide->notes['notes'] );
				$notes         = sprintf( '<aside class="notes"%1$s>%2$s</aside>', $slide->notes['markdown'] ? ' data-markdown=""' : '', $notes_content );
			}
			$slide_content  = $trusted_html ? $slide->content : $trust->sanitize( $slide->content );
			$html          .= "<section id='{$id}'{$slide->class}{$data_attributes}>{$slide_content}{$notes}</section>";
		}

		return $html;
	}

	/**
	 * Project stored legacy slide values into a safe, deterministic runtime list.
	 *
	 * Use the migration normalizer so the legacy runtime and migration planner
	 * share one definition for objects, arrays, missing numbers, and the boolean
	 * false record found in the production snapshot. The normalizer supplies a
	 * source-position fallback number and uses source position as the stable
	 * tiebreaker for duplicates without changing the stored metadata.
	 *
	 * @param array $slides Stored legacy slide values.
	 * @return array Runtime-safe legacy slide objects.
	 */
	private function prepare_legacy_slides( $slides ) {
		$prepared   = array();
		$normalizer = new \Presenter\Legacy_Slide_Normalizer();

		foreach ( $normalizer->normalize( array_values( $slides ) ) as $slide ) {
			$slide['data'] = array_map(
				static function ( $data ) {
					return (object) $data;
				},
				$slide['data']
			);
			unset( $slide['sourceIndex'], $slide['warnings'] );
			$prepared[] = (object) $slide;
		}

		return $prepared;
	}

	/**
	 * Build a complete legacy slide replacement from request data.
	 *
	 * Invalid or incomplete requests fail closed so the save handler can leave
	 * every existing metadata value untouched. Users without unfiltered_html
	 * receive the same KSES protection WordPress applies to post content.
	 *
	 * @param array $post_data Request data with slashes removed.
	 * @return array|null Validated slides, or null when the request is malformed.
	 */
	private function _get_slides_from_post_data( array $post_data ) {
		$required_fields = array( 'slide-title', 'slide-content', 'slide-notes', 'slide-classes' );
		foreach ( $required_fields as $required_field ) {
			if ( ! isset( $post_data[ $required_field ] ) || ! is_array( $post_data[ $required_field ] ) ) {
				return null;
			}
		}

		foreach ( array( 'slide-data', 'slide-data-value' ) as $optional_field ) {
			if ( isset( $post_data[ $optional_field ] ) && ! is_array( $post_data[ $optional_field ] ) ) {
				return null;
			}
		}

		$slides                 = array();
		$slide_num              = 0;
		$allows_unfiltered_html = current_user_can( 'unfiltered_html' );
		foreach ( $post_data['slide-title'] as $num => $slide_title ) {
			// Ignore the empty slide we use to create new slides from.
			if ( '__new__' === $num ) {
				continue;
			}

			if (
				! is_string( $slide_title )
				|| ! array_key_exists( $num, $post_data['slide-content'] )
				|| ! is_string( $post_data['slide-content'][ $num ] )
				|| ! array_key_exists( $num, $post_data['slide-notes'] )
				|| ! is_array( $post_data['slide-notes'][ $num ] )
				|| ! array_key_exists( 'notes', $post_data['slide-notes'][ $num ] )
				|| ! is_string( $post_data['slide-notes'][ $num ]['notes'] )
				|| ! array_key_exists( $num, $post_data['slide-classes'] )
				|| ! is_string( $post_data['slide-classes'][ $num ] )
			) {
				return null;
			}

			$markdown = $post_data['slide-notes'][ $num ]['markdown'] ?? false;
			if ( ! is_bool( $markdown ) && ! is_string( $markdown ) ) {
				return null;
			}

			$content = $post_data['slide-content'][ $num ];
			$notes   = $post_data['slide-notes'][ $num ]['notes'];
			if ( ! $allows_unfiltered_html ) {
				$content = wp_kses_post( $content );
				$notes   = wp_kses_post( $notes );
			}

			$slide          = new stdClass();
			$slide->number  = ++$slide_num;
			$slide->content = $content;
			$slide->notes   = array(
				'notes'    => $notes,
				'markdown' => (bool) $markdown,
			);
			$slide->class   = $post_data['slide-classes'][ $num ];
			$slide->data    = array();

			$has_data_names  = isset( $post_data['slide-data'] ) && array_key_exists( $num, $post_data['slide-data'] );
			$has_data_values = isset( $post_data['slide-data-value'] ) && array_key_exists( $num, $post_data['slide-data-value'] );
			if ( $has_data_names !== $has_data_values ) {
				return null;
			}

			if ( $has_data_names ) {
				if ( ! is_array( $post_data['slide-data'][ $num ] ) || ! is_array( $post_data['slide-data-value'][ $num ] ) ) {
					return null;
				}

				foreach ( $post_data['slide-data'][ $num ] as $data_num => $name ) {
					if (
						! is_string( $name )
						|| ! array_key_exists( $data_num, $post_data['slide-data-value'][ $num ] )
						|| ! is_string( $post_data['slide-data-value'][ $num ][ $data_num ] )
					) {
						return null;
					}

					if ( ! empty( $name ) ) {
						$data          = new stdClass();
						$data->name    = $name;
						$data->value   = $post_data['slide-data-value'][ $num ][ $data_num ];
						$slide->data[] = $data;
					}
				}
			}
			$slide->title = $slide_title;
			$slides[]     = $slide;
		}

		return $slides;
	}

	/**
	 * Save a complete, authorized legacy editor replacement.
	 *
	 * @param int     $post_id Slideshow post ID.
	 * @param WP_Post $post    Slideshow post object.
	 * @param bool    $update  Whether this is an existing post update.
	 */
	public function save_post_slideshow( $post_id, $post, $update ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by the WordPress save hook signature.
		// Don't process autosaves or AJAX requests.
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}

		if (
			! $post instanceof WP_Post
			|| (int) $post->ID !== (int) $post_id
			|| 'slideshow' !== $post->post_type
		) {
			return;
		}

		if ( false !== wp_is_post_revision( $post_id ) || in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) || $this->importing ) {
			return;
		}

		if ( ! presenter_get_runtime()->deck_mode()->uses_legacy_runtime( (int) $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST['_presenter_nonce'] ) ? wp_unslash( $_POST['_presenter_nonce'] ) : '';
		if (
			! is_string( $nonce ) ||
			! wp_verify_nonce(
				sanitize_text_field( $nonce ),
				$this->legacy_save_nonce_action( (int) $post_id )
			) ||
			! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}

		$post_data = wp_unslash( $_POST );
		$slides    = $this->_get_slides_from_post_data( $post_data );
		if (
			null === $slides
			|| ( isset( $post_data['presenter_theme'] ) && ! is_string( $post_data['presenter_theme'] ) )
			|| ( isset( $post_data['presenter_short_url'] ) && ! is_string( $post_data['presenter_short_url'] ) )
		) {
			return;
		}

		$themes = $this->get_themes();
		$theme  = isset( $post_data['presenter_theme'] ) ? sanitize_text_field( $post_data['presenter_theme'] ) : '';

		if ( empty( $theme ) || ! isset( $themes[ $theme ] ) ) {
			$theme = '';
		}
		update_post_meta( $post_id, '_presenter-theme', $theme );

		$short_url = isset( $post_data['presenter_short_url'] ) ? esc_url_raw( $post_data['presenter_short_url'] ) : '';
		if ( ! wp_http_validate_url( $short_url ) ) {
			$short_url = '';
		}
		update_post_meta( $post_id, '_presenter-short-url', $short_url );

		// Remove old slides.
		delete_post_meta( $post_id, '_presenter_slides' );

		// Add slides.
		foreach ( $slides as $slide ) {
			add_post_meta( $post_id, '_presenter_slides', $slide );
		}

		$stored_slides = get_post_meta( $post_id, '_presenter_slides', false );
		presenter_get_runtime()->legacy_html_trust()->synchronize(
			(int) $post_id,
			is_array( $stored_slides ) ? $stored_slides : array(),
			current_user_can( 'unfiltered_html' )
		);
	}

	/**
	 * Bind a legacy editor nonce to exactly one slideshow.
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return string Nonce action.
	 */
	private function legacy_save_nonce_action( int $post_id ): string {
		return self::SAVE_NONCE_ACTION . ':' . $post_id;
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
		$reveal_plugins           = array();
		if ( isset( $reveal_initialize_object->plugins ) && is_array( $reveal_initialize_object->plugins ) ) {
			foreach ( $reveal_initialize_object->plugins as $reveal_plugin ) {
				if ( is_string( $reveal_plugin ) && 1 === preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/', $reveal_plugin ) ) {
					$reveal_plugins[] = $reveal_plugin;
				}
			}
		}

		$plugin_sentinel                   = '__PRESENTER_REVEAL_PLUGIN_LIST__';
		$reveal_initialize_object->plugins = $plugin_sentinel;
		$reveal_initialize_json            = wp_json_encode(
			$reveal_initialize_object,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		if ( false === $reveal_initialize_json ) {
			$reveal_initialize_json = '{}';
		}
		$reveal_initialize_json            = str_replace(
			'"' . $plugin_sentinel . '"',
			'[' . implode( ',', $reveal_plugins ) . ']',
			$reveal_initialize_json
		);
		?>
		<script>

			// Full list of configuration options available here:
			// https://github.com/hakimel/reveal.js#configuration
			Reveal.initialize(<?php echo $reveal_initialize_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Script-safe JSON with separately validated JavaScript identifiers. ?>);

		</script>
		<?php
	}

	/**
	 * Register the classic Presenter controls for an existing legacy deck.
	 *
	 * Native and newly-created slideshows use the block editor and must not load
	 * the legacy slide editor alongside it.
	 *
	 * @param WP_Post $post Slideshow being edited.
	 * @return void
	 */
	public function register_legacy_meta_boxes( $post ) {
		if ( ! $this->is_legacy_slideshow_editor( $post ) ) {
			return;
		}

		add_meta_box( 'slides', __( 'Slides', 'presenter' ), array( $this, 'slides_meta_box' ), 'slideshow', 'normal', 'core');
		add_meta_box( 'pageparentdiv', __( 'Slideshow Attributes', 'presenter' ), array( $this, 'slideshow_attributes_meta_box' ), 'slideshow', 'side', 'default' );
	}

	public function slides_meta_box( $post ) {
		$slides = $this->prepare_legacy_slides( get_post_meta( $post->ID, '_presenter_slides', false ) );

		// Blank slide used for adding new slides
		$slide = new stdClass();
		$slide->number = '__i__'; // __i__ is replaced with new-# where # is the number of new slides added
		$slide->index_name = '__new__'; // __new__ is replaced with an empty string, and is ignored if it makes it to the PHP processing saves
		$slide->content = '';
		$slide->class = '';
		$slide->notes = array(
			'notes'    => '',
			'markdown' => false
		);
		$slide->title = 'New Slide';
		array_unshift( $slides, $slide );

		foreach ( $slides as $slide ) {
			if ( '__i__' !== $slide->number ) {
				$slide->number = absint( $slide->number );
			}
			if ( ! isset( $slide->index_name ) || empty( $slide->index_name ) ) {
				$slide->index_name = $slide->number;
			}
			// Back Compat for before notes were stored separately.
			if ( ! isset( $slide->notes ) ) {
				$slide->notes = array(
					'notes'    => '',
					'markdown' => false
				);
			}
			?>
			<div class="slide stuffbox" id="slide-<?php echo esc_attr( $slide->number ); ?>">
				<h3 class="slide-hndle">
					<span class="title"><?php echo esc_html( $slide->title ); ?></span>
					<span class="dashicons dashicons-arrow-up-alt move up alignright"></span>
					<span class="dashicons dashicons-arrow-down-alt move down alignright"></span>
				</h3>
				<input type='hidden' name='slide-index' value='<?php echo esc_attr( $slide->index_name ); ?>'>
				<div class="inside">
					<div class="titlediv">
						<?php
						/**
						 * Filter the title field placeholder text.
						 *
						 * @param string  $text Placeholder text. Default 'Enter title here'.
						 * @param WP_Post $post Post object.
						 */
						?>
						<label class="screen-reader-text title-prompt-text" id="slide-title-<?php echo esc_attr( $slide->number ); ?>-prompt-text" for="slide-title-<?php echo esc_attr( $slide->number ); ?>"><?php esc_html_e( 'Enter slide title here', 'presenter' ); ?></label>
						<input type="text" class="title" name="slide-title[<?php echo esc_attr( $slide->index_name ); ?>]" size="30" value="<?php echo esc_attr( $slide->title ); ?>" id="slide-title-<?php echo esc_attr( $slide->number ); ?>" spellcheck="true" autocomplete="off" />
					</div>
					<div class="postdivrich postarea">
					<?php
					if ( '__i__' == $slide->number ) {
						printf( '<textarea class="wp-editor-area" id="slide-content-%1$s" name="slide-content[%2$s]"></textarea>', esc_attr( $slide->number ), esc_attr( $slide->index_name ) );
					} else {
						wp_editor( $slide->content, "slide-content-{$slide->number}", array(
							'textarea_name' => 'slide-content[' . esc_attr( $slide->index_name ) . ']',
							'drag_drop_upload' => true,
							'tabfocus_elements' => 'content-html,save-post',
							'editor_height' => 300,
							'tinymce' => array(
								'resize' => false,
								'add_unload_trigger' => false,
							),
						) );
					}
					?>
					</div>
					<p>
						<label for="slide-notes-<?php echo esc_attr( $slide->number ); ?>"><?php esc_html_e( 'Speaker Notes', 'presenter' ); ?></label>
						<textarea name="slide-notes[<?php echo esc_attr( $slide->index_name ); ?>][notes]" id="slide-notes-<?php echo esc_attr( $slide->number ); ?>" class="large-text"><?php echo esc_textarea( $slide->notes['notes'] ); ?></textarea>
						<input type="checkbox" name="slide-notes[<?php echo esc_attr( $slide->index_name ); ?>][markdown]" value="true" id="slide-notes-<?php echo esc_attr( $slide->number ); ?>-markdown"<?php checked( $slide->notes['markdown'], true, true ); ?> /> <label for="slide-notes-<?php echo esc_attr( $slide->number ); ?>-markdown"><?php esc_html_e( 'Use Markdown', 'presenter' ); ?></label>
					</p>
					<a href="#advanced" class="show-hide-advanced hide-if-no-js show" role="button"><span class="show"><?php esc_html_e( 'Show Advanced Slide Settings ▼', 'presenter' ); ?></span><span class="hide"><?php esc_html_e( 'Hide Advanced Slide Settings ▲', 'presenter' ); ?></span></a>
					<div class="presenter-advanced hide-if-js" id="presenter-advanced-<?php echo esc_attr( $slide->number ); ?>">
						<p>
							<label for="slide-classes-<?php echo esc_attr( $slide->number ); ?>"><?php esc_html_e( 'CSS classes to add to slide, space separated', 'presenter' ); ?></label>
							<input name="slide-classes[<?php echo esc_attr( $slide->index_name ); ?>]" type="text" id="slide-classes-<?php echo esc_attr( $slide->number ); ?>" class="large-text" value="<?php echo esc_attr( $slide->class ); ?>" />
						</p>
						<div class="data-attributes" id="slide-data-attributes-<?php echo esc_attr( $slide->number ); ?>">
							<p><strong><?php esc_html_e( 'Slide Data Attributes', 'presenter' ); ?></strong></p>
							<table class="slide-data-attributes-table">
								<thead>
									<tr>
									<th class="left"><?php esc_html_e( 'Name', 'presenter' ); ?></th>
									<th><?php esc_html_e( 'Value', 'presenter' ); ?></th>
									</tr>
								</thead>
								<tfoot>
									<tr>
										<td colspan="2">
											<div class="submit">
											<div class="button dashicon add-data before"><?php esc_html_e( 'Add Data Field', 'presenter' ); ?></div>
											</div>
										</td>
									</tr>
								</tfoot>

								<tbody>
									<?php
									if ( isset( $slide->data ) && is_array( $slide->data ) ) {
										foreach ( $slide->data as $data ) {
											?>
											<tr>
												<td class="left newdataleft">
													<input type="text" name="slide-data[<?php echo esc_attr( $slide->index_name ); ?>][]" value="<?php echo esc_attr( $data->name ); ?>">
												</td>
												<td>
													<input type="text" name="slide-data-value[<?php echo esc_attr( $slide->index_name ); ?>][]" value="<?php echo esc_attr( $data->value ); ?>">
												</td>
											</tr>
											<?php
										}
									}
									?>
								</tbody>
							</table>
						</div>
					</div>
					<div class="button dashicon remove"><?php esc_html_e( 'Remove Slide', 'presenter' ); ?></div>
					<div class="button dashicon add alignright before"><?php esc_html_e( 'Add Above', 'presenter' ); ?></div>
					<div class="button dashicon add alignright after"><?php esc_html_e( 'Add Below', 'presenter' ); ?></div>
				</div>
			</div>
			<?php
			//add_meta_box( 'slide-' . $slide->number, $slide->title, array( $this, 'slide_meta_box' ), 'slideshow', 'slides', null, $slide );
		}
		do_meta_boxes( 'slideshow', 'slides', $post );
		?>
		<div class="button dashicon add" id="presenter-add-slide"><?php esc_html_e( 'Add New Slide', 'presenter' ); ?></div>
		<?php
	}

	public function slideshow_attributes_meta_box( $post ) {
		wp_nonce_field( $this->legacy_save_nonce_action( (int) $post->ID ), '_presenter_nonce' );
		?>
		<p>
			<strong><?php esc_html_e( 'Slideshow Theme', 'presenter' ); ?></strong>
		</p>
		<label class="screen-reader-text" for="presenter_theme">
			<?php esc_html_e( 'Slideshow Theme', 'presenter' ); ?>
		</label>
		<select name="presenter_theme" id="presenter_theme">
			<option value='default'><?php esc_html_e( 'Default Template', 'presenter' ); ?></option>
			<?php $this->_presenter_themes_dropdown_options( get_post_meta( $post->ID, '_presenter-theme', true ) ); ?>
		</select>
		<p>
			<strong><?php esc_html_e( 'Order', 'presenter' ); ?></strong>
		</p>
		<p>
			<label class="screen-reader-text" for="menu_order">
				<?php esc_html_e( 'Order', 'presenter' ); ?>
			</label>
			<input name="menu_order" type="text" size="4" id="menu_order" value="<?php echo esc_attr( $post->menu_order ); ?>" />
		</p>
		<p>
			<strong><?php esc_html_e( 'Short URL', 'presenter' ); ?></strong>
		</p>
		<p>
			<label class="screen-reader-text" for="presenter_short_url">
				<?php esc_html_e( 'Short URL', 'presenter' ); ?>
			</label>
			<input name="presenter_short_url" type="text" id="presenter_short_url" value="<?php echo esc_attr( get_post_meta( $post->ID, '_presenter-short-url', true ) ); ?>" />
		</p>
	<?php
	}

	public function get_themes() {
		$presenter_themes = $this->_cache_get( 'themes' );

		if ( ! is_array( $presenter_themes ) ) {
			$presenter_theme_directories = array( plugin_dir_path( __FILE__ ) . 'reveal.js/dist/theme' );
			if ( file_exists( get_stylesheet_directory() . '/presenter' ) ) {
				$presenter_theme_directories[] = get_stylesheet_directory() . '/presenter';
			}
			if ( is_child_theme() && file_exists( get_template_directory() . '/presenter' ) ) {
				$presenter_theme_directories[] = get_template_directory() . '/presenter';
			}
			$presenter_theme_directories = apply_filters( 'presenter-theme-directories', $presenter_theme_directories );
			$presenter_theme_directories = is_array( $presenter_theme_directories ) ? $presenter_theme_directories : array();

			$files = array();
			foreach ( $presenter_theme_directories as $presenter_theme_directory ) {
				if ( is_string( $presenter_theme_directory ) ) {
					$files += $this->_scandir( $presenter_theme_directory );
				}
			}

			$presenter_themes = array();

			foreach ( $files as $file => $full_path ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a discovered local CSS theme file.
				$contents = file_get_contents( $full_path );
				if ( false === $contents ) {
					continue;
				}

				// Handle Reveal's historical distributed theme header.
				if ( ! preg_match( '|([^\*]*)theme for reveal.js|mi', $contents, $header ) ) {
					// Prefer WordPress-style headers for custom themes.
					if ( ! preg_match( '|Template Name:(.*)$|mi', $contents, $header ) ) {
						continue;
					}
				} else {
					// The distributed files don't all have unique names, so add the filename.
					$header[1] = $this->cleanup_theme_header( $header[1] ) . ' (' . basename( $full_path ) . ')';
				}

				$presenter_themes[ str_replace( WP_CONTENT_DIR, '', $full_path ) ] = $this->cleanup_theme_header( $header[1] );
			}

			$this->_cache_add( 'themes', $presenter_themes );
		}

		/**
		 * Filter list of Presenter themes.
		 *
		 * This filter does not currently allow for themes to be added.
		 *
		 * @param array    $presenter_themes Array of themes. Keys are filenames relative to WP_CONTENT_DIR, values are translated names.
		 * @param WP_Theme $this             The Presenter object.
		 */
		$return = apply_filters( 'presenter-themes', $presenter_themes, $this );

		$presenter_themes = array_intersect_assoc( $return, $presenter_themes );

		return $presenter_themes;
	}

	public function get_default_theme() {
		return apply_filters( 'presenter-default-theme', str_replace( WP_CONTENT_DIR, '', plugin_dir_path( __FILE__ ) . 'reveal.js/dist/theme/league.css' ) );
	}

	private function _presenter_themes_dropdown_options( $selected_theme = '' ) {
		$themes = $this->get_themes();
		asort( $themes );

		foreach ( $themes as $theme => $name ) {
			echo '<option value="' . esc_attr( $theme ) . '"';
			selected( $selected_theme, $theme );
			echo '>' . esc_html( $name ) . '</option>';
		}
	}

	/**
	 * Normalize a theme name captured from a CSS header.
	 *
	 * @param string $header Raw captured header value.
	 * @return string Clean theme name.
	 */
	private function cleanup_theme_header( $header ) {
		$cleaned = preg_replace( '/\s*(?:\*\/|\?>).*/', '', $header );

		return sanitize_text_field( null === $cleaned ? '' : trim( $cleaned ) );
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
		if ( ! is_dir( $path ) ) {
			return array();
		}

		if ( $extensions ) {
			$extensions       = (array) $extensions;
			$extension_pattern = implode( '|', $extensions );
		}

		$relative_path = trailingslashit( $relative_path );
		if ( '/' === $relative_path ) {
			$relative_path = '';
		}

		$results = scandir( $path );
		$files   = array();
		if ( false === $results ) {
			return $files;
		}

		foreach ( $results as $result ) {
			if ( '.' === $result[0] ) {
				continue;
			}
			if ( is_dir( $path . '/' . $result ) ) {
				if ( ! $depth || 'CVS' === $result ) {
					continue;
				}
				$found = $this->_scandir( $path . '/' . $result, $extensions, $depth - 1, $relative_path . $result );
				$files = array_merge_recursive( $files, $found );
			} elseif ( ! $extensions || preg_match( '~\.(' . $extension_pattern . ')$~', $result ) ) {
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
		if (
			is_singular( 'slideshow' ) &&
			! post_password_required( get_the_ID() ) &&
			presenter_get_runtime()->deck_mode()->uses_legacy_runtime( get_the_ID() )
		) {
			$template = plugin_dir_path( __FILE__ ) . 'templates/index.php';

			wp_scripts()->add_data( 'html5shiv', 'conditional', 'lt IE 9' );

			/**
			 * Reveal.js plugins as dependencies
			 */
			wp_register_script( 'RevealMarkdown', plugins_url( 'reveal.js/plugin/markdown/markdown.js', __FILE__ ), array(), '4.1.2', true );
			wp_register_script( 'RevealSearch', plugins_url( 'reveal.js/plugin/search/search.js', __FILE__ ), array(), '4.1.2', true );
			wp_register_script( 'RevealNotes', plugins_url( 'reveal.js/plugin/notes/notes.js', __FILE__ ), array(), '4.1.2', true );
			wp_register_script( 'RevealZoom', plugins_url( 'reveal.js/plugin/zoom/zoom.js', __FILE__ ), array(), '4.1.2', true );
			$reveal_js_dependencies = array( 'RevealMarkdown', 'RevealSearch', 'RevealNotes', 'RevealZoom' );
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

			wp_register_style( 'presenter', plugins_url( 'css/presenter.css', __FILE__ ), array(), self::VERSION );
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
			wp_register_style( 'reveal-theme', apply_filters( 'presenter-theme', content_url( $theme ) ), array(), self::VERSION );

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
		$url = \Presenter\Meta::sanitize_short_url( get_post_meta( get_the_ID(), '_presenter-short-url', true ) );
		if ( empty( $url ) ) {
			$url = get_permalink();
		}
		return $url;
	}

	public function print_editor_styles() {
		if ( $this->is_legacy_slideshow_editor() ) {
			wp_enqueue_style( 'presenter-admin-edit-styles', plugins_url( 'css/edit-slide-admin.css', __FILE__ ), array( 'dashicons' ), '20141117' );
		}
	}

	public function print_editor_scripts() {
		if ( $this->is_legacy_slideshow_editor() ) {
			wp_enqueue_editor();
			wp_enqueue_script( 'presenter-admin-edit-styles', plugins_url( 'js/edit-slide-admin.js', __FILE__ ), array( 'post', 'backbone' ), '20141117', false );
		}
	}

	/**
	 * Determine whether the current editor belongs to a stored legacy deck.
	 *
	 * @param WP_Post|null $post Optional post supplied by the meta-box action.
	 * @return bool
	 */
	private function is_legacy_slideshow_editor( $post = null ) {
		$screen = get_current_screen();
		if ( ! $screen || 'slideshow' !== $screen->post_type ) {
			return false;
		}

		if ( ! $post instanceof WP_Post ) {
			$post = get_post();
		}

		return $post instanceof WP_Post
			&& 'slideshow' === $post->post_type
			&& presenter_get_runtime()->deck_mode()->uses_legacy_runtime( $post->ID );
	}

	/**
	 * Replace singular legacy slideshow content with normalized slide markup.
	 *
	 * @param string $content Filtered post content.
	 * @return string Presentation content.
	 */
	public function the_content( $content ) {
		// If this is a single slideshow, build the content from slides.
		if (
			is_singular( 'slideshow' ) &&
			! post_password_required( get_the_ID() ) &&
			presenter_get_runtime()->deck_mode()->uses_legacy_runtime( get_the_ID() )
		) {
			$post_id       = get_the_ID();
			$stored_slides = get_post_meta( $post_id, '_presenter_slides', false );
			$trusted_html  = presenter_get_runtime()->legacy_html_trust()->is_trusted( $post_id, $stored_slides );
			$slides        = $this->prepare_legacy_slides( $stored_slides );
			$content       = $this->_get_html_from_slides( $slides, $trusted_html );
		}
		return $content;
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

require_once __DIR__ . '/includes/class-bootstrap.php';

/**
 * Get the Presenter 2.0 application instance.
 *
 * @return \Presenter\Application Presenter application.
 */
function presenter_get_runtime(): \Presenter\Application {
	static $runtime = null;

	if ( ! $runtime instanceof \Presenter\Application ) {
		$runtime = \Presenter\Bootstrap::create( __FILE__ );
	}

	return $runtime;
}

presenter_get_runtime()->register();
