<?php
/**
 * Plugin Name: Simple Export & Import
 * Version: 1.0
 * Description: A simple plugin to export and import WordPress posts with full data preservation.
 * Author: Vitalii Kaplia
 * Author URI: https://vitaliikaplia.com/
 * License: GPLv2 or later
 * Text Domain: simple-export-import
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Constants
define( 'SEI_VERSION', '1.0' );
define( 'SEI_PREFIX', 'sei_' );
define( 'SEI_TEXT_DOMAIN', 'simple-export-import' );

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Get default settings
 */
function sei_get_default_settings() {
	return array(
		'post_types'         => array( 'post', 'page' ),
		'export_capability'  => 'edit_posts',
		'import_capability'  => 'edit_posts',
		'import_status'      => 'draft',
	);
}

/**
 * Get list of available capabilities
 */
function sei_get_capabilities_list() {
	return array(
		'read'              => __( 'Read', SEI_TEXT_DOMAIN ),
		'edit_posts'        => __( 'Edit Posts', SEI_TEXT_DOMAIN ),
		'edit_pages'        => __( 'Edit Pages', SEI_TEXT_DOMAIN ),
		'edit_published_posts' => __( 'Edit Published Posts', SEI_TEXT_DOMAIN ),
		'publish_posts'     => __( 'Publish Posts', SEI_TEXT_DOMAIN ),
		'manage_options'    => __( 'Manage Options', SEI_TEXT_DOMAIN ),
	);
}

/**
 * Validate JSON file
 */
function sei_validate_json_file( $file ) {
	$errors = array();

	// Check if file was uploaded
	if ( empty( $file['tmp_name'] ) ) {
		$errors[] = __( 'No file uploaded', SEI_TEXT_DOMAIN );
		return $errors;
	}

	// Check file size (max 5MB)
	if ( $file['size'] > 5 * 1024 * 1024 ) {
		$errors[] = __( 'File size exceeds 5MB limit', SEI_TEXT_DOMAIN );
	}

	// Check file extension
	$allowed_extensions = array( 'json' );
	$file_extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
	if ( ! in_array( $file_extension, $allowed_extensions ) ) {
		$errors[] = __( 'Only JSON files are allowed', SEI_TEXT_DOMAIN );
	}

	// Primary check: try to read the file as JSON (most reliable)
	$file_content = file_get_contents( $file['tmp_name'] );
	if ( $file_content === false ) {
		$errors[] = __( 'Unable to read file', SEI_TEXT_DOMAIN );
	} else {
		json_decode( $file_content );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$errors[] = sprintf(
				__( 'File does not contain valid JSON: %s', SEI_TEXT_DOMAIN ),
				json_last_error_msg()
			);
		}
	}

	return $errors;
}

// ============================================================================
// SETTINGS SECTION
// ============================================================================

/**
 * Register settings
 */
function sei_register_settings() {
	register_setting( 'sei_settings_group', 'sei_post_types' );
	register_setting( 'sei_settings_group', 'sei_export_capability' );
	register_setting( 'sei_settings_group', 'sei_import_capability' );
	register_setting( 'sei_settings_group', 'sei_import_status' );
}
add_action( 'admin_init', 'sei_register_settings' );

/**
 * Add settings page to menu
 */
function sei_add_settings_page() {
	add_options_page(
		__( 'Export & Import Settings', SEI_TEXT_DOMAIN ),
		__( 'Export & Import', SEI_TEXT_DOMAIN ),
		'manage_options',
		'sei-settings',
		'sei_settings_page'
	);
}
add_action( 'admin_menu', 'sei_add_settings_page' );

/**
 * Render settings page
 */
