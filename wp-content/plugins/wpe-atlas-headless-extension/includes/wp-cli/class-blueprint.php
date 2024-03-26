<?php
/**
 * WP-CLI commands to export and import Atlas blueprints.
 *
 * A blueprint is a zip file containing:
 *
 * - An main.json file that describes custom post types, taxonomies,
 *   entries, terms, and other data to restore.
 * - Media files to import for entries with media field data.
 *
 * @package WPE\Atlas\Headless\Extension
 */

declare(strict_types=1);

namespace WPE\Atlas\Headless\Extension\WP_CLI;

use WP_CLI;
use function WPE\Atlas\Headless\Extension\Blueprint\Export\{
	collect_media,
	collect_options,
	collect_post_meta,
	collect_post_tags,
	collect_posts,
	delete_folder,
	generate_meta,
	get_blueprint_temp_dir,
	write_manifest,
	zip_blueprint
};

use function WPE\Atlas\Headless\Extension\Blueprint\Import\{
	unzip_blueprint,
	get_manifest,
	check_versions,
	import_acf_custom_post_type_schemas,
	import_acf_taxonomy_schemas,
	import_acf_field_group_schemas,
	import_posts,
	import_post_meta,
	import_terms,
	tag_posts,
	import_options,
	import_media
};

use function WPE\Atlas\Headless\Extension\Blueprint\Fetch\{
	save_blueprint_to_upload_dir,
	get_blueprint
};

/**
 * Blueprint subcommands for the `wp atlas blueprint` WP-CLI command.
 */
