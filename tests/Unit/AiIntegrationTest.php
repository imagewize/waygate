<?php

namespace Imagewize\Waygate\Tests\Unit;

use Imagewize\Waygate\AiIntegration;
use PHPUnit\Framework\TestCase;

class AiIntegrationTest extends TestCase {

	public function test_is_text_generation_not_supported_when_wp_client_missing(): void {
		// wp_ai_client_prompt is not available in the unit-test environment
		$this->assertFalse( function_exists( 'wp_ai_client_prompt' ) );
		$this->assertFalse( AiIntegration::is_text_generation_supported() );
	}

	public function test_get_prompt_templates_returns_array(): void {
		$templates = AiIntegration::get_prompt_templates();
		$this->assertIsArray( $templates );
	}

	public function test_get_prompt_templates_has_expected_keys(): void {
		$templates = AiIntegration::get_prompt_templates();
		$this->assertArrayHasKey( 'homepage', $templates );
		$this->assertArrayHasKey( 'about', $templates );
		$this->assertArrayHasKey( 'services', $templates );
		$this->assertArrayHasKey( 'contact', $templates );
		$this->assertArrayHasKey( 'landing', $templates );
		$this->assertArrayHasKey( 'portfolio', $templates );
	}

	public function test_each_template_has_required_fields(): void {
		foreach ( AiIntegration::get_prompt_templates() as $key => $tpl ) {
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

		$templates = AiIntegration::get_prompt_templates();

		remove_all_filters( 'waygate_prompt_templates' );

		$this->assertArrayHasKey( 'faq', $templates );
		$this->assertSame( $extra['label'], $templates['faq']['label'] );
	}

	public function test_homepage_template_prompt_contains_placeholder(): void {
		$templates = AiIntegration::get_prompt_templates();
		$this->assertStringContainsString( '[industry]', $templates['homepage']['prompt'] );
	}
}
