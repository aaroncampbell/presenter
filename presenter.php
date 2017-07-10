<?php
/**
 * Plugin Name: Presenter
 * Plugin URI: http://aarondcampbell.com/wordpress-plugins/presenter/
 * Description: Presenter
 * Version: 1.2.0
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
require_once('aaron-plugin-framework.php');
class presenter extends AaronPlugin {
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

	protected function _init() {
		$this->_hook = 'presenter';
		$this->_slug = 'presenter';
		$this->_file = plugin_basename( __FILE__ );
		$this->_pageTitle = __( 'Presenter', $this->_slug );
		$this->_menuTitle = __( 'Presenter', $this->_slug );
		$this->_accessLevel = 'manage_options';
		$this->_optionGroup = 'presenter-options';
		$this->_optionNames = array('presenter');
		$this->_optionCallbacks = array();
		$this->_paypalButtonId = '9996714';

		$this->importing = false;

		/**
		 * Add filters and actions
		 */
		add_action( 'plugins_loaded',                   array( $this, 'upgrade_check'         )          );
		add_action( 'after_setup_theme',                array( $this, 'after_setup_theme'     )          );
		add_filter( 'single_template',                  array( $this, 'single_template'       )          );
		add_action( 'save_post_slideshow',              array( $this, 'save_post_slideshow'   ), null, 3 );
		add_action( 'admin_init',                       array( $this, 'admin_init'            )          );
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
			),
			'menu_icon'       => 'dashicons-slides',
		);
		register_post_type( 'slideshow', $args );
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
			foreach ( $_POST['slide-data'][$num] as $data_num => $name ) {
				if ( ! empty( $name ) ) {
					$data = new stdClass();
					$data->name = $name;
					$data->value = $_POST['slide-data-value'][$num][$data_num];
					$slide->data[] = $data;
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
		?>
		<!-- If the query includes 'print-pdf', include the PDF print sheet -->
		<script>
			if( window.location.search.match( /print-pdf/gi ) ) {
				var link = document.createElement( 'link' );
				link.rel = 'stylesheet';
				link.type = 'text/css';
				link.href = '<?php echo plugins_url( 'reveal.js/css/print/pdf.css', __FILE__ ); ?>';
				document.getElementsByTagName( 'head' )[0].appendChild( link );
			}
		</script>
		<?php
		global $SyntaxHighlighter;
		if ( is_a( $SyntaxHighlighter, 'SyntaxHighlighter' ) ) {
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
		wp_print_scripts( array( 'reveal-head', 'reveal' ) );
		?>
		<script>

			// Full list of configuration options available here:
			// https://github.com/hakimel/reveal.js#configuration
			Reveal.initialize({
				controls: true,
				progress: true,
				history: true,
				center: true,

				theme: Reveal.getQueryHash().theme, // available themes are in /css/theme
				transition: Reveal.getQueryHash().transition || 'default', // default/cube/page/concave/zoom/linear/fade/none

				// Parallax scrolling
				// parallaxBackgroundImage: 'https://s3.amazonaws.com/hakim-static/reveal-js/reveal-parallax-1.jpg',
				// parallaxBackgroundSize: '2100px 900px',

				// Optional libraries used to extend on reveal.js
				dependencies: [
					{ src: '<?php echo plugins_url( 'reveal.js/lib/js/classList.js', __FILE__ ); ?>', condition: function() { return !document.body.classList; } },
					{ src: '<?php echo plugins_url( 'reveal.js/plugin/markdown/marked.js', __FILE__ ); ?>', condition: function() { return !!document.querySelector( '[data-markdown]' ); } },
					{ src: '<?php echo plugins_url( 'reveal.js/plugin/markdown/markdown.js', __FILE__ ); ?>', condition: function() { return !!document.querySelector( '[data-markdown]' ); } },
					<?php
					// Only load highlight.js if SyntaxHighlighter isn't active
					if ( ! is_a( $SyntaxHighlighter, 'SyntaxHighlighter' ) ) {
					?>
					{ src: '<?php echo plugins_url( 'reveal.js/plugin/highlight/highlight.js', __FILE__ ); ?>', async: true, callback: function() { hljs.initHighlightingOnLoad(); } },
					<?php
					}
					?>
					{ src: '<?php echo plugins_url( 'reveal.js/plugin/zoom-js/zoom.js', __FILE__ ); ?>', async: true, condition: function() { return !!document.body.classList; } },
					{ src: '<?php echo plugins_url( 'reveal.js/plugin/notes/notes.js', __FILE__ ); ?>', async: true, condition: function() { return !!document.body.classList; } }
				]
			});

		</script>
		<?php
	}

	public function admin_init() {
		add_meta_box( 'slides', 'Slides', array( $this, 'slides_meta_box' ), 'slideshow', 'normal', 'core');
		add_meta_box( 'pageparentdiv', __( 'Slideshow Attributes', $this->_slug ), array( $this, 'slideshow_attributes_meta_box' ), 'slideshow', 'side', 'default' );
	}

	public function slides_meta_box( $post ) {
		$slides = get_post_meta( $post->ID, '_presenter_slides' );
		usort( $slides, array( $this, 'sort_slides' ) );

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
			<div class="slide stuffbox" id="<?php echo "slide-{$slide->number}"?>">
				<h3 class="slide-hndle">
					<span class="title"><?php echo esc_html( $slide->title ) ?></span>
					<span class="dashicons dashicons-arrow-up-alt move up alignright"></span>
					<span class="dashicons dashicons-arrow-down-alt move down alignright"></span>
				</h3>
				<input type='hidden' name='slide-index' value='<?php echo esc_attr( $slide->index_name ) ?>'>
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
						<label class="screen-reader-text title-prompt-text" id="slide-title-<?php echo $slide->number?>-prompt-text" for="slide-title-<?php echo $slide->number; ?>"><?php esc_html_e( 'Enter slide title here', $this->_slug ); ?></label>
						<input type="text" class="title" name="slide-title[<?php echo esc_attr( $slide->index_name ); ?>]" size="30" value="<?php echo esc_attr( htmlspecialchars( $slide->title ) ); ?>" id="slide-title-<?php echo $slide->number; ?>" spellcheck="true" autocomplete="off" />
					</div>
					<div class="postdivrich postarea">
					<?php
					if ( '__i__' == $slide->number ) {
						printf( '<textarea class="wp-editor-area" id="slide-content-%1$s" name="slide-content[%2$s]"></textarea>', $slide->number, esc_attr( $slide->index_name ) );
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
						<label for="slide-notes-<?php echo $slide->number; ?>"><?php _e( 'Speaker Notes', $this->_slug ); ?></label>
						<textarea name="slide-notes[<?php echo $slide->index_name; ?>][notes]" id="slide-notes-<?php echo $slide->number; ?>" class="large-text"><?php echo esc_html( $slide->notes['notes'] ); ?></textarea>
						<input type="checkbox" name="slide-notes[<?php echo $slide->index_name; ?>][markdown]" value="true" id="slide-notes-<?php echo $slide->number; ?>-markdown"<?php checked( $slide->notes['markdown'], true, true ) ?> /> <label for="slide-notes-<?php echo $slide->number; ?>-markdown"><?php _e( 'Use Markdown', $this->_slug ); ?></label>
					</p>
					<a href="#advanced" class="show-hide-advanced hide-if-no-js show" role="button"><span class="show"><?php _e('Show Advanced Slide Settings &#9660;'); ?></span><span class="hide"><?php _e('Hide Advanced Slide Settings &#9650;'); ?></span></a>
					<div class="presenter-advanced hide-if-js" id="presenter-advanced-<?php echo $slide->number; ?>">
						<p>
							<label for="slide-classes-<?php echo $slide->number; ?>"><?php _e( 'CSS classes to add to slide, space separated', $this->_slug ); ?></label>
							<input name="slide-classes[<?php echo $slide->index_name; ?>]" type="text" id="slide-classes-<?php echo $slide->number; ?>" class="large-text" value="<?php echo esc_attr( $slide->class ); ?>" />
						</p>
						<div class="data-attributes" id="slide-data-attributes-<?php echo $slide->number; ?>">
							<p><strong>Slide Data Attributes</strong></p>
							<table class="slide-data-attributes-table">
								<thead>
									<tr>
										<th class="left">Name</th>
										<th>Value</th>
									</tr>
								</thead>
								<tfoot>
									<tr>
										<td colspan="2">
											<div class="submit">
												<div class="button dashicon add-data before"><?php esc_html_e( 'Add Data Field', $this->_slug ); ?></div>
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
													<input type="text" name="slide-data[<?php echo $slide->index_name; ?>][]" value="<?php echo esc_attr( $data->name ); ?>">
												</td>
												<td>
													<input type="text" name="slide-data-value[<?php echo $slide->index_name; ?>][]" value="<?php echo esc_attr( $data->value ); ?>">
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
					<div class="button dashicon remove"><?php esc_html_e( 'Remove Slide', $this->_slug ); ?></div>
					<div class="button dashicon add alignright before"><?php esc_html_e( 'Add Above', $this->_slug ); ?></div>
					<div class="button dashicon add alignright after"><?php esc_html_e( 'Add Below', $this->_slug ); ?></div>
				</div>
			</div>
			<?php
			//add_meta_box( 'slide-' . $slide->number, $slide->title, array( $this, 'slide_meta_box' ), 'slideshow', 'slides', null, $slide );
		}
		do_meta_boxes( 'slideshow', 'slides', $post );
		?>
		<div class="button dashicon add" id="presenter-add-slide"><?php esc_html_e( 'Add New Slide', $this->_slug ); ?></div>
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
		$files = (array) $this->_scandir( plugin_dir_path( __FILE__ ) . 'reveal.js/css/theme' );

		if ( file_exists( get_stylesheet_directory() . '/presenter' ) ) {
			$files += (array) $this->_scandir( get_stylesheet_directory() . '/presenter' );
		}
		if ( is_child_theme() && file_exists( get_template_directory() . '/presenter' ) ) {
			$files += (array) $this->_scandir( get_template_directory() . '/presenter' );
		}

		$presenter_themes = $this->_cache_get( 'themes' );

		if ( ! is_array( $presenter_themes ) ) {

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

				$presenter_themes[ str_replace( WP_CONTENT_DIR, '', $full_path ) ] = _cleanup_header_comment( $header[1] );
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
		return apply_filters( 'presenter-default-theme', str_replace( WP_CONTENT_DIR, '', plugin_dir_path( __FILE__ ) . 'reveal.js/css/theme/league.css' ) );
	}

	private function _presenter_themes_dropdown_options( $selected_theme = '' ) {
		$themes = $this->get_themes();
		asort( $themes );

		foreach ( $themes as $theme => $name ) {
			$selected = selected( $selected_theme, $theme, false );
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $theme ), $selected, esc_html( $name ) );
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


			wp_register_style( 'presenter', plugins_url( 'css/presenter.css', __FILE__ ) );
			wp_register_style( 'reveal', plugins_url( 'reveal.js/css/reveal.css', __FILE__ ), array(), '3.5.0' );
			$theme = get_post_meta( get_the_ID(), '_presenter-theme', true );
			if ( empty( $theme ) ) {
				$theme = $this->get_default_theme();
			}
			wp_register_style( 'reveal-theme', content_url( $theme ) );
			wp_register_style( 'reveal-lib-zenburn', plugins_url( 'reveal.js/lib/css/zenburn.css', __FILE__ ), array(), '3.5.0' );

			wp_register_script( 'html5shiv', plugins_url( 'reveal.js/lib/js/html5shiv.js', __FILE__ ) );
			global $wp_scripts;
			$wp_scripts->add_data( 'html5shiv', 'conditional', 'lt IE 9' );

			wp_register_script( 'reveal-head', plugins_url( 'reveal.js/lib/js/head.min.js', __FILE__ ), array(), '3.5.0', true );
			wp_register_script( 'reveal', plugins_url( 'reveal.js/js/reveal.js', __FILE__ ), array( 'reveal-head' ), '3.5.0', true );
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

	public function print_editor_styles() {
		if ( 'slideshow' == get_current_screen()->post_type ) {
			wp_enqueue_style( 'presenter-admin-edit-styles', plugins_url( 'css/edit-slide-admin.css', __FILE__ ), array( 'dashicons' ), '20141117' );
		}
	}

	public function print_editor_scripts() {
		if ( 'slideshow' == get_current_screen()->post_type ) {
			wp_enqueue_editor();
			wp_enqueue_script( 'presenter-admin-edit-styles', plugins_url( 'js/edit-slide-admin.js', __FILE__ ), array( 'post', 'backbone' ), '20141117' );
		}
	}

	public function the_content( $content ) {
		// If this is a single slideshow, build the content from slides
		if ( is_singular( 'slideshow' ) ) {
			$slides = get_post_meta( get_the_ID(), '_presenter_slides' );
			usort( $slides, array( $this, 'sort_slides' ) );
			$content = $this->_get_html_from_slides( $slides );
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
