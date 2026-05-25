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

	// --- rewrite_pattern_texts() ---

	public function test_rewrite_pattern_texts_returns_empty_array_for_no_slugs(): void {
		$result = AI_Integration::rewrite_pattern_texts( [], 'coffee shop' );

		$this->assertSame( [], $result );
	}

	public function test_rewrite_pattern_texts_returns_empty_array_when_patterns_have_no_content(): void {
		// Slugs provided but patterns registered without a content field.
		WP_Block_Patterns_Registry::get_instance()->register( [ 'slug' => 'elayne/hero', 'title' => 'Hero' ] );

		$result = AI_Integration::rewrite_pattern_texts( [ 'elayne/hero' ], 'coffee shop' );

		$this->assertSame( [], $result );
	}

	public function test_rewrite_pattern_texts_returns_empty_array_when_slugs_not_registered(): void {
		$result = AI_Integration::rewrite_pattern_texts( [ 'elayne/nonexistent' ], 'coffee shop' );

		$this->assertSame( [], $result );
	}

	public function test_rewrite_pattern_texts_returns_empty_array_when_ai_unavailable(): void {
		// wp_ai_client_prompt doesn't exist in the test environment.
		// The Error thrown by calling an undefined function is caught by \Throwable.
		WP_Block_Patterns_Registry::get_instance()->register( [
			'slug'    => 'elayne/hero',
			'title'   => 'Hero',
			'content' => '<!-- wp:heading --><h1>Hello</h1><!-- /wp:heading -->',
		] );

		$result = AI_Integration::rewrite_pattern_texts( [ 'elayne/hero' ], 'coffee shop' );

		$this->assertSame( [], $result );
	}
}
