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
}
