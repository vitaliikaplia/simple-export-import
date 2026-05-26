<?php
/**
 * Plugin Name: Simple Export & Import
 * Plugin URI: https://vitaliikaplia.com/
 * Description: Export & import WordPress posts as JSON with full Gutenberg / ACF / WPML / WP-LOC support.
 * Version: 1.2
 * Author: Vitalii Kaplia
 * Author URI: https://vitaliikaplia.com/
 * License: GPLv2 or later
 * Text Domain: simple-export-import
 * Domain Path: /languages
 * Requires at least: 4.7
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEI_VERSION', '1.2' );
define( 'SEI_PATH', plugin_dir_path( __FILE__ ) );
define( 'SEI_URL', plugin_dir_url( __FILE__ ) );
define( 'SEI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once SEI_PATH . 'includes/helpers.php';
require_once SEI_PATH . 'includes/class-sei-multilingual.php';
require_once SEI_PATH . 'includes/class-sei-media.php';
require_once SEI_PATH . 'includes/class-sei-settings.php';
require_once SEI_PATH . 'includes/class-sei-export.php';
require_once SEI_PATH . 'includes/class-sei-import.php';

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'simple-export-import', false, dirname( SEI_PLUGIN_BASENAME ) . '/languages' );

	SEI_Settings::init();
	SEI_Export::init();
	SEI_Import::init();
} );
