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
		register_rest_route(
			self::NAMESPACE,
			'/generate/select',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'generate_select' ),
				'permission_callback' => fn() => current_user_can( 'publish_pages' ),
				'args'                => array(
					'description' => array(
						'type'              => 'string',
						'description'       => 'Natural-language description of the desired page.',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'vertical'    => array(
						'type'              => 'string',
						'description'       => 'Optional vertical slug limiting the offered patterns.',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/generate/pairs',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'generate_pairs' ),
				'permission_callback' => fn() => current_user_can( 'publish_pages' ),
				'args'                => array(
					'description' => array(
						'type'              => 'string',
						'description'       => 'Natural-language description of the desired page.',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'slug'        => array(
						'type'              => 'string',
						'description'       => 'The pattern slug whose texts should be rewritten.',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'language'    => array(
						'type'              => 'string',
						'description'       => 'Language to write in, e.g. "English"; empty leaves it to the model.',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/generate/images',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'generate_images' ),
				'permission_callback' => fn() => current_user_can( 'publish_pages' ),
				'args'                => array(
					'description' => array(
						'type'              => 'string',
						'description'       => 'Natural-language description of the desired page.',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'slug'        => array(
						'type'              => 'string',
						'description'       => 'The pattern slug whose image slots should be filled.',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'ids'         => array(
						'type'        => 'array',
						'description' => 'Media-library attachment IDs the page may use.',
						'required'    => true,
						'items'       => array( 'type' => 'integer' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/generate/build',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'generate_build' ),
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
					'title'        => array(
						'type'              => 'string',
						'description'       => 'Page title.',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'sections'     => array(
						'type'        => 'array',
						'description' => 'Ordered sections: pattern slugs or {pattern, texts, links} objects.',
						'required'    => true,
					),
					'slugs'        => array(
						'type'        => 'array',
						'description' => 'Ordered pattern slugs, stored as page meta.',
						'required'    => false,
						'items'       => array( 'type' => 'string' ),
					),
					'reasoning'    => array(
						'type'              => 'string',
						'description'       => "The AI's pattern-choice explanation, stored as page meta.",
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'personalized' => array(
						'type'        => 'boolean',
						'description' => 'Whether the sections carry rewritten texts.',
						'required'    => false,
					),
					'template'     => array(
						'type'              => 'string',
						'description'       => 'Optional page template slug, e.g. "page-no-title".',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
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

	/**
	 * POST /waygate/v1/generate/select
	 *
	 * The first step of stepwise generation: one AI call that picks the patterns. Kept as its
	 * own request so it finishes below web-server upstream read timeouts (nginx defaults to
	 * 60 seconds); the rewrite and build steps run as their own requests.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function generate_select( \WP_REST_Request $request ): \WP_REST_Response {
		$result = AI_Integration::select_patterns(
			$request->get_param( 'description' ),
			(string) $request->get_param( 'vertical' )
		);

		return rest_ensure_response( $result );
	}

	/**
	 * POST /waygate/v1/generate/pairs
	 *
	 * One rewrite step: rewrites one pattern's texts. Uses the step timeout
	 * (waygate_ai_step_timeout, default 45 seconds) so the request stays below web-server
	 * upstream read timeouts. A pattern with no rewritable texts returns an unpersonalized
	 * section instead of an error.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function generate_pairs( \WP_REST_Request $request ) {
		$slug = sanitize_text_field( (string) $request->get_param( 'slug' ) );

		$registered = array_column( Pattern_Lab::get_patterns(), 'slug' );

		if ( ! in_array( $slug, $registered, true ) ) {
			return new \WP_Error(
				'unknown_pattern',
				'That pattern is not registered.',
				array( 'status' => 400 )
			);
		}

		$section = AI_Integration::personalize_sections(
			array( $slug ),
			$request->get_param( 'description' ),
			(string) $request->get_param( 'language' ),
			AI_Integration::step_timeout()
		);

		if ( is_wp_error( $section ) ) {
			if ( 'no_texts' === $section->get_error_code() ) {
				return rest_ensure_response( array( 'section' => array( 'pattern' => $slug ) ) );
			}

			return new \WP_Error(
				$section->get_error_code(),
				$section->get_error_message(),
				array( 'status' => 502 )
			);
		}

		return rest_ensure_response( array( 'section' => $section[0] ) );
	}

	/**
	 * POST /waygate/v1/generate/images
	 *
	 * One image step: the AI decides which of the user's media-library pictures fill this
	 * pattern's image slots. Uses the step timeout like the pairs step, so it stays below
	 * web-server upstream read timeouts. Slots nothing fits keep the pattern's original
	 * image; a pattern with no image slots returns an unpersonalized section.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function generate_images( \WP_REST_Request $request ) {
		$slug = sanitize_text_field( (string) $request->get_param( 'slug' ) );

		if ( ! in_array( $slug, array_column( Pattern_Lab::get_patterns(), 'slug' ), true ) ) {
			return new \WP_Error(
				'unknown_pattern',
				'That pattern is not registered.',
				array( 'status' => 400 )
			);
		}

		$description = (string) $request->get_param( 'description' );

		$attachments = array();
		$seen        = array();
		foreach ( array_map( 'absint', (array) $request->get_param( 'ids' ) ) as $id ) {
			$post = $id > 0 ? get_post( $id ) : null;

			if ( ! $post || 'attachment' !== $post->post_type || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;

			$url = Pattern_Lab::attachment_image_url( $id );

			if ( '' !== $url ) {
				$attachments[] = array(
					'id'  => $id,
					'url' => $url,
					'alt' => Pattern_Lab::attachment_alt( $id ),
				);
			}
		}

		if ( empty( $attachments ) ) {
			return new \WP_Error(
				'no_images',
				'No valid media-library images were provided.',
				array( 'status' => 400 )
			);
		}

		$section = AI_Integration::assign_images( $slug, $description, $attachments, AI_Integration::step_timeout() );

		if ( is_wp_error( $section ) ) {
			if ( 'no_images' === $section->get_error_code() ) {
				return rest_ensure_response( array( 'section' => array( 'pattern' => $slug ) ) );
			}

			return new \WP_Error(
				$section->get_error_code(),
				$section->get_error_message(),
				array( 'status' => 502 )
			);
		}

		return rest_ensure_response( array( 'section' => $section ) );
	}

	/**
	 * POST /waygate/v1/generate/build
	 *
	 * The final step of stepwise generation: creates the draft from the collected sections
	 * (no AI call) and stores the generation meta.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function generate_build( \WP_REST_Request $request ) {
		$warnings = array();
		$post_id  = Pattern_Lab::create_page(
			$request->get_param( 'title' ),
			(array) $request->get_param( 'sections' ),
			'draft',
			$warnings,
			(string) $request->get_param( 'template' )
		);

		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error(
				$post_id->get_error_code(),
				$post_id->get_error_message(),
				array( 'status' => 422 )
			);
		}

		AI_Integration::record_page_meta(
			$post_id,
			! empty( $request->get_param( 'slugs' ) ) ? (array) $request->get_param( 'slugs' ) : array(),
			(string) $request->get_param( 'reasoning' ),
			! empty( $request->get_param( 'personalized' ) )
		);

		return rest_ensure_response(
			array(
				'page_id'  => $post_id,
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
				'view_url' => get_permalink( $post_id ),
				'warnings' => $warnings,
			)
		);
	}
}
