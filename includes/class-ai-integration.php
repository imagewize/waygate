<?php
/**
 * Orchestrates AI page generation via the WordPress AI Client.
 *
 * @package Imagewize\Waygate
 */

namespace Imagewize\Waygate;

defined( 'ABSPATH' ) || exit;

/**
 * Handles AI provider registration, feature detection, and page generation.
 */
class AI_Integration {

	/**
	 * Built-in prompt templates, filterable via waygate_prompt_templates.
	 *
	 * @var array<string, array{label: string, description: string, prompt: string}>
	 */
	private static array $prompt_templates = array(
		'homepage'  => array(
			'label'       => 'Homepage',
			'description' => 'Hero, features grid, testimonials, and CTA',
			'prompt'      => 'Create a homepage for a [industry] business with a hero section, features grid, customer testimonials, and a call-to-action section',
		),
		'about'     => array(
			'label'       => 'About Page',
			'description' => 'Team bios, company story, and mission',
			'prompt'      => 'Create an about page for a [industry] company with team member cards, company history, core values, and a contact form',
		),
		'services'  => array(
			'label'       => 'Services Page',
			'description' => 'Services listing with benefits and pricing CTA',
			'prompt'      => 'Create a services page for a [industry] business showcasing our main services with descriptions, key benefits, and a pricing call-to-action',
		),
		'contact'   => array(
			'label'       => 'Contact Page',
			'description' => 'Contact form, location, and team info',
			'prompt'      => 'Create a contact page with a contact form, office location details, a brief team introduction, and social media links',
		),
		'landing'   => array(
			'label'       => 'Landing Page',
			'description' => 'Conversion-focused with hero and strong CTA',
			'prompt'      => 'Create a landing page for [product or service] with a compelling hero section, key benefits, social proof, and a strong call-to-action',
		),
		'portfolio' => array(
			'label'       => 'Portfolio / Work',
			'description' => 'Work showcase with case studies and CTA',
			'prompt'      => 'Create a portfolio page for a [industry] studio showcasing selected projects, client logos, a brief process overview, and a hire-us CTA',
		),
	);

	/**
	 * Returns all prompt templates, allowing third parties to add their own via the filter.
	 *
	 * @return array<string, array{label: string, description: string, prompt: string}>
	 */
	public static function get_prompt_templates(): array {
		return apply_filters( 'waygate_prompt_templates', self::$prompt_templates );
	}

	/**
	 * Returns the ordered model preference list used for all AI calls.
	 *
	 * @return string[]
	 */
	private static function get_model_preferences(): array {
		return array(
			'mistral-large-latest',
			'mistral-small-latest',
			'claude-sonnet-4-6',
			'claude-opus-4-6',
			'claude-haiku-4-5',
			'gpt-4.1',
			'gemini-2.0-flash',
		);
	}

