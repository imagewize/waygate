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

	// --- sections with text and link pairs ---

	private const HERO = '<!-- wp:heading {"level":1} --><h1>Your firm</h1><!-- /wp:heading --><p><a href="/contact">Call us</a></p>';

	public function test_compose_applies_pairs_and_warns_on_misses(): void {
		$this->register( 'elayne/hero', [], [ 'content' => self::HERO ] );
		$this->register( 'elayne/cta', [], [ 'content' => '<p>CTA</p>' ] );

		$result = Pattern_Lab::compose(
			[
				[
					'pattern' => 'elayne/hero',
					'texts'   => [ [ 'find' => 'Your firm', 'replace' => 'Salon Rosa' ], [ 'find' => 'Nope', 'replace' => 'x' ] ],
					'links'   => [ [ 'find' => '/contact', 'replace' => '/book' ] ],
				],
				'elayne/cta',
			]
		);

		$this->assertSame(
			'<!-- wp:heading {"level":1} --><h1>Salon Rosa</h1><!-- /wp:heading --><p><a href="/book">Call us</a></p>' . "\n\n<p>CTA</p>",
			$result['content']
		);
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'Section 0 (elayne/hero): "Nope"', $result['warnings'][0] );
	}

	public function test_compose_refuses_pairs_on_references(): void {
		$this->register( 'elayne/hero', [], [ 'content' => self::HERO ] );

		$result = Pattern_Lab::compose( [ [ 'pattern' => 'elayne/hero', 'texts' => [ [ 'find' => 'Your firm', 'replace' => 'x' ] ] ] ], false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'references_with_replacements', $result->get_error_code() );
	}

	public function test_compose_rejects_malformed_sections(): void {
		$this->register( 'elayne/hero', [], [ 'content' => self::HERO ] );

		$this->assertInstanceOf( WP_Error::class, Pattern_Lab::compose( [ [ 'texts' => [] ] ] ) );
		$this->assertInstanceOf( WP_Error::class, Pattern_Lab::compose( [ [ 'pattern' => 'elayne/hero', 'texts' => [ 'Your firm' ] ] ] ) );
		$this->assertInstanceOf( WP_Error::class, Pattern_Lab::compose( [ [ 'pattern' => 'elayne/hero', 'texts' => [ 'find' => 'a', 'replace' => 'b' ] ] ] ) );
	}

	public function test_create_page_inlines_all_patterns_once_any_has_pairs(): void {
		$this->register( 'elayne/hero', [], [ 'content' => self::HERO ] );
		$this->register( 'elayne/cta', [], [ 'content' => '<p>CTA</p>' ] );

		$warnings = null;
		$result   = Pattern_Lab::create_page(
			'Salon',
			[ [ 'pattern' => 'elayne/hero', 'texts' => [ [ 'find' => 'Your firm', 'replace' => 'Salon Rosa' ], [ 'find' => 'Missing', 'replace' => 'x' ] ] ], 'elayne/cta' ],
			'draft',
			$warnings
		);

		$this->assertSame( 1, $result );
		$content = $GLOBALS['wp_last_inserted_post']['post_content'];
		$this->assertStringContainsString( '<h1>Salon Rosa</h1>', $content );
		$this->assertStringContainsString( '<p>CTA</p>', $content );
		$this->assertStringNotContainsString( 'wp:pattern', $content );
		$this->assertCount( 1, $warnings );
	}

	public function test_get_pattern_returns_texts_and_links(): void {
		$this->register( 'elayne/hero', [ 'elayne/hero' ], [ 'content' => self::HERO ] );

		$pattern = Pattern_Lab::get_pattern( 'elayne/hero' );

		$this->assertSame( self::HERO, $pattern['content'] );
		$this->assertSame( [ 'Your firm', 'Call us' ], $pattern['texts'] );
		$this->assertSame( [ '/contact' ], $pattern['links'] );
		$this->assertSame( [ 'elayne/hero' ], $pattern['categories'] );
	}

	public function test_get_pattern_expands_nested_references(): void {
		$this->register( 'elayne/cta', [], [ 'content' => '<p>CTA</p>' ] );
		$this->register( 'elayne/page', [], [ 'content' => '<!-- wp:pattern {"slug":"elayne/cta"} /-->' ] );

		$this->assertSame( [ 'CTA' ], Pattern_Lab::get_pattern( 'elayne/page' )['texts'] );
	}

	public function test_get_pattern_null_for_patterns_not_offered(): void {
		$this->register( 'other/hero', [], [ 'content' => self::HERO ] );

		$this->assertNull( Pattern_Lab::get_pattern( 'other/hero' ) );
		$this->assertNull( Pattern_Lab::get_pattern( 'elayne/missing' ) );
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

	public function test_create_page_applies_template(): void {
		$this->register( 'elayne/a', [], [ 'content' => '<p>A</p>' ] );

		Pattern_Lab::create_page( 'Test', [ 'elayne/a' ], 'draft', $warnings, 'page-no-title.php' );

		$this->assertSame( 'page-no-title.php', $GLOBALS['wp_postmeta'][1]['_wp_page_template'] ?? null );
	}
}