function sei_settings_page() {
	$defaults = sei_get_default_settings();
	$post_types_saved = get_option( 'sei_post_types', $defaults['post_types'] );
	$export_capability = get_option( 'sei_export_capability', $defaults['export_capability'] );
	$import_capability = get_option( 'sei_import_capability', $defaults['import_capability'] );
	$import_status = get_option( 'sei_import_status', $defaults['import_status'] );

	// Get all public post types
	$post_types = get_post_types( array( 'public' => true ), 'objects' );

	// Get capabilities list
	$capabilities = sei_get_capabilities_list();

	// Post statuses
	$post_statuses = array(
		'draft'   => __( 'Draft', SEI_TEXT_DOMAIN ),
		'publish' => __( 'Published', SEI_TEXT_DOMAIN ),
		'pending' => __( 'Pending Review', SEI_TEXT_DOMAIN ),
		'private' => __( 'Private', SEI_TEXT_DOMAIN ),
	);

	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Export & Import Settings', SEI_TEXT_DOMAIN ); ?></h1>

		<form method="post" action="options.php">
			<?php settings_fields( 'sei_settings_group' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<?php echo esc_html__( 'Post Types', SEI_TEXT_DOMAIN ); ?>
					</th>
					<td>
						<fieldset>
							<?php foreach ( $post_types as $post_type ) : ?>
								<label>
									<input type="checkbox"
										   name="sei_post_types[]"
										   value="<?php echo esc_attr( $post_type->name ); ?>"
										   <?php checked( in_array( $post_type->name, (array) $post_types_saved ) ); ?>>
									<?php echo esc_html( $post_type->labels->name ); ?>
								</label><br>
							<?php endforeach; ?>
							<p class="description">
								<?php echo esc_html__( 'Select post types that will have export functionality', SEI_TEXT_DOMAIN ); ?>
							</p>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="sei_export_capability">
							<?php echo esc_html__( 'Export Capability', SEI_TEXT_DOMAIN ); ?>
						</label>
					</th>
					<td>
						<select name="sei_export_capability" id="sei_export_capability">
							<?php foreach ( $capabilities as $cap_key => $cap_label ) : ?>
								<option value="<?php echo esc_attr( $cap_key ); ?>"
										<?php selected( $export_capability, $cap_key ); ?>>
									<?php echo esc_html( $cap_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php echo esc_html__( 'Minimum capability required to export posts', SEI_TEXT_DOMAIN ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="sei_import_capability">
							<?php echo esc_html__( 'Import Capability', SEI_TEXT_DOMAIN ); ?>
						</label>
					</th>
					<td>
						<select name="sei_import_capability" id="sei_import_capability">
							<?php foreach ( $capabilities as $cap_key => $cap_label ) : ?>
								<option value="<?php echo esc_attr( $cap_key ); ?>"
										<?php selected( $import_capability, $cap_key ); ?>>
									<?php echo esc_html( $cap_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php echo esc_html__( 'Minimum capability required to import posts', SEI_TEXT_DOMAIN ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="sei_import_status">
							<?php echo esc_html__( 'Import Status', SEI_TEXT_DOMAIN ); ?>
						</label>
					</th>
					<td>
						<select name="sei_import_status" id="sei_import_status">
							<?php foreach ( $post_statuses as $status_key => $status_label ) : ?>
								<option value="<?php echo esc_attr( $status_key ); ?>"
										<?php selected( $import_status, $status_key ); ?>>
									<?php echo esc_html( $status_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php echo esc_html__( 'Default status for imported posts', SEI_TEXT_DOMAIN ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

// ============================================================================
// EXPORT SECTION
// ============================================================================

/**
 * Export post as JSON
 */
function sei_export_post_as_json() {
	// Check if post ID is provided
	if ( ! ( isset( $_GET['post'] ) || isset( $_POST['post'] ) || ( isset( $_REQUEST['action'] ) && 'sei_export_post' == $_REQUEST['action'] ) ) ) {
		wp_die( esc_html__( 'No post to export has been supplied!', SEI_TEXT_DOMAIN ) );
	}

	// Verify nonce
	if ( ! isset( $_GET['export_nonce'] ) || ! wp_verify_nonce( $_GET['export_nonce'], 'sei_export_' . absint( $_GET['post'] ) ) ) {
		wp_die( esc_html__( 'Security check failed', SEI_TEXT_DOMAIN ) );
	}

	// Check capability
	$export_capability = get_option( 'sei_export_capability', 'edit_posts' );
	if ( ! current_user_can( $export_capability ) ) {
		wp_die( esc_html__( 'You do not have permission to export posts', SEI_TEXT_DOMAIN ) );
	}

	$post_id = absint( $_GET['post'] );
	$post = get_post( $post_id );

	if ( ! $post ) {
		wp_die( esc_html__( 'Post not found', SEI_TEXT_DOMAIN ) );
	}

	// Collect basic post data
	$post_data = array(
		'ID'           => $post->ID,
		'post_title'   => $post->post_title,
		'post_content' => $post->post_content,
		'post_excerpt' => $post->post_excerpt,
		'post_status'  => $post->post_status,
		'post_name'    => $post->post_name,
		'post_type'    => $post->post_type,
		'post_author'  => $post->post_author,
		'post_date'    => $post->post_date,
		'meta_fields'  => array(),
		'taxonomies'   => array(),
		'featured_image' => null,
		'attachments'  => array(),
	);

	// Collect meta fields
	$meta_fields = get_post_meta( $post_id );
	foreach ( $meta_fields as $meta_key => $meta_value ) {
		// Skip internal WordPress meta keys
		if ( substr( $meta_key, 0, 1 ) === '_' && $meta_key !== '_thumbnail_id' ) {
			continue;
		}
		$post_data['meta_fields'][$meta_key] = maybe_unserialize( $meta_value[0] );
	}

	// Collect taxonomies
	$taxonomies = get_object_taxonomies( $post->post_type );
	foreach ( $taxonomies as $taxonomy ) {
		$terms = wp_get_post_terms( $post_id, $taxonomy );
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			$post_data['taxonomies'][$taxonomy] = array();
			foreach ( $terms as $term ) {
				$post_data['taxonomies'][$taxonomy][] = array(
					'term_id' => $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
				);
			}
		}
	}

	// Collect featured image
	$thumbnail_id = get_post_thumbnail_id( $post_id );
	if ( $thumbnail_id ) {
		$post_data['featured_image'] = array(
			'id'   => $thumbnail_id,
			'url'  => wp_get_attachment_url( $thumbnail_id ),
			'file' => get_attached_file( $thumbnail_id ),
		);
	}

	// Collect attachments
	$attachments = get_attached_media( '', $post_id );
	foreach ( $attachments as $attachment ) {
		$post_data['attachments'][] = array(
			'id'    => $attachment->ID,
			'title' => $attachment->post_title,
			'url'   => wp_get_attachment_url( $attachment->ID ),
			'file'  => get_attached_file( $attachment->ID ),
		);
	}

	// Convert to JSON
	$json_data = wp_json_encode( $post_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

	// Sanitize filename
	$filename = sanitize_file_name( $post->post_title ) . '-export.json';

	// Send headers
	header( 'Content-Description: File Transfer' );
	header( 'Content-Disposition: attachment; filename=' . $filename );
	header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ), true );
	header( 'Content-Length: ' . strlen( $json_data ) );

	echo $json_data;
	exit;
}
add_action( 'admin_action_sei_export_post', 'sei_export_post_as_json' );

/**
 * Add export link to post row actions
 */
function sei_add_export_link( $actions, $post ) {
	// Get allowed post types from settings
	$defaults = sei_get_default_settings();
	$allowed_types = get_option( 'sei_post_types', $defaults['post_types'] );

	// Get export capability from settings
	$export_capability = get_option( 'sei_export_capability', 'edit_posts' );

	// Check if current post type is in allowed types and user has capability
	if ( current_user_can( $export_capability ) && in_array( get_post_type( $post ), (array) $allowed_types ) ) {
		$nonce_url = wp_nonce_url(
			admin_url( 'admin.php?action=sei_export_post&post=' . $post->ID ),
			'sei_export_' . $post->ID,
			'export_nonce'
		);

		$actions['export'] = '<a href="' . esc_url( $nonce_url ) . '" title="' . esc_attr__( 'Export this post', SEI_TEXT_DOMAIN ) . '">' . esc_html__( 'Export', SEI_TEXT_DOMAIN ) . '</a>';
	}

	return $actions;
}
add_filter( 'post_row_actions', 'sei_add_export_link', 10, 2 );
add_filter( 'page_row_actions', 'sei_add_export_link', 10, 2 );

/**
 * Dynamically add export links for custom post types
 */
function sei_add_export_for_custom_post_types() {
	$defaults = sei_get_default_settings();
	$allowed_types = get_option( 'sei_post_types', $defaults['post_types'] );

	foreach ( (array) $allowed_types as $post_type ) {
		// Skip built-in types as they're already handled
		if ( in_array( $post_type, array( 'post', 'page' ) ) ) {
			continue;
		}

		// Add filter for custom post type
		add_filter( $post_type . '_row_actions', 'sei_add_export_link', 10, 2 );
	}
}
add_action( 'admin_init', 'sei_add_export_for_custom_post_types' );

/**
 * Add settings link on plugins page
 */
function sei_add_settings_link( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=sei-settings' ) ) . '">' . esc_html__( 'Settings', SEI_TEXT_DOMAIN ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'sei_add_settings_link' );

// ============================================================================
// IMPORT SECTION
// ============================================================================

/**
 * Register import page
 */
function sei_register_import_page() {
	$import_capability = get_option( 'sei_import_capability', 'edit_posts' );

	add_submenu_page(
		'tools.php',
		__( 'Import Post from JSON', SEI_TEXT_DOMAIN ),
		__( 'Import Post', SEI_TEXT_DOMAIN ),
		$import_capability,
		'sei-import-post',
		'sei_import_page'
	);
}
add_action( 'admin_menu', 'sei_register_import_page' );

/**
 * Render import page
 */
function sei_import_page() {
	$import_capability = get_option( 'sei_import_capability', 'edit_posts' );

	// Double-check capability
	if ( ! current_user_can( $import_capability ) ) {
		wp_die( esc_html__( 'You do not have permission to import posts', SEI_TEXT_DOMAIN ) );
	}

	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Import Post from JSON', SEI_TEXT_DOMAIN ); ?></h1>

		<?php
		// Handle import
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_FILES['json_file']['tmp_name'] ) ) {
			// Verify nonce
			if ( ! isset( $_POST['sei_import_nonce'] ) || ! wp_verify_nonce( $_POST['sei_import_nonce'], 'sei_import_action' ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Security check failed', SEI_TEXT_DOMAIN ) . '</p></div>';
			} else {
				$result = sei_handle_import();
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
							<?php echo esc_html__( 'JSON File', SEI_TEXT_DOMAIN ); ?>
						</label>
					</th>
					<td>
						<input type="file" name="json_file" id="json_file" accept=".json" required>
						<p class="description">
							<?php echo esc_html__( 'Select a JSON file exported from this plugin (max 5MB)', SEI_TEXT_DOMAIN ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Import Post', SEI_TEXT_DOMAIN ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * Handle post import
 */
function sei_handle_import() {
	// Validate file
	$validation_errors = sei_validate_json_file( $_FILES['json_file'] );
	if ( ! empty( $validation_errors ) ) {
		return new WP_Error( 'validation_failed', implode( '<br>', $validation_errors ) );
	}

	// Read and parse JSON
	$file = $_FILES['json_file']['tmp_name'];
	$json_data = file_get_contents( $file );
	$post_data = json_decode( $json_data, true );

	if ( json_last_error() !== JSON_ERROR_NONE ) {
		return new WP_Error( 'invalid_json', __( 'Invalid JSON file', SEI_TEXT_DOMAIN ) );
	}

	// Check if required fields exist
	if ( empty( $post_data['post_title'] ) || empty( $post_data['post_type'] ) ) {
		return new WP_Error( 'missing_fields', __( 'Required fields missing in JSON file', SEI_TEXT_DOMAIN ) );
	}

	// Get import status from settings
	$defaults = sei_get_default_settings();
	$import_status = get_option( 'sei_import_status', $defaults['import_status'] );

	// Prepare post data for import
	$new_post_data = array(
		'post_title'    => sanitize_text_field( $post_data['post_title'] ) . ' - imported',
		'post_content'  => wp_kses_post( $post_data['post_content'] ),
		'post_excerpt'  => wp_kses_post( $post_data['post_excerpt'] ),
		'post_status'   => $import_status,
		'post_type'     => sanitize_key( $post_data['post_type'] ),
		'post_author'   => get_current_user_id(),
	);

	// Insert post
	$new_post_id = wp_insert_post( $new_post_data );

	if ( is_wp_error( $new_post_id ) ) {
		return $new_post_id;
	}

	// Restore meta fields
	if ( ! empty( $post_data['meta_fields'] ) && is_array( $post_data['meta_fields'] ) ) {
		foreach ( $post_data['meta_fields'] as $meta_key => $meta_value ) {
			// Skip thumbnail meta as we'll handle it separately
			if ( $meta_key === '_thumbnail_id' ) {
				continue;
			}
			update_post_meta( $new_post_id, sanitize_key( $meta_key ), $meta_value );
		}
	}

	// Restore taxonomies
	if ( ! empty( $post_data['taxonomies'] ) && is_array( $post_data['taxonomies'] ) ) {
		foreach ( $post_data['taxonomies'] as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$term_ids = array();
			foreach ( $terms as $term_data ) {
				// Check if term exists by ID
				if ( term_exists( (int) $term_data['term_id'], $taxonomy ) ) {
					$term_ids[] = (int) $term_data['term_id'];
				} elseif ( ! empty( $term_data['slug'] ) && term_exists( $term_data['slug'], $taxonomy ) ) {
					// Try to find by slug
					$existing_term = get_term_by( 'slug', $term_data['slug'], $taxonomy );
					if ( $existing_term ) {
						$term_ids[] = $existing_term->term_id;
					}
				}
			}

			if ( ! empty( $term_ids ) ) {
				wp_set_post_terms( $new_post_id, $term_ids, $taxonomy );
			}
		}
	}

	// Restore featured image
	if ( ! empty( $post_data['featured_image']['id'] ) ) {
		$thumbnail_id = (int) $post_data['featured_image']['id'];
		// Check if attachment exists
		if ( get_post( $thumbnail_id ) && get_post_type( $thumbnail_id ) === 'attachment' ) {
			set_post_thumbnail( $new_post_id, $thumbnail_id );
		}
	}

	// Restore attachments (associate existing attachments with new post)
	if ( ! empty( $post_data['attachments'] ) && is_array( $post_data['attachments'] ) ) {
		foreach ( $post_data['attachments'] as $attachment_data ) {
			$attachment_id = (int) $attachment_data['id'];
			// Check if attachment exists
			if ( get_post( $attachment_id ) && get_post_type( $attachment_id ) === 'attachment' ) {
				// Update attachment's post parent
				wp_update_post( array(
					'ID'          => $attachment_id,
					'post_parent' => $new_post_id,
				) );
			}
		}
	}

	// Return success message
	$edit_link = esc_url( admin_url( 'post.php?action=edit&post=' . $new_post_id ) );
	return sprintf(
		__( 'Post "<a href="%s">%s</a>" was successfully imported!', SEI_TEXT_DOMAIN ),
		$edit_link,
		esc_html( $new_post_data['post_title'] )
	);
}
