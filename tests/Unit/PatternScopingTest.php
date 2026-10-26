<?php

namespace Imagewize\Waygate\Tests\Unit;

use Imagewize\Waygate\AI_Integration;
use Imagewize\Waygate\Pattern_Lab;
use PHPUnit\Framework\TestCase;
use WP_Block_Patterns_Registry;

class PatternScopingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_Block_Patterns_Registry::get_instance()->reset();
		$GLOBALS['wp_filters'] = [];
		$GLOBALS['wp_theme']   = [ 'stylesheet' => 'elayne', 'template' => 'elayne' ];
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

	private function slugs( array $patterns ): array {
		return array_column( $patterns, 'slug' );
	}

	// --- is_page_section() / sections_only ---

	public function test_sections_only_excludes_structural_patterns(): void {
		$this->register( 'elayne/hero', [ 'elayne/hero' ] );
		$this->register( 'elayne/header-main', [ 'header' ] );
		$this->register( 'elayne/footer-main', [ 'elayne/footer' ] );
		$this->register( 'elayne/page-home', [ 'elayne/page', 'elayne/legal' ] );
		$this->register( 'elayne/template-index', [], [ 'templateTypes' => [ 'index' ] ] );
		$this->register( 'elayne/hidden', [], [ 'inserter' => false ] );
		$this->register( 'elayne/part', [], [ 'blockTypes' => [ 'core/template-part/header' ] ] );

		$this->assertSame( [ 'elayne/hero' ], $this->slugs( Pattern_Lab::get_patterns( [ 'sections_only' => true ] ) ) );
	}

	public function test_default_get_patterns_still_returns_everything(): void {
		$this->register( 'elayne/hero', [ 'elayne/hero' ] );
		$this->register( 'elayne/header-main', [ 'header' ] );

		$this->assertCount( 2, Pattern_Lab::get_patterns() );
	}

	public function test_structural_categories_are_filterable(): void {
		$this->register( 'elayne/posts-grid', [ 'elayne/posts' ] );

		add_filter( 'waygate_structural_categories', fn( $cats ) => array_merge( $cats, [ 'elayne/posts' ] ) );

		$this->assertCount( 0, Pattern_Lab::get_patterns( [ 'sections_only' => true ] ) );
	}

	// --- vertical ---

	public function test_vertical_filter_keeps_only_that_vertical(): void {
		$this->register( 'elayne/legal-hero-form', [ 'elayne/hero', 'elayne/legal' ] );
		$this->register( 'elayne/spa-hero', [ 'elayne/hero', 'elayne/spa' ] );
		$this->register( 'elayne/hero-generic', [ 'elayne/hero' ] );

		$this->assertSame( [ 'elayne/legal-hero-form' ], $this->slugs( Pattern_Lab::get_patterns( [ 'vertical' => 'legal' ] ) ) );
	}

	public function test_default_vertical_from_child_theme_name(): void {
		$GLOBALS['wp_theme'] = [ 'stylesheet' => 'elayne-legal', 'template' => 'elayne' ];
		$this->assertSame( 'legal', Pattern_Lab::get_default_vertical() );
	}

	public function test_default_vertical_empty_for_parent_or_unknown_child(): void {
		$this->assertSame( '', Pattern_Lab::get_default_vertical() );

		$GLOBALS['wp_theme'] = [ 'stylesheet' => 'elayne-dentist', 'template' => 'elayne' ];
		$this->assertSame( '', Pattern_Lab::get_default_vertical() );

		$GLOBALS['wp_theme'] = [ 'stylesheet' => 'ecotuin', 'template' => 'aviendha' ];
		$this->assertSame( '', Pattern_Lab::get_default_vertical() );
	}

	public function test_verticals_are_filterable(): void {
		add_filter( 'waygate_verticals', fn( $v ) => array_merge( $v, [ 'dentist' ] ) );
		$GLOBALS['wp_theme'] = [ 'stylesheet' => 'elayne-dentist', 'template' => 'elayne' ];

		$this->assertSame( 'dentist', Pattern_Lab::get_default_vertical() );
	}

	// --- AI candidates ---

	public function test_candidates_scope_to_vertical_sections(): void {
		$this->register( 'elayne/legal-hero-form', [ 'elayne/legal' ] );
		$this->register( 'elayne/legal-departments', [ 'elayne/legal' ] );
		$this->register( 'elayne/legal-cta-strip', [ 'elayne/legal' ] );
		$this->register( 'elayne/page-legal-home', [ 'elayne/page', 'elayne/legal' ] );
		$this->register( 'elayne/spa-hero', [ 'elayne/spa' ] );

		$this->assertSame(
			[ 'elayne/legal-hero-form', 'elayne/legal-departments', 'elayne/legal-cta-strip' ],
			$this->slugs( AI_Integration::get_candidate_patterns( 'legal' ) )
		);
	}

	public function test_candidates_fall_back_when_vertical_too_small(): void {
		$this->register( 'elayne/legal-hero-form', [ 'elayne/legal' ] );
		$this->register( 'elayne/spa-hero', [ 'elayne/spa' ] );

		$this->assertCount( 2, AI_Integration::get_candidate_patterns( 'legal' ) );
	}

	public function test_describe_patterns_includes_description_and_keywords(): void {
		$line = AI_Integration::describe_patterns(
			[
				[
					'slug'        => 'elayne/legal-hero-form',
					'title'       => 'Legal Hero',
					'description' => 'Hero with enquiry form',
					'categories'  => [ 'elayne/hero', 'elayne/legal' ],
					'keywords'    => [ 'law', 'form' ],
				],
			]
		);

		$this->assertSame( "- elayne/legal-hero-form | Legal Hero | hero, legal | Hero with enquiry form | law, form\n", $line );
	}
}
