<?php
/**
 * WP-CLI command to reset ACF content by deleting post types, taxonomies,
 * field groups, posts, taxonomy terms, and media.
 *
 * `wp atlas reset`
 * `wp atlas reset --yes` to skip the confirmation prompt.
 * `wp atlas reset --all` to delete all post types and media (core posts, pages).
 *
 * @package WPE\Atlas\Headless\Extension
 */

declare(strict_types=1);

namespace WPE\Atlas\Headless\Extension\WP_CLI;

use function WPE\Atlas\Headless\Extension\ACF\delete_custom_post_types as delete_acf_custom_post_types;
use function WPE\Atlas\Headless\Extension\ACF\delete_field_groups as delete_acf_field_groups;
use function WPE\Atlas\Headless\Extension\ACF\delete_taxonomies as delete_acf_taxonomies;

/**
 * Reset ACF data.
 */
class Reset {
	/**
	 * ACF field groups.
	 *
	 * @var array
	 */
	private array $field_groups;

	/**
	 * ACF taxonomies.
	 *
	 * @var array
	 */
	private array $taxonomies;

	/**
	 * ACF custom post types.
	 *
	 * @var array
	 */
	private array $post_types;

	/**
	 * Arrays of post IDs, each keyed by model ID.
	 *
	 * @var array
	 */
	private array $posts;

	/**
	 * Array of media IDs.
	 *
	 * @var array
	 */
	private array $media;

	/**
	 * Stats to count deleted items of different types.
	 *
	 * @var array
	 */
	private array $stats;

	/**
	 * Sets up data needed for the reset command.
	 */
	public function __construct() {
		if ( function_exists( 'acf_get_acf_taxonomies' ) ) {
			$this->taxonomies   = acf_get_acf_taxonomies();
			$this->post_types   = acf_get_acf_post_types();
			$this->field_groups = acf_get_field_groups();
		}

		$this->posts = $this->get_post_ids();
		$this->media = $this->get_media_ids();
		$this->stats = [
			'taxonomy_terms' => 0,
			'taxonomies'     => 0,
			'posts'          => 0,
			'media'          => 0,
			'post_types'     => 0,
			'field_groups'   => 0,
		];
	}

	/**
	 * Resets ACF by deleting post types, field groups, taxonomies, taxonomy terms, posts, and media items relating to ACF fields.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip prompt to confirm deletion.
	 *
	 * [--all]
	 * : Delete all published posts, pages, custom posts and media, not just posts and media associated with ACF.
	 *
	 * ## EXAMPLES
	 *
	 *     wp atlas reset
	 *     wp atlas reset --yes
	 *     wp atlas reset --all
	 *     wp atlas reset --yes --all
	 *
	 * @param array $args Options passed to the command, keyed by integer.
	 * @param array $assoc_args Options keyed by string.
	 */
	public function __invoke( $args, $assoc_args ) {
		\WP_CLI::confirm( 'Delete ACF data, including posts, media, taxonomies, field groups, and custom post types?', $assoc_args );

		$delete_all = $assoc_args['all'] ?? false;

		\WP_CLI::log( 'Deleting taxonomy terms.' );
		$this->delete_taxonomy_terms();

		\WP_CLI::log( 'Deleting ACF taxonomies.' );
		$this->delete_taxonomies();

		\WP_CLI::log( 'Deleting posts.' );
		$this->delete_posts( $delete_all );

		\WP_CLI::log( 'Deleting media.' );
		$this->delete_media( $delete_all );

		\WP_CLI::log( 'Deleting custom post types.' );
		$this->delete_custom_post_types();

		\WP_CLI::log( 'Deleting field groups.' );
		$this->delete_field_groups();

		$this->log_stats();
		\WP_CLI::success( 'Atlas reset complete.' );
	}

	/**
	 * Deletes taxonomy terms
	 */
	public function delete_taxonomy_terms(): void {
		foreach ( $this->taxonomies as $taxonomy ) {
			$terms = get_terms(
				[
					'taxonomy'   => $taxonomy['taxonomy'],
					'hide_empty' => false,
				]
			);
			foreach ( $terms as $term ) {
				if ( (bool) wp_delete_term( $term->term_id, $taxonomy['taxonomy'] ) ) {
					$this->stats['taxonomy_terms']++;
				}
			}
		}
	}

