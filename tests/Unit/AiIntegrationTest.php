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
}
