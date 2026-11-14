<?php

namespace Imagewize\Waygate\Tests\Unit;

use Imagewize\Waygate\AI_Integration;
use PHPUnit\Framework\TestCase;
use WP_Block_Patterns_Registry;

class AiIntegrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_Block_Patterns_Registry::get_instance()->reset();
		$GLOBALS['wp_filters'] = [];
	}

	public function test_is_text_generation_not_supported_when_wp_client_missing(): void {
		// wp_ai_client_prompt is not available in the unit-test environment
		$this->assertFalse( function_exists( 'wp_ai_client_prompt' ) );
		$this->assertFalse( AI_Integration::is_text_generation_supported() );
	}

	public function test_get_prompt_templates_returns_array(): void {
		$templates = AI_Integration::get_prompt_templates();
		$this->assertIsArray( $templates );
	}

	public function test_get_prompt_templates_has_expected_keys(): void {
		$templates = AI_Integration::get_prompt_templates();
		$this->assertArrayHasKey( 'homepage', $templates );
		$this->assertArrayHasKey( 'about', $templates );
		$this->assertArrayHasKey( 'services', $templates );
		$this->assertArrayHasKey( 'contact', $templates );
		$this->assertArrayHasKey( 'landing', $templates );
		$this->assertArrayHasKey( 'portfolio', $templates );
	}

	public function test_each_template_has_required_fields(): void {
		foreach ( AI_Integration::get_prompt_templates() as $key => $tpl ) {
			$this->assertArrayHasKey( 'label', $tpl, "Template '{$key}' missing 'label'" );
			$this->assertArrayHasKey( 'description', $tpl, "Template '{$key}' missing 'description'" );
			$this->assertArrayHasKey( 'prompt', $tpl, "Template '{$key}' missing 'prompt'" );
			$this->assertNotEmpty( $tpl['label'], "Template '{$key}' has empty 'label'" );
			$this->assertNotEmpty( $tpl['description'], "Template '{$key}' has empty 'description'" );
			$this->assertNotEmpty( $tpl['prompt'], "Template '{$key}' has empty 'prompt'" );
		}
	}

	public function test_get_prompt_templates_is_filterable(): void {
		// Simulate a third-party adding a template via the filter.
		$extra = array(
			'label'       => 'FAQ',
			'description' => 'Frequently asked questions',
			'prompt'      => 'Create an FAQ page for a [industry] business',
		);

		// apply_filters is not available in unit tests; call the filter manually.
		add_filter(
			'waygate_prompt_templates',
			function ( array $templates ) use ( $extra ): array {
				$templates['faq'] = $extra;
				return $templates;
			}
		);

		$templates = AI_Integration::get_prompt_templates();

		remove_all_filters( 'waygate_prompt_templates' );

		$this->assertArrayHasKey( 'faq', $templates );
		$this->assertSame( $extra['label'], $templates['faq']['label'] );
	}

	public function test_homepage_template_prompt_contains_placeholder(): void {
		$templates = AI_Integration::get_prompt_templates();
		$this->assertStringContainsString( '[industry]', $templates['homepage']['prompt'] );
	}

	// --- text pairs ---

	private function register_hero(): void {
		WP_Block_Patterns_Registry::get_instance()->register(
			[
				'slug'    => 'elayne/hero',
				'title'   => 'Hero',
				'content' => '<!-- wp:heading --><h1>Built for <em>families</em>.</h1><!-- /wp:heading --><p>3,600+</p><p><a href="/contact">Call us</a></p>',
			]
		);
	}

	public function test_rewritable_texts_leaves_out_numbers_and_punctuation(): void {
		$this->register_hero();

		$this->assertSame(
			[ 'elayne/hero' => [ 'Built for', 'families', 'Call us' ] ],
			AI_Integration::rewritable_texts( [ 'elayne/hero', 'elayne/missing' ] )
		);
	}

	public function test_pairs_prompt_numbers_each_text(): void {
		$prompt = AI_Integration::pairs_prompt( [ 'elayne/hero' => [ 'Built for', "two\nlines" ] ], 'carpenter' );

		$this->assertStringContainsString( "Theme/topic: carpenter", $prompt );
		$this->assertStringContainsString( "=== elayne/hero ===\n0: Built for\n1: two lines\n", $prompt );
	}

	public function test_sections_from_response_maps_numbers_to_exact_finds(): void {
		$texts = [ 'elayne/hero' => [ 'Built for', 'families', 'Call us' ] ];
		$data  = [
			'elayne/hero'  => [
				[ 'n' => 2, 'text' => 'Plan a visit' ],
				[ 'n' => 1, 'text' => ' oak and pine ' ],
				[ 'n' => 1, 'text' => 'duplicate, dropped' ],
				[ 'n' => 0, 'text' => 'Built for' ],
				[ 'n' => 9, 'text' => 'out of range' ],
				[ 'n' => '0', 'text' => '' ],
				[ 'text' => 'no number' ],
				'not an item',
			],
			'elayne/other' => [ [ 'n' => 0, 'text' => 'not on the page' ] ],
		];

		$this->assertSame(
			[
				[
					'pattern' => 'elayne/hero',
					'texts'   => [
						[ 'find' => 'families', 'replace' => 'oak and pine' ],
						[ 'find' => 'Call us', 'replace' => 'Plan a visit' ],
					],
				],
				'elayne/cta',
			],
			AI_Integration::sections_from_response( [ 'elayne/hero', 'elayne/cta' ], $texts, $data )
		);
	}

	public function test_sections_from_response_keeps_slugs_on_garbage(): void {
		$this->assertSame( [ 'elayne/hero' ], AI_Integration::sections_from_response( [ 'elayne/hero' ], [], 'nope' ) );
	}

	public function test_personalize_sections_empty_without_texts(): void {
		$this->assertSame( [], AI_Integration::personalize_sections( [ 'elayne/nonexistent' ], 'carpenter' ) );
	}

	public function test_personalize_sections_empty_when_ai_unavailable(): void {
		// wp_ai_client_prompt doesn't exist here; the Error is caught.
		$this->register_hero();

		$this->assertSame( [], AI_Integration::personalize_sections( [ 'elayne/hero' ], 'carpenter' ) );
	}
}