	/**
	 * Deletes ACF taxonomies.
	 */
	public function delete_taxonomies(): void {
		$deleted = delete_acf_taxonomies();
		if ( $deleted ) {
			$this->stats['taxonomies'] = count( $this->taxonomies );
			$this->taxonomies          = [];
		}
	}

	/**
	 * Deletes published posts.
	 *
	 * @param bool $delete_all Pass true to also delete post types unrelated to
	 *                         ACF, such as core posts and pages.
	 */
	public function delete_posts( $delete_all = false ): void {
		if ( $delete_all ) {
			$posts = new \WP_Query(
				[
					'post_type'      => 'any',
					'posts_per_page' => -1,
				]
			);

			foreach ( $posts->posts as $post ) {
				if ( (bool) wp_delete_post( $post->ID, true ) ) {
					$this->stats['posts']++;
				}
			}
		}

		// Delete ACF-specific posts.
		foreach ( $this->posts as $post_ids ) {
			foreach ( $post_ids as $post_id ) {
				if ( (bool) wp_delete_post( $post_id, true ) ) {
					$this->stats['posts']++;
				}
			}
		}
	}

	/**
	 * Deletes media, including files and their database records.
	 *
	 * @param bool $delete_all Pass true to delete all media including files
	 *                         unrelated to ACF entries.
	 */
	public function delete_media( bool $delete_all = false ): void {
		if ( $delete_all ) {
			$media = new \WP_Query(
				[
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => -1,
				]
			);

			foreach ( $media->posts as $post ) {
				if ( (bool) wp_delete_attachment( $post->ID, true ) ) {
					$this->stats['media']++;
				}
			}

			return;
		}

		// Otherwise, just delete ACF-specific media.
		foreach ( $this->media as $media_id ) {
			if ( (bool) wp_delete_attachment( $media_id, true ) ) {
				$this->stats['media']++;
			}
		}
	}

	/**
	 * Deletes ACF custom post type schemas.
	 */
	public function delete_custom_post_types(): void {
		$deleted = delete_acf_custom_post_types();
		if ( $deleted ) {
			$this->stats['post_types'] = count( $this->post_types );
			$this->post_types          = [];
		}
	}

	/**
	 * Deletes all ACF field groups.
	 *
	 * @return void
	 */
	public function delete_field_groups(): void {
		$deleted = delete_acf_field_groups();
		if ( $deleted ) {
			$this->stats['field_groups'] = count( $this->field_groups );
			$this->field_groups          = [];
		}
	}

	/**
	 * Gets IDs of posts for all ACF models.
	 *
	 * ```
	 * [ 'cats' => [1, 2, 3], 'dogs' => [4, 5, 6] ]
	 * ```
	 *
	 * Model IDs are preserved so that `get_media_ids()` can check for media
	 * fields linked to that model and retrieve related media for each post.
	 */
	public function get_post_ids() : array {
		$posts = [];

		foreach ( $this->post_types as $post_type ) {
			$post_ids = get_posts(
				[
					'post_type'   => $post_type['post_type'],
					'numberposts' => -1,
					'fields'      => 'ids',
				]
			);

			$posts[ $post_type['post_type'] ] = $post_ids;
		}

		return $posts;
	}

	/**
	 * Gets all media IDs associated with ACF posts.
	 *
	 * Includes IDs stored in media fields as well as thumbnail_id.
	 */
	public function get_media_ids() : array {
		if ( ! function_exists( 'acf_is_field_key' ) ) {
			return [];
		}
		$media_ids = [];
		$posts     = new \WP_Query(
			[
				'post_type'      => array_keys( $this->posts ),
				'posts_per_page' => - 1,
				'fields'         => 'ids',
			]
		);
		$post_ids  = $posts->posts ?? [];

		foreach ( $post_ids as $post_id ) {
			$media_ids[] = get_post_meta( $post_id, '_thumbnail_id', true ) ?: false;

			$metas = get_post_meta( $post_id, '', true );
			foreach ( $metas as $meta_key => $meta_value ) {
				if ( ! acf_is_field_key( $meta_value[0] ) ) {
					continue;
				}

				$field = acf_get_field( $meta_value[0] );

				if ( isset( $field['type'] ) && $field['type'] === 'image' ) {
					$media_ids[] = get_post_meta( $post_id, $field['name'], true );
				}
			}
		}

		/**
		 * Flatten to prevent nesting of repeating media field IDs.
		 * We want a flat array with only unique media IDs at the top level.
		 */
		$media_ids_flattened = [];

		array_walk_recursive(
			$media_ids,
			function( $a ) use ( &$media_ids_flattened ) {
				$media_ids_flattened[] = $a;
			}
		);

		return array_filter( array_unique( $media_ids_flattened ) );
	}

