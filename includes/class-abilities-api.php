<?php
/**
 * Registers WordPress Abilities API entries for Waygate actions.
 *
 * @package Imagewize\Waygate
 */

namespace Imagewize\Waygate;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the waygate/list-patterns, waygate/get-pattern and waygate/create-page abilities.
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
	 * JSON schema for a list of {find, replace} pairs.
	 *
	 * @return array
	 */
	private static function pair_list_schema(): array {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'properties'           => array(
					'find'    => array( 'type' => 'string' ),
					'replace' => array( 'type' => 'string' ),
				),
				'required'             => array( 'find', 'replace' ),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Registers the abilities with the WordPress Abilities API.
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
			'waygate/get-pattern',
			array(
				'label'               => __( 'Get Pattern', 'waygate' ),
				'description'         => __( 'Returns one pattern: its block markup, the exact text strings waygate/create-page can replace ("texts"), its link targets ("links") and its numbered image slots ("images"). Use these strings verbatim as "find" values, and image slot numbers as "n" values in "images" pairs. Each text is one whole text run; a heading with inline emphasis has one text per run.', 'waygate' ),
				'category'            => 'waygate',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug' => array(
							'type'        => 'string',
							'description' => 'Pattern slug from waygate/list-patterns.',
						),
					),
					'required'             => array( 'slug' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'slug'        => array( 'type' => 'string' ),
						'title'       => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'categories'  => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'keywords'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'content'     => array( 'type' => 'string' ),
						'texts'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'links'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'images'      => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'url' => array( 'type' => 'string' ),
									'alt' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => function ( array $params ) {
					$pattern = Pattern_Lab::get_pattern( (string) $params['slug'] );

					return $pattern ?? new \WP_Error( 'unknown_pattern', 'Unknown pattern. Call waygate/list-patterns for valid slugs.' );
				},
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		wp_register_ability(
			'waygate/create-page',
			array(
				'label'               => __( 'Create Page from Patterns', 'waygate' ),
				'description'         => __( 'Creates a new WordPress page assembled from an ordered list of block patterns. Each item is a pattern slug, or an object with "pattern" plus "texts" and "links": lists of {find, replace} pairs whose find strings come verbatim from waygate/get-pattern, and "images": a list of {n, id} pairs filling the numbered image slots with media-library attachments. Replacements are plain text or URLs, never HTML. Returns warnings for find strings that matched nothing.', 'waygate' ),
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
							'items'       => array(
								'anyOf' => array(
									array( 'type' => 'string' ),
									array(
										'type'       => 'object',
										'properties' => array(
											'pattern' => array( 'type' => 'string' ),
											'texts'   => self::pair_list_schema(),
											'links'   => self::pair_list_schema(),
											'images'  => array(
												'type'  => 'array',
												'items' => array(
													'type' => 'object',
													'properties' => array(
														'n'  => array( 'type' => 'integer' ),
														'id' => array( 'type' => 'integer' ),
													),
													'required' => array( 'n', 'id' ),
													'additionalProperties' => false,
												),
											),
										),
										'required'   => array( 'pattern' ),
										'additionalProperties' => false,
									),
								),
							),
							'description' => 'Ordered list of pattern slugs or {pattern, texts, links} sections.',
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
					$warnings = array();
					$result   = Pattern_Lab::create_page(
						$params['title'],
						$params['patterns'],
						$params['status'] ?? 'draft',
						$warnings
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
						'warnings' => $warnings,
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
