<?php

namespace Imagewize\Waygate;

defined( 'ABSPATH' ) || exit;

class AiIntegration {

	public static function init(): void {
		add_action( 'init', [ self::class, 'register_mistral_provider' ], 6 );
	}

	/**
	 * Register the Mistral provider with the WordPress AI Client registry.
	 *
	 * The Composer distribution of saarnilauri/ai-provider-for-mistral excludes
	 * plugin.php, so the provider must be registered manually.
	 */
	public static function register_mistral_provider(): void {
		if (
			! class_exists( 'SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral' ) ||
			! class_exists( 'WordPress\AiClient\AiClient' )
		) {
			return;
		}

		$registry = \WordPress\AiClient\AiClient::defaultRegistry();

		if ( ! $registry->hasProvider( \SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral::class ) ) {
			$registry->registerProvider( \SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral::class );
		}
	}

	/**
	 * Ask the AI to select patterns and assemble a draft page.
	 *
	 * @param string $description Natural-language description of the desired page.
	 * @return array{title:string,patterns:string[],pattern_count:int,reasoning:string,edit_url:string,view_url:string}|array{error:string}
	 */
	public static function generate_page( string $description ): array {
		$patterns       = PatternLab::get_patterns();
		$pattern_detail = '';

		foreach ( $patterns as $p ) {
			$cats            = implode( ', ', array_map( fn( $c ) => str_replace( 'elayne/', '', $c ), $p['categories'] ) );
			$pattern_detail .= "- {$p['slug']} | {$p['title']} | {$cats}\n";
		}

		$schema = [
			'type'       => 'object',
			'properties' => [
				'title'     => [
					'type'        => 'string',
					'description' => 'Suggested page title.',
				],
				'patterns'  => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Ordered list of Elayne pattern slugs to assemble the page.',
				],
				'reasoning' => [
					'type'        => 'string',
					'description' => 'One sentence explaining pattern choices.',
				],
			],
			'required'   => [ 'title', 'patterns', 'reasoning' ],
		];

		$prompt = <<<PROMPT
User request: {$description}

Available Elayne patterns (slug | title | categories):
{$pattern_detail}

Select the best patterns to assemble a page for this request.
PROMPT;

		$system = <<<SYSTEM
You are a WordPress page assembler for the Elayne block theme.
Select appropriate block patterns and return ONLY a JSON object.

Rules:
- Use between 3 and 7 patterns
- Use only slugs from the provided list — no inventing slugs
- Always start with a hero pattern (slug contains "hero") if one fits
- Always end with a CTA or contact pattern if one fits
- Avoid two consecutive grid/card patterns — interleave with stats or testimonials
- Return a descriptive page title
SYSTEM;

		try {
			$raw = wp_ai_client_prompt( $prompt )
				->using_system_instruction( $system )
				->as_json_response( $schema )
				->using_model_preference(
					'mistral-large-latest',
					'mistral-small-latest',
					'claude-sonnet-4-6',
					'claude-opus-4-6',
					'claude-haiku-4-5',
					'gpt-4.1',
					'gemini-2.0-flash'
				)
				->generate_text();
		} catch ( \Throwable $e ) {
			return [ 'error' => 'AI request failed: ' . $e->getMessage() ];
		}

		if ( is_wp_error( $raw ) ) {
			return [ 'error' => 'AI provider error: ' . $raw->get_error_message() ];
		}

		if ( ! is_string( $raw ) ) {
			return [ 'error' => 'AI returned an unexpected type: ' . gettype( $raw ) ];
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || empty( $data['patterns'] ) ) {
			return [ 'error' => 'AI returned an unexpected response. Raw output: ' . esc_html( substr( $raw, 0, 300 ) ) ];
		}

		$post_id = PatternLab::create_page( $data['title'] ?? $description, $data['patterns'], 'draft' );

		if ( is_wp_error( $post_id ) ) {
			return [ 'error' => $post_id->get_error_message() ];
		}

		return [
			'title'         => $data['title'] ?? $description,
			'patterns'      => $data['patterns'],
			'pattern_count' => count( $data['patterns'] ),
			'reasoning'     => $data['reasoning'] ?? '',
			'edit_url'      => get_edit_post_link( $post_id, 'raw' ),
			'view_url'      => get_permalink( $post_id ),
		];
	}
}
