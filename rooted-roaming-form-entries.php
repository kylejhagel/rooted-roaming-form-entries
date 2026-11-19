<?php
/**
 * Plugin Name: Rooted and Roaming — Form Entries
 * Description: Collects approved Contact Form 7 newsletter signups, displays subscribers in WordPress, and exports CSV.
 * Version: 0.1.1
 * Requires PHP: 7.4
 * Text Domain: rooted-roaming-form-entries
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RRFE_VERSION', '0.1.1' );
define( 'RRFE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
require_once RRFE_PLUGIN_DIR . 'includes/class-rrfe-plugin.php';
register_activation_hook( __FILE__, array( 'RRFE_Plugin', 'activate' ) );
add_action( 'plugins_loaded', array( 'RRFE_Plugin', 'bootstrap' ) );
