<?php
/**
 * Settings page: registration, rendering, plugins-row link.
 *
 * @package Simple_Export_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEI_Settings {

	const OPTION_GROUP = 'sei_settings_group';
	const PAGE_SLUG    = 'sei-settings';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_filter( 'plugin_action_links_' . SEI_PLUGIN_BASENAME, array( __CLASS__, 'add_settings_link' ) );
	}

	public static function register_settings() {
		$registry = array(
			'sei_post_types'                 => array( 'type' => 'array',   'sanitize' => array( __CLASS__, 'sanitize_post_types' ) ),
			'sei_export_capability'          => array( 'type' => 'string',  'sanitize' => 'sanitize_key' ),
			'sei_import_capability'          => array( 'type' => 'string',  'sanitize' => 'sanitize_key' ),
			'sei_import_status'              => array( 'type' => 'string',  'sanitize' => 'sanitize_key' ),
			'sei_export_wpml_translations'   => array( 'type' => 'boolean', 'sanitize' => array( __CLASS__, 'sanitize_bool' ) ),
			'sei_import_title_suffix'        => array( 'type' => 'string',  'sanitize' => 'sanitize_text_field' ),
			'sei_preserve_original_author'   => array( 'type' => 'boolean', 'sanitize' => array( __CLASS__, 'sanitize_bool' ) ),
			'sei_extra_skip_meta_keys'       => array( 'type' => 'string',  'sanitize' => array( __CLASS__, 'sanitize_meta_keys_textarea' ) ),
			'sei_max_file_size_mb'           => array( 'type' => 'integer', 'sanitize' => array( __CLASS__, 'sanitize_positive_int' ) ),
			'sei_pretty_json'                => array( 'type' => 'boolean', 'sanitize' => array( __CLASS__, 'sanitize_bool' ) ),
			'sei_embed_media'                => array( 'type' => 'boolean', 'sanitize' => array( __CLASS__, 'sanitize_bool' ) ),
			'sei_max_embedded_file_kb'       => array( 'type' => 'integer', 'sanitize' => array( __CLASS__, 'sanitize_positive_int' ) ),
		);

		foreach ( $registry as $option_name => $args ) {
			register_setting( self::OPTION_GROUP, $option_name, array(
				'type'              => $args['type'],
				'sanitize_callback' => $args['sanitize'],
			) );
		}
	}

	public static function sanitize_post_types( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
	}

	public static function sanitize_bool( $value ) {
		return ! empty( $value ) ? 1 : 0;
	}

	public static function sanitize_positive_int( $value ) {
		$int = (int) $value;
		return $int > 0 ? $int : 1;
	}

	public static function sanitize_meta_keys_textarea( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$lines = preg_split( '/\r\n|\r|\n/', $value );
		$out   = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line !== '' ) {
				$out[] = $line;
			}
		}

		return implode( "\n", $out );
	}

	public static function add_settings_page() {
		add_options_page(
			__( 'Export & Import Settings', 'simple-export-import' ),
			__( 'Export & Import', 'simple-export-import' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function add_settings_link( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Settings', 'simple-export-import' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	public static function render_settings_page() {
		$defaults = sei_get_default_settings();

		$post_types_saved         = get_option( 'sei_post_types', $defaults['post_types'] );
		$export_capability        = get_option( 'sei_export_capability', $defaults['export_capability'] );
		$import_capability        = get_option( 'sei_import_capability', $defaults['import_capability'] );
		$import_status            = get_option( 'sei_import_status', $defaults['import_status'] );
		$export_wpml_translations = get_option( 'sei_export_wpml_translations', $defaults['export_wpml_translations'] );
		$import_title_suffix      = get_option( 'sei_import_title_suffix', $defaults['import_title_suffix'] );
		$preserve_original_author = get_option( 'sei_preserve_original_author', $defaults['preserve_original_author'] );
		$extra_skip_meta_keys     = get_option( 'sei_extra_skip_meta_keys', $defaults['extra_skip_meta_keys'] );
		$max_file_size_mb         = get_option( 'sei_max_file_size_mb', $defaults['max_file_size_mb'] );
		$pretty_json              = get_option( 'sei_pretty_json', $defaults['pretty_json'] );
		$embed_media              = get_option( 'sei_embed_media', $defaults['embed_media'] );
		$max_embedded_file_kb     = get_option( 'sei_max_embedded_file_kb', $defaults['max_embedded_file_kb'] );

		$multilingual_active = sei_is_multilingual_active();
		$post_types          = get_post_types( array( 'public' => true ), 'objects' );
		$capabilities        = sei_get_capabilities_list();
		$post_statuses       = array(
			'draft'   => __( 'Draft', 'simple-export-import' ),
			'publish' => __( 'Published', 'simple-export-import' ),
			'pending' => __( 'Pending Review', 'simple-export-import' ),
			'private' => __( 'Private', 'simple-export-import' ),
		);

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Export & Import Settings', 'simple-export-import' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<h2><?php echo esc_html__( 'General', 'simple-export-import' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Post Types', 'simple-export-import' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $post_types as $post_type ) : ?>
									<label>
										<input type="checkbox"
											name="sei_post_types[]"
											value="<?php echo esc_attr( $post_type->name ); ?>"
											<?php checked( in_array( $post_type->name, (array) $post_types_saved, true ) ); ?>>
										<?php echo esc_html( $post_type->labels->name ); ?>
									</label><br>
								<?php endforeach; ?>
								<p class="description">
									<?php echo esc_html__( 'Select post types that will have export functionality', 'simple-export-import' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Permissions', 'simple-export-import' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="sei_export_capability"><?php echo esc_html__( 'Export Capability', 'simple-export-import' ); ?></label>
						</th>
						<td>
							<select name="sei_export_capability" id="sei_export_capability">
								<?php foreach ( $capabilities as $cap_key => $cap_label ) : ?>
									<option value="<?php echo esc_attr( $cap_key ); ?>" <?php selected( $export_capability, $cap_key ); ?>>
										<?php echo esc_html( $cap_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Minimum capability required to export posts', 'simple-export-import' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sei_import_capability"><?php echo esc_html__( 'Import Capability', 'simple-export-import' ); ?></label>
						</th>
						<td>
							<select name="sei_import_capability" id="sei_import_capability">
								<?php foreach ( $capabilities as $cap_key => $cap_label ) : ?>
									<option value="<?php echo esc_attr( $cap_key ); ?>" <?php selected( $import_capability, $cap_key ); ?>>
										<?php echo esc_html( $cap_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Minimum capability required to import posts', 'simple-export-import' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Import Behavior', 'simple-export-import' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="sei_import_status"><?php echo esc_html__( 'Import Status', 'simple-export-import' ); ?></label>
						</th>
						<td>
							<select name="sei_import_status" id="sei_import_status">
								<?php foreach ( $post_statuses as $status_key => $status_label ) : ?>
									<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $import_status, $status_key ); ?>>
										<?php echo esc_html( $status_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Default status for imported posts', 'simple-export-import' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sei_import_title_suffix"><?php echo esc_html__( 'Import Title Suffix', 'simple-export-import' ); ?></label>
						</th>
						<td>
							<input type="text" name="sei_import_title_suffix" id="sei_import_title_suffix"
								value="<?php echo esc_attr( $import_title_suffix ); ?>" class="regular-text">
							<p class="description"><?php echo esc_html__( 'Appended to imported post titles. Leave empty to keep the original title unchanged.', 'simple-export-import' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sei_preserve_original_author"><?php echo esc_html__( 'Preserve Original Author', 'simple-export-import' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" name="sei_preserve_original_author" id="sei_preserve_original_author"
									value="1" <?php checked( $preserve_original_author, 1 ); ?>>
								<?php echo esc_html__( 'Use the original author when their user ID exists on this site', 'simple-export-import' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'When unchecked, the current user becomes the author of every imported post.', 'simple-export-import' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Export Behavior', 'simple-export-import' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="sei_pretty_json"><?php echo esc_html__( 'Pretty-print JSON', 'simple-export-import' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" name="sei_pretty_json" id="sei_pretty_json"
									value="1" <?php checked( $pretty_json, 1 ); ?>>
								<?php echo esc_html__( 'Format exported JSON with indentation', 'simple-export-import' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'Disable for smaller export files.', 'simple-export-import' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sei_extra_skip_meta_keys"><?php echo esc_html__( 'Extra Meta Keys to Skip', 'simple-export-import' ); ?></label>
						</th>
						<td>
							<textarea name="sei_extra_skip_meta_keys" id="sei_extra_skip_meta_keys"
								rows="5" cols="50" class="large-text code"><?php echo esc_textarea( $extra_skip_meta_keys ); ?></textarea>
							<p class="description"><?php echo esc_html__( 'Additional meta keys to exclude from export (one per line). WordPress internal meta (_edit_lock, _wp_trash_meta_status etc.) are skipped by default.', 'simple-export-import' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sei_max_file_size_mb"><?php echo esc_html__( 'Max Upload Size (MB)', 'simple-export-import' ); ?></label>
						</th>
						<td>
							<input type="number" name="sei_max_file_size_mb" id="sei_max_file_size_mb"
								value="<?php echo esc_attr( $max_file_size_mb ); ?>" min="1" max="100" step="1" class="small-text">
							<p class="description"><?php echo esc_html__( 'Maximum size for uploaded JSON files on the Import page.', 'simple-export-import' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Media', 'simple-export-import' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="sei_embed_media"><?php echo esc_html__( 'Embed Media Files', 'simple-export-import' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" name="sei_embed_media" id="sei_embed_media"
									value="1" <?php checked( $embed_media, 1 ); ?>>
								<?php echo esc_html__( 'Embed referenced files as base64 in the JSON export', 'simple-export-import' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'Includes featured image, attached media, images inside Gutenberg/ACF blocks, and any attachment ID referenced from post meta. On import, attachments are recreated and IDs are remapped across content, blocks, meta and featured image. Disabled by default — exports stay small when off.', 'simple-export-import' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sei_max_embedded_file_kb"><?php echo esc_html__( 'Max Embedded File Size (KB)', 'simple-export-import' ); ?></label>
						</th>
						<td>
							<input type="number" name="sei_max_embedded_file_kb" id="sei_max_embedded_file_kb"
								value="<?php echo esc_attr( $max_embedded_file_kb ); ?>" min="1" step="1" class="regular-text">
							<p class="description"><?php echo esc_html__( 'Files larger than this are exported as references only. Default: 10240 (10 MB).', 'simple-export-import' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Multilingual', 'simple-export-import' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="sei_export_wpml_translations"><?php echo esc_html__( 'Multilingual Translations', 'simple-export-import' ); ?></label>
						</th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" name="sei_export_wpml_translations" id="sei_export_wpml_translations"
										value="1"
										<?php checked( $export_wpml_translations, 1 ); ?>
										<?php disabled( ! $multilingual_active ); ?>>
									<?php echo esc_html__( 'Export with translations (WPML / WP-LOC)', 'simple-export-import' ); ?>
								</label>
								<p class="description">
									<?php
									if ( $multilingual_active ) {
										echo esc_html__( 'When enabled, exporting a post will include all of its translations in the same JSON file. Works with WPML and WP-LOC (any plugin exposing the WPML hook surface).', 'simple-export-import' );
									} else {
										echo esc_html__( 'No multilingual plugin detected. Install and activate WPML or WP-LOC to use this feature.', 'simple-export-import' );
									}
									?>
								</p>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
