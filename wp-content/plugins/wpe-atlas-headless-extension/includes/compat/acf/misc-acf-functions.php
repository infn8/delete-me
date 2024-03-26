<?php
/**
 * Miscellaneous functionality related to ACF.
 *
 * @package WPE\Atlas\Headless\Extension
 */

declare(strict_types=1);

namespace WPE\Atlas\Headless\Extension\ACF;

use WP_Error;

/**
 * Deletes ACF custom post type schemas.
 */
function delete_custom_post_types(): bool {
	if ( ! function_exists( 'acf_delete_post_type' ) ) {
		return false;
	}

	$schemas = acf_get_acf_post_types();
	if ( empty( $schemas ) ) {
		return true;
	}

	// @todo better error handling
	$success = true;
	foreach ( $schemas as $schema ) {
		if ( ! acf_delete_post_type( $schema['ID'] ) ) {
			$success = false;
		}
	}
	return $success;
}

/**
 * Deletes ACF taxonomies.
 */
function delete_taxonomies(): bool {
	if ( ! function_exists( 'acf_delete_taxonomy' ) ) {
		return false;
	}
	$taxonomy_schemas = acf_get_acf_taxonomies();
	if ( empty( $taxonomy_schemas ) ) {
		return true;
	}
	// @todo better error handling
	$success = true;
	foreach ( $taxonomy_schemas as $taxonomy_schema ) {
		if ( ! acf_delete_taxonomy( $taxonomy_schema['ID'] ) ) {
			$success = false;
		}
	}
	return $success;
}

/**
 * Deletes all ACF field groups.
 *
 * @return bool
 */
function delete_field_groups(): bool {
	if ( ! function_exists( 'acf_delete_field_group' ) ) {
		return false;
	}

	$schemas = acf_get_field_groups();
	if ( empty( $schemas ) ) {
		return true;
	}

	$success = true;
	foreach ( $schemas as $schema ) {
		if ( ! acf_delete_field_group( $schema['ID'] ) ) {
			$success = false;
		}
	}
	return $success;
}

/**
 * Imports ACF custom post type schemas.
 *
 * @param array $schemas Custom Post Types in their original stored format.
 * @return WP_Error|bool Gives a WP_Error in the case of import issues.
 */
function import_custom_post_type_schemas( array $schemas = [] ) {
	if ( ! function_exists( 'acf_import_post_type' ) ) {
		return new WP_Error(
			'wpe_atlas_headless_extension_import_error',
			esc_html__( 'Error importing custom post type schemas. acf_import_post_type() function does not exist.', 'wpe-atlas-headless-extension' )
		);
	}

	foreach ( $schemas as $schema ) {
		if ( ! is_array( $schema ) ) {
			continue;
		}

		$schema = acf_prepare_post_type_for_import( $schema );
		$result = acf_import_post_type( $schema );

		if ( is_wp_error( $result ) ) {
			/* @var WP_Error $result Error object. */
			return new WP_Error(
				'wpe_atlas_headless_extension_import_error',
				// translators: string containing error message.
				sprintf( esc_html__( 'Error importing custom post type schemas. Reason: %s', 'wpe-atlas-headless-extension' ), $result->get_error_message() )
			);
		}
	}
	return true;
}

/**
 * Imports ACF taxonomy schemas.
 *
 * @param array $schemas Taxonomies in their original stored format.
 * @return WP_Error|bool Gives a WP_Error in the case of collisions with an
 *                       existing taxonomy or other taxonomy import issues.
 */
function import_taxonomy_schemas( array $schemas = [] ) {
	if ( ! function_exists( 'acf_import_taxonomy' ) ) {
		return new WP_Error(
			'wpe_atlas_headless_extension_import_error',
			esc_html__( 'Error importing taxonomy schemas. acf_import_taxonomy() function does not exist.', 'wpe-atlas-headless-extension' )
		);
	}

	foreach ( $schemas as $schema ) {
		if ( ! is_array( $schema ) ) {
			continue;
		}

		$schema = acf_prepare_taxonomy_for_import( $schema );
		$result = acf_import_taxonomy( $schema );
		if ( is_wp_error( $result ) ) {
			/* @var WP_Error $result Error object. */
			return new WP_Error(
				'wpe_atlas_headless_extension_import_error',
				// translators: string containing error message.
				sprintf( esc_html__( 'Error importing taxonomy schemas. Reason: %s', 'wpe-atlas-headless-extension' ), $result->get_error_message() )
			);
		}
	}
	return true;
}
