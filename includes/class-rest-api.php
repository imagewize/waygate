<?php
/**
 * REST API endpoints for remote pattern listing and page creation.
 *
 * @package Imagewize\Waygate
 */

namespace Imagewize\Waygate;

defined( 'ABSPATH' ) || exit;

/**
 * Registers /waygate/v1/patterns and /waygate/v1/pages REST endpoints.
 */
class Rest_API {

	const NAMESPACE  = 'waygate/v1';
	const RATE_LIMIT = 10; // Max page-creation requests per user per 60 seconds.

	/**
	 * Registers the rest_api_init hook.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registers all Waygate REST routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/patterns',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_patterns' ),
				'permission_callback' => fn() => current_user_can( 'edit_posts' ),
				'args'                => array(
					'category' => array(
						'type'              => 'string',
						'description'       => 'Filter by category slug, e.g. "hero", "features", "cta".',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/pages',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'create_page' ),
				'permission_callback' => function () {
					if ( ! current_user_can( 'publish_pages' ) ) {
						return false;
					}
					if ( self::is_rate_limited() ) {
						return new \WP_Error(
							'rate_limited',
							'Too many requests. Try again in a minute.',
							array( 'status' => 429 )
						);
					}
					return true;
				},
				'args'                => array(
					'title'    => array(
						'type'              => 'string',
						'description'       => 'Page title.',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'patterns' => array(
						'type'        => 'array',
						'description' => 'Ordered list of pattern slugs.',
						'required'    => true,
						'items'       => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * GET /waygate/v1/patterns
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function get_patterns( \WP_REST_Request $request ): \WP_REST_Response {
		$patterns = Pattern_Lab::get_patterns();

		$category = $request->get_param( 'category' );
		if ( ! empty( $category ) ) {
			$cat_slug = 'elayne/' . $category;
			$patterns = array_values(
				array_filter( $patterns, fn( $p ) => in_array( $cat_slug, $p['categories'], true ) )
			);
		}

		return rest_ensure_response( $patterns );
	}

	/**
	 * Returns true if the current user has exceeded the page-creation rate limit.
	 * Uses a per-user transient counter with a 60-second sliding window.
	 */
	public static function is_rate_limited(): bool {
		$key   = 'waygate_rl_' . get_current_user_id();
		$count = (int) get_transient( $key );
		$limit = apply_filters( 'waygate_rate_limit', self::RATE_LIMIT );

		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $key, $count + 1, 60 );
		return false;
	}

	/**
	 * POST /waygate/v1/pages
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_page( \WP_REST_Request $request ) {
		$result = Pattern_Lab::create_page(
			$request->get_param( 'title' ),
			(array) $request->get_param( 'patterns' ),
			'draft'
		);

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 422 )
			);
		}

		return rest_ensure_response(
			array(
				'page_id'  => $result,
				'edit_url' => get_edit_post_link( $result, 'raw' ),
				'view_url' => get_permalink( $result ),
			)
		);
	}
}
