<?php
/**
 * Plugin Name: Waygate
 * Plugin URI:  https://github.com/imagewize/waygate
 * Description: AI-powered pattern page builder for the Elayne block theme. Lists registered patterns, creates pages from pattern slugs, and integrates with WordPress AI Client for natural-language page generation.
 * Version:     0.5.0
 * Author:      Jasper Frumau
 * Author URI:  https://imagewize.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: waygate
 * Domain Path: /languages
 * Requires at least: 7.0
 * Requires PHP: 8.3
 */

defined( 'ABSPATH' ) || exit;

define( 'WAYGATE_VERSION', '0.5.0' );
define( 'WAYGATE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WAYGATE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WAYGATE_PLUGIN_DIR . 'includes/class-pattern-lab.php';
require_once WAYGATE_PLUGIN_DIR . 'includes/class-abilities-api.php';
require_once WAYGATE_PLUGIN_DIR . 'includes/class-ai-integration.php';
require_once WAYGATE_PLUGIN_DIR . 'includes/class-admin.php';

add_action( 'plugins_loaded', [ 'Imagewize\\Waygate\\PatternLab', 'init' ] );
add_action( 'plugins_loaded', [ 'Imagewize\\Waygate\\AiIntegration', 'init' ] );
add_action( 'plugins_loaded', [ 'Imagewize\\Waygate\\AbilitiesApi', 'init' ] );
add_action( 'plugins_loaded', [ 'Imagewize\\Waygate\\Admin', 'init' ] );
