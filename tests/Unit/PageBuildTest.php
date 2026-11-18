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
		$GLOBALS['wp_filters']      = [];
		$GLOBALS['wp_options']      = [];
		$GLOBALS['wp_abilities']    = [];
		$GLOBALS['wp_attachments']  = [];
		$GLOBALS['wp_postmeta']     = [];
		$GLOBALS['wp_last_inserted_post'] = null;
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

	// --- sections with image pairs ---

	private const IMAGE_HERO = <<<'HTML'
<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="https://example.test/themes/elayne/patterns/images/hero.webp" alt="Back massage"/></figure>
<!-- /wp:image -->
<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="https://example.test/themes/elayne/patterns/images/team.webp" alt="Studio"/></figure>
<!-- /wp:image -->
HTML;

	private function add_attachment( int $id, string $url, string $alt ): void {
		$GLOBALS['wp_attachments'][ $id ] = $url;
		$GLOBALS['wp_postmeta'][ $id ]['_wp_attachment_image_alt'] = $alt;
	}

	public function test_get_pattern_returns_image_slots(): void {
		$this->register( 'elayne/hero', [], [ 'content' => self::IMAGE_HERO ] );

		$pattern = Pattern_Lab::get_pattern( 'elayne/hero' );

		$this->assertSame(
			[
				[ 'url' => 'https://example.test/themes/elayne/patterns/images/hero.webp', 'alt' => 'Back massage' ],
				[ 'url' => 'https://example.test/themes/elayne/patterns/images/team.webp', 'alt' => 'Studio' ],
			],
			$pattern['images']
		);
	}

	public function test_compose_applies_image_pairs(): void {
		$this->register( 'elayne/hero', [], [ 'content' => self::IMAGE_HERO ] );
		$this->add_attachment( 5, 'https://example.test/uploads/team-office.webp', 'Photo of the team' );

		$result = Pattern_Lab::compose(
			[
				[
					'pattern' => 'elayne/hero',
					'images'  => [ [ 'n' => 0, 'id' => 5 ] ],
				],
			]
		);

		$this->assertStringContainsString( '<img src="https://example.test/uploads/team-office.webp" alt="Photo of the team"/>', $result['content'] );
		$this->assertStringNotContainsString( 'patterns/images/hero.webp', $result['content'] );
		$this->assertStringContainsString( 'patterns/images/team.webp', $result['content'] );
	}

	public function test_compose_warns_about_unknown_slots_and_attachments_and_kept_images(): void {
		$this->register( 'elayne/hero', [], [ 'content' => self::IMAGE_HERO ] );
		$this->add_attachment( 5, 'https://example.test/uploads/team-office.webp', 'Photo of the team' );

		$result = Pattern_Lab::compose(
			[
				[
					'pattern' => 'elayne/hero',
					'images'  => [
						[ 'n' => 0, 'id' => 5 ],
						[ 'n' => 7, 'id' => 5 ],
						[ 'n' => 1, 'id' => 999 ],
					],
				],
			]
		);

		$this->assertSame(
			[
				'Section 0 (elayne/hero): image slot 7 does not exist; see waygate/get-pattern for its image slots.',
				'Section 0 (elayne/hero): image slot 1 attachment 999 was not found; keeping the original image.',
				'Section 0 (elayne/hero): kept the original image for 1 of 2 image slots.',
			],
			$result['warnings']
		);
	}

	public function test_compose_rejects_malformed_image_pairs(): void {
		$this->register( 'elayne/hero', [], [ 'content' => self::IMAGE_HERO ] );

		$this->assertInstanceOf( WP_Error::class, Pattern_Lab::compose( [ [ 'pattern' => 'elayne/hero', 'images' => [ 0 ] ] ] ) );
		$this->assertInstanceOf( WP_Error::class, Pattern_Lab::compose( [ [ 'pattern' => 'elayne/hero', 'images' => [ [ 'n' => 0 ] ] ] ] ) );
		$this->assertInstanceOf( WP_Error::class, Pattern_Lab::compose( [ [ 'pattern' => 'elayne/hero', 'images' => [ 'n' => 0, 'id' => 5 ] ] ] ) );
	}

	public function test_compose_refuses_image_pairs_on_references(): void {
		$this->register( 'elayne/hero', [], [ 'content' => self::IMAGE_HERO ] );

		$result = Pattern_Lab::compose( [ [ 'pattern' => 'elayne/hero', 'images' => [ [ 'n' => 0, 'id' => 5 ] ] ] ], false );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'references_with_replacements', $result->get_error_code() );
	}

	public function test_create_page_inlines_all_patterns_once_any_has_image_pairs(): void {
		$this->register( 'elayne/hero', [], [ 'content' => self::IMAGE_HERO ] );
		$this->register( 'elayne/cta', [], [ 'content' => '<p>CTA</p>' ] );
		$this->add_attachment( 5, 'https://example.test/uploads/team-office.webp', 'Photo of the team' );

		$warnings = null;
		$result   = Pattern_Lab::create_page(
			'Salon',
			[ [ 'pattern' => 'elayne/hero', 'images' => [ [ 'n' => 0, 'id' => 5 ], [ 'n' => 1, 'id' => 5 ] ] ], 'elayne/cta' ],
			'draft',
			$warnings
		);

		$this->assertSame( 1, $result );
		$content = $GLOBALS['wp_last_inserted_post']['post_content'];
		$this->assertStringNotContainsString( 'wp:pattern', $content );
		$this->assertStringContainsString( 'team-office.webp', $content );
		$this->assertSame( [], $warnings );
	}

	public function test_create_page_warns_when_aludra_page_skips_images(): void {
		$GLOBALS['wp_options'][ Pattern_Lab::ALUDRA_OPTION ] = '1';
		$GLOBALS['wp_abilities']['aludra/compose-page']      = new class( [ 'page_id' => 42 ] ) {
			public ?array $input = null;
			public function __construct( private mixed $result ) {}
			public function execute( mixed $input = null ): mixed {
				$this->input = $input;
				return $this->result;
			}
		};
		$this->register( 'aludra/section-faq', [], [ 'content' => '<p>FAQ</p>' ] );

		$warnings = null;
		$result   = Pattern_Lab::create_page(
			'FAQ',
			[ [ 'pattern' => 'aludra/section-faq', 'images' => [ [ 'n' => 0, 'id' => 5 ] ] ] ],
			'draft',
			$warnings
		);

		$this->assertSame( 42, $result );
		$this->assertStringContainsString( 'aludra/compose-page', $warnings[0] );
	}
}
