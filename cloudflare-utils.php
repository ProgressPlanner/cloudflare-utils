<?php
/**
 * Plugin Name: Cloudflare utils by Progress Planner
 * Description: Make clearing and setting Cloudflare cache easier.
 * Version: 1.0
 * Author: Team Progress Planner
 * Author URI: https://progressplanner.com/
 * Textdomain: pp-cf-utils
 *
 * @package PP_Cloudflare_Utils
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PP_CF_UTILS_DIR', __DIR__ );
define( 'PP_CF_UTILS_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );

/**
 * Load the plugin.
 */
require_once __DIR__ . '/classes/class-base.php';
new PP_Cloudflare_Utils\Base();
