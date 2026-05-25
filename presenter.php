<?php
/**
 * Plugin Name: Presenter
 * Plugin URI: http://aarondcampbell.com/wordpress-plugins/presenter/
 * Description: Presenter
 * Version: 2.0.0
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
	 * @var string Plugin slug used for translations and cache keys.
	 */
	private $_slug = 'presenter';

	/**
	 * @var int - Plugin version used to trigger upgrade routines. Only update if an upgrade routine is needed.
	 */
	private $_version = 20260529;

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
		add_action( 'load-post.php',                    array( $this, 'maybe_migrate_admin_post' )       );
		add_action( 'template_redirect',                array( $this, 'maybe_migrate_current_slideshow' ) );
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
		add_filter( 'render_block',                     array( $this, 'render_fragment_block' ),    10, 2 );

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
		register_block_type( __DIR__ );
	}

	/**
	 * Add reveal.js fragment classes to rendered blocks that opt in through the
	 * block editor sidebar.
	 *
	 * @param string $block_content Rendered block HTML.
	 * @param array  $block         Parsed block data.
	 * @return string Updated block HTML.
	 */
	public function render_fragment_block( $block_content, $block ) {
		if ( empty( $block['attrs']['presenterFragment'] ) || '' === trim( $block_content ) ) {
			return $block_content;
		}

		$fragment_classes = $this->_fragment_classes_from_block_attrs( $block['attrs'] );
		if ( empty( $fragment_classes ) ) {
			return $block_content;
		}

		$fragment_index = null;
		if ( isset( $block['attrs']['presenterFragmentIndex'] ) && is_numeric( $block['attrs']['presenterFragmentIndex'] ) ) {
			$fragment_index = max( 0, (int) $block['attrs']['presenterFragmentIndex'] );
		}

		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$processor = new WP_HTML_Tag_Processor( $block_content );
			if ( ! $processor->next_tag() ) {
				return $block_content;
			}

			foreach ( $fragment_classes as $fragment_class ) {
				$processor->add_class( $fragment_class );
			}

			if ( null !== $fragment_index ) {
				$processor->set_attribute( 'data-fragment-index', (string) $fragment_index );
			}

			return $processor->get_updated_html();
		}

		return $this->_render_fragment_block_fallback( $block_content, $fragment_classes, $fragment_index );
	}

	/**
	 * Build the list of reveal.js fragment classes for a block.
	 *
	 * @access private
	 *
	 * @param array $attrs Block attributes.
	 * @return string[] Classes to add to the rendered block wrapper.
	 */
	private function _fragment_classes_from_block_attrs( $attrs ) {
		$classes = array( 'fragment' );
		$effect = isset( $attrs['presenterFragmentEffect'] ) ? (string) $attrs['presenterFragmentEffect'] : '';

		if ( 'custom' === $effect ) {
			$custom_classes = isset( $attrs['presenterFragmentCustomEffect'] ) ? (string) $attrs['presenterFragmentCustomEffect'] : '';
			foreach ( preg_split( '/\s+/', $custom_classes ) as $custom_class ) {
				$custom_class = sanitize_html_class( $custom_class );
				if ( '' !== $custom_class ) {
					$classes[] = $custom_class;
				}
			}
		} else {
			$effect = sanitize_html_class( $effect );
			if ( '' !== $effect ) {
				$classes[] = $effect;
			}
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Fallback fragment rendering for older WordPress versions.
	 *
	 * @access private
	 *
	 * @param string   $block_content Rendered block HTML.
	 * @param string[] $classes       Classes to add.
	 * @param int|null $fragment_index Optional fragment order.
	 * @return string Updated block HTML.
	 */
	private function _render_fragment_block_fallback( $block_content, $classes, $fragment_index ) {
		if ( ! preg_match( '/<([a-z][a-z0-9:-]*)(\s[^>]*)?>/i', $block_content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $block_content;
		}

		$tag = $matches[0][0];
		$existing_classes = array();
		if ( preg_match( '/\sclass=(["\'])(.*?)\1/i', $tag, $class_matches ) ) {
			$existing_classes = preg_split( '/\s+/', trim( $class_matches[2] ) );
		}

		$next_classes = trim( implode( ' ', array_unique( array_filter( array_merge( $existing_classes, $classes ) ) ) ) );
		if ( preg_match( '/\sclass=(["\'])(.*?)\1/i', $tag ) ) {
			$tag = preg_replace( '/\sclass=(["\'])(.*?)\1/i', ' class="' . esc_attr( $next_classes ) . '"', $tag, 1 );
		} else {
			$tag = substr_replace( $tag, ' class="' . esc_attr( $next_classes ) . '"', -1, 0 );
		}

		if ( null !== $fragment_index && ! preg_match( '/\sdata-fragment-index=/i', $tag ) ) {
			$tag = substr_replace( $tag, ' data-fragment-index="' . esc_attr( (string) $fragment_index ) . '"', -1, 0 );
		}

		return substr_replace( $block_content, $tag, $matches[0][1], strlen( $matches[0][0] ) );
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

	public function maybe_migrate_admin_post() {
		if ( empty( $_GET['post'] ) ) {
			return;
		}

		$this->_maybe_migrate_legacy_slideshow( absint( $_GET['post'] ) );
	}

	public function maybe_migrate_current_slideshow() {
		if ( is_singular( 'slideshow' ) && ! post_password_required( get_the_ID() ) ) {
			$this->_maybe_migrate_legacy_slideshow( get_the_ID() );
		}
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

		if ( $current_version < 20260524 ) {
			$this->_upgrade_20260524();
		}

		if ( $current_version < 20260526 ) {
			$this->_upgrade_20260526();
		}

		if ( $current_version < 20260527 ) {
			$this->_upgrade_20260527();
		}

		if ( $current_version < 20260528 ) {
			$this->_upgrade_20260528();
		}

		if ( $current_version < 20260529 ) {
			$this->_upgrade_20260529();
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

	/**
	 * Convert legacy slideshows that stored their slides in the
	 * `_presenter_slides` post meta into presenter/slide blocks inside the post
	 * content, so they open natively in the block editor.
	 *
	 * The original slides are preserved in `_presenter_slides_backup` meta.
	 */
	private function _upgrade_20260524() {
		$args = array(
			'post_type'     => 'slideshow',
			'post_status'   => 'any',
			'nopaging'      => true,
			'cache_results' => false,
			'no_found_rows' => true,
		);
		$posts = new WP_Query( $args );

		while ( $posts->have_posts() ) {
			$post = $posts->next_post();

			$this->_maybe_migrate_legacy_slideshow( $post->ID );
		}
	}

	private function _upgrade_20260526() {
		$this->_upgrade_20260524();
	}

	private function _upgrade_20260527() {
		$this->_upgrade_20260524();
	}

	private function _upgrade_20260528() {
		$this->_upgrade_20260524();
	}

	private function _upgrade_20260529() {
		$this->_upgrade_20260524();
	}

	/**
	 * Convert one legacy slideshow post if it still has old slide meta and no
	 * presenter/slide blocks. Used by the upgrader and lazy import handling.
	 *
	 * @access private
	 *
	 * @param int $post_id Slideshow post ID.
	 * @return bool Whether a migration was performed.
	 */
	private function _maybe_migrate_legacy_slideshow( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'slideshow' !== $post->post_type ) {
			return false;
		}

		$slides = get_post_meta( $post->ID, '_presenter_slides' );
		$delete_legacy_slide_meta = true;
		$backup_legacy_slides = true;
		$native_block_remigration = false;
		if ( empty( $slides ) ) {
			$backup_slides = get_post_meta( $post->ID, '_presenter_slides_backup' );
			$native_block_migration_version = (int) get_post_meta( $post->ID, '_presenter_native_block_migration_version', true );
			if (
				! empty( $backup_slides ) &&
				$this->_post_has_slide_blocks( $post ) &&
				20260529 > $native_block_migration_version
			) {
				$slides = $backup_slides;
				$delete_legacy_slide_meta = false;
				$backup_legacy_slides = false;
				$native_block_remigration = true;
			} else {
				return false;
			}
		}

		// Don't convert twice.
		if ( $delete_legacy_slide_meta && $this->_post_has_slide_blocks( $post ) ) {
			return false;
		}

		$slides = $this->_unique_legacy_slides( $slides );
		usort( $slides, array( $this, '_sort_slides' ) );

		$blocks = '';
		foreach ( $slides as $slide ) {
			$blocks .= $this->_slide_meta_to_block( $slide );
		}

		if ( '' === $blocks ) {
			return false;
		}

		if ( $backup_legacy_slides ) {
			// Back up the original slides before replacing them.
			foreach ( $slides as $slide ) {
				add_post_meta( $post->ID, '_presenter_slides_backup', $slide );
			}
		} else {
			add_post_meta( $post->ID, '_presenter_html_block_migration_backup', $post->post_content );
		}

		// Update post_content directly to avoid triggering save hooks.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => $blocks ), array( 'ID' => $post->ID ) );
		clean_post_cache( $post->ID );

		if ( $delete_legacy_slide_meta ) {
			delete_post_meta( $post->ID, '_presenter_slides' );
		}
		if ( $native_block_remigration ) {
			update_post_meta( $post->ID, '_presenter_native_block_migration_version', 20260529 );
		}

		return true;
	}

	/**
	 * Check whether a slideshow post already stores presenter/slide blocks.
	 *
	 * @access private
	 *
	 * @param WP_Post $post Slideshow post.
	 * @return bool Whether the post has slide blocks.
	 */
	private function _post_has_slide_blocks( $post ) {
		return false !== strpos( (string) $post->post_content, 'wp:presenter/slide' );
	}

	/**
	 * Check whether a post appears to have been migrated into Custom HTML slide
	 * contents only, so it can be safely rebuilt from the saved legacy backup.
	 *
	 * @access private
	 *
	 * @param WP_Post $post Slideshow post.
	 * @return bool Whether the post has legacy Custom HTML slide blocks.
	 */
	private function _post_has_legacy_html_slide_blocks( $post ) {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return false;
		}

		$blocks = parse_blocks( $post->post_content );
		$slide_blocks = array_filter( $blocks, function( $block ) {
			return isset( $block['blockName'] ) && 'presenter/slide' === $block['blockName'];
		} );

		if ( empty( $slide_blocks ) ) {
			return false;
		}

		foreach ( $slide_blocks as $slide_block ) {
			$inner_blocks = isset( $slide_block['innerBlocks'] ) ? $slide_block['innerBlocks'] : array();
			if ( 1 !== count( $inner_blocks ) ) {
				return false;
			}
			if ( ! isset( $inner_blocks[0]['blockName'] ) || 'core/html' !== $inner_blocks[0]['blockName'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sort legacy slide objects by their stored slide number.
	 *
	 * @access private
	 *
	 * @param object $a
	 * @param object $b
	 * @return int
	 */
	private function _sort_slides( $a, $b ) {
		$an = isset( $a->number ) ? (int) $a->number : 0;
		$bn = isset( $b->number ) ? (int) $b->number : 0;
		if ( $an === $bn ) {
			return 0;
		}
		return ( $an > $bn ) ? 1 : -1;
	}

	/**
	 * Remove duplicate legacy slide backups before rebuilding block content.
	 *
	 * @access private
	 *
	 * @param array $slides Legacy slide objects.
	 * @return array Unique slide objects.
	 */
	private function _unique_legacy_slides( $slides ) {
		$unique = array();
		$seen = array();

		foreach ( $slides as $slide ) {
			$key = md5( wp_json_encode( array(
				'number'  => isset( $slide->number ) ? (int) $slide->number : null,
				'title'   => isset( $slide->title ) ? (string) $slide->title : '',
				'class'   => isset( $slide->class ) ? (string) $slide->class : '',
				'content' => isset( $slide->content ) ? (string) $slide->content : '',
			) ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$unique[] = $slide;
		}

		return $unique;
	}

	/**
	 * Build presenter/slide block markup from a legacy slide meta object.
	 *
	 * The slide's HTML content is converted into native core blocks where it is
	 * safe to do so. Markup that does not map cleanly is preserved in a Custom
	 * HTML block.
	 *
	 * @access private
	 *
	 * @param object $slide Legacy slide object.
	 * @return string Serialized block markup.
	 */
	private function _slide_meta_to_block( $slide ) {
		$attributes = array();

		if ( ! empty( $slide->title ) ) {
			$attributes['title'] = (string) $slide->title;
		}

		if ( ! empty( $slide->notes['notes'] ) ) {
			$attributes['speakerNotes'] = (string) $slide->notes['notes'];
		}

		if ( ! empty( $slide->class ) ) {
			$attributes['extraClass'] = trim( (string) $slide->class );
		}

		// Map the legacy "data attributes" UI onto block attributes.
		if ( ! empty( $slide->data ) && is_array( $slide->data ) ) {
			$extra = array();
			foreach ( $slide->data as $data ) {
				if ( empty( $data->name ) ) {
					continue;
				}
				switch ( $data->name ) {
					case 'background':
					case 'background-image':
						$attributes['bgImageUrl'] = (string) $data->value;
						break;
					case 'background-color':
						$attributes['bgColor'] = (string) $data->value;
						break;
					case 'background-video':
						$attributes['bgVideoUrl'] = (string) $data->value;
						break;
					case 'transition':
						$attributes['transition'] = (string) $data->value;
						break;
					case 'auto-animate':
						$attributes['autoAnimate'] = true;
						break;
					default:
						$extra[] = array(
							'name'  => (string) $data->name,
							'value' => isset( $data->value ) ? (string) $data->value : '',
						);
						break;
				}
			}
			if ( ! empty( $extra ) ) {
				$attributes['extraData'] = $extra;
			}
		}

		$content = isset( $slide->content ) ? trim( (string) $slide->content ) : '';
		$content = $this->_legacy_extract_outer_section( $content, $attributes );
		$innerBlocks = $this->_legacy_html_to_inner_blocks( $content );
		$innerContent = empty( $innerBlocks ) ? array() : array( "\n" );

		foreach ( $innerBlocks as $innerBlock ) {
			$innerContent[] = null;
			$innerContent[] = "\n";
		}

		if ( function_exists( 'serialize_block' ) ) {
			return serialize_block( array(
				'blockName'    => 'presenter/slide',
				'attrs'        => $attributes,
				'innerBlocks'  => $innerBlocks,
				'innerHTML'    => '',
				'innerContent' => $innerContent,
			) ) . "\n\n";
		}

		$inner = '';
		if ( '' !== $content ) {
			$inner = "\n<!-- wp:html -->\n" . $content . "\n<!-- /wp:html -->\n";
		}
		$comment_attrs = empty( $attributes ) ? '' : ' ' . wp_json_encode( $attributes );
		return '<!-- wp:presenter/slide' . $comment_attrs . ' -->' . $inner . '<!-- /wp:presenter/slide -->' . "\n\n";
	}

	/**
	 * Pull a legacy top-level slide section into the presenter/slide attributes.
	 *
	 * Older Presenter content often stores the real slide as a `<section>` in
	 * the slide content itself. In the block editor, the presenter/slide block is
	 * the section, so keeping that wrapper inside the slide forces a Custom HTML
	 * block and prevents normal editor styling.
	 *
	 * @access private
	 *
	 * @param string $content Legacy slide HTML.
	 * @param array  $attributes Slide block attributes, passed by reference.
	 * @return string Slide HTML without a single outer section wrapper.
	 */
	private function _legacy_extract_outer_section( $content, &$attributes ) {
		$content = trim( (string) $content );
		if ( '' === $content || ! class_exists( 'DOMDocument' ) ) {
			return $content;
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$encoded_content = $this->_legacy_prepare_html_for_dom( $content );
		$loaded = $document->loadHTML(
			'<!DOCTYPE html><html><body><div id="presenter-fragment">' .
			$encoded_content .
			'</div></body></html>'
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $content;
		}

		$fragment = $document->getElementById( 'presenter-fragment' );
		if ( ! $fragment ) {
			return $content;
		}

		$element_nodes = array();
		foreach ( $fragment->childNodes as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType && '' === trim( $node->textContent ) ) {
				continue;
			}
			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				return $content;
			}
			$element_nodes[] = $node;
		}

		if ( 1 !== count( $element_nodes ) || 'section' !== strtolower( $element_nodes[0]->nodeName ) ) {
			return $content;
		}

		$section = $element_nodes[0];
		$this->_legacy_apply_section_attrs_to_slide( $section, $attributes );

		return $this->_legacy_node_inner_html( $section, $document );
	}

	/**
	 * Map legacy reveal.js section attributes to presenter/slide block attrs.
	 *
	 * @access private
	 *
	 * @param DOMElement $section Legacy section element.
	 * @param array      $attributes Slide block attributes, passed by reference.
	 * @return void
	 */
	private function _legacy_apply_section_attrs_to_slide( $section, &$attributes ) {
		if ( $section->hasAttribute( 'class' ) ) {
			$section_class = trim( $section->getAttribute( 'class' ) );
			if ( '' !== $section_class ) {
				$existing = isset( $attributes['extraClass'] ) ? trim( $attributes['extraClass'] ) : '';
				$attributes['extraClass'] = trim( $existing . ' ' . $section_class );
			}
		}

		if ( $section->hasAttribute( 'id' ) ) {
			$id = trim( $section->getAttribute( 'id' ) );
			$title = isset( $attributes['title'] ) ? trim( $attributes['title'] ) : '';
			if ( '' !== $id && ( '' === $title || preg_match( '/^Slide [0-9]+$/', $title ) ) ) {
				$attributes['title'] = $id;
			}
		}

		if ( ! $section->hasAttributes() ) {
			return;
		}

		foreach ( $section->attributes as $attribute ) {
			$name = strtolower( $attribute->name );
			if ( 0 !== strpos( $name, 'data-' ) ) {
				continue;
			}

			$data_name = substr( $name, 5 );
			$value = (string) $attribute->value;
			switch ( $data_name ) {
				case 'background':
				case 'background-image':
					$attributes['bgImageUrl'] = $value;
					break;
				case 'background-color':
					$attributes['bgColor'] = $value;
					break;
				case 'background-video':
					$attributes['bgVideoUrl'] = $value;
					break;
				case 'transition':
					$attributes['transition'] = $value;
					break;
				case 'auto-animate':
					$attributes['autoAnimate'] = true;
					break;
				default:
					$this->_legacy_add_extra_data_attribute( $attributes, $data_name, $value );
					break;
			}
		}
	}

	/**
	 * Add a slide extraData item while avoiding duplicate legacy attributes.
	 *
	 * @access private
	 *
	 * @param array  $attributes Slide block attributes, passed by reference.
	 * @param string $name Data attribute name without the data- prefix.
	 * @param string $value Data attribute value.
	 * @return void
	 */
	private function _legacy_add_extra_data_attribute( &$attributes, $name, $value ) {
		if ( empty( $attributes['extraData'] ) || ! is_array( $attributes['extraData'] ) ) {
			$attributes['extraData'] = array();
		}

		foreach ( $attributes['extraData'] as $extra ) {
			if ( isset( $extra['name'] ) && $name === $extra['name'] ) {
				return;
			}
		}

		$attributes['extraData'][] = array(
			'name'  => (string) $name,
			'value' => (string) $value,
		);
	}

	/**
	 * Convert legacy slide HTML into native block structures where possible.
	 *
	 * @access private
	 *
	 * @param string $content Legacy slide HTML.
	 * @return array[] Parsed block structures.
	 */
	private function _legacy_html_to_inner_blocks( $content ) {
		$content = trim( (string) $content );
		if ( '' === $content ) {
			return array();
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			return array( $this->_legacy_html_block( $content ) );
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$encoded_content = $this->_legacy_prepare_html_for_dom( $content );
		$loaded = $document->loadHTML(
			'<!DOCTYPE html><html><body><div id="presenter-fragment">' .
			$encoded_content .
			'</div></body></html>'
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return array( $this->_legacy_html_block( $content ) );
		}

		$fragment = $document->getElementById( 'presenter-fragment' );
		if ( ! $fragment ) {
			return array( $this->_legacy_html_block( $content ) );
		}

		$blocks = array();
		$inline_html = '';
		$inline_has_element = false;
		foreach ( $fragment->childNodes as $node ) {
			if ( $this->_legacy_node_is_inline_content( $node ) ) {
				if ( XML_TEXT_NODE === $node->nodeType ) {
					$inline_html .= esc_html( html_entity_decode( $node->textContent, ENT_QUOTES, 'UTF-8' ) );
				} else {
					$inline_html .= $document->saveHTML( $node );
					$inline_has_element = true;
				}
				continue;
			}

			if ( '' !== trim( $inline_html ) ) {
				$blocks[] = $this->_legacy_inline_html_to_block( $inline_html, $inline_has_element );
				$inline_html = '';
				$inline_has_element = false;
			}

			$block = $this->_legacy_node_to_block( $node, $document );
			if ( $block ) {
				$blocks[] = $block;
			}
		}

		if ( '' !== trim( $inline_html ) ) {
			$blocks[] = $this->_legacy_inline_html_to_block( $inline_html, $inline_has_element );
		}

		return empty( $blocks ) ? array( $this->_legacy_html_block( $content ) ) : $blocks;
	}

	private function _legacy_prepare_html_for_dom( $content ) {
		if ( ! function_exists( 'mb_encode_numericentity' ) ) {
			return $content;
		}

		return mb_encode_numericentity( $content, array( 0x80, 0x10ffff, 0, 0xffffff ), 'UTF-8' );
	}

	/**
	 * Convert one DOM node into a block structure.
	 *
	 * @access private
	 *
	 * @param DOMNode      $node Legacy DOM node.
	 * @param DOMDocument  $document Owning document.
	 * @return array|null Parsed block structure.
	 */
	private function _legacy_node_to_block( $node, $document ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$text = trim( html_entity_decode( $node->textContent, ENT_QUOTES, 'UTF-8' ) );
			$visible_text = trim( str_replace( html_entity_decode( '&nbsp;', ENT_QUOTES, 'UTF-8' ), ' ', $text ) );
			if ( '' === $visible_text ) {
				return null;
			}

			if ( '[' === substr( $text, 0, 1 ) && ']' === substr( $text, -1 ) ) {
				return $this->_legacy_shortcode_block( esc_html( $text ) );
			}

			return $this->_legacy_paragraph_block( esc_html( $text ) );
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return null;
		}

		$tag = strtolower( $node->nodeName );
		if ( preg_match( '/^h([1-6])$/', $tag, $matches ) ) {
			return $this->_legacy_heading_block( $node, $document, (int) $matches[1] );
		}

		switch ( $tag ) {
			case 'p':
				return $this->_legacy_paragraph_block( $this->_legacy_node_inner_html( $node, $document ), $node );
			case 'img':
				return $this->_legacy_image_block( $node, $document );
			case 'ul':
			case 'ol':
				return $this->_legacy_list_block( $node, $document, 'ol' === $tag );
			case 'blockquote':
				return $this->_legacy_quote_block( $node, $document );
			case 'pre':
				return $this->_legacy_preformatted_block( $node, $document );
			case 'header':
			case 'section':
			case 'div':
			case 'footer':
				return $this->_legacy_container_to_group_or_html_block( $node, $document );
			default:
				return $this->_legacy_html_block( $document->saveHTML( $node ) );
		}
	}

	private function _legacy_node_is_inline_content( $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return '' !== trim( html_entity_decode( $node->textContent, ENT_QUOTES, 'UTF-8' ) );
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return false;
		}

		return in_array( strtolower( $node->nodeName ), array(
			'a',
			'br',
			'strong',
			'em',
			'del',
			'code',
			'cite',
			'span',
		), true );
	}

	private function _legacy_inline_html_to_block( $html, $has_element ) {
		$html = trim( $html );
		$text = trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );

		if ( ! $has_element && '[' === substr( $text, 0, 1 ) && ']' === substr( $text, -1 ) ) {
			return $this->_legacy_shortcode_block( esc_html( $text ) );
		}

		return $this->_legacy_paragraph_block( $html );
	}

	private function _legacy_container_to_group_or_html_block( $node, $document ) {
		$innerBlocks = array();
		$innerContent = array();
		$inline_html = '';
		$inline_has_element = false;

		foreach ( $node->childNodes as $childNode ) {
			if ( $this->_legacy_node_is_inline_content( $childNode ) ) {
				if ( XML_TEXT_NODE === $childNode->nodeType ) {
					$inline_html .= esc_html( html_entity_decode( $childNode->textContent, ENT_QUOTES, 'UTF-8' ) );
				} else {
					$inline_html .= $document->saveHTML( $childNode );
					$inline_has_element = true;
				}
				continue;
			}

			if ( '' !== trim( $inline_html ) ) {
				$innerBlocks[] = $this->_legacy_inline_html_to_block( $inline_html, $inline_has_element );
				$inline_html = '';
				$inline_has_element = false;
			}

			$block = $this->_legacy_node_to_block( $childNode, $document );
			if ( ! $block ) {
				continue;
			}
			if ( 'core/html' === $block['blockName'] ) {
				return $this->_legacy_html_block( $document->saveHTML( $node ) );
			}
			$innerBlocks[] = $block;
		}

		if ( '' !== trim( $inline_html ) ) {
			$innerBlocks[] = $this->_legacy_inline_html_to_block( $inline_html, $inline_has_element );
		}

		if ( empty( $innerBlocks ) ) {
			return null;
		}

		$innerContent[] = "\n";
		foreach ( $innerBlocks as $innerBlock ) {
			$innerContent[] = null;
			$innerContent[] = "\n";
		}

		return array(
			'blockName'    => 'core/group',
			'attrs'        => $this->_legacy_node_group_attrs( $node ),
			'innerBlocks'  => $innerBlocks,
			'innerHTML'    => '',
			'innerContent' => $innerContent,
		);
	}

	private function _legacy_heading_block( $node, $document, $level ) {
		$attrs = array_merge(
			array(
				'content' => $this->_legacy_node_inner_html( $node, $document ),
				'level'   => $level,
			),
			$this->_legacy_node_block_attrs( $node )
		);

		return array(
			'blockName'    => 'core/heading',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $document->saveHTML( $node ),
			'innerContent' => array( $document->saveHTML( $node ) ),
		);
	}

	private function _legacy_paragraph_block( $content, $node = null ) {
		$attrs = array( 'content' => $content );
		if ( $node ) {
			$attrs = array_merge( $attrs, $this->_legacy_node_block_attrs( $node ) );
		}

		$class = ! empty( $attrs['className'] ) ? ' class="' . esc_attr( $attrs['className'] ) . '"' : '';
		$style = ( $node && $node->hasAttribute( 'style' ) ) ? ' style="' . esc_attr( $node->getAttribute( 'style' ) ) . '"' : '';
		$html = '<p' . $class . $style . '>' . $content . '</p>';

		return array(
			'blockName'    => 'core/paragraph',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	private function _legacy_image_block( $node, $document ) {
		$attrs = $this->_legacy_node_block_attrs( $node );
		if ( $node->hasAttribute( 'src' ) ) {
			$attrs['url'] = $node->getAttribute( 'src' );
		}
		if ( $node->hasAttribute( 'alt' ) ) {
			$attrs['alt'] = $node->getAttribute( 'alt' );
		}
		if ( $node->hasAttribute( 'width' ) ) {
			$attrs['width'] = (int) $node->getAttribute( 'width' );
		}
		if ( $node->hasAttribute( 'height' ) ) {
			$attrs['height'] = (int) $node->getAttribute( 'height' );
		}
		if ( preg_match( '/wp-image-([0-9]+)/', $node->getAttribute( 'class' ), $matches ) ) {
			$attrs['id'] = (int) $matches[1];
		}

		return array(
			'blockName'    => 'core/image',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '<figure class="wp-block-image">' . $document->saveHTML( $node ) . '</figure>',
			'innerContent' => array( '<figure class="wp-block-image">' . $document->saveHTML( $node ) . '</figure>' ),
		);
	}

	private function _legacy_list_block( $node, $document, $ordered ) {
		$attrs = $this->_legacy_node_block_attrs( $node );
		if ( $ordered ) {
			$attrs['ordered'] = true;
		}

		$innerBlocks = array();
		$innerContent = array();
		foreach ( $node->childNodes as $childNode ) {
			if ( XML_ELEMENT_NODE !== $childNode->nodeType || 'li' !== strtolower( $childNode->nodeName ) ) {
				continue;
			}

			$innerBlocks[] = $this->_legacy_list_item_block( $childNode, $document );
		}

		if ( empty( $innerBlocks ) ) {
			return array(
				'blockName'    => 'core/list',
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => $document->saveHTML( $node ),
				'innerContent' => array( $document->saveHTML( $node ) ),
			);
		}

		$innerContent[] = $ordered ? '<ol>' : '<ul>';
		foreach ( $innerBlocks as $innerBlock ) {
			$innerContent[] = null;
		}
		$innerContent[] = $ordered ? '</ol>' : '</ul>';

		return array(
			'blockName'    => 'core/list',
			'attrs'        => $attrs,
			'innerBlocks'  => $innerBlocks,
			'innerHTML'    => '',
			'innerContent' => $innerContent,
		);
	}

	private function _legacy_list_item_block( $node, $document ) {
		$attrs = $this->_legacy_node_block_attrs( $node );
		$content = '';
		$innerBlocks = array();
		$innerContent = array();

		foreach ( $node->childNodes as $childNode ) {
			if (
				XML_ELEMENT_NODE === $childNode->nodeType &&
				in_array( strtolower( $childNode->nodeName ), array( 'ul', 'ol' ), true )
			) {
				$nested_list = $this->_legacy_list_block( $childNode, $document, 'ol' === strtolower( $childNode->nodeName ) );
				$innerBlocks[] = $nested_list;
				continue;
			}

			$content .= $document->saveHTML( $childNode );
		}

		$content = trim( $content );
		$attrs['content'] = $content;

		$opening = '<li' . $this->_legacy_node_html_attrs( $node ) . '>' . $content;
		if ( empty( $innerBlocks ) ) {
			return array(
				'blockName'    => 'core/list-item',
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => $opening . '</li>',
				'innerContent' => array( $opening . '</li>' ),
			);
		}

		$innerContent[] = $opening;
		foreach ( $innerBlocks as $innerBlock ) {
			$innerContent[] = null;
		}
		$innerContent[] = '</li>';

		return array(
			'blockName'    => 'core/list-item',
			'attrs'        => $attrs,
			'innerBlocks'  => $innerBlocks,
			'innerHTML'    => '',
			'innerContent' => $innerContent,
		);
	}

	private function _legacy_quote_block( $node, $document ) {
		$html = $document->saveHTML( $node );
		return array(
			'blockName'    => 'core/quote',
			'attrs'        => $this->_legacy_node_block_attrs( $node ),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	private function _legacy_preformatted_block( $node, $document ) {
		$html = $document->saveHTML( $node );
		return array(
			'blockName'    => 'core/preformatted',
			'attrs'        => $this->_legacy_node_block_attrs( $node ),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	private function _legacy_shortcode_block( $content ) {
		return array(
			'blockName'    => 'core/shortcode',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $content,
			'innerContent' => array( $content ),
		);
	}

	private function _legacy_html_block( $content ) {
		return array(
			'blockName'    => 'core/html',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $content,
			'innerContent' => array( $content ),
		);
	}

	private function _legacy_node_block_attrs( $node ) {
		$attrs = $this->_legacy_node_fragment_attrs( $node );
		$attrs = array_merge( $attrs, $this->_legacy_node_class_attrs( $node ) );
		$style = $this->_legacy_node_style_attr( $node );
		if ( ! empty( $style ) ) {
			$attrs['style'] = $style;
		}

		return $attrs;
	}

	private function _legacy_node_fragment_attrs( $node ) {
		if ( ! $node || ! $node->hasAttribute( 'class' ) ) {
			return array();
		}

		$classes = preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) );
		$classes = array_values( array_filter( $classes, 'strlen' ) );
		if ( ! in_array( 'fragment', $classes, true ) ) {
			return array();
		}

		$fragment_effects = $this->_fragment_effect_classes();
		$effect_classes = array_values( array_intersect( $classes, $fragment_effects ) );
		$remaining_classes = array_values( array_diff( $classes, array_merge( array( 'fragment' ), $fragment_effects ) ) );

		if ( empty( $remaining_classes ) ) {
			$node->removeAttribute( 'class' );
		} else {
			$node->setAttribute( 'class', implode( ' ', $remaining_classes ) );
		}

		$attrs = array(
			'presenterFragment' => true,
		);

		if ( 1 === count( $effect_classes ) ) {
			$attrs['presenterFragmentEffect'] = $effect_classes[0];
		} elseif ( count( $effect_classes ) > 1 ) {
			$attrs['presenterFragmentEffect'] = 'custom';
			$attrs['presenterFragmentCustomEffect'] = implode( ' ', $effect_classes );
		}

		if ( $node->hasAttribute( 'data-fragment-index' ) ) {
			$fragment_index = $node->getAttribute( 'data-fragment-index' );
			if ( is_numeric( $fragment_index ) ) {
				$attrs['presenterFragmentIndex'] = max( 0, (int) $fragment_index );
			}
			$node->removeAttribute( 'data-fragment-index' );
		}

		return $attrs;
	}

	private function _fragment_effect_classes() {
		return array(
			'fade-out',
			'fade-up',
			'fade-down',
			'fade-left',
			'fade-right',
			'fade-in-then-out',
			'current-visible',
			'fade-in-then-semi-out',
			'grow',
			'semi-fade-out',
			'shrink',
			'strike',
			'highlight-red',
			'highlight-green',
			'highlight-blue',
			'highlight-current-red',
			'highlight-current-green',
			'highlight-current-blue',
		);
	}

	private function _legacy_node_class_attrs( $node ) {
		if ( ! $node || ! $node->hasAttribute( 'class' ) ) {
			return array();
		}

		$class = trim( $node->getAttribute( 'class' ) );
		return '' === $class ? array() : array( 'className' => $class );
	}

	private function _legacy_node_style_attr( $node ) {
		if ( ! $node || ! $node->hasAttribute( 'style' ) ) {
			return array();
		}

		return $this->_legacy_css_declarations_to_block_style( $node->getAttribute( 'style' ) );
	}

	private function _legacy_css_declarations_to_block_style( $css ) {
		$style = array();
		$custom_css = array();
		$declarations = explode( ';', (string) $css );

		foreach ( $declarations as $declaration ) {
			if ( false === strpos( $declaration, ':' ) ) {
				continue;
			}

			list( $property, $value ) = array_map( 'trim', explode( ':', $declaration, 2 ) );
			$property = strtolower( $property );
			if ( '' === $property || '' === $value ) {
				continue;
			}

			switch ( $property ) {
				case 'color':
					$style['color']['text'] = $value;
					break;
				case 'background':
				case 'background-color':
					$style['color']['background'] = $value;
					break;
				case 'font-size':
					$style['typography']['fontSize'] = $value;
					break;
				case 'line-height':
					$style['typography']['lineHeight'] = $value;
					break;
				case 'margin':
					$style['spacing']['margin'] = $this->_legacy_css_box_value_to_sides( $value );
					break;
				case 'margin-top':
				case 'margin-right':
				case 'margin-bottom':
				case 'margin-left':
					$side = substr( $property, 7 );
					$style['spacing']['margin'][ $side ] = $value;
					break;
				case 'padding':
					$style['spacing']['padding'] = $this->_legacy_css_box_value_to_sides( $value );
					break;
				case 'padding-top':
				case 'padding-right':
				case 'padding-bottom':
				case 'padding-left':
					$side = substr( $property, 8 );
					$style['spacing']['padding'][ $side ] = $value;
					break;
				default:
					$custom_css[] = $property . ': ' . $value . ';';
					break;
			}
		}

		if ( ! empty( $custom_css ) ) {
			$style['css'] = implode( "\n", $custom_css );
		}

		return $style;
	}

	private function _legacy_css_box_value_to_sides( $value ) {
		$parts = preg_split( '/\s+/', trim( $value ) );
		$parts = array_values( array_filter( $parts, 'strlen' ) );

		if ( empty( $parts ) ) {
			return array();
		}

		if ( 1 === count( $parts ) ) {
			return array(
				'top'    => $parts[0],
				'right'  => $parts[0],
				'bottom' => $parts[0],
				'left'   => $parts[0],
			);
		}

		if ( 2 === count( $parts ) ) {
			return array(
				'top'    => $parts[0],
				'right'  => $parts[1],
				'bottom' => $parts[0],
				'left'   => $parts[1],
			);
		}

		if ( 3 === count( $parts ) ) {
			return array(
				'top'    => $parts[0],
				'right'  => $parts[1],
				'bottom' => $parts[2],
				'left'   => $parts[1],
			);
		}

		return array(
			'top'    => $parts[0],
			'right'  => $parts[1],
			'bottom' => $parts[2],
			'left'   => $parts[3],
		);
	}

	private function _legacy_node_html_attrs( $node ) {
		$attrs = '';
		if ( ! $node || ! $node->hasAttributes() ) {
			return $attrs;
		}

		foreach ( $node->attributes as $attribute ) {
			$name = strtolower( $attribute->name );
			if ( in_array( $name, array( 'class', 'style' ), true ) ) {
				$attrs .= ' ' . $name . '="' . esc_attr( $attribute->value ) . '"';
			}
		}

		return $attrs;
	}

	private function _legacy_node_group_attrs( $node ) {
		$attrs = $this->_legacy_node_block_attrs( $node );

		if ( $node && $node->hasAttribute( 'id' ) ) {
			$anchor = sanitize_title( $node->getAttribute( 'id' ) );
			if ( '' !== $anchor ) {
				$attrs['anchor'] = $anchor;
			}
		}

		return $attrs;
	}

	private function _legacy_node_inner_html( $node, $document ) {
		$html = '';
		foreach ( $node->childNodes as $childNode ) {
			$html .= $document->saveHTML( $childNode );
		}
		return trim( $html );
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
			'template'        => array( array( 'presenter/slide' ) ),
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
		if ( is_singular( 'slideshow' ) && ! post_password_required( get_the_ID() ) ) {
			$this->_maybe_migrate_legacy_slideshow( get_the_ID() );

			$template = plugin_dir_path( __FILE__ ) . 'templates/index.php';

			global $wp_scripts;
			$wp_scripts->add_data( 'html5shiv', 'conditional', 'lt IE 9' );

			/**
			 * Reveal.js plugins as dependencies
			 */
			wp_register_script( 'RevealMarkdown', plugins_url( 'reveal.js/dist/plugin/markdown.js', __FILE__ ), array(), '6.0.0', true );
			wp_register_script( 'RevealSearch', plugins_url( 'reveal.js/dist/plugin/search.js', __FILE__ ), array(), '6.0.0', true );
			wp_register_script( 'RevealNotes', plugins_url( 'reveal.js/dist/plugin/notes.js', __FILE__ ), array(), '6.0.0', true );
			wp_register_script( 'RevealMath', plugins_url( 'reveal.js/dist/plugin/math.js', __FILE__ ), array(), '6.0.0', true );
			wp_register_script( 'RevealZoom', plugins_url( 'reveal.js/dist/plugin/zoom.js', __FILE__ ), array(), '6.0.0', true );
			$reveal_js_dependencies = array( 'RevealMarkdown', 'RevealSearch', 'RevealNotes', 'RevealMath', 'RevealZoom' );
			$reveal_css_dependencies = array();

			// Only load highlight.js if SyntaxHighlighter isn't active
			global $SyntaxHighlighter;
			if ( ! is_a( $SyntaxHighlighter, 'SyntaxHighlighter' ) ) {
				wp_register_style( 'RevealHighlightStyle', plugins_url( 'reveal.js/dist/plugin/highlight/monokai.css', __FILE__ ), array(), '6.0.0' );
				wp_register_script( 'RevealHighlight', plugins_url( 'reveal.js/dist/plugin/highlight.js', __FILE__ ), array(), '6.0.0', true );
				$reveal_js_dependencies[] = 'RevealHighlight';
				$reveal_css_dependencies[] = 'RevealHighlightStyle';
			}
			$reveal_js_dependencies = apply_filters( 'presenter-reveal-js-dependencies', $reveal_js_dependencies );
			$reveal_css_dependencies = apply_filters( 'presenter-reveal-css-dependencies', $reveal_css_dependencies );
			wp_register_script( 'reveal', plugins_url( 'reveal.js/dist/reveal.js', __FILE__ ), $reveal_js_dependencies, '6.0.0', true );

			wp_register_style( 'presenter', plugins_url( 'css/presenter.css', __FILE__ ) );
			wp_register_style( 'reveal', plugins_url( 'reveal.js/dist/reveal.css', __FILE__ ), $reveal_css_dependencies, '6.0.0' );
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
			$this->_maybe_migrate_legacy_slideshow( get_the_ID() );

			wp_register_style(
				'presenter-editor',
				plugins_url( 'css/edit-slide-admin.css', __FILE__ ),
				array( 'dashicons' ),
				filemtime( plugin_dir_path( __FILE__ ) . 'css/edit-slide-admin.css' )
			);
			wp_enqueue_style(
				'presenter-editor-reveal',
				plugins_url( 'reveal.js/dist/reveal.css', __FILE__ ),
				array(),
				'6.0.0'
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
