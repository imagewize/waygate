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
	 * The texts of each pattern the AI may rewrite: every replaceable text with at least one
	 * letter. Numbers and punctuation are left out, so the AI cannot invent statistics.
	 *
	 * @param string[] $slugs Ordered, validated pattern slugs.
	 * @return array<string,string[]> Slug => texts, numbered from 0. Patterns without texts are left out.
	 */
	public static function rewritable_texts( array $slugs ): array {
		$out = array();

		foreach ( $slugs as $slug ) {
			$pattern = Pattern_Lab::get_pattern( $slug );
			$texts   = array_values( array_filter( $pattern['texts'] ?? array(), fn( string $t ): bool => (bool) preg_match( '/\p{L}/u', $t ) ) );

			if ( ! empty( $texts ) ) {
				$out[ $slug ] = $texts;
			}
		}

		return $out;
	}

	/**
	 * The user prompt for the rewrite call: the topic, then each pattern's texts as numbered lines.
	 *
	 * @param array<string,string[]> $texts_by_slug From rewritable_texts().
	 * @param string                 $theme         The user's page description.
	 * @return string
	 */
	public static function pairs_prompt( array $texts_by_slug, string $theme ): string {
		$prompt = "Theme/topic: {$theme}\n\nRewrite the texts of these WordPress block patterns for this theme.\n\n";

		foreach ( $texts_by_slug as $slug => $texts ) {
			$prompt .= "=== {$slug} ===\n";
			foreach ( $texts as $n => $text ) {
				$prompt .= $n . ': ' . str_replace( array( "\r", "\n" ), ' ', $text ) . "\n";
			}
			$prompt .= "\n";
		}

		return $prompt;
	}

	/**
	 * Turn the AI's rewrite answer into page sections with text pairs.
	 *
	 * The AI answers per slug with {n, text} items, n being a line number from pairs_prompt().
	 * Numbers map back to the exact texts, so a find string is never mistyped. Unknown slugs,
	 * out-of-range or repeated numbers, empty texts and unchanged texts are dropped.
	 *
	 * @param string[]               $slugs         Ordered page slugs.
	 * @param array<string,string[]> $texts_by_slug From rewritable_texts().
	 * @param mixed                  $data          Decoded AI response.
	 * @return array<int,string|array> Sections for Pattern_Lab::create_page(); a plain slug where
	 *                                 nothing is replaced.
	 */
	public static function sections_from_response( array $slugs, array $texts_by_slug, $data ): array {
		$sections = array();

		foreach ( $slugs as $slug ) {
			$texts = $texts_by_slug[ $slug ] ?? array();
			$items = is_array( $data ) && isset( $data[ $slug ] ) && is_array( $data[ $slug ] ) ? $data[ $slug ] : array();
			$pairs = array();

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['n'], $item['text'] ) || ! is_numeric( $item['n'] ) || ! is_string( $item['text'] ) ) {
					continue;
				}

				$n    = (int) $item['n'];
				$text = trim( $item['text'] );

				if ( ! isset( $texts[ $n ] ) || isset( $pairs[ $n ] ) || '' === $text || $text === $texts[ $n ] ) {
					continue;
				}

				$pairs[ $n ] = array(
					'find'    => $texts[ $n ],
					'replace' => $text,
				);
			}

			ksort( $pairs );

			$sections[] = empty( $pairs ) ? $slug : array(
				'pattern' => $slug,
				'texts'   => array_values( $pairs ),
			);
		}

		return $sections;
	}

	/**
	 * Ask the AI to rewrite the selected patterns' texts for a topic, as find/replace pairs.
	 *
	 * Only text is replaced, through the same exact-match pairs as waygate/create-page, so the
	 * page keeps the patterns' markup and stays valid in the editor. Works for Aludra patterns
	 * too, since the pairs are passed on to aludra/compose-page.
	 *
	 * @param string[] $slugs Ordered list of validated pattern slugs.
	 * @param string   $theme The user's page description.
	 * @return array<int,string|array> Sections for Pattern_Lab::create_page(). Empty on failure.
	 */
	public static function personalize_sections( array $slugs, string $theme ): array {
		$texts_by_slug = self::rewritable_texts( $slugs );

		if ( empty( $texts_by_slug ) ) {
			return array();
		}

		$item       = array(
			'type'       => 'object',
			'properties' => array(
				'n'    => array( 'type' => 'integer' ),
				'text' => array( 'type' => 'string' ),
			),
			'required'   => array( 'n', 'text' ),
		);
		$properties = array();
		foreach ( array_keys( $texts_by_slug ) as $slug ) {
			$properties[ $slug ] = array(
				'type'  => 'array',
				'items' => $item,
			);
		}

		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
			'required'   => array_keys( $properties ),
		);

		$system = "You rewrite the visible texts of WordPress block patterns for a new topic.\n\n"
			. "Each pattern lists its texts as numbered lines (\"n: text\").\n"
			. "Rules:\n"
			. "- Answer with a JSON object: one key per pattern slug, each an array of {\"n\": line number, \"text\": new text}\n"
			. "- Only include lines you change; leave out lines that already fit\n"
			. "- Plain text only, no HTML or Markdown\n"
			. "- Keep each text about as long as the original, and keep its role: a button label stays a short label, a form field label stays a field label\n"
			. "- Consecutive lines can be parts of one sentence split by emphasis (e.g. \"Built for\", \"families\", \".\"); rewrite the parts so the joined sentence reads naturally\n"
			. "- Do not invent facts such as prices, years, awards or client names\n"
			. '- Write in the language of the topic description';

		try {
			$raw = wp_ai_client_prompt( self::pairs_prompt( $texts_by_slug, $theme ) )
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

		return is_array( $data ) ? self::sections_from_response( $slugs, $texts_by_slug, $data ) : array();
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
	 * @param bool   $personalize_text When true, a second AI call rewrites the selected patterns'
	 *                                 texts for the description, as find/replace pairs.
	 * @param string $vertical         Optional vertical slug (e.g. "legal"); limits the choice to that
	 *                                 vertical's patterns when it has at least three page sections.
	 * @return array{title:string,patterns:string[],pattern_count:int,reasoning:string,personalized:bool,warnings:string[],edit_url:string,view_url:string}|array{error:string}
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
		$sections     = $slugs;
		$warnings     = array();

		if ( $personalize_text ) {
			$rewritten = self::personalize_sections( $slugs, $description );

			if ( array_filter( $rewritten, 'is_array' ) ) {
				$sections     = $rewritten;
				$personalized = true;
			}
		}

		$post_id = Pattern_Lab::create_page( $page_title, $sections, 'draft', $warnings );

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
			'warnings'      => $warnings,
			'edit_url'      => get_edit_post_link( $post_id, 'raw' ),
			'view_url'      => get_permalink( $post_id ),
		);
	}
}
