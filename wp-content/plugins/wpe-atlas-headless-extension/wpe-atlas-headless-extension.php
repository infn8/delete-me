<?php
/**
 * Plugin Name: WP Engine Atlas Headless Extension
 * Plugin URI: https://developers.wpengine.com/
 * Description: A utility plugin that provides Atlas-specific functionality to your WordPress site.
 * Author: WP Engine
 * Author URI: https://wpengine.com/
 * Text Domain: wpe-atlas-headless-extension
 * Domain Path: /languages
 * Version: 0.1.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WPE\Atlas\Headless\Extension
 */

declare(strict_types=1);

namespace WPE\Atlas\Headless\Extension;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPE_ATLAS_HEADLESS_EXTENSION_FILE', __FILE__ );
define( 'WPE_ATLAS_HEADLESS_EXTENSION_URL', plugin_dir_url( __FILE__ ) );
define( 'WPE_ATLAS_HEADLESS_EXTENSION_PATH', plugin_basename( WPE_ATLAS_HEADLESS_EXTENSION_FILE ) );
define( 'WPE_ATLAS_HEADLESS_EXTENSION_SLUG', dirname( plugin_basename( WPE_ATLAS_HEADLESS_EXTENSION_FILE ) ) );
define( 'WPE_ATLAS_HEADLESS_EXTENSION_INCLUDES_DIR', __DIR__ . '/includes' );

add_action( 'plugins_loaded', __NAMESPACE__ . '\loader' );
/**
 * Loads the plugin files on `plugins_loaded` hook.
 *
 * @return void
 */
function loader(): void {
	require_once WPE_ATLAS_HEADLESS_EXTENSION_INCLUDES_DIR . '/compat/acf/misc-acf-functions.php';
	require_once WPE_ATLAS_HEADLESS_EXTENSION_INCLUDES_DIR . '/blueprints/export.php';
	require_once WPE_ATLAS_HEADLESS_EXTENSION_INCLUDES_DIR . '/blueprints/fetch.php';
	require_once WPE_ATLAS_HEADLESS_EXTENSION_INCLUDES_DIR . '/blueprints/import.php';
	require_once WPE_ATLAS_HEADLESS_EXTENSION_INCLUDES_DIR . '/updates/update-functions.php';
	require_once WPE_ATLAS_HEADLESS_EXTENSION_INCLUDES_DIR . '/updates/update-callbacks.php';

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once WPE_ATLAS_HEADLESS_EXTENSION_INCLUDES_DIR . '/wp-cli/class-blueprint.php';
		require_once WPE_ATLAS_HEADLESS_EXTENSION_INCLUDES_DIR . '/wp-cli/class-reset.php';
		\WP_CLI::add_command( 'atlas blueprint', 'WPE\Atlas\Headless\Extension\WP_CLI\Blueprint' );
		\WP_CLI::add_command( 'atlas reset', 'WPE\Atlas\Headless\Extension\WP_CLI\Reset' );
	}
}
