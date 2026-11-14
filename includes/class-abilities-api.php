<?php
/**
 * Registers WordPress Abilities API entries for Waygate actions.
 *
 * @package Imagewize\Waygate
 */

namespace Imagewize\Waygate;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the waygate/list-patterns and waygate/create-page abilities.
 *
 * Until 1.1.0 these were named elayne/* and assigned to a "content" category that
 * nothing registers, so WordPress rejected both with a _doing_it_wrong notice and
 * they never existed. Renaming them breaks no client for that reason.
 */
class Abilities_API {

	/**
	 * Hooks into the Abilities API init action.
	 */
	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Registers the ability category. An ability whose category is not registered is
	 * refused by the registry, so this must run on the categories hook, which fires first.
	 */
	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'waygate',
			array(
				'label'       => __( 'Waygate', 'waygate' ),
				'description' => __( 'Build pages from registered block patterns.', 'waygate' ),
			)
		);
	}

	/**
	 * Registers both abilities with the WordPress Abilities API.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'waygate/list-patterns',
			array(
				'label'               => __( 'List Patterns', 'waygate' ),
				'description'         => __( 'Returns the block patterns Waygate can build pages from, with slug, title, categories and keywords.', 'waygate' ),
				'category'            => 'waygate',
				'input_schema'        => array(
					'type'       => 'object',
					'default'    => array(),
					'properties' => array(
						'category' => array(
							'type'        => 'string',
							'description' => 'Optional category filter, e.g. "hero", "features", "cta".',
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'slug'       => array( 'type' => 'string' ),
							'title'      => array( 'type' => 'string' ),
							'categories' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
							'keywords'   => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
					),
				),
				'execute_callback'    => function ( array $params = array() ): array {
					$patterns = Pattern_Lab::get_patterns();

					if ( ! empty( $params['category'] ) ) {
						$cat      = 'elayne/' . sanitize_key( $params['category'] );
						$patterns = array_values(
							array_filter( $patterns, fn( $p ) => in_array( $cat, $p['categories'], true ) )
						);
					}

					return $patterns;
				},
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);

		wp_register_ability(
			'waygate/create-page',
			array(
				'label'               => __( 'Create Page from Patterns', 'waygate' ),
				'description'         => __( 'Creates a new WordPress page assembled from an ordered list of block patterns.', 'waygate' ),
				'category'            => 'waygate',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'    => array(
							'type'        => 'string',
							'description' => 'Page title.',
						),
						'patterns' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Ordered list of Elayne pattern slugs.',
						),
						'status'   => array(
							'type'    => 'string',
							'enum'    => array( 'draft', 'publish' ),
							'default' => 'draft',
						),
					),
					'required'   => array( 'title', 'patterns' ),
				),
				'execute_callback'    => function ( array $params ): array {
					$result = Pattern_Lab::create_page(
						$params['title'],
						$params['patterns'],
						$params['status'] ?? 'draft'
					);

					if ( is_wp_error( $result ) ) {
						return array(
							'success' => false,
							'error'   => $result->get_error_message(),
						);
					}

					return array(
						'success'  => true,
						'page_id'  => $result,
						'edit_url' => get_edit_post_link( $result, 'raw' ),
						'view_url' => get_permalink( $result ),
					);
				},
				'permission_callback' => fn() => current_user_can( 'publish_pages' ),
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => true,
					'annotations'  => array( 'idempotent' => true ),
				),
			)
		);
	}
}
