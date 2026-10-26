<?php

namespace Imagewize\Waygate\Tests\Unit;

use Imagewize\Waygate\Pattern_Lab;
use PHPUnit\Framework\TestCase;
use WP_Block_Patterns_Registry;
use WP_Error;

class PageBuildTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_Block_Patterns_Registry::get_instance()->reset();
		$GLOBALS['wp_filters'] = [];
	}

	private function register( string $slug, array $categories = [], array $extra = [] ): void {
		WP_Block_Patterns_Registry::get_instance()->register(
			array_merge(
				[
					'slug'        => $slug,
					'title'       => $slug,
					'description' => '',
					'categories'  => $categories,
					'keywords'    => [],
				],
				$extra
			)
		);
	}

	// --- expand_pattern_references() ---

	public function test_expand_inlines_nested_references(): void {
		$this->register( 'elayne/cta', [], [ 'content' => '<p>CTA</p>' ] );
		$this->register( 'elayne/page', [], [ 'content' => "<p>Top</p>\n<!-- wp:pattern {\"slug\":\"elayne/cta\"} /-->" ] );

		$this->assertSame( "<p>Top</p>\n<p>CTA</p>", Pattern_Lab::expand_pattern_references( '<!-- wp:pattern {"slug":"elayne/page"} /-->' ) );
	}

	public function test_expand_leaves_unknown_references(): void {
		$ref = '<!-- wp:pattern {"slug":"elayne/missing"} /-->';
		$this->assertSame( $ref, Pattern_Lab::expand_pattern_references( $ref ) );
	}

	public function test_expand_stops_on_self_reference(): void {
		$self = '<!-- wp:pattern {"slug":"elayne/loop"} /-->';
		$this->register( 'elayne/loop', [], [ 'content' => $self ] );

		$this->assertSame( $self, Pattern_Lab::expand_pattern_references( $self ) );
	}

	// --- build_content() ---

	public function test_build_content_inlines_in_order(): void {
		$this->register( 'elayne/a', [], [ 'content' => '<p>A</p>' ] );
		$this->register( 'elayne/b', [], [ 'content' => '<p>B</p>' ] );

		$this->assertSame( "<p>B</p>\n\n<p>A</p>", Pattern_Lab::build_content( [ 'elayne/b', 'elayne/a' ] ) );
	}

	public function test_build_content_emits_references_when_not_expanding(): void {
		$this->register( 'elayne/a', [], [ 'content' => '<p>A</p>' ] );

		$this->assertSame( '<!-- wp:pattern {"slug":"elayne/a"} /-->', Pattern_Lab::build_content( [ 'elayne/a' ], false ) );
	}

	public function test_build_content_rejects_unknown_slug(): void {
		$this->register( 'elayne/a', [], [ 'content' => '<p>A</p>' ] );

		$result = Pattern_Lab::build_content( [ 'elayne/a', 'elayne/nope' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'elayne/nope', $result->get_error_message() );
	}

	// --- backslashes survive wp_insert_post() unslashing ---

	public function test_create_page_from_content_keeps_backslashes(): void {
		$markup = '<!-- wp:paragraph {"content":"a\\u002d\\u002db"} --><p>a "quoted" \\ b</p><!-- /wp:paragraph -->';

		Pattern_Lab::create_page_from_content( 'Test', [ $markup ] );

		$this->assertSame( $markup, $GLOBALS['wp_last_inserted_post']['post_content'] );
	}

	public function test_create_page_keeps_references_intact(): void {
		$this->register( 'elayne/a', [], [ 'content' => '<p>A</p>' ] );

		Pattern_Lab::create_page( 'Test', [ 'elayne/a' ] );

		$this->assertSame( "<!-- wp:pattern {\"slug\":\"elayne/a\"} /-->\n\n", $GLOBALS['wp_last_inserted_post']['post_content'] );
	}
}
