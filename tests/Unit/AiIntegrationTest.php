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

	// --- request timeout ---

	public function test_request_timeout_defaults_to_two_minutes_and_is_filterable(): void {
		$this->assertSame( 120.0, AI_Integration::request_timeout() );

		add_filter( 'waygate_ai_request_timeout', fn() => 300 );
		$this->assertSame( 300.0, AI_Integration::request_timeout() );

		remove_all_filters( 'waygate_ai_request_timeout' );
		add_filter( 'waygate_ai_request_timeout', fn() => 'nope' );
		$this->assertSame( 120.0, AI_Integration::request_timeout() );
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

	public function test_person_placeholders_are_names_and_initials(): void {
		foreach ( [ 'Sarah J.', 'SJ', 'MT', 'Anne Marie V.', 'Émile R.' ] as $name ) {
			$this->assertTrue( AI_Integration::is_person_placeholder( $name ), $name );
		}
		foreach ( [ 'Real Reviews', 'What Our Customers Say', 'Homeowner · Google Review', 'FAQ about us', 'Call Now' ] as $text ) {
			$this->assertFalse( AI_Integration::is_person_placeholder( $text ), $text );
		}
	}

	public function test_rewritable_texts_leave_out_people(): void {
		WP_Block_Patterns_Registry::get_instance()->register(
			[
				'slug'    => 'elayne/reviews',
				'title'   => 'Reviews',
				'content' => '<p>Great work!</p><p>SJ</p><p>Sarah J.</p><p>Homeowner</p>',
			]
		);

		$this->assertSame( [ 'elayne/reviews' => [ 'Great work!', 'Homeowner' ] ], AI_Integration::rewritable_texts( [ 'elayne/reviews' ] ) );
	}

	public function test_contact_tokens_normalise_phones_and_emails(): void {
		$this->assertSame( [ '5551234567' ], AI_Integration::contact_tokens( 'Call Now: (555) 123-4567' ) );
		$this->assertSame( [ '5551234567' ], AI_Integration::contact_tokens( 'Bel nu: 555-123-4567' ) );
		$this->assertSame( [ 'info@example.com' ], AI_Integration::contact_tokens( 'Mail Info@Example.com today' ) );
		$this->assertSame( [], AI_Integration::contact_tokens( '20+ years, 2,400+ jobs, 24/7' ) );
	}

	public function test_sections_from_response_rejects_changed_contact_details(): void {
		$texts = [ 'elayne/cta' => [ 'Call Now: (555) 123-4567', 'Request a Callback', 'We reply fast.' ] ];
		$data  = [
			'elayne/cta' => [
				[ 'n' => 0, 'text' => 'Bel nu: 030 123 4567' ],
				[ 'n' => 1, 'text' => 'Mail info@houtwerk.test' ],
				[ 'n' => 2, 'text' => 'We reply within a day.' ],
			],
		];

		$this->assertSame(
			[ [ 'pattern' => 'elayne/cta', 'texts' => [ [ 'find' => 'We reply fast.', 'replace' => 'We reply within a day.' ] ] ] ],
			AI_Integration::sections_from_response( [ 'elayne/cta' ], $texts, $data )
		);

		$kept = AI_Integration::sections_from_response( [ 'elayne/cta' ], $texts, [ 'elayne/cta' => [ [ 'n' => 0, 'text' => 'Bel nu: (555) 123-4567' ] ] ] );
		$this->assertSame( 'Bel nu: (555) 123-4567', $kept[0]['texts'][0]['replace'] );
	}

	public function test_pairs_prompt_states_the_language(): void {
		$this->assertStringContainsString( "Write in: English\n", AI_Integration::pairs_prompt( [ 'elayne/hero' => [ 'Hi' ] ], 'carpenter', 'English' ) );
		$this->assertStringNotContainsString( 'Write in:', AI_Integration::pairs_prompt( [ 'elayne/hero' => [ 'Hi' ] ], 'carpenter' ) );
	}

	public function test_personalize_sections_errors_without_texts(): void {
		$result = AI_Integration::personalize_sections( [ 'elayne/nonexistent' ], 'carpenter' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_texts', $result->get_error_code() );
	}

	public function test_personalize_sections_reports_ai_failure(): void {
		// wp_ai_client_prompt doesn't exist here; the Error is caught and reported.
		$this->register_hero();

		$result = AI_Integration::personalize_sections( array( 'elayne/hero' ), 'carpenter' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_failed', $result->get_error_code() );
	}

	public function test_step_timeout_defaults_to_45_seconds(): void {
		$this->assertSame( 45.0, AI_Integration::step_timeout() );
	}

	public function test_step_timeout_is_filterable(): void {
		add_filter( 'waygate_ai_step_timeout', fn() => 20.0 );

		$this->assertSame( 20.0, AI_Integration::step_timeout() );

		remove_all_filters( 'waygate_ai_step_timeout' );
	}

	public function test_step_timeout_never_exceeds_request_timeout(): void {
		add_filter( 'waygate_ai_request_timeout', fn() => 10.0 );
		add_filter( 'waygate_ai_step_timeout', fn() => 60.0 );

		$this->assertSame( 10.0, AI_Integration::step_timeout() );

		remove_all_filters( 'waygate_ai_step_timeout' );
		remove_all_filters( 'waygate_ai_request_timeout' );
	}

	public function test_step_timeout_falls_back_on_invalid_values(): void {
		add_filter( 'waygate_ai_step_timeout', fn() => -5.0 );

		$this->assertSame( 45.0, AI_Integration::step_timeout() );

		remove_all_filters( 'waygate_ai_step_timeout' );
	}

	public function test_record_page_meta_stores_generation_meta(): void {
		$GLOBALS['wp_postmeta'] = [];

		AI_Integration::record_page_meta( 1, array( 'elayne/hero' ), 'Fits a bookstore', true );

		$this->assertSame( 'Fits a bookstore', $GLOBALS['wp_postmeta'][1]['_waygate_reasoning'] );
		$this->assertSame( wp_json_encode( array( 'elayne/hero' ) ), $GLOBALS['wp_postmeta'][1]['_waygate_patterns'] );
		$this->assertSame( '1', $GLOBALS['wp_postmeta'][1]['_waygate_personalized'] );
	}

	public function test_select_patterns_reports_ai_failure(): void {
		// wp_ai_client_prompt doesn't exist here; the Error is caught and reported.
		$this->register_hero();

		$result = AI_Integration::select_patterns( 'A bookstore homepage' );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'AI request failed', $result['error'] );
	}

	public function test_select_patterns_errors_without_registered_patterns(): void {
		$result = AI_Integration::select_patterns( 'A bookstore homepage' );

		$this->assertSame( array( 'error' => 'No page section patterns are registered.' ), $result );
	}

	// --- image assignment ---

	private function register_image_pattern(): void {
		WP_Block_Patterns_Registry::get_instance()->register(
			[
				'slug'    => 'elayne/hero',
				'title'   => 'Hero',
				'content' => '<!-- wp:image {"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="https://example.test/patterns/images/hero.webp" alt="Back massage"/></figure><!-- /wp:image -->'
					. '<!-- wp:cover {"url":"https://example.test/patterns/images/desktop.webp"} --><div class="wp-block-cover"></div><!-- /wp:cover -->',
			]
		);
	}

	public function test_images_prompt_numbers_slots_and_pictures(): void {
		$prompt = AI_Integration::images_prompt(
			'elayne/hero',
			'day spa',
			[
				[ 'url' => 'https://example.test/patterns/images/hero.webp', 'alt' => 'Back massage' ],
				[ 'url' => 'https://example.test/patterns/images/desktop.webp', 'alt' => '' ],
			],
			[
				[ 'id' => 10, 'url' => 'https://example.test/uploads/team-office.webp', 'alt' => 'Photo of the team' ],
				[ 'id' => 11, 'url' => 'https://example.test/uploads/treatment-room.webp', 'alt' => '' ],
			]
		);

		$this->assertStringContainsString( "Theme/topic: day spa\n", $prompt );
		$this->assertStringContainsString( "=== Image slots of elayne/hero ===\n", $prompt );
		$this->assertStringContainsString( "0: Back massage | hero.webp\n", $prompt );
		$this->assertStringContainsString( "1: (no alt text) | desktop.webp\n", $prompt );
		$this->assertStringContainsString( "=== Pictures chosen by the user ===\n", $prompt );
		$this->assertStringContainsString( "0: team-office.webp | Photo of the team\n", $prompt );
	}

	public function test_image_pairs_from_response_maps_numbers_strictly(): void {
		$slots = [
			[ 'url' => 'https://example.test/a.webp', 'alt' => 'A' ],
			[ 'url' => 'https://example.test/b.webp', 'alt' => 'B' ],
			[ 'url' => 'https://example.test/c.webp', 'alt' => 'C' ],
		];
		$attachments = [
			[ 'id' => 10, 'url' => 'https://example.test/10.webp', 'alt' => 'Team' ],
			[ 'id' => 11, 'url' => 'https://example.test/11.webp', 'alt' => 'Room' ],
		];

		$pairs = AI_Integration::image_pairs_from_response(
			$slots,
			$attachments,
			array(
				'slots' => array(
					array( 'n' => 2, 'image' => 1 ),
					array( 'n' => 0, 'image' => 0 ),
					array( 'n' => 0, 'image' => 1 ),   // duplicate slot, dropped
					array( 'n' => 1, 'image' => 1 ),   // picture 1 reused, dropped
					array( 'n' => 9, 'image' => 0 ),   // slot out of range, dropped
					array( 'n' => 1, 'image' => 7 ),   // picture out of range, dropped
					array( 'n' => 1 ),                 // missing picture, dropped
					'not an item',                     // dropped
				),
			)
		);

		$this->assertSame(
			array(
				array( 'n' => 0, 'id' => 10 ),
				array( 'n' => 2, 'id' => 11 ),
			),
			$pairs
		);
	}

	public function test_image_pairs_from_response_keeps_empty_on_garbage(): void {
		$this->assertSame( array(), AI_Integration::image_pairs_from_response( array(), array(), 'nope' ) );
		$this->assertSame( array(), AI_Integration::image_pairs_from_response( array(), array(), array( 'slots' => 'x' ) ) );
	}

	public function test_assign_images_errors_without_image_slots(): void {
		WP_Block_Patterns_Registry::get_instance()->register(
			[
				'slug'    => 'elayne/text-only',
				'title'   => 'Text only',
				'content' => '<p>Just words</p>',
			]
		);

		$result = AI_Integration::assign_images(
			'elayne/text-only',
			'day spa',
			array( array( 'id' => 10, 'url' => 'https://example.test/10.webp', 'alt' => 'Team' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_images', $result->get_error_code() );
	}

	public function test_assign_images_reports_ai_failure(): void {
		$this->register_image_pattern();

		$result = AI_Integration::assign_images(
			'elayne/hero',
			'day spa',
			array( array( 'id' => 10, 'url' => 'https://example.test/10.webp', 'alt' => 'Team' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ai_failed', $result->get_error_code() );
	}
}