class Blueprint {
	/**
	 * Exports an Atlas blueprint using the current state of the site.
	 *
	 * [--name]
	 * : Optional blueprint name. Used in the manifest and zip file name.
	 * Defaults to “Atlas Blueprint” resulting in atlas-blueprint.zip.
	 *
	 * [--description]
	 * : Optional description of the blueprint.
	 *
	 * [--min-wp]
	 * : Minimum WordPress version. Defaults to current WordPress version.
	 *
	 * [--min-wpe-atlas-headless-extension]
	 * : Minimum WP Engine Headless Extension plugin version. Defaults to current version.
	 *
	 * [--version]
	 * : Optional blueprint version. Defaults to 1.0.
	 *
	 * [--post-types]
	 * : Post types to collect posts for, separated by commas. Defaults to post,
	 * page and all registered ACF post types.
	 *
	 * [--wp-options]
	 * : Named wp_options keys to export, separated by commas. Default includes `stylesheet`, `template`, `current_theme`, and `theme_mods_{stylesheet}`.
	 *
	 * [--wp-theme]
	 * : Theme slug. Defaults to twentytwentythree.
	 *
	 * [--wp-theme-version]
	 * : Theme version. Defaults to latest.
	 *
	 * [--open]
	 * : Open the folder containing the generated zip on success (macOS only,
	 * requires that `shell_exec()` has not been disabled).
	 *
	 * @param array $args Options passed to the command, keyed by integer.
	 * @param array $assoc_args Options keyed by string.
	 */
	public function export( array $args = [], array $assoc_args = [] ): void {
		$meta_overrides = [];

		WP_CLI::log( 'Collecting blueprint data.' );
		foreach ( [ 'name', 'description', 'min-wp', 'min-wpe-atlas-headless-extension', 'version', 'wp-theme', 'wp-theme-version', 'wp-plugins' ] as $key ) {
			if ( ( $assoc_args[ $key ] ?? false ) ) {
				$meta_overrides[ $key ] = $assoc_args[ $key ];
			}
		}

		$manifest = generate_meta( $meta_overrides );
		$temp_dir = get_blueprint_temp_dir( $manifest );

		if ( is_wp_error( $temp_dir ) ) {
			WP_CLI::error( $temp_dir->get_error_message() );
		}

		delete_folder( $temp_dir ); // Cleans up previous exports.

		WP_CLI::log( 'Collecting custom post types.' );
		$manifest['services']['wordpress']['plugins']['advanced-custom-fields']['post-types'] = $this->get_post_type_schemas();

		WP_CLI::log( 'Collecting custom taxonomies.' );
		$manifest['services']['wordpress']['plugins']['advanced-custom-fields']['taxonomies'] = $this->get_taxonomy_schemas();

		WP_CLI::log( 'Collecting ACF field groups.' );
		$manifest['services']['wordpress']['plugins']['advanced-custom-fields']['field-groups'] = $this->get_field_group_schemas();

		WP_CLI::log( 'Collecting posts.' );
		$post_types = array_merge(
			array_keys( $this->get_post_type_schemas() ),
			[ 'post', 'page' ]
		);
		if ( ! empty( $assoc_args['post-types'] ) ) {
			$post_types = array_map(
				'trim',
				explode( ',', $assoc_args['post-types'] )
			);
		}
		$manifest['services']['wordpress']['posts'] = collect_posts( $post_types );

		if ( ! empty( $manifest['services']['wordpress']['posts'] ?? [] ) ) {
			WP_CLI::log( 'Collecting post tags.' );
			$manifest['services']['wordpress']['post_terms'] = collect_post_tags(
				$manifest['services']['wordpress']['posts'] ?? []
			);
		}

		WP_CLI::log( 'Collecting post meta.' );
		$manifest['services']['wordpress']['post_meta'] = collect_post_meta(
			$manifest['services']['wordpress']['posts'] ?? []
		);

		if ( ! empty( $manifest['services']['wordpress']['post_meta'] ?? [] ) ) {
			WP_CLI::log( 'Collecting media.' );
			$manifest['services']['wordpress']['media'] = collect_media(
				$manifest,
				$temp_dir
			);
		}

		if ( ! empty( $assoc_args['wp-options'] ) ) {
			$wp_options                                      = array_map(
				'trim',
				explode( ',', $assoc_args['wp-options'] )
			);
			$manifest['services']['wordpress']['wp-options'] = array_merge( $manifest['services']['wordpress']['wp-options'], collect_options( $wp_options ) );
		}

		WP_CLI::log( 'Writing main.json manifest.' );
		$write_manifest = write_manifest( $manifest, $temp_dir );

		if ( is_wp_error( $write_manifest ) ) {
			WP_CLI::error( $write_manifest->get_error_message() );
		}

		WP_CLI::log( 'Generating zip.' );
		$path_to_zip = zip_blueprint(
			$temp_dir,
			sanitize_title_with_dashes( $manifest['blueprint']['name'] )
		);

		if ( is_wp_error( $path_to_zip ) ) {
			WP_CLI::error( $path_to_zip->get_error_message() );
		}

		if (
			PHP_OS === 'Darwin'
			&& ( $assoc_args['open'] ?? false )
			&& function_exists( 'shell_exec' )
		) {
			WP_CLI::log( 'Opening blueprint temp folder.' );
			shell_exec( "open {$temp_dir}" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		}

		WP_CLI::success( sprintf( 'Blueprint saved to %s.', $path_to_zip ) );
	}

	/**
	 * Imports an Atlas blueprint from a PATH or URL.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : The URL or local path of the blueprint zip file, or local path to the
	 * blueprint folder containing the main.json manifest file. Local paths must
	 * be absolute.
	 *
	 * ## EXAMPLES
	 *
	 *     wp atlas blueprint import https://example.com/path/to/blueprint.zip
	 *     wp atlas blueprint import /local/path/to/blueprint.zip
	 *     wp atlas blueprint import /local/path/to/blueprint-folder/
	 *
	 * @param array $args Options passed to the command.
	 * @param array $assoc_args Optional flags passed to the command.
	 */
	public function import( $args, $assoc_args ): void {
		list( $path )      = $args;
		$path_is_directory = pathinfo( $path, PATHINFO_EXTENSION ) === '';

		if ( $path_is_directory ) {
			$blueprint_folder = save_blueprint_to_upload_dir( $path, basename( $path ) );
			if ( is_wp_error( $blueprint_folder ) ) {
				\WP_CLI::error( $blueprint_folder->get_error_message() );
			}
		}

		if ( ! $path_is_directory ) {
			\WP_CLI::log( 'Fetching blueprint.' );
			$zip_file = get_blueprint( $path );
			if ( is_wp_error( $zip_file ) ) {
				\WP_CLI::error( $zip_file->get_error_message() );
			}

			$valid_file = save_blueprint_to_upload_dir( $zip_file, basename( $path ) );
			if ( is_wp_error( $valid_file ) ) {
				\WP_CLI::error( $valid_file->get_error_message() );
			}

			\WP_CLI::log( 'Unzipping.' );
			$blueprint_folder = unzip_blueprint( $valid_file );

			if ( is_wp_error( $blueprint_folder ) ) {
				\WP_CLI::error( $blueprint_folder->get_error_message() );
			}
		}

		\WP_CLI::log( 'Verifying blueprint manifest.' );
		$manifest = get_manifest( $blueprint_folder );

		if ( is_wp_error( $manifest ) ) {
			\WP_CLI::error(
				$manifest->get_error_message( 'wpe_atlas_headless_extension_manifest_error' )
			);
		}

		\WP_CLI::log( 'Checking minimum versions.' );
		$version_test = check_versions( $manifest );

		if ( is_wp_error( $version_test ) ) {
			\WP_CLI::error(
				$version_test->get_error_message( 'wpe_atlas_headless_extension_version_error' )
			);
		}

		if ( ! empty( $manifest['services']['wordpress']['plugins']['advanced-custom-fields']['post-types'] ?? [] ) ) {
			\WP_CLI::log( 'Importing ACF post types.' );

			$post_type_import = import_acf_custom_post_type_schemas( $manifest['services']['wordpress']['plugins']['advanced-custom-fields']['post-types'] );

			if ( is_wp_error( $post_type_import ) ) {
				\WP_CLI::error( $post_type_import->get_error_message() );
			}
		}

		if ( ! empty( $manifest['services']['wordpress']['plugins']['advanced-custom-fields']['taxonomies'] ?? [] ) ) {
			\WP_CLI::log( 'Importing ACF taxonomies.' );

			$taxonomy_import = import_acf_taxonomy_schemas( $manifest['services']['wordpress']['plugins']['advanced-custom-fields']['taxonomies'] );

			if ( is_wp_error( $taxonomy_import ) ) {
				foreach ( $taxonomy_import->get_error_messages() as $message ) {
					\WP_CLI::warning( $message );
				}
			}
		}

		if ( ! empty( $manifest['services']['wordpress']['plugins']['advanced-custom-fields']['field-groups'] ?? [] ) ) {
			\WP_CLI::log( 'Importing ACF field groups.' );

			$field_group_import = import_acf_field_group_schemas( $manifest['services']['wordpress']['plugins']['advanced-custom-fields']['field-groups'] );

			if ( is_wp_error( $field_group_import ) ) {
				foreach ( $field_group_import->get_error_messages() as $message ) {
					\WP_CLI::warning( $message );
				}
			}
		}

		$post_ids_old_new = [];
		if ( ! empty( $manifest['services']['wordpress']['posts'] ?? [] ) ) {
			\WP_CLI::log( 'Importing posts.' );
			$post_ids_old_new = import_posts( $manifest['services']['wordpress']['posts'] );
		}

		$term_ids_old_new = [];
		if ( ! empty( $manifest['services']['wordpress']['post_terms'] ?? [] ) ) {
			\WP_CLI::log( 'Importing terms.' );
			$term_ids_old_new = import_terms( $manifest['services']['wordpress']['post_terms'] );

			if ( is_wp_error( $term_ids_old_new['errors'] ) ) {
				foreach ( $term_ids_old_new['errors']->get_error_messages() as $message ) {
					\WP_CLI::warning( $message );
				}
			}
		}

		if ( ! empty( $manifest['services']['wordpress']['post_terms'] ?? [] ) ) {
			\WP_CLI::log( 'Tagging posts.' );
			$tag_posts = tag_posts(
				$manifest['services']['wordpress']['post_terms'],
				$post_ids_old_new,
				$term_ids_old_new['ids'] ?? []
			);

			if ( is_wp_error( $tag_posts ) ) {
				foreach ( $tag_posts->get_error_messages() as $message ) {
					\WP_CLI::warning( $message );
				}
			}
		}

		$media_ids_old_new = [];
		if ( ! empty( $manifest['services']['wordpress']['media'] ?? [] ) ) {
			\WP_CLI::log( 'Importing media.' );

			$media_ids_old_new = import_media( $manifest['services']['wordpress']['media'], $blueprint_folder );

			if ( is_wp_error( $media_ids_old_new ) ) {
				\WP_CLI::error( $media_ids_old_new->get_error_message() );
			}
		}

		if ( ! empty( $manifest['services']['wordpress']['post_meta'] ?? [] ) ) {
			\WP_CLI::log( 'Importing post meta.' );
			import_post_meta(
				$manifest,
				$post_ids_old_new,
				$media_ids_old_new
			);
		}

		if ( ! empty( $manifest['services']['wordpress']['wp-options'] ?? [] ) ) {
			\WP_CLI::log( 'Importing WordPress options.' );
			import_options( $manifest['services']['wordpress']['wp-options'] );
		}

		\WP_CLI::success( 'Import complete.' );
	}

	/**
	 * Gets the custom post type schemas from ACF.
	 *
	 * @return array List of custom post type schemas from ACF.
	 */
	protected function get_post_type_schemas(): array {
		$schemas = [];

		if ( function_exists( 'acf_get_acf_post_types' ) ) {
			$post_type_schemas = acf_get_acf_post_types();
			foreach ( $post_type_schemas as $post_type_schema ) {
				$schemas[ $post_type_schema['post_type'] ] = acf_prepare_post_type_for_export( $post_type_schema );
			}
		}
		return $schemas;
	}

	/**
	 * Gets the custom taxonomy schemas from ACF.
	 *
	 * @return array List of custom taxonomy schemas from ACF.
	 */
	protected function get_taxonomy_schemas(): array {
		$schemas = [];

		if ( function_exists( 'acf_get_acf_taxonomies' ) ) {
			$taxonomy_schemas = acf_get_acf_taxonomies();
			foreach ( $taxonomy_schemas as $taxonomy_schema ) {
				$schemas[ $taxonomy_schema['taxonomy'] ] = acf_prepare_taxonomy_for_export( $taxonomy_schema );
			}
		}

		return $schemas;
	}

	/**
	 * Gets field group schemas from ACF.
	 *
	 * @return array List of field group schemas from ACF.
	 */
	protected function get_field_group_schemas(): array {
		$schemas = [];

		if ( function_exists( 'acf_get_field_groups' ) ) {
			$field_group_schemas = acf_get_field_groups();
			foreach ( $field_group_schemas as $field_group_schema ) {
				$schemas[ $field_group_schema['key'] ]           = acf_prepare_field_group_for_export( $field_group_schema );
				$schemas[ $field_group_schema['key'] ]['fields'] = acf_prepare_fields_for_export( acf_get_fields( $field_group_schema['ID'] ) );
			}
		}

		return $schemas;
	}
}
