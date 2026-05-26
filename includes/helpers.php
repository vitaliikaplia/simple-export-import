<?php
/**
 * Procedural helpers used across the plugin.
 *
 * @package Simple_Export_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default values for every option the plugin stores.
 */
function sei_get_default_settings() {
	return array(
		'post_types'                 => array( 'post', 'page' ),
		'export_capability'          => 'edit_posts',
		'import_capability'          => 'edit_posts',
		'import_status'              => 'draft',
		'export_wpml_translations'   => false,
		'import_title_suffix'        => ' - imported',
		'preserve_original_author'   => false,
		'extra_skip_meta_keys'       => '',
		// Defaults derived from the actual server config — no artificial caps.
		'max_file_size_mb'           => sei_get_php_upload_limit_mb(),
		'pretty_json'                => true,
		'embed_media'                => false,
		'max_embedded_file_kb'       => 10240,
	);
}

/**
 * Effective PHP upload ceiling in MB.
 * wp_max_upload_size() returns the smaller of upload_max_filesize and
 * post_max_size in bytes; we round up to whole MB so a "64M" config
 * reads as 64, not 63.
 */
function sei_get_php_upload_limit_mb() {
	$bytes = (int) wp_max_upload_size();
	if ( $bytes <= 0 ) {
		return 1;
	}
	return max( 1, (int) ceil( $bytes / 1024 / 1024 ) );
}

/**
 * PHP memory_limit in MB. Returns 0 if unlimited (-1) or unparseable.
 */
function sei_get_php_memory_limit_mb() {
	$raw = (string) ini_get( 'memory_limit' );
	if ( $raw === '' || $raw === '-1' ) {
		return 0;
	}
	$bytes = (int) wp_convert_hr_to_bytes( $raw );
	if ( $bytes <= 0 ) {
		return 0;
	}
	return (int) round( $bytes / 1024 / 1024 );
}

/**
 * Effective JSON-upload limit at import time = min(setting, PHP cap).
 * The setting can only tighten the cap, never raise it past what PHP
 * physically accepts; that way the validator's error message matches
 * what would actually fail.
 */
function sei_get_effective_upload_limit_mb() {
	$php_mb     = sei_get_php_upload_limit_mb();
	$setting_mb = max( 1, (int) get_option( 'sei_max_file_size_mb', $php_mb ) );
	return min( $setting_mb, $php_mb );
}

/**
 * Capabilities offered in the settings dropdowns.
 */
function sei_get_capabilities_list() {
	return array(
		'read'                 => __( 'Read', 'simple-export-import' ),
		'edit_posts'           => __( 'Edit Posts', 'simple-export-import' ),
		'edit_pages'           => __( 'Edit Pages', 'simple-export-import' ),
		'edit_published_posts' => __( 'Edit Published Posts', 'simple-export-import' ),
		'publish_posts'        => __( 'Publish Posts', 'simple-export-import' ),
		'manage_options'       => __( 'Manage Options', 'simple-export-import' ),
	);
}

/**
 * Built-in meta keys that must never travel between posts.
 * WordPress internals — locks, trash bookkeeping, ping queues, thumbnail
 * reference (handled separately via featured_image / set_post_thumbnail).
 */
function sei_get_default_skip_meta_keys() {
	return array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_pingme',
		'_encloseme',
		'_thumbnail_id',
	);
}

/**
 * User-supplied extra meta keys to skip, parsed from the textarea option.
 */
function sei_get_extra_skip_meta_keys() {
	$option = get_option( 'sei_extra_skip_meta_keys', '' );

	if ( ! is_string( $option ) || $option === '' ) {
		return array();
	}

	$lines = preg_split( '/\r\n|\r|\n/', $option );
	$keys  = array();

	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( $line !== '' ) {
			$keys[] = $line;
		}
	}

	return $keys;
}

/**
 * Effective skip list — defaults plus user-supplied extras.
 */
function sei_get_skip_meta_keys() {
	return array_values( array_unique( array_merge(
		sei_get_default_skip_meta_keys(),
		sei_get_extra_skip_meta_keys()
	) ) );
}

/**
 * Validate the uploaded JSON file.
 *
 * @return string[] Error messages; empty array means valid.
 */
function sei_validate_json_file( $file ) {
	$errors = array();

	if ( empty( $file['tmp_name'] ) ) {
		$errors[] = __( 'No file uploaded', 'simple-export-import' );
		return $errors;
	}

	$max_mb    = sei_get_effective_upload_limit_mb();
	$max_bytes = $max_mb * 1024 * 1024;

	if ( $file['size'] > $max_bytes ) {
		$errors[] = sprintf(
			/* translators: %d: effective maximum file size in megabytes (min of plugin setting and PHP cap) */
			__( 'File size exceeds %d MB limit', 'simple-export-import' ),
			$max_mb
		);
	}

	$file_extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
	if ( $file_extension !== 'json' ) {
		$errors[] = __( 'Only JSON files are allowed', 'simple-export-import' );
	}

	$file_content = file_get_contents( $file['tmp_name'] );
	if ( $file_content === false ) {
		$errors[] = __( 'Unable to read file', 'simple-export-import' );
	} else {
		json_decode( $file_content );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$errors[] = sprintf(
				/* translators: %s: JSON parser error message */
				__( 'File does not contain valid JSON: %s', 'simple-export-import' ),
				json_last_error_msg()
			);
		}
	}

	return $errors;
}

/**
 * Detect any multilingual backend that emulates the WPML hook surface.
 * Returns true for real WPML and lightweight clones like WP-LOC that
 * register the wpml_* filters/actions without defining WPML constants.
 */
function sei_is_multilingual_active() {
	if ( defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' ) ) {
		return true;
	}

	if ( class_exists( 'WP_LOC' ) ) {
		return true;
	}

	return false !== has_filter( 'wpml_element_language_details' )
		&& false !== has_filter( 'wpml_get_element_translations' );
}

/**
 * Normalize wpml_element_language_details payload to an object.
 * WPML returns stdClass, WP-LOC returns an associative array — callers
 * expect property access (->trid, ->language_code).
 */
function sei_normalize_lang_details( $details ) {
	if ( empty( $details ) ) {
		return null;
	}

	if ( is_array( $details ) ) {
		return (object) $details;
	}

	return is_object( $details ) ? $details : null;
}
