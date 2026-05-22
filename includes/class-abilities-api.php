<?php

namespace Imagewize\Waygate;

defined( 'ABSPATH' ) || exit;

class AbilitiesApi {

	public static function init(): void {
		add_action( 'wp_abilities_api_init', [ self::class, 'register_abilities' ] );
	}

	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'elayne/list-patterns',
			[
				'label'               => __( 'List Elayne Patterns', 'waygate' ),
				'description'         => __( 'Returns all available Elayne block patterns with slug, title, categories and keywords.', 'waygate' ),
				'category'            => 'content',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'category' => [
							'type'        => 'string',
							'description' => 'Optional category filter, e.g. "hero", "features", "cta".',
						],
					],
				],
				'output_schema'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'slug'       => [ 'type' => 'string' ],
							'title'      => [ 'type' => 'string' ],
							'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
							'keywords'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						],
					],
				],
				'execute_callback'    => function ( array $params = [] ): array {
					$patterns = PatternLab::get_patterns();

					if ( ! empty( $params['category'] ) ) {
						$cat      = 'elayne/' . sanitize_key( $params['category'] );
						$patterns = array_values(
							array_filter( $patterns, fn( $p ) => in_array( $cat, $p['categories'], true ) )
						);
					}

					return $patterns;
				},
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'meta'                => [ 'show_in_rest' => true ],
			]
		);

		wp_register_ability(
			'elayne/create-page',
			[
				'label'               => __( 'Create Page from Elayne Patterns', 'waygate' ),
				'description'         => __( 'Creates a new WordPress draft page assembled from Elayne block patterns.', 'waygate' ),
				'category'            => 'content',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'title'    => [
							'type'        => 'string',
							'description' => 'Page title.',
						],
						'patterns' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'Ordered list of Elayne pattern slugs.',
						],
						'status'   => [
							'type'    => 'string',
							'enum'    => [ 'draft', 'publish' ],
							'default' => 'draft',
						],
					],
					'required'   => [ 'title', 'patterns' ],
				],
				'execute_callback'    => function ( array $params ): array {
					$result = PatternLab::create_page(
						$params['title'],
						$params['patterns'],
						$params['status'] ?? 'draft'
					);

					if ( is_wp_error( $result ) ) {
						return [ 'success' => false, 'error' => $result->get_error_message() ];
					}

					return [
						'success'  => true,
						'page_id'  => $result,
						'edit_url' => get_edit_post_link( $result, 'raw' ),
						'view_url' => get_permalink( $result ),
					];
				},
				'permission_callback' => fn() => current_user_can( 'publish_pages' ),
				'meta'                => [ 'show_in_rest' => true ],
			]
		);
	}
}