	/**
	 * Gets media fields for the given `$post_type`.
	 *
	 * @param string $post_type The post type slug.
	 * @return array Media fields for the given post type.
	 */
	public function get_media_fields( string $post_type ) : array {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return [];
		}
		$field_groups = acf_get_field_groups( [ 'post_type' => $post_type ] );
		if ( empty( $field_groups ) ) {
			return [];
		}

		foreach ( $field_groups as $key => $field_group ) {
			if ( empty( $field_group['key'] ) ) {
				continue;
			}
			$field_groups[ $key ]['fields'] = acf_get_fields( $field_group['key'] );
		}

		$media_fields = [];
		foreach ( $field_groups as $field_group ) {
			foreach ( $field_group['fields'] as $field ) {
				if ( isset( $field['type'] ) && $field['type'] === 'image' ) {
					$media_fields[] = $field;
				}
			}
		}

		return wp_list_pluck( $media_fields, 'name' );
	}

	/**
	 * Logs stats about what was deleted.
	 */
	private function log_stats(): void {
		$stats_strings = [
			/* translators: 1: singular number of terms, 2: plural number of terms  */
			'taxonomy_terms' => sprintf( _n( '%s term', '%s terms', $this->stats['taxonomy_terms'], 'wpe-atlas-headless-extension' ), $this->stats['taxonomy_terms'] ),
			/* translators: 1: singular number of taxonomies, 2: plural number of taxonomies  */
			'taxonomies'     => sprintf( _n( '%s taxonomy', '%s taxonomies', $this->stats['taxonomies'], 'wpe-atlas-headless-extension' ), $this->stats['taxonomies'] ),
			/* translators: 1: singular number of field groups, 2: plural number of field groups  */
			'field_groups'   => sprintf( _n( '%s field group', '%s field groups', $this->stats['field_groups'], 'wpe-atlas-headless-extension' ), $this->stats['field_groups'] ),
			/* translators: 1: singular number of posts, 2: plural number of posts  */
			'posts'          => sprintf( _n( '%s post', '%s posts', $this->stats['posts'], 'wpe-atlas-headless-extension' ), $this->stats['posts'] ),
			/* translators: 1: singular number of media, 2: plural number of media  */
			'media'          => sprintf( _n( '%s media', '%s media', $this->stats['media'], 'wpe-atlas-headless-extension' ), $this->stats['media'] ),
			/* translators: 1: singular number of post types, 2: plural number of post types  */
			'post_types'     => sprintf( _n( '%s post type', '%s post types', $this->stats['post_types'], 'wpe-atlas-headless-extension' ), $this->stats['post_types'] ),
		];

		/**
		 * Puts highest counts first for friendlier output.
		 * "9 posts, 0 media, 0 models" instead of "0 media, 0 models, 9 posts”.
		 */
		arsort( $this->stats, SORT_NUMERIC );

		/**
		 * Replaces plain stat counts with the above strings, modifying the
		 * $stats class property in place.
		 * Input: `['posts' => 23, 'models' => 1]`
		 * Output: `['posts' => '23 posts', 'models' => '1 model']`
		 */
		array_walk(
			$this->stats,
			function( &$stat_value, $stat_key ) use ( $stats_strings ) {
				$stat_value = $stats_strings[ $stat_key ];
			}
		);

		\WP_CLI::log( 'Deleted: ' . join( ', ', $this->stats ) . '.' );
	}
}
