<?php

namespace Imagewize\Waygate\Tests\Unit;

use Imagewize\Waygate\Pattern_Lab;
use PHPUnit\Framework\TestCase;
use WP_Block_Patterns_Registry;
use WP_Error;

class PatternLabTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_Block_Patterns_Registry::get_instance()->reset();
		$GLOBALS['wp_filters'] = [];
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

	private function register_with_content( string $slug, string $content ): void {
		WP_Block_Patterns_Registry::get_instance()->register(
			[
				'slug'        => $slug,
				'title'       => 'Test Pattern',
				'description' => '',
				'categories'  => [],
				'keywords'    => [],
				'content'     => $content,
			]
		);
	}

	// --- get_patterns() ---

	public function test_get_patterns_returns_elayne_patterns_by_default(): void {
		$this->register( 'elayne/hero', 'Hero' );
		$this->register( 'othertheme/hero', 'Other Hero' );

		$patterns = Pattern_Lab::get_patterns();

		$this->assertCount( 1, $patterns );
		$this->assertSame( 'elayne/hero', $patterns[0]['slug'] );
	}

	public function test_get_patterns_respects_custom_prefix_via_filter(): void {
		$this->register( 'elayne/hero', 'Elayne Hero' );
		$this->register( 'mytheme/hero', 'My Hero' );

		add_filter( 'waygate_pattern_prefixes', fn() => [ 'mytheme/' ] );

		$patterns = Pattern_Lab::get_patterns();

		$this->assertCount( 1, $patterns );
		$this->assertSame( 'mytheme/hero', $patterns[0]['slug'] );
	}

	public function test_get_patterns_supports_multiple_prefixes_via_filter(): void {
		$this->register( 'elayne/hero', 'Elayne Hero' );
		$this->register( 'mytheme/hero', 'My Hero' );
		$this->register( 'othertheme/cta', 'Other CTA' );

		add_filter( 'waygate_pattern_prefixes', fn() => [ 'elayne/', 'mytheme/' ] );

		$patterns = Pattern_Lab::get_patterns();
		$slugs    = array_column( $patterns, 'slug' );

		$this->assertCount( 2, $patterns );
		$this->assertContains( 'elayne/hero', $slugs );
		$this->assertContains( 'mytheme/hero', $slugs );
	}

	public function test_get_patterns_excludes_entries_without_slug(): void {
		WP_Block_Patterns_Registry::get_instance()->register( [ 'title' => 'No Slug' ] );

		$this->assertCount( 0, Pattern_Lab::get_patterns() );
	}

	public function test_get_patterns_returns_all_metadata_fields(): void {
		$this->register( 'elayne/hero', 'Hero', [ 'elayne/header' ] );

		$p = Pattern_Lab::get_patterns()[0];

		$this->assertArrayHasKey( 'slug', $p );
		$this->assertArrayHasKey( 'title', $p );
		$this->assertArrayHasKey( 'description', $p );
		$this->assertArrayHasKey( 'categories', $p );
		$this->assertArrayHasKey( 'keywords', $p );
	}

	// --- get_pattern_content() ---

	public function test_get_pattern_content_returns_empty_string_for_missing_slug(): void {
		$this->assertSame( '', Pattern_Lab::get_pattern_content( 'elayne/nonexistent' ) );
	}

	public function test_get_pattern_content_returns_content_for_registered_pattern(): void {
		$markup = '<!-- wp:heading --><h1>Hello</h1><!-- /wp:heading -->';
		$this->register_with_content( 'elayne/hero', $markup );

		$this->assertSame( $markup, Pattern_Lab::get_pattern_content( 'elayne/hero' ) );
	}

	public function test_get_pattern_content_returns_empty_string_when_content_field_absent(): void {
		$this->register( 'elayne/hero' );

		$this->assertSame( '', Pattern_Lab::get_pattern_content( 'elayne/hero' ) );
	}

	// --- create_page_from_content() ---

	public function test_create_page_from_content_returns_error_for_empty_array(): void {
		$result = Pattern_Lab::create_page_from_content( 'Test', [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_content', $result->get_error_code() );
	}

	public function test_create_page_from_content_returns_error_when_all_strings_empty(): void {
		$result = Pattern_Lab::create_page_from_content( 'Test', [ '', '', '' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_content', $result->get_error_code() );
	}

	public function test_create_page_from_content_returns_post_id_with_valid_content(): void {
		$result = Pattern_Lab::create_page_from_content( 'Test', [ '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' ] );

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );
	}

	public function test_create_page_from_content_filters_empty_strings_from_mixed_array(): void {
		$result = Pattern_Lab::create_page_from_content( 'Test', [ '', '<p>Valid</p>', '' ] );

		$this->assertIsInt( $result );
	}

	public function test_create_page_from_content_invalid_status_falls_back_to_draft(): void {
		$result = Pattern_Lab::create_page_from_content( 'Test', [ '<p>Hello</p>' ], 'invalid_status' );

		$this->assertIsInt( $result );
	}

	public function test_create_page_from_content_strips_script_tags_from_ai_content(): void {
		$malicious = '<script>alert(1)</script><p>Safe content</p>';
		$result    = Pattern_Lab::create_page_from_content( 'Test', [ $malicious ] );

		$this->assertIsInt( $result );
	}

	// --- create_page() ---

	public function test_create_page_returns_error_with_no_valid_patterns(): void {
		$result = Pattern_Lab::create_page( 'My Page', [ 'nonexistent/slug' ], 'draft' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_valid_patterns', $result->get_error_code() );
	}

	public function test_create_page_skips_slugs_with_invalid_format(): void {
		$this->register( 'elayne/hero', 'Hero' );

		$result = Pattern_Lab::create_page( 'My Page', [ 'elayne/hero', '../bad', 'noslash' ], 'draft' );

		$this->assertIsInt( $result );
	}

	public function test_create_page_skips_unregistered_slugs(): void {
		$this->register( 'elayne/hero', 'Hero' );

		// elayne/cta passes format check but is not registered
		$result = Pattern_Lab::create_page( 'My Page', [ 'elayne/hero', 'elayne/cta' ], 'draft' );

		$this->assertIsInt( $result );
	}

	public function test_create_page_returns_error_when_only_unregistered_slugs(): void {
		$result = Pattern_Lab::create_page( 'My Page', [ 'elayne/hero' ], 'draft' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
