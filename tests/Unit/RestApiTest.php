<?php

namespace Imagewize\Waygate\Tests\Unit;

use Imagewize\Waygate\Rest_API;
use PHPUnit\Framework\TestCase;
use WP_Block_Patterns_Registry;
use WP_Error;
use WP_REST_Request;

class RestApiTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_Block_Patterns_Registry::get_instance()->reset();
		$GLOBALS['wp_filters']     = [];
		$GLOBALS['wp_rest_routes'] = [];
		$GLOBALS['wp_transients']  = [];
	}

	private function register( string $slug, string $title = 'Test Pattern', array $categories = [] ): void {
		WP_Block_Patterns_Registry::get_instance()->register(
			[
				'slug'        => $slug,
				'title'       => $title,
				'description' => '',
				'categories'  => $categories,
				'keywords'    => [],
			]
		);
	}

	private function request( array $params ): WP_REST_Request {
		return new WP_REST_Request( $params );
	}

	// --- register_routes() ---

	public function test_register_routes_registers_patterns_endpoint(): void {
		Rest_API::register_routes();

		$this->assertArrayHasKey( 'waygate/v1/patterns', $GLOBALS['wp_rest_routes'] );
	}

	public function test_register_routes_registers_pages_endpoint(): void {
		Rest_API::register_routes();

		$this->assertArrayHasKey( 'waygate/v1/pages', $GLOBALS['wp_rest_routes'] );
	}

	public function test_patterns_endpoint_uses_get_method(): void {
		Rest_API::register_routes();

		$this->assertSame( 'GET', $GLOBALS['wp_rest_routes']['waygate/v1/patterns']['methods'] );
	}

	public function test_pages_endpoint_uses_post_method(): void {
		Rest_API::register_routes();

		$this->assertSame( 'POST', $GLOBALS['wp_rest_routes']['waygate/v1/pages']['methods'] );
	}

	// --- get_patterns() ---

	public function test_get_patterns_returns_all_registered_patterns(): void {
		$this->register( 'elayne/hero' );
		$this->register( 'elayne/cta' );

		$data = Rest_API::get_patterns( $this->request( [] ) )->get_data();

		$this->assertCount( 2, $data );
	}

	public function test_get_patterns_returns_empty_array_when_no_patterns(): void {
		$data = Rest_API::get_patterns( $this->request( [] ) )->get_data();

		$this->assertSame( [], $data );
	}

	public function test_get_patterns_filters_by_category(): void {
		$this->register( 'elayne/hero', 'Hero', [ 'elayne/header' ] );
		$this->register( 'elayne/cta', 'CTA', [ 'elayne/footer' ] );

		$data = Rest_API::get_patterns( $this->request( [ 'category' => 'header' ] ) )->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'elayne/hero', $data[0]['slug'] );
	}

	public function test_get_patterns_returns_empty_for_unknown_category(): void {
		$this->register( 'elayne/hero', 'Hero', [ 'elayne/header' ] );

		$data = Rest_API::get_patterns( $this->request( [ 'category' => 'nonexistent' ] ) )->get_data();

		$this->assertSame( [], $data );
	}

	public function test_get_patterns_without_category_param_returns_all(): void {
		$this->register( 'elayne/hero' );
		$this->register( 'elayne/features' );

		$data = Rest_API::get_patterns( $this->request( [ 'category' => null ] ) )->get_data();

		$this->assertCount( 2, $data );
	}

	// --- create_page() ---

	public function test_create_page_returns_page_id_on_success(): void {
		$this->register( 'elayne/hero' );

		$data = Rest_API::create_page(
			$this->request( [ 'title' => 'My Page', 'patterns' => [ 'elayne/hero' ] ] )
		)->get_data();

		$this->assertArrayHasKey( 'page_id', $data );
		$this->assertIsInt( $data['page_id'] );
	}

	public function test_create_page_returns_edit_url_on_success(): void {
		$this->register( 'elayne/hero' );

		$data = Rest_API::create_page(
			$this->request( [ 'title' => 'My Page', 'patterns' => [ 'elayne/hero' ] ] )
		)->get_data();

		$this->assertArrayHasKey( 'edit_url', $data );
		$this->assertStringContainsString( 'action=edit', $data['edit_url'] );
	}

	public function test_create_page_returns_view_url_on_success(): void {
		$this->register( 'elayne/hero' );

		$data = Rest_API::create_page(
			$this->request( [ 'title' => 'My Page', 'patterns' => [ 'elayne/hero' ] ] )
		)->get_data();

		$this->assertArrayHasKey( 'view_url', $data );
		$this->assertNotEmpty( $data['view_url'] );
	}

	public function test_create_page_returns_wp_error_for_no_valid_patterns(): void {
		$result = Rest_API::create_page(
			$this->request( [ 'title' => 'My Page', 'patterns' => [ 'nonexistent/slug' ] ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_valid_patterns', $result->get_error_code() );
	}

	public function test_create_page_error_carries_422_http_status(): void {
		$result = Rest_API::create_page(
			$this->request( [ 'title' => 'My Page', 'patterns' => [ 'nonexistent/slug' ] ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	// --- is_rate_limited() ---

	public function test_is_rate_limited_returns_false_under_limit(): void {
		$this->assertFalse( Rest_API::is_rate_limited() );
	}

	public function test_is_rate_limited_increments_transient_counter(): void {
		Rest_API::is_rate_limited();
		Rest_API::is_rate_limited();

		$this->assertSame( 2, $GLOBALS['wp_transients']['waygate_rl_1'] );
	}

	public function test_is_rate_limited_returns_true_at_limit(): void {
		$GLOBALS['wp_transients']['waygate_rl_1'] = 10;

		$this->assertTrue( Rest_API::is_rate_limited() );
	}

	public function test_is_rate_limited_returns_true_above_limit(): void {
		$GLOBALS['wp_transients']['waygate_rl_1'] = 99;

		$this->assertTrue( Rest_API::is_rate_limited() );
	}

	public function test_rate_limit_is_filterable(): void {
		add_filter( 'waygate_rate_limit', fn() => 2 );

		Rest_API::is_rate_limited(); // count → 1
		Rest_API::is_rate_limited(); // count → 2, now at limit
		$result = Rest_API::is_rate_limited(); // should be blocked

		remove_all_filters( 'waygate_rate_limit' );

		$this->assertTrue( $result );
	}

	// --- Stepwise generation endpoints (each AI call below server timeouts) ---

	public function test_register_routes_registers_generate_select(): void {
		Rest_API::register_routes();

		$this->assertArrayHasKey( 'waygate/v1/generate/select', $GLOBALS['wp_rest_routes'] );
	}

	public function test_register_routes_registers_generate_pairs(): void {
		Rest_API::register_routes();

		$this->assertArrayHasKey( 'waygate/v1/generate/pairs', $GLOBALS['wp_rest_routes'] );
	}

	public function test_register_routes_registers_generate_build(): void {
		Rest_API::register_routes();

		$this->assertArrayHasKey( 'waygate/v1/generate/build', $GLOBALS['wp_rest_routes'] );
	}

	public function test_generate_endpoints_use_post_method(): void {
		Rest_API::register_routes();

		$this->assertSame( 'POST', $GLOBALS['wp_rest_routes']['waygate/v1/generate/select']['methods'] );
		$this->assertSame( 'POST', $GLOBALS['wp_rest_routes']['waygate/v1/generate/pairs']['methods'] );
		$this->assertSame( 'POST', $GLOBALS['wp_rest_routes']['waygate/v1/generate/images']['methods'] );
		$this->assertSame( 'POST', $GLOBALS['wp_rest_routes']['waygate/v1/generate/build']['methods'] );
	}

	public function test_register_routes_registers_generate_images(): void {
		Rest_API::register_routes();

		$this->assertArrayHasKey( 'waygate/v1/generate/images', $GLOBALS['wp_rest_routes'] );
	}

	public function test_generate_images_rejects_unregistered_slug(): void {
		$result = Rest_API::generate_images(
			$this->request( [ 'description' => 'Day spa', 'slug' => 'elayne/unknown', 'ids' => [ 5 ] ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unknown_pattern', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_generate_images_rejects_missing_or_invalid_ids(): void {
		$this->register( 'elayne/hero' );

		$result = Rest_API::generate_images(
			$this->request( [ 'description' => 'Day spa', 'slug' => 'elayne/hero', 'ids' => [ 999 ] ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_images', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_generate_images_returns_plain_section_when_pattern_has_no_images(): void {
		$this->register( 'elayne/hero' );
		$this->add_attachment_helper( 5, 'https://example.test/uploads/team.webp', 'Photo of the team' );

		$data = Rest_API::generate_images(
			$this->request( [ 'description' => 'Day spa', 'slug' => 'elayne/hero', 'ids' => [ 5 ] ] )
		)->get_data();

		$this->assertSame( array( 'section' => array( 'pattern' => 'elayne/hero' ) ), $data );
	}

	public function test_generate_images_reports_ai_failure(): void {
		WP_Block_Patterns_Registry::get_instance()->register(
			[
				'slug'    => 'elayne/hero',
				'title'   => 'Hero',
				'content' => '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.test/hero.webp" alt="Hero"/></figure><!-- /wp:image -->',
			]
		);
		$this->add_attachment_helper( 5, 'https://example.test/uploads/team.webp', 'Photo of the team' );

		$result = Rest_API::generate_images(
			$this->request( [ 'description' => 'Day spa', 'slug' => 'elayne/hero', 'ids' => [ 5 ] ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ai_failed', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	private function add_attachment_helper( int $id, string $url, string $alt ): void {
		$GLOBALS['wp_attachments'][ $id ] = $url;
		$GLOBALS['wp_postmeta'][ $id ]['_wp_attachment_image_alt'] = $alt;
		$GLOBALS['wp_posts'][ $id ] = [ 'post_type' => 'attachment' ];
	}

	public function test_generate_pairs_rejects_unregistered_slug(): void {
		$result = Rest_API::generate_pairs(
			$this->request( [ 'description' => 'Bookstore', 'slug' => 'elayne/unknown' ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unknown_pattern', $result->get_error_code() );
	}

	public function test_generate_pairs_returns_plain_section_when_no_texts(): void {
		$this->register( 'elayne/hero' );

		$data = Rest_API::generate_pairs(
			$this->request( [ 'description' => 'Bookstore', 'slug' => 'elayne/hero' ] )
		)->get_data();

		$this->assertSame( array( 'section' => array( 'pattern' => 'elayne/hero' ) ), $data );
	}

	public function test_generate_build_creates_page_with_sections(): void {
		$this->register( 'elayne/hero' );

		$data = Rest_API::generate_build(
			$this->request( [ 'title' => 'My Page', 'sections' => array( 'elayne/hero' ) ] )
		)->get_data();

		$this->assertArrayHasKey( 'page_id', $data );
		$this->assertIsInt( $data['page_id'] );
		$this->assertSame( 'My Page', $GLOBALS['wp_last_inserted_post']['post_title'] );
	}

	public function test_generate_build_records_pattern_and_reasoning_meta(): void {
		$this->register( 'elayne/hero' );

		$data = Rest_API::generate_build(
			$this->request( array(
				'title'       => 'My Page',
				'sections'    => array( 'elayne/hero' ),
				'slugs'       => array( 'elayne/hero' ),
				'reasoning'   => 'Fits a bookstore',
				'personalized' => true,
			) )
		)->get_data();

		$meta = $GLOBALS['wp_postmeta'][ $data['page_id'] ];

		$this->assertSame( 'Fits a bookstore', $meta['_waygate_reasoning'] );
		$this->assertSame( wp_json_encode( array( 'elayne/hero' ) ), $meta['_waygate_patterns'] );
		$this->assertSame( '1', $meta['_waygate_personalized'] );
	}

	public function test_generate_build_applies_template_meta(): void {
		$this->register( 'elayne/hero' );

		Rest_API::generate_build(
			$this->request( [ 'title' => 'My Page', 'sections' => array( 'elayne/hero' ), 'template' => 'page-no-title.php' ] )
		);

		$this->assertSame( 'page-no-title.php', $GLOBALS['wp_postmeta'][1]['_wp_page_template'] ?? null );
	}
}
