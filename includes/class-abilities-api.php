<?php
/**
 * Registers WordPress Abilities API entries for Waygate actions.
 *
 * @package Imagewize\Waygate
 */

namespace Imagewize\Waygate;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the elayne/list-patterns and elayne/create-page abilities.
 */
class Abilities_API {

	/**
	 * Hooks into the Abilities API init action.
	 */
	public static function init(): void {
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Registers both abilities with the WordPress Abilities API.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'elayne/list-patterns',
			array(
				'label'               => __( 'List Elayne Patterns', 'waygate' ),
				'description'         => __( 'Returns all available Elayne block patterns with slug, title, categories and keywords.', 'waygate' ),
				'category'            => 'content',
				'input_schema'        => array(
					'type'       => 'object',
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
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);

		wp_register_ability(
			'elayne/create-page',
			array(
				'label'               => __( 'Create Page from Elayne Patterns', 'waygate' ),
				'description'         => __( 'Creates a new WordPress draft page assembled from Elayne block patterns.', 'waygate' ),
				'category'            => 'content',
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
					'show_in_rest' => true,
					'annotations'  => array( 'idempotent' => true ),
				),
			)
		);
	}
}
