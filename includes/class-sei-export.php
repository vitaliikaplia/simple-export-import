<?php
/**
 * Export logic: data collection, JSON download, post row actions.
 *
 * @package Simple_Export_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEI_Export {

	public static function init() {
		add_action( 'admin_action_sei_export_post', array( __CLASS__, 'handle_export' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'add_export_link' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'add_export_link' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'register_custom_post_type_row_actions' ) );
	}

	/**
	 * Collect everything we need to recreate a post on another site.
	 *
	 * @return array|null
	 */
	public static function collect_post_data( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return null;
		}

		$post_data = array(
			'ID'             => $post->ID,
			'post_title'     => $post->post_title,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_status'    => $post->post_status,
			'post_name'      => $post->post_name,
			'post_type'      => $post->post_type,
			'post_author'    => $post->post_author,
			'post_date'      => $post->post_date,
			'post_date_gmt'  => $post->post_date_gmt,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'menu_order'     => (int) $post->menu_order,
			'meta_fields'    => array(),
			'taxonomies'     => array(),
			'featured_image' => null,
			'attachments'    => array(),
		);

		$meta_fields    = get_post_meta( $post_id );
		$skip_meta_keys = sei_get_skip_meta_keys();
		foreach ( $meta_fields as $meta_key => $meta_value ) {
			if ( in_array( $meta_key, $skip_meta_keys, true ) ) {
				continue;
			}
			$post_data['meta_fields'][ $meta_key ] = maybe_unserialize( $meta_value[0] );
		}

		$taxonomies = get_object_taxonomies( $post->post_type );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $post_id, $taxonomy );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$post_data['taxonomies'][ $taxonomy ] = array();
				foreach ( $terms as $term ) {
					$post_data['taxonomies'][ $taxonomy ][] = array(
						'term_id' => $term->term_id,
						'name'    => $term->name,
						'slug'    => $term->slug,
					);
				}
			}
		}

		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id ) {
			$post_data['featured_image'] = array(
				'id'   => $thumbnail_id,
				'url'  => wp_get_attachment_url( $thumbnail_id ),
				'file' => get_attached_file( $thumbnail_id ),
			);
		}

		$attachments = get_attached_media( '', $post_id );
		foreach ( $attachments as $attachment ) {
			$post_data['attachments'][] = array(
				'id'    => $attachment->ID,
				'title' => $attachment->post_title,
				'url'   => wp_get_attachment_url( $attachment->ID ),
				'file'  => get_attached_file( $attachment->ID ),
			);
		}

		// Embedded files (base64) — opt-in via setting. Each post carries its
		// own list; the importer deduplicates across main + translations
		// using the source attachment ID as the key.
		$post_data['embedded_attachments'] = array();
		if ( SEI_Media::embed_enabled() ) {
			$ids = SEI_Media::collect_attachment_ids( $post_data );
			foreach ( $ids as $att_id ) {
				$embedded = SEI_Media::embed_attachment( $att_id );
				if ( $embedded ) {
					$post_data['embedded_attachments'][] = $embedded;
				}
			}
		}

		return $post_data;
	}

	/**
	 * admin-post.php handler: stream the JSON file as a download.
	 */
	public static function handle_export() {
		if ( ! isset( $_GET['post'] ) ) {
			wp_die( esc_html__( 'No post to export has been supplied!', 'simple-export-import' ) );
		}

		$post_id = absint( $_GET['post'] );

		if ( ! isset( $_GET['export_nonce'] ) || ! wp_verify_nonce( $_GET['export_nonce'], 'sei_export_' . $post_id ) ) {
			wp_die( esc_html__( 'Security check failed', 'simple-export-import' ) );
		}

		$export_capability = get_option( 'sei_export_capability', 'edit_posts' );
		if ( ! current_user_can( $export_capability ) ) {
			wp_die( esc_html__( 'You do not have permission to export posts', 'simple-export-import' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_die( esc_html__( 'Post not found', 'simple-export-import' ) );
		}

		$post_data = self::collect_post_data( $post_id );
		if ( ! $post_data ) {
			wp_die( esc_html__( 'Failed to collect post data', 'simple-export-import' ) );
		}

		$defaults            = sei_get_default_settings();
		$export_translations = get_option( 'sei_export_wpml_translations', $defaults['export_wpml_translations'] );

		// Field name kept as wpml_enabled for back-compat with existing JSON exports —
		// it just signals "this file carries translation metadata".
		$post_data['wpml_enabled']      = false;
		$post_data['original_language'] = null;
		$post_data['translations']      = array();

		if ( $export_translations && sei_is_multilingual_active() ) {
			$element_type = 'post_' . $post->post_type;

			$lang_details = sei_normalize_lang_details( apply_filters( 'wpml_element_language_details', null, array(
				'element_id'   => $post_id,
				'element_type' => $element_type,
			) ) );

			if ( $lang_details ) {
				$post_data['wpml_enabled']      = true;
				$post_data['original_language'] = $lang_details->language_code ?? null;

				$translations = SEI_Multilingual::get_post_translations( $post_id, $post->post_type );

				foreach ( $translations as $lang_code => $translation ) {
					if ( (int) $translation->element_id === (int) $post_id ) {
						continue;
					}

					$translation_data = self::collect_post_data( $translation->element_id );
					if ( $translation_data ) {
						$translation_data['language_code'] = $lang_code;
						$post_data['translations'][]       = $translation_data;
					}
				}
			}
		}

		$flags = JSON_UNESCAPED_UNICODE;
		if ( get_option( 'sei_pretty_json', $defaults['pretty_json'] ) ) {
			$flags |= JSON_PRETTY_PRINT;
		}
		$json_data = wp_json_encode( $post_data, $flags );

		$filename = sanitize_file_name( $post->post_title ) . '-export.json';

		header( 'Content-Description: File Transfer' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ), true );
		header( 'Content-Length: ' . strlen( $json_data ) );

		echo $json_data;
		exit;
	}

	/**
	 * Inject the Export row action on supported post types.
	 */
	public static function add_export_link( $actions, $post ) {
		// Attachments ride along inside posts (featured_image / embedded
		// media); never expose Export on the Media library row even if
		// the option still contains 'attachment' from an older save.
		if ( get_post_type( $post ) === 'attachment' ) {
			return $actions;
		}

		$defaults          = sei_get_default_settings();
		$allowed_types     = get_option( 'sei_post_types', $defaults['post_types'] );
		$export_capability = get_option( 'sei_export_capability', $defaults['export_capability'] );

		if ( current_user_can( $export_capability ) && in_array( get_post_type( $post ), (array) $allowed_types, true ) ) {
			$nonce_url = wp_nonce_url(
				admin_url( 'admin.php?action=sei_export_post&post=' . $post->ID ),
				'sei_export_' . $post->ID,
				'export_nonce'
			);

			$actions['export'] = '<a href="' . esc_url( $nonce_url ) . '" title="' . esc_attr__( 'Export this post', 'simple-export-import' ) . '">' . esc_html__( 'Export', 'simple-export-import' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * post_row_actions / page_row_actions cover post + page; CPTs need their
	 * own per-type filter ({post_type}_row_actions).
	 */
	public static function register_custom_post_type_row_actions() {
		$defaults      = sei_get_default_settings();
		$allowed_types = get_option( 'sei_post_types', $defaults['post_types'] );

		foreach ( (array) $allowed_types as $post_type ) {
			if ( in_array( $post_type, array( 'post', 'page', 'attachment' ), true ) ) {
				continue;
			}
			add_filter( $post_type . '_row_actions', array( __CLASS__, 'add_export_link' ), 10, 2 );
		}
	}
}
