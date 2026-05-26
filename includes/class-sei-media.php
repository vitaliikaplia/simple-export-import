<?php
/**
 * Media embedding (base64) and ID/URL remapping across posts, content, meta.
 *
 * @package Simple_Export_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEI_Media {

	/**
	 * Block attribute names that conventionally hold attachment IDs.
	 * Walker still remaps any integer matching the id_map even outside this
	 * list — this is just used for defensive sanity, not the source of truth.
	 */
	private const KNOWN_ID_ATTRS = array( 'id', 'mediaId', 'imageId', 'videoId', 'audioId', 'fileId', 'ids', 'mediaIds' );

	/* ----------------------------------------------------------------------
	 * Settings accessors
	 * -------------------------------------------------------------------- */

	public static function embed_enabled() {
		$defaults = sei_get_default_settings();
		return (bool) get_option( 'sei_embed_media', $defaults['embed_media'] );
	}

	public static function max_embedded_file_kb() {
		$defaults = sei_get_default_settings();
		$kb       = (int) get_option( 'sei_max_embedded_file_kb', $defaults['max_embedded_file_kb'] );
		return max( 1, $kb );
	}

	/* ----------------------------------------------------------------------
	 * EXPORT SIDE
	 * -------------------------------------------------------------------- */

	/**
	 * Find every attachment ID this post references — featured image, attached
	 * media, block attribute IDs (core/image, core/gallery, ACF blocks…),
	 * legacy markup (wp-image-N, [gallery ids=""], data-id), and meta values
	 * (recursive — covers ACF Image/Gallery/File and Repeater/Group/Flex).
	 *
	 * @return int[] Unique attachment IDs.
	 */
	public static function collect_attachment_ids( array $post_data ) {
		$ids = array();

		if ( ! empty( $post_data['featured_image']['id'] ) ) {
			$ids[] = (int) $post_data['featured_image']['id'];
		}

		if ( ! empty( $post_data['attachments'] ) && is_array( $post_data['attachments'] ) ) {
			foreach ( $post_data['attachments'] as $att ) {
				if ( ! empty( $att['id'] ) ) {
					$ids[] = (int) $att['id'];
				}
			}
		}

		if ( ! empty( $post_data['post_content'] ) ) {
			$ids = array_merge( $ids, self::scan_content_for_ids( (string) $post_data['post_content'] ) );
		}

		if ( ! empty( $post_data['meta_fields'] ) && is_array( $post_data['meta_fields'] ) ) {
			$ids = array_merge( $ids, self::scan_meta_for_ids( $post_data['meta_fields'] ) );
		}

		$ids = array_filter( array_map( 'intval', $ids ), function ( $id ) {
			return $id > 0 && get_post_type( $id ) === 'attachment';
		} );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Extract attachment IDs from a piece of post_content. Walks parsed
	 * blocks first (handles core/image, core/gallery, ACF blocks with
	 * nested data — innerBlocks are walked too), then falls back to regex
	 * for non-block markup (wp-image-N class, data-id="N", gallery shortcode).
	 *
	 * @return int[]
	 */
	private static function scan_content_for_ids( $content ) {
		$ids = array();

		if ( $content === '' ) {
			return $ids;
		}

		if ( function_exists( 'parse_blocks' ) && has_blocks( $content ) ) {
			$blocks = parse_blocks( $content );
			self::walk_blocks_collect( $blocks, $ids );
		}

		// Legacy <img class="wp-image-N">
		if ( preg_match_all( '/wp-image-(\d+)/i', $content, $m ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $m[1] ) );
		}

		// data-id="N" / data-attachment-id="N"
		if ( preg_match_all( '/data-(?:attachment-)?id=["\'](\d+)["\']/i', $content, $m ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $m[1] ) );
		}

		// [gallery ids="1,2,3"] shortcode
		if ( preg_match_all( '/\[gallery[^\]]*\bids=["\']([\d,\s]+)["\']/i', $content, $m ) ) {
			foreach ( $m[1] as $csv ) {
				foreach ( explode( ',', $csv ) as $id ) {
					$id = (int) trim( $id );
					if ( $id ) {
						$ids[] = $id;
					}
				}
			}
		}

		return $ids;
	}

	/**
	 * Recurse into parsed blocks gathering any integer attrs and any
	 * innerBlocks. Innocent (non-attachment) ints get filtered later by
	 * the attachment-post-type check in collect_attachment_ids().
	 */
	private static function walk_blocks_collect( $blocks, array &$ids ) {
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				self::walk_value_collect( $block['attrs'], $ids );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk_blocks_collect( $block['innerBlocks'], $ids );
			}
		}
	}

	private static function walk_value_collect( $value, array &$ids ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				self::walk_value_collect( $v, $ids );
			}
			return;
		}
		if ( is_int( $value ) && $value > 0 ) {
			$ids[] = $value;
			return;
		}
		if ( is_string( $value ) && ctype_digit( $value ) ) {
			$ids[] = (int) $value;
		}
	}

	private static function scan_meta_for_ids( array $meta ) {
		$ids = array();
		foreach ( $meta as $value ) {
			self::walk_value_collect( $value, $ids );
		}
		return $ids;
	}

	/**
	 * Build the embeddable payload for one attachment. Returns null if the
	 * file is missing, unreadable, or larger than the configured limit.
	 *
	 * Also captures sibling per-language attachment rows (WPML / WP-LOC
	 * media translation model: one physical file, multiple attachment
	 * posts with different post_title/excerpt/content/alt, all sharing
	 * _wp_attached_file). On import we recreate that structure.
	 */
	public static function embed_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || get_post_type( $attachment_id ) !== 'attachment' ) {
			return null;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_readable( $file ) ) {
			return null;
		}

		$size_bytes = (int) filesize( $file );
		$max_bytes  = self::max_embedded_file_kb() * 1024;
		if ( $size_bytes > $max_bytes ) {
			return null;
		}

		$contents = file_get_contents( $file );
		if ( $contents === false ) {
			return null;
		}

		$attachment_post = get_post( $attachment_id );

		$record = array(
			'id'            => $attachment_id,
			'filename'      => wp_basename( $file ),
			'mime_type'     => (string) get_post_mime_type( $attachment_id ),
			'url'           => (string) wp_get_attachment_url( $attachment_id ),
			'file'          => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
			'title'         => (string) $attachment_post->post_title,
			'alt'           => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'caption'       => (string) $attachment_post->post_excerpt,
			'description'   => (string) $attachment_post->post_content,
			'base64'        => base64_encode( $contents ),
			'size_kb'       => (int) ceil( $size_bytes / 1024 ),
			'language_code' => null,
			'translations'  => array(),
		);

		// WPML/WP-LOC: capture sibling-language attachment rows. Each carries
		// its own title/alt/caption/description but shares the file on disk.
		if ( sei_is_multilingual_active() ) {
			$lang_details = sei_normalize_lang_details( apply_filters( 'wpml_element_language_details', null, array(
				'element_id'   => $attachment_id,
				'element_type' => 'post_attachment',
			) ) );

			if ( $lang_details && ! empty( $lang_details->trid ) ) {
				$record['language_code'] = $lang_details->language_code ?? null;

				$translations = apply_filters( 'wpml_get_element_translations', null, $lang_details->trid, 'post_attachment' );

				if ( is_array( $translations ) ) {
					foreach ( $translations as $lang_code => $translation ) {
						if ( (int) $translation->element_id === $attachment_id ) {
							continue;
						}

						$trans_post = get_post( (int) $translation->element_id );
						if ( ! $trans_post ) {
							continue;
						}

						$record['translations'][] = array(
							'id'            => (int) $translation->element_id,
							'language_code' => (string) $lang_code,
							'title'         => (string) $trans_post->post_title,
							'alt'           => (string) get_post_meta( $translation->element_id, '_wp_attachment_image_alt', true ),
							'caption'       => (string) $trans_post->post_excerpt,
							'description'   => (string) $trans_post->post_content,
						);
					}
				}
			}
		}

		return $record;
	}

	/* ----------------------------------------------------------------------
	 * IMPORT SIDE
	 * -------------------------------------------------------------------- */

	/**
	 * Decode the embedded file, write it under uploads/, insert the main
	 * attachment row directly via $wpdb (bypassing add_attachment / save_post
	 * hooks), then for every translation in the payload insert a sibling
	 * attachment row pointing at the SAME file (no re-upload, no re-resize)
	 * with that language's title/alt/caption/description. Finally connect
	 * the group via the WPML hook surface so wp_yoast_indexable, language
	 * switchers, and admin UIs see them as proper translations.
	 *
	 * Image-sizes generation uses wp_generate_attachment_metadata() — that
	 * fires media-processing filters (Smush, Imagify) but not content-
	 * mutating ones, which matches the spirit of the import.
	 *
	 * @return array<int,int>|WP_Error Map: source_attachment_id => new_attachment_id
	 *                                  (one entry for the main, plus one per translation).
	 *                                  WP_Error on hard failure.
	 */
	public static function import_attachment( array $attachment_data ) {
		global $wpdb;

		if ( empty( $attachment_data['base64'] ) || empty( $attachment_data['filename'] ) ) {
			return new WP_Error( 'sei_media_missing', __( 'Embedded attachment is missing required fields', 'simple-export-import' ) );
		}

		$bytes = base64_decode( $attachment_data['base64'], true );
		if ( $bytes === false ) {
			return new WP_Error( 'sei_media_decode', __( 'Could not decode embedded attachment', 'simple-export-import' ) );
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'sei_media_upload_dir', (string) $upload_dir['error'] );
		}

		$filename  = sanitize_file_name( (string) $attachment_data['filename'] );
		$dest_name = wp_unique_filename( $upload_dir['path'], $filename );
		$dest_path = trailingslashit( $upload_dir['path'] ) . $dest_name;

		if ( file_put_contents( $dest_path, $bytes ) === false ) {
			return new WP_Error( 'sei_media_write', __( 'Failed to write embedded attachment to uploads', 'simple-export-import' ) );
		}

		$mime_type = ! empty( $attachment_data['mime_type'] )
			? sanitize_mime_type( $attachment_data['mime_type'] )
			: ( wp_check_filetype( $dest_path )['type'] ?? 'application/octet-stream' );

		$relative_path = _wp_relative_upload_path( $dest_path );
		$guid          = trailingslashit( $upload_dir['url'] ) . $dest_name;

		// Generate image sizes ONCE for the file; all language siblings reuse them.
		$generated_metadata = null;
		if ( strpos( $mime_type, 'image/' ) === 0 ) {
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$generated_metadata = wp_generate_attachment_metadata( 0, $dest_path );
		}

		// Insert main attachment row.
		$new_main_id = self::insert_attachment_row(
			$attachment_data,
			$mime_type,
			$dest_name,
			$guid,
			$relative_path,
			$generated_metadata,
			null // no language suffix in slug
		);

		if ( is_wp_error( $new_main_id ) ) {
			@unlink( $dest_path );
			return $new_main_id;
		}

		$id_map = array();
		$source_main_id = (int) ( $attachment_data['id'] ?? 0 );
		if ( $source_main_id ) {
			$id_map[ $source_main_id ] = $new_main_id;
		}

		// Sibling rows for each translation — same file, translated metadata.
		$new_translation_ids = array(); // [lang_code => new_id]
		if ( ! empty( $attachment_data['translations'] ) && is_array( $attachment_data['translations'] ) ) {
			foreach ( $attachment_data['translations'] as $trans ) {
				$lang_code = (string) ( $trans['language_code'] ?? '' );
				if ( $lang_code === '' ) {
					continue;
				}

				$sibling_id = self::insert_attachment_row(
					$trans,
					$mime_type,
					$dest_name,
					$guid,
					$relative_path,
					$generated_metadata,
					$lang_code
				);

				if ( is_wp_error( $sibling_id ) ) {
					continue;
				}

				$source_trans_id = (int) ( $trans['id'] ?? 0 );
				if ( $source_trans_id ) {
					$id_map[ $source_trans_id ] = $sibling_id;
				}
				$new_translation_ids[ $lang_code ] = $sibling_id;
			}

			// Connect the language group via WPML hook surface (works on
			// real WPML, WP-LOC, or any compatible plugin).
			$main_lang = (string) ( $attachment_data['language_code'] ?? '' );
			if ( $main_lang !== '' && $new_translation_ids && sei_is_multilingual_active() ) {
				$existing_trid = apply_filters( 'wpml_element_trid', null, $new_main_id, 'post_attachment' );

				do_action( 'wpml_set_element_language_details', array(
					'element_id'           => $new_main_id,
					'element_type'         => 'post_attachment',
					'trid'                 => $existing_trid ?: null,
					'language_code'        => $main_lang,
					'source_language_code' => null,
				) );

				$trid = apply_filters( 'wpml_element_trid', null, $new_main_id, 'post_attachment' );

				foreach ( $new_translation_ids as $lang_code => $sibling_id ) {
					do_action( 'wpml_set_element_language_details', array(
						'element_id'           => $sibling_id,
						'element_type'         => 'post_attachment',
						'trid'                 => $trid ?: null,
						'language_code'        => $lang_code,
						'source_language_code' => $main_lang,
					) );
				}
			}
		}

		return $id_map;
	}

	/**
	 * Internal: insert one attachment row + its meta. Shared between the
	 * main attachment and its per-language siblings. Siblings get the
	 * SAME _wp_attached_file and _wp_attachment_metadata (one file on
	 * disk, image sizes computed once) but each row has its own title,
	 * excerpt, content and alt.
	 *
	 * @return int|WP_Error
	 */
	private static function insert_attachment_row( array $data, $mime_type, $dest_name, $guid, $relative_path, $generated_metadata, $language_suffix ) {
		global $wpdb;

		$now_local = current_time( 'mysql' );
		$now_gmt   = current_time( 'mysql', true );

		$title = ! empty( $data['title'] )
			? sanitize_text_field( $data['title'] )
			: pathinfo( $dest_name, PATHINFO_FILENAME );

		$base_slug = sanitize_title( pathinfo( $dest_name, PATHINFO_FILENAME ) );
		$slug      = $language_suffix
			? $base_slug . '-' . sanitize_key( $language_suffix )
			: $base_slug;

		$row = array(
			'post_author'           => get_current_user_id(),
			'post_date'             => $now_local,
			'post_date_gmt'         => $now_gmt,
			'post_content'          => (string) ( $data['description'] ?? '' ),
			'post_title'            => $title,
			'post_excerpt'          => (string) ( $data['caption'] ?? '' ),
			'post_status'           => 'inherit',
			'comment_status'        => 'closed',
			'ping_status'           => 'closed',
			'post_password'         => '',
			'post_name'             => $slug,
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => $now_local,
			'post_modified_gmt'     => $now_gmt,
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => $guid,
			'menu_order'            => 0,
			'post_type'             => 'attachment',
			'post_mime_type'        => $mime_type,
			'comment_count'         => 0,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d' );

		if ( $wpdb->insert( $wpdb->posts, $row, $formats ) === false ) {
			return new WP_Error( 'sei_media_db', $wpdb->last_error );
		}

		$new_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->postmeta,
			array( 'post_id' => $new_id, 'meta_key' => '_wp_attached_file', 'meta_value' => $relative_path ),
			array( '%d', '%s', '%s' )
		);

		if ( ! empty( $data['alt'] ) ) {
			$wpdb->insert(
				$wpdb->postmeta,
				array( 'post_id' => $new_id, 'meta_key' => '_wp_attachment_image_alt', 'meta_value' => sanitize_text_field( $data['alt'] ) ),
				array( '%d', '%s', '%s' )
			);
		}

		if ( $generated_metadata ) {
			$wpdb->insert(
				$wpdb->postmeta,
				array( 'post_id' => $new_id, 'meta_key' => '_wp_attachment_metadata', 'meta_value' => maybe_serialize( $generated_metadata ) ),
				array( '%d', '%s', '%s' )
			);
		}

		clean_post_cache( $new_id );

		return $new_id;
	}

	/* ----------------------------------------------------------------------
	 * REMAPPING (old_id -> new_id, old_url -> new_url)
	 * -------------------------------------------------------------------- */

	/**
	 * Apply id_map and url_map to a single post_data structure in-place:
	 *  - featured_image.id, attachments[].id
	 *  - post_content (block attrs + legacy markup + URLs)
	 *  - meta_fields recursively (integer matches + URL replacements in strings)
	 *
	 * @param array          $post_data
	 * @param array<int,int> $id_map  Old attachment ID => new attachment ID.
	 * @param array<string,string> $url_map  Old URL => new URL.
	 */
	public static function remap_post_data( array $post_data, array $id_map, array $url_map ) {
		if ( ! empty( $post_data['featured_image']['id'] ) ) {
			$old = (int) $post_data['featured_image']['id'];
			if ( isset( $id_map[ $old ] ) ) {
				$post_data['featured_image']['id'] = $id_map[ $old ];
				if ( ! empty( $url_map[ $post_data['featured_image']['url'] ?? '' ] ) ) {
					$post_data['featured_image']['url'] = $url_map[ $post_data['featured_image']['url'] ];
				}
			}
		}

		if ( ! empty( $post_data['attachments'] ) && is_array( $post_data['attachments'] ) ) {
			foreach ( $post_data['attachments'] as &$att ) {
				if ( ! empty( $att['id'] ) && isset( $id_map[ (int) $att['id'] ] ) ) {
					$att['id'] = $id_map[ (int) $att['id'] ];
				}
			}
			unset( $att );
		}

		if ( isset( $post_data['post_content'] ) ) {
			$post_data['post_content'] = self::remap_content( (string) $post_data['post_content'], $id_map, $url_map );
		}

		if ( ! empty( $post_data['meta_fields'] ) && is_array( $post_data['meta_fields'] ) ) {
			foreach ( $post_data['meta_fields'] as $key => $value ) {
				$post_data['meta_fields'][ $key ] = self::remap_value( $value, $id_map, $url_map );
			}
		}

		return $post_data;
	}

	/**
	 * Remap IDs and URLs in post_content. Block content goes through
	 * parse_blocks/serialize_blocks (so ACF block JSON inside the comment
	 * is re-encoded properly with escapes); legacy markup is patched via
	 * regex. URL replacement is applied last.
	 */
	public static function remap_content( $content, array $id_map, array $url_map ) {
		if ( $content === '' ) {
			return $content;
		}

		if ( function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) && has_blocks( $content ) ) {
			$blocks  = parse_blocks( $content );
			$blocks  = self::walk_blocks_remap( $blocks, $id_map );
			$content = serialize_blocks( $blocks );
		}

		// Legacy wp-image-N classes.
		if ( $id_map ) {
			$content = preg_replace_callback( '/wp-image-(\d+)/', function ( $m ) use ( $id_map ) {
				$old = (int) $m[1];
				return isset( $id_map[ $old ] ) ? 'wp-image-' . $id_map[ $old ] : $m[0];
			}, $content );

			$content = preg_replace_callback( '/(data-(?:attachment-)?id=["\'])(\d+)(["\'])/i', function ( $m ) use ( $id_map ) {
				$old = (int) $m[2];
				return isset( $id_map[ $old ] ) ? $m[1] . $id_map[ $old ] . $m[3] : $m[0];
			}, $content );

			// [gallery ids="1,2,3"] — remap each ID in the CSV.
			$content = preg_replace_callback( '/(\[gallery[^\]]*\bids=["\'])([\d,\s]+)(["\'])/i', function ( $m ) use ( $id_map ) {
				$parts = array_map( function ( $id ) use ( $id_map ) {
					$id = (int) trim( $id );
					return isset( $id_map[ $id ] ) ? (string) $id_map[ $id ] : (string) $id;
				}, explode( ',', $m[2] ) );
				return $m[1] . implode( ',', $parts ) . $m[3];
			}, $content );
		}

		// URL replacement (do this AFTER block reserialization so escapes
		// stay correct). Longest URLs first so /foo/bar.jpg-150x150 isn't
		// partly matched by /foo/bar.jpg.
		if ( $url_map ) {
			uksort( $url_map, function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			} );
			$content = strtr( $content, $url_map );
		}

		return $content;
	}

	/**
	 * Walk parsed blocks, remap attrs (recursively for ACF block data),
	 * and recurse into innerBlocks. attrs walker treats any int or
	 * numeric-string matching id_map as remappable, mirroring the
	 * collector's heuristic.
	 */
	private static function walk_blocks_remap( $blocks, array $id_map ) {
		foreach ( $blocks as &$block ) {
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$block['attrs'] = self::remap_value( $block['attrs'], $id_map, array() );
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::walk_blocks_remap( $block['innerBlocks'], $id_map );
			}
		}
		unset( $block );
		return $blocks;
	}

	/**
	 * Recursive remap for a single value (meta value, block attr).
	 * Numeric / numeric-string values get the id_map applied; strings get
	 * the url_map applied; arrays recurse.
	 */
	public static function remap_value( $value, array $id_map, array $url_map ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::remap_value( $v, $id_map, $url_map );
			}
			return $value;
		}
		if ( is_int( $value ) && isset( $id_map[ $value ] ) ) {
			return (int) $id_map[ $value ];
		}
		if ( is_string( $value ) ) {
			if ( ctype_digit( $value ) && isset( $id_map[ (int) $value ] ) ) {
				return (string) $id_map[ (int) $value ];
			}
			if ( $url_map ) {
				uksort( $url_map, function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				} );
				return strtr( $value, $url_map );
			}
		}
		return $value;
	}
}
