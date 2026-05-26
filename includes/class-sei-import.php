<?php
/**
 * Import logic: Tools page, JSON parsing, post insertion.
 *
 * @package Simple_Export_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEI_Import {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_import_page' ) );
	}

	public static function register_import_page() {
		$import_capability = get_option( 'sei_import_capability', 'edit_posts' );

		add_submenu_page(
			'tools.php',
			__( 'Import Post from JSON', 'simple-export-import' ),
			__( 'Import Post', 'simple-export-import' ),
			$import_capability,
			'sei-import-post',
			array( __CLASS__, 'render_import_page' )
		);
	}

	public static function render_import_page() {
		$import_capability = get_option( 'sei_import_capability', 'edit_posts' );

		if ( ! current_user_can( $import_capability ) ) {
			wp_die( esc_html__( 'You do not have permission to import posts', 'simple-export-import' ) );
		}

		$max_mb = sei_get_effective_upload_limit_mb();

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Import Post from JSON', 'simple-export-import' ); ?></h1>

			<?php
			if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_FILES['json_file']['tmp_name'] ) ) {
				if ( ! isset( $_POST['sei_import_nonce'] ) || ! wp_verify_nonce( $_POST['sei_import_nonce'], 'sei_import_action' ) ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Security check failed', 'simple-export-import' ) . '</p></div>';
				} else {
					$result = self::handle_import();
					if ( is_wp_error( $result ) ) {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
					} else {
						echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $result ) . '</p></div>';
					}
				}
			}
			?>

			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'sei_import_action', 'sei_import_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="json_file">
								<?php echo esc_html__( 'JSON File', 'simple-export-import' ); ?>
							</label>
						</th>
						<td>
							<input type="file" name="json_file" id="json_file" accept=".json" required>
							<p class="description">
								<?php
								echo esc_html( sprintf(
									/* translators: %d: maximum file size in megabytes */
									__( 'Select a JSON file exported from this plugin (max %d MB)', 'simple-export-import' ),
									$max_mb
								) );
								?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Import Post', 'simple-export-import' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Insert a single post from decoded JSON data using $wpdb directly.
	 *
	 * Bypasses wp_insert_post() / update_post_meta() / wp_set_post_terms() /
	 * set_post_thumbnail() / wp_update_post() so that hooks registered by
	 * themes or other plugins (save_post, transition_post_status, kses
	 * filters, added_post_meta, set_object_terms, etc.) cannot:
	 *   - mutate the imported content (e.g. block-attribute rewriters)
	 *   - reject the insert via validation hooks
	 *   - recurse (e.g. a save_post handler that triggers more inserts)
	 *   - hit external APIs and time out
	 *
	 * Because we don't go through wp_insert_post/update_post_meta, we do NOT
	 * call wp_slash() — those functions wp_unslash internally; $wpdb does not.
	 * Slashing here would write literal backslashes to the DB.
	 *
	 * @param array $post_data
	 * @param string $import_status
	 * @param array<int,int>        $id_map  Optional. old_attachment_id => new_attachment_id.
	 * @param array<string,string>  $url_map Optional. old_url => new_url.
	 * @return int|WP_Error New post ID or WP_Error on failure.
	 */
	public static function import_single_post( $post_data, $import_status, array $id_map = array(), array $url_map = array() ) {
		global $wpdb;

		// Apply attachment-ID and URL remapping BEFORE inserting, so the row
		// we write has the correct references in post_content, meta, etc.
		if ( $id_map || $url_map ) {
			$post_data = SEI_Media::remap_post_data( $post_data, $id_map, $url_map );
		}

		$post_type = sanitize_key( $post_data['post_type'] ?? '' );
		if ( ! $post_type || ! post_type_exists( $post_type ) ) {
			return new WP_Error( 'invalid_post_type', __( 'Post type does not exist on this site', 'simple-export-import' ) );
		}

		$defaults        = sei_get_default_settings();
		$title_suffix    = (string) get_option( 'sei_import_title_suffix', $defaults['import_title_suffix'] );
		$preserve_author = (bool) get_option( 'sei_preserve_original_author', $defaults['preserve_original_author'] );

		$author_id = get_current_user_id();
		if ( $preserve_author && ! empty( $post_data['post_author'] ) ) {
			$orig_author_id = (int) $post_data['post_author'];
			if ( $orig_author_id > 0 && get_user_by( 'id', $orig_author_id ) ) {
				$author_id = $orig_author_id;
			}
		}

		$post_title = sanitize_text_field( $post_data['post_title'] ) . $title_suffix;
		$post_date  = ! empty( $post_data['post_date'] ) ? $post_data['post_date'] : current_time( 'mysql' );
		$post_date_gmt = ! empty( $post_data['post_date_gmt'] )
			? $post_data['post_date_gmt']
			: get_gmt_from_date( $post_date );
		$now_local  = current_time( 'mysql' );
		$now_gmt    = current_time( 'mysql', true );

		$base_slug = ! empty( $post_data['post_name'] )
			? sanitize_title( $post_data['post_name'] )
			: sanitize_title( $post_title );
		$post_name = self::generate_unique_slug( $base_slug );

		$row = array(
			'post_author'           => $author_id,
			'post_date'             => $post_date,
			'post_date_gmt'         => $post_date_gmt,
			'post_content'          => (string) ( $post_data['post_content'] ?? '' ),
			'post_title'            => $post_title,
			'post_excerpt'          => (string) ( $post_data['post_excerpt'] ?? '' ),
			'post_status'           => $import_status,
			'comment_status'        => ! empty( $post_data['comment_status'] ) ? sanitize_key( $post_data['comment_status'] ) : 'closed',
			'ping_status'           => ! empty( $post_data['ping_status'] ) ? sanitize_key( $post_data['ping_status'] ) : 'closed',
			'post_password'         => '',
			'post_name'             => $post_name,
			'to_ping'               => '',
			'pinged'                => '',
			'post_modified'         => $now_local,
			'post_modified_gmt'     => $now_gmt,
			'post_content_filtered' => '',
			'post_parent'           => 0,
			'guid'                  => '',
			'menu_order'            => (int) ( $post_data['menu_order'] ?? 0 ),
			'post_type'             => $post_type,
			'post_mime_type'        => '',
			'comment_count'         => 0,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d' );

		$inserted = $wpdb->insert( $wpdb->posts, $row, $formats );

		if ( $inserted === false ) {
			return new WP_Error(
				'db_insert_failed',
				/* translators: %s: database error message */
				sprintf( __( 'Database insert failed: %s', 'simple-export-import' ), $wpdb->last_error )
			);
		}

		$new_post_id = (int) $wpdb->insert_id;

		// GUID is conventionally home_url() . '/?p=ID'.
		$wpdb->update(
			$wpdb->posts,
			array( 'guid' => home_url( '/?p=' . $new_post_id ) ),
			array( 'ID' => $new_post_id ),
			array( '%s' ),
			array( '%d' )
		);

		self::restore_meta( $new_post_id, $post_data );
		self::restore_taxonomies( $new_post_id, $post_data );
		self::restore_featured_image( $new_post_id, $post_data );
		self::reparent_attachments( $new_post_id, $post_data );

		// SEO plugins like Yoast keep per-post caches in custom tables
		// (wp_yoast_indexable) that are normally rebuilt by save_post —
		// which we deliberately bypass. Trigger their rebuild explicitly,
		// scoped to this single post, so the imported _yoast_wpseo_* meta
		// actually surfaces on the frontend.
		self::rebuild_seo_indexables( $new_post_id );

		// Bust the post cache so subsequent reads in this request see the new
		// post. We accept the 'clean_post_cache' action firing — it is a
		// read-side hook and not expected to mutate content or recurse.
		clean_post_cache( $new_post_id );

		return $new_post_id;
	}

	/**
	 * Decode and create every embedded_attachments entry across the main post
	 * and every translation. Returns [ id_map, url_map ] used to rewrite
	 * references in post_content / meta / featured_image / attachments.
	 *
	 * Deduplication by source attachment ID means the same image referenced
	 * by EN, UK, DE language posts is uploaded once and all three posts end
	 * up pointing at the same new attachment.
	 *
	 * @return array{0:array<int,int>,1:array<string,string>}
	 */
	private static function import_embedded_attachments( array $post_data ) {
		$id_map  = array();
		$url_map = array();

		$pools = array();
		if ( ! empty( $post_data['embedded_attachments'] ) && is_array( $post_data['embedded_attachments'] ) ) {
			$pools[] = $post_data['embedded_attachments'];
		}
		if ( ! empty( $post_data['translations'] ) && is_array( $post_data['translations'] ) ) {
			foreach ( $post_data['translations'] as $translation ) {
				if ( ! empty( $translation['embedded_attachments'] ) && is_array( $translation['embedded_attachments'] ) ) {
					$pools[] = $translation['embedded_attachments'];
				}
			}
		}

		foreach ( $pools as $pool ) {
			foreach ( $pool as $embedded ) {
				$old_id = (int) ( $embedded['id'] ?? 0 );
				if ( ! $old_id || isset( $id_map[ $old_id ] ) ) {
					continue;
				}

				$result = SEI_Media::import_attachment( $embedded );
				if ( is_wp_error( $result ) || ! is_array( $result ) ) {
					continue;
				}

				// Merge ALL language siblings into the global map. Each
				// language post will then remap to the attachment for its
				// language (e.g. UK post's wp:image id=101 → new UK sibling).
				foreach ( $result as $src_id => $new_id ) {
					$id_map[ (int) $src_id ] = (int) $new_id;
				}

				// All language siblings share one file → one canonical URL.
				if ( ! empty( $embedded['url'] ) && isset( $result[ $old_id ] ) ) {
					$new_url = wp_get_attachment_url( $result[ $old_id ] );
					if ( $new_url ) {
						$url_map[ (string) $embedded['url'] ] = $new_url;
					}
				}
			}
		}

		return array( $id_map, $url_map );
	}

	/**
	 * Ask known SEO plugins to rebuild any cached representation of the post.
	 * Each block is fully isolated in try/catch — if a third-party container
	 * throws, the import has already succeeded; we don't want to lose it.
	 */
	private static function rebuild_seo_indexables( $post_id ) {
		// Yoast SEO 14+: wp_yoast_indexable row is built from postmeta.
		if ( function_exists( 'YoastSEO' ) ) {
			try {
				$container     = YoastSEO();
				$builder_class = '\Yoast\WP\SEO\Builders\Indexable_Builder';
				if ( is_object( $container ) && isset( $container->classes ) && class_exists( $builder_class ) ) {
					$builder = $container->classes->get( $builder_class );
					if ( $builder && method_exists( $builder, 'build_for_id_and_type' ) ) {
						$builder->build_for_id_and_type( (int) $post_id, 'post' );
					}
				}
			} catch ( \Throwable $e ) {
				// Indexable will rebuild on next post edit or via Yoast's
				// nightly cron — postmeta is already correct in the DB.
			}
		}

		// Rank Math: rebuilds its internal_links / object_id caches on save_post.
		// Trigger via the dedicated action it exposes for external imports.
		if ( did_action( 'rank_math/loaded' ) ) {
			try {
				do_action( 'rank_math/links/clear_cache_for_post', (int) $post_id );
			} catch ( \Throwable $e ) {
			}
		}
	}

	/**
	 * Generate a unique post_name (slug). Replicates wp_unique_post_slug
	 * without firing its hooks — just a direct lookup against the posts table.
	 */
	private static function generate_unique_slug( $base_slug ) {
		global $wpdb;

		if ( $base_slug === '' ) {
			$base_slug = 'imported-' . wp_generate_password( 6, false, false );
		}

		$slug  = $base_slug;
		$tries = 0;
		while ( $tries < 100 ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s LIMIT 1",
				$slug
			) );
			if ( ! $exists ) {
				return $slug;
			}
			$tries++;
			$slug = $base_slug . '-' . $tries;
		}

		return $base_slug . '-' . wp_generate_password( 6, false, false );
	}

	/**
	 * Direct postmeta inserts — bypasses added_post_meta / update_post_metadata.
	 * Uses maybe_serialize manually (which update_post_meta normally does).
	 */
	private static function restore_meta( $new_post_id, $post_data ) {
		global $wpdb;

		if ( empty( $post_data['meta_fields'] ) || ! is_array( $post_data['meta_fields'] ) ) {
			return;
		}

		$skip_meta_keys = sei_get_skip_meta_keys();
		foreach ( $post_data['meta_fields'] as $meta_key => $meta_value ) {
			if ( in_array( $meta_key, $skip_meta_keys, true ) ) {
				continue;
			}
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $new_post_id,
					'meta_key'   => sanitize_key( $meta_key ),
					'meta_value' => maybe_serialize( $meta_value ),
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Direct term_relationships insert + manual term count bump — bypasses
	 * set_object_terms / edited_term_taxonomy hooks.
	 */
	private static function restore_taxonomies( $new_post_id, $post_data ) {
		global $wpdb;

		if ( empty( $post_data['taxonomies'] ) || ! is_array( $post_data['taxonomies'] ) ) {
			return;
		}

		foreach ( $post_data['taxonomies'] as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			foreach ( $terms as $term_data ) {
				$term_id = 0;

				if ( ! empty( $term_data['term_id'] ) && term_exists( (int) $term_data['term_id'], $taxonomy ) ) {
					$term_id = (int) $term_data['term_id'];
				} elseif ( ! empty( $term_data['slug'] ) ) {
					$existing_term = get_term_by( 'slug', $term_data['slug'], $taxonomy );
					if ( $existing_term ) {
						$term_id = (int) $existing_term->term_id;
					}
				}

				if ( ! $term_id ) {
					continue;
				}

				$tt_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s LIMIT 1",
					$term_id,
					$taxonomy
				) );
				if ( ! $tt_id ) {
					continue;
				}

				// INSERT IGNORE so a duplicate relationship doesn't fail the import.
				$wpdb->query( $wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) VALUES (%d, %d, 0)",
					$new_post_id,
					$tt_id
				) );

				if ( $wpdb->rows_affected ) {
					$wpdb->query( $wpdb->prepare(
						"UPDATE {$wpdb->term_taxonomy} SET count = count + 1 WHERE term_taxonomy_id = %d",
						$tt_id
					) );
				}
			}
		}
	}

	/**
	 * Set featured image via direct postmeta insert (no set_post_thumbnail hook chain).
	 */
	private static function restore_featured_image( $new_post_id, $post_data ) {
		global $wpdb;

		if ( empty( $post_data['featured_image']['id'] ) ) {
			return;
		}

		$thumbnail_id = (int) $post_data['featured_image']['id'];
		$attachment_type = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_type FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
			$thumbnail_id
		) );

		if ( $attachment_type !== 'attachment' ) {
			return;
		}

		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $new_post_id,
				'meta_key'   => '_thumbnail_id',
				'meta_value' => (string) $thumbnail_id,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Re-parent existing attachments to the new post via direct UPDATE,
	 * skipping wp_update_post's full hook chain.
	 */
	private static function reparent_attachments( $new_post_id, $post_data ) {
		global $wpdb;

		if ( empty( $post_data['attachments'] ) || ! is_array( $post_data['attachments'] ) ) {
			return;
		}

		foreach ( $post_data['attachments'] as $attachment_data ) {
			$attachment_id = (int) ( $attachment_data['id'] ?? 0 );
			if ( ! $attachment_id ) {
				continue;
			}

			$attachment_type = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_type FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
				$attachment_id
			) );

			if ( $attachment_type !== 'attachment' ) {
				continue;
			}

			$wpdb->update(
				$wpdb->posts,
				array( 'post_parent' => $new_post_id ),
				array( 'ID' => $attachment_id ),
				array( '%d' ),
				array( '%d' )
			);
			clean_post_cache( $attachment_id );
		}
	}

	/**
	 * Form handler: validate, parse, import, return success/WP_Error.
	 *
	 * @return string|WP_Error HTML success message or WP_Error.
	 */
	public static function handle_import() {
		$validation_errors = sei_validate_json_file( $_FILES['json_file'] );
		if ( ! empty( $validation_errors ) ) {
			return new WP_Error( 'validation_failed', implode( '<br>', $validation_errors ) );
		}

		$json_data = file_get_contents( $_FILES['json_file']['tmp_name'] );
		$post_data = json_decode( $json_data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', __( 'Invalid JSON file', 'simple-export-import' ) );
		}

		if ( empty( $post_data['post_title'] ) || empty( $post_data['post_type'] ) ) {
			return new WP_Error( 'missing_fields', __( 'Required fields missing in JSON file', 'simple-export-import' ) );
		}

		$defaults      = sei_get_default_settings();
		$import_status = get_option( 'sei_import_status', $defaults['import_status'] );
		$title_suffix  = (string) get_option( 'sei_import_title_suffix', $defaults['import_title_suffix'] );

		// Build a single global old->new attachment ID + URL map by importing
		// every embedded attachment carried in the main post and every
		// translation, deduplicating by source ID. Multilingual sites often
		// reference the same images across language posts; we want to upload
		// each file exactly once.
		list( $id_map, $url_map ) = self::import_embedded_attachments( $post_data );

		$new_post_id = self::import_single_post( $post_data, $import_status, $id_map, $url_map );

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		$edit_url       = esc_url( admin_url( 'post.php?action=edit&post=' . $new_post_id ) );
		$imported_title = esc_html( sanitize_text_field( $post_data['post_title'] ) . $title_suffix );

		// "wpml_enabled" name kept for back-compat with existing JSON exports —
		// it just means "this file carries translation metadata".
		$has_translation_data = ! empty( $post_data['wpml_enabled'] );

		if ( $has_translation_data && ! empty( $post_data['translations'] ) ) {
			$multilingual_active = sei_is_multilingual_active();
			$original_lang       = ! empty( $post_data['original_language'] ) ? $post_data['original_language'] : 'en';
			$translations_map    = array();

			foreach ( $post_data['translations'] as $translation_data ) {
				$lang_code = ! empty( $translation_data['language_code'] ) ? $translation_data['language_code'] : '';

				if ( empty( $lang_code ) ) {
					continue;
				}

				$translation_post_id = self::import_single_post( $translation_data, $import_status, $id_map, $url_map );

				if ( ! is_wp_error( $translation_post_id ) ) {
					$translations_map[ $lang_code ] = $translation_post_id;
				}
			}

			if ( $multilingual_active && ! empty( $translations_map ) ) {
				SEI_Multilingual::connect_translations( $new_post_id, $original_lang, $translations_map );

				return sprintf(
					/* translators: 1: edit URL, 2: post title, 3: translations count */
					__( 'Post "<a href="%1$s">%2$s</a>" and %3$d translation(s) were successfully imported and connected!', 'simple-export-import' ),
					$edit_url,
					$imported_title,
					count( $translations_map )
				);
			}

			return sprintf(
				/* translators: 1: edit URL, 2: post title, 3: translations count */
				__( 'Post "<a href="%1$s">%2$s</a>" and %3$d translation(s) were imported as separate posts. <strong>Note:</strong> no multilingual plugin (WPML / WP-LOC) is active, so translations were not connected.', 'simple-export-import' ),
				$edit_url,
				$imported_title,
				count( $translations_map )
			);
		}

		return sprintf(
			/* translators: 1: edit URL, 2: post title */
			__( 'Post "<a href="%1$s">%2$s</a>" was successfully imported!', 'simple-export-import' ),
			$edit_url,
			$imported_title
		);
	}
}
