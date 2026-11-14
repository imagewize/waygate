<?php

namespace Imagewize\Waygate\Tests\Unit;

use Imagewize\Waygate\Pattern_Lab;
use PHPUnit\Framework\TestCase;
use WP_Block_Patterns_Registry;
use WP_Error;

/**
 * Records the input it is executed with and returns a canned result, like a WP_Ability.
 */
class FakeAbility {
	public ?array $input = null;

	public function __construct( private mixed $result ) {}

	public function execute( mixed $input = null ): mixed {
		$this->input = $input;
		return $this->result;
	}
}

class AludraTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_Block_Patterns_Registry::get_instance()->reset();
		$GLOBALS['wp_filters']            = [];
		$GLOBALS['wp_options']            = [];
		$GLOBALS['wp_abilities']          = [];
		$GLOBALS['wp_last_inserted_post'] = null;
	}

	private function register( string $slug, array $categories = [] ): void {
		WP_Block_Patterns_Registry::get_instance()->register(
			[
				'slug'        => $slug,
				'title'       => $slug,
				'description' => '',
				'categories'  => $categories,
				'keywords'    => [],
			]
		);
	}

	private function enable_aludra( mixed $result = [ 'page_id' => 42 ] ): FakeAbility {
		$ability = new FakeAbility( $result );

		$GLOBALS['wp_options'][ Pattern_Lab::ALUDRA_OPTION ] = '1';
		$GLOBALS['wp_abilities']['aludra/compose-page']      = $ability;
		return $ability;
	}

	// --- use_aludra() ---

	public function test_use_aludra_is_off_by_default(): void {
		$GLOBALS['wp_abilities']['aludra/compose-page'] = new FakeAbility( [] );

		$this->assertFalse( Pattern_Lab::use_aludra() );
	}

	public function test_use_aludra_needs_the_ability(): void {
		$GLOBALS['wp_options'][ Pattern_Lab::ALUDRA_OPTION ] = '1';

		$this->assertFalse( Pattern_Lab::use_aludra() );
	}

	public function test_use_aludra_with_option_and_ability(): void {
		$this->enable_aludra();

		$this->assertTrue( Pattern_Lab::use_aludra() );
	}

	// --- get_patterns() ---

	public function test_aludra_patterns_hidden_unless_enabled(): void {
		$this->register( 'elayne/hero' );
		$this->register( 'aludra/section-faq' );

		$this->assertSame( [ 'elayne/hero' ], array_column( Pattern_Lab::get_patterns(), 'slug' ) );

		$this->enable_aludra();

		$this->assertSame( [ 'elayne/hero', 'aludra/section-faq' ], array_column( Pattern_Lab::get_patterns(), 'slug' ) );
	}

	public function test_aludra_page_patterns_are_not_sections(): void {
		$this->enable_aludra();
		$this->register( 'aludra/section-faq', [ 'aludra-convert' ] );
		$this->register( 'aludra/page-about', [ 'aludra-pages' ] );

		$this->assertSame( [ 'aludra/section-faq' ], array_column( Pattern_Lab::get_patterns( [ 'sections_only' => true ] ), 'slug' ) );
	}

	// --- aludra_sections() ---

	public function test_aludra_sections_maps_slugs_in_order(): void {
		$this->assertSame(
			[ [ 'pattern' => 'aludra/section-hero-split' ], [ 'pattern' => 'aludra/section-faq' ] ],
			Pattern_Lab::aludra_sections( [ 'aludra/section-hero-split', 'aludra/section-faq' ] )
		);
	}

	public function test_aludra_sections_null_for_mixed_or_empty(): void {
		$this->assertNull( Pattern_Lab::aludra_sections( [ 'aludra/section-faq', 'elayne/hero' ] ) );
		$this->assertNull( Pattern_Lab::aludra_sections( [] ) );
	}

	// --- create_page() ---

	public function test_create_page_delegates_all_aludra_pages(): void {
		$ability = $this->enable_aludra();
		$this->register( 'aludra/section-hero-split' );
		$this->register( 'aludra/section-faq' );

		$result = Pattern_Lab::create_page( 'Nail salon', [ 'aludra/section-hero-split', 'aludra/section-faq' ], 'publish' );

		$this->assertSame( 42, $result );
		$this->assertSame(
			[
				'title'    => 'Nail salon',
				'status'   => 'publish',
				'sections' => [ [ 'pattern' => 'aludra/section-hero-split' ], [ 'pattern' => 'aludra/section-faq' ] ],
			],
			$ability->input
		);
		$this->assertNull( $GLOBALS['wp_last_inserted_post'] );
	}

	public function test_create_page_keeps_references_for_mixed_pages(): void {
		$ability = $this->enable_aludra();
		$this->register( 'aludra/section-faq' );
		$this->register( 'elayne/hero' );

		$result = Pattern_Lab::create_page( 'Mixed', [ 'elayne/hero', 'aludra/section-faq' ] );

		$this->assertSame( 1, $result );
		$this->assertNull( $ability->input );
		$this->assertStringContainsString( '"slug":"aludra/section-faq"', $GLOBALS['wp_last_inserted_post']['post_content'] );
	}

	public function test_create_page_passes_aludra_errors_through(): void {
		$this->enable_aludra( new WP_Error( 'block_not_registered', 'Pattern uses a disabled block.' ) );
		$this->register( 'aludra/section-faq' );

		$result = Pattern_Lab::create_page( 'Broken', [ 'aludra/section-faq' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'block_not_registered', $result->get_error_code() );
	}
}
