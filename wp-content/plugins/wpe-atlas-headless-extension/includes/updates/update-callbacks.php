<?php
/**
 * Plugin updates related callbacks.
 *
 * @package WPE\Atlas\Headless\Extension
 */

declare(strict_types=1);

namespace WPE\Atlas\Headless\Extension\PluginUpdater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'pre_set_site_transient_update_plugins', __NAMESPACE__ . '\check_for_plugin_updates' );
/**
 * Callback for WordPress 'pre_set_site_transient_update_plugins' filter.
 *
 * Check for plugin updates by retrieving data from the plugin update source.
 *
 * This will let WordPress know an update is available.
 *
 * @param object $data WordPress update object.
 *
 * @return object $data An updated object if an update exists, default object if not.
 */
function check_for_plugin_updates( $data ) {
	if ( empty( $data ) ) {
		return $data;
	}

	$response = get_remote_plugin_info();
	if ( empty( $response->requires_at_least ) || empty( $response->version ) ) {
		return $data;
	}

	$current_plugin_data = \get_plugin_data( WPE_ATLAS_HEADLESS_EXTENSION_FILE );
	$meets_wp_req        = version_compare( get_bloginfo( 'version' ), $response->requires_at_least, '>=' );

	// Only update the response if there's a newer version, otherwise WP shows an update notice for the same version.
	if ( $meets_wp_req && version_compare( $current_plugin_data['Version'], $response->version, '<' ) ) {
		$response->plugin                                    = plugin_basename( WPE_ATLAS_HEADLESS_EXTENSION_FILE );
		$data->response[ WPE_ATLAS_HEADLESS_EXTENSION_PATH ] = $response;
	}

	return $data;
}

add_filter( 'plugins_api', __NAMESPACE__ . '\custom_plugin_api_request', 10, 3 );
/**
 * Callback for WordPress 'plugins_api' filter.
 *
 * Return a custom response for this plugin from the custom endpoint.
 *
 * @link https://developer.wordpress.org/reference/hooks/plugins_api/
 *
 * @param false|object|array $api The result object or array. Default false.
 * @param string             $action The type of information being requested from the Plugin Installation API.
 * @param object             $args Plugin API arguments.
 *
 * @return false|stdClass $response Plugin API arguments.
 */
function custom_plugin_api_request( $api, $action, $args ) {
	if ( empty( $args->slug ) || WPE_ATLAS_HEADLESS_EXTENSION_SLUG !== $args->slug ) {
		return $api;
	}

	$response = get_plugin_data_from_wpe( $args );
	if ( empty( $response ) || is_wp_error( $response ) ) {
		return $api;
	}

	return $response;
}

add_action( 'admin_notices', __NAMESPACE__ . '\delegate_plugin_row_notice' );
/**
 * Callback for WordPress 'admin_notices' action.
 *
 * Delegate actions to display an error message on the plugin table row if present.
 *
 * @link https://developer.wordpress.org/reference/hooks/admin_notices/
 *
 * @return void
 */
function delegate_plugin_row_notice() {
	$screen = get_current_screen();
	if ( 'plugins' !== $screen->id ) {
		return;
	}

	$error = get_plugin_api_error();
	if ( ! $error ) {
		return;
	}

	$plugin_basename = plugin_basename( WPE_ATLAS_HEADLESS_EXTENSION_FILE );

	remove_action( "after_plugin_row_{$plugin_basename}", 'wp_plugin_update_row' );
	add_action( "after_plugin_row_{$plugin_basename}", __NAMESPACE__ . '\display_plugin_row_notice', 10, 2 );
}

/**
 * Callback for WordPress 'after_plugin_row_{plugin_basename}' action.
 *
 * Callback added in add_plugin_page_notices().
 *
 * Show a notice in the plugin table row when there is an error present.
 *
 * @param string $plugin_file Path to the plugin file relative to the plugins directory.
 * @param array  $plugin_data An array of plugin data.
 *
 * @return void
 */
function display_plugin_row_notice( string $plugin_file, array $plugin_data = [] ) {
	$error = get_plugin_api_error();

	?>
	<tr class="plugin-update-tr active" id="wpe-atlas-headless-extension-update" data-slug="wpe-atlas-headless-extension" data-plugin="wpe-atlas-headless-extension/wpe-atlas-headless-extension.php">
		<td colspan="3" class="plugin-update">
			<div class="update-message notice inline notice-error notice-alt">
				<p>
					<?php echo wp_kses_post( get_api_error_text( $error ) ); ?>
				</p>
			</div>
		</td>
	</tr>
	<?php
}

add_action( 'admin_notices', __NAMESPACE__ . '\display_update_page_notice' );
/**
 * Callback for WordPress 'admin_notices' action.
 *
 * Display an error notice on the "WordPress Updates" page if present.
 *
 * @link https://developer.wordpress.org/reference/hooks/admin_notices/
 *
 * @return void
 */
function display_update_page_notice() {
	$screen = get_current_screen();
	if ( 'update-core' !== $screen->id ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only used to avoid displaying messages when inappropriate.
	if ( ! empty( $_GET['action'] ) && 'do-theme-upgrade' === $_GET['action'] ) {
		return;
	}

	$error = get_plugin_api_error();
	if ( ! $error ) {
		return;
	}

	?>
	<div class="error">
		<p>
			<?php echo wp_kses_post( get_api_error_text( $error ) ); ?>
		</p>
	</div>
	<?php
}