	/**
	 * Registers the Mistral provider on the init hook.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_mistral_provider' ), 6 );
	}

	/**
	 * Returns true when a configured AI provider supports text generation.
	 */
	public static function is_text_generation_supported(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		try {
			return (bool) wp_ai_client_prompt( 'test' )->is_supported_for_text_generation();
		} catch ( \Throwable ) {
			return false;
		}
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
	 * Rewrite visible text inside pattern block markup to match a given theme.
	 *
	 * Makes one AI call that receives all selected patterns' raw block content and returns
	 * the same markup with headings, paragraphs, and button labels rewritten to be
	 * on-topic. Block comments, HTML tags, attributes, and class names are preserved.
	 *
	 * @param string[] $slugs  Ordered list of validated pattern slugs.
	 * @param string   $theme  The user's original page description (used as the theme).
	 * @return array<string,string> Map of slug → rewritten block content. Empty on failure.
	 */
	public static function rewrite_pattern_texts( array $slugs, string $theme ): array {
		$pattern_contents = array();

		foreach ( $slugs as $slug ) {
			$content = Pattern_Lab::get_pattern_content( $slug );
			if ( '' !== $content ) {
				$pattern_contents[ $slug ] = $content;
			}
		}

		if ( empty( $pattern_contents ) ) {
			return array();
		}

		$properties = array();
		foreach ( array_keys( $pattern_contents ) as $slug ) {
			$properties[ $slug ] = array( 'type' => 'string' );
		}

		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => array_keys( $properties ),
		);

		$patterns_block = '';
		foreach ( $pattern_contents as $slug => $content ) {
			$patterns_block .= "=== {$slug} ===\n{$content}\n\n";
		}

		$prompt = "Theme/topic: {$theme}\n\n"
			. "Rewrite the text content in the WordPress block patterns below to match this theme.\n\n"
			. $patterns_block;

		$system = "You are rewriting text content inside WordPress block patterns.\n\n"
			. "Rules:\n"
			. "- Keep ALL WordPress block comment markup (<!-- wp:... --> and <!-- /wp:... -->) EXACTLY as-is\n"
			. "- Keep ALL HTML tags, attributes, class names, and IDs EXACTLY as-is\n"
			. "- Only replace visible text content between opening and closing HTML tags\n"
			. "- Make replacement text relevant and specific to the given theme/topic\n"
			. "- Keep text length proportional to the original (do not add extra paragraphs)\n"
			. '- Return a JSON object: keys are the pattern slugs (from the === slug === headers), values are the complete rewritten block content strings';

		try {
			$raw = wp_ai_client_prompt( $prompt )
				->using_system_instruction( $system )
				->as_json_response( $schema )
				->using_model_preference( ...self::get_model_preferences() )
				->generate_text();
		} catch ( \Throwable ) {
			return array();
		}

		if ( is_wp_error( $raw ) || ! is_string( $raw ) ) {
			return array();
		}

		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Return the page section patterns the AI may choose from.
	 *
	 * Scoped to the vertical when it has at least three sections (a hero, a body section and
	 * a CTA); otherwise falls back to every page section.
	 *
	 * @param string $vertical Optional vertical slug.
	 * @return array[]
	 */
	public static function get_candidate_patterns( string $vertical = '' ): array {
		if ( '' !== $vertical ) {
			$scoped = Pattern_Lab::get_patterns(
				array(
					'sections_only' => true,
					'vertical'      => $vertical,
				)
			);

			if ( count( $scoped ) >= 3 ) {
				return $scoped;
			}
		}

		return Pattern_Lab::get_patterns( array( 'sections_only' => true ) );
	}

	/**
	 * Format patterns as one prompt line each: slug | title | categories | description | keywords.
	 *
	 * @param array[] $patterns Patterns from Pattern_Lab::get_patterns().
	 * @return string
	 */
	public static function describe_patterns( array $patterns ): string {
		$lines = '';

		foreach ( $patterns as $p ) {
			$cats     = implode( ', ', array_map( fn( $c ) => str_replace( 'elayne/', '', $c ), $p['categories'] ) );
			$keywords = implode( ', ', $p['keywords'] );
			$lines   .= "- {$p['slug']} | {$p['title']} | {$cats} | {$p['description']} | {$keywords}\n";
		}

		return $lines;
	}

	/**
	 * Ask the AI to select patterns and assemble a draft page.
	 *
	 * @param string $description      Natural-language description of the desired page.
	 * @param bool   $personalize_text When true, a second AI call rewrites the text content
	 *                                 of each selected pattern to match the description's theme.
	 * @param string $vertical         Optional vertical slug (e.g. "legal"); limits the choice to that
	 *                                 vertical's patterns when it has at least three page sections.
	 * @return array{title:string,patterns:string[],pattern_count:int,reasoning:string,personalized:bool,edit_url:string,view_url:string}|array{error:string}
	 */
	public static function generate_page( string $description, bool $personalize_text = true, string $vertical = '' ): array {
		$patterns = self::get_candidate_patterns( $vertical );

		if ( empty( $patterns ) ) {
			return array( 'error' => 'No page section patterns are registered.' );
		}

		$pattern_detail = self::describe_patterns( $patterns );

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'title'     => array(
					'type'        => 'string',
					'description' => 'Suggested page title.',
				),
				'patterns'  => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Ordered list of Elayne pattern slugs to assemble the page.',
				),
				'reasoning' => array(
					'type'        => 'string',
					'description' => 'One sentence explaining pattern choices.',
				),
			),
			'required'   => array( 'title', 'patterns', 'reasoning' ),
		);

		$prompt = "User request: {$description}\n\n"
			. "Available Elayne patterns (slug | title | categories | description | keywords):\n"
			. "{$pattern_detail}\n"
			. 'Select the best patterns to assemble a page for this request.';

		$system = "You are a WordPress page assembler for the Elayne block theme.\n"
			. "Select appropriate block patterns and return ONLY a JSON object.\n\n"
			. "Rules:\n"
			. "- Use between 3 and 7 patterns\n"
			. "- Use only slugs from the provided list — no inventing slugs\n"
			. "- Always start with a hero pattern (slug contains \"hero\") if one fits\n"
			. "- Always end with a CTA or contact pattern if one fits\n"
			. "- Avoid two consecutive grid/card patterns — interleave with stats or testimonials\n"
			. '- Return a descriptive page title';

		try {
			$raw = wp_ai_client_prompt( $prompt )
				->using_system_instruction( $system )
				->as_json_response( $schema )
				->using_model_preference( ...self::get_model_preferences() )
				->generate_text();
		} catch ( \Throwable $e ) {
			return array( 'error' => 'AI request failed: ' . $e->getMessage() );
		}

		if ( is_wp_error( $raw ) ) {
			return array( 'error' => 'AI provider error: ' . $raw->get_error_message() );
		}

		if ( ! is_string( $raw ) ) {
			return array( 'error' => 'AI returned an unexpected type: ' . gettype( $raw ) );
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || empty( $data['patterns'] ) ) {
			return array( 'error' => 'AI returned an unexpected response. Raw output: ' . esc_html( substr( $raw, 0, 300 ) ) );
		}

		// Keep only slugs that were offered, so the AI cannot place a header, template or other vertical.
		$offered = array_column( $patterns, 'slug' );
		$slugs   = array_values( array_filter( (array) $data['patterns'], fn( $s ) => in_array( $s, $offered, true ) ) );

		if ( empty( $slugs ) ) {
			return array( 'error' => 'AI selected no patterns from the offered list.' );
		}

		$page_title   = $data['title'] ?? $description;
		$personalized = false;

		if ( $personalize_text ) {
			$rewritten = self::rewrite_pattern_texts( $slugs, $description );

			if ( ! empty( $rewritten ) ) {
				$block_contents = array();
				foreach ( $slugs as $slug ) {
					$block_contents[] = $rewritten[ $slug ] ?? Pattern_Lab::get_pattern_content( $slug );
				}
				$post_id      = Pattern_Lab::create_page_from_content( $page_title, $block_contents, 'draft' );
				$personalized = true;
			} else {
				$post_id = Pattern_Lab::create_page( $page_title, $slugs, 'draft' );
			}
		} else {
			$post_id = Pattern_Lab::create_page( $page_title, $slugs, 'draft' );
		}

		if ( is_wp_error( $post_id ) ) {
			return array( 'error' => $post_id->get_error_message() );
		}

		$reasoning = $data['reasoning'] ?? '';

		update_post_meta( $post_id, '_waygate_reasoning', $reasoning );
		update_post_meta( $post_id, '_waygate_patterns', wp_json_encode( $slugs ) );
		update_post_meta( $post_id, '_waygate_generated_at', current_time( 'mysql' ) );
		update_post_meta( $post_id, '_waygate_personalized', $personalized ? '1' : '0' );

		return array(
			'title'         => $page_title,
			'patterns'      => $slugs,
			'pattern_count' => count( $slugs ),
			'reasoning'     => $reasoning,
			'personalized'  => $personalized,
			'edit_url'      => get_edit_post_link( $post_id, 'raw' ),
			'view_url'      => get_permalink( $post_id ),
		);
	}
}
