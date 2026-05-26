<?php
/**
 * Plugin uninstall handler — wipes every option the plugin stored.
 *
 * @package Simple_Export_Import
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$sei_options = array(
	'sei_post_types',
	'sei_export_capability',
	'sei_import_capability',
	'sei_import_status',
	'sei_export_wpml_translations',
	'sei_import_title_suffix',
	'sei_preserve_original_author',
	'sei_extra_skip_meta_keys',
	'sei_max_file_size_mb',
	'sei_pretty_json',
	'sei_embed_media',
	'sei_max_embedded_file_kb',
);

if ( is_multisite() ) {
	foreach ( (array) get_sites( array( 'fields' => 'ids' ) ) as $blog_id ) {
		switch_to_blog( $blog_id );
		foreach ( $sei_options as $opt ) {
			delete_option( $opt );
		}
		restore_current_blog();
	}
} else {
	foreach ( $sei_options as $opt ) {
		delete_option( $opt );
	}
}
