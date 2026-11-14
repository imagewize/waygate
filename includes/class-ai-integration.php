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
	 * Returns the ordered model preference list for an AI call.
	 *
	 * Rewriting a page's texts is long output that a fast model writes well, while a large
	 * model can take minutes (mistral-large-latest: about 55 seconds for one hero's 13 texts),
	 * so that call prefers fast models.
	 *
	 * @param string $task "select" to choose patterns, "rewrite" to rewrite texts.
	 * @return string[]
	 */
	private static function get_model_preferences( string $task = 'select' ): array {
		if ( 'rewrite' === $task ) {
			return array(
				'mistral-small-latest',
				'claude-haiku-4-5',
				'gemini-2.0-flash',
				'gpt-4.1',
				'mistral-large-latest',
				'claude-sonnet-4-6',
			);
		}

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
	 * Seconds Waygate waits for an AI answer, filterable via waygate_ai_request_timeout.
	 *
	 * Core's AI Client waits 30 seconds, too short for rewriting a page's texts with a large
	 * model. Waygate sets its own timeout on its calls only.
	 *
	 * @return float
	 */
	public static function request_timeout(): float {
		$timeout = apply_filters( 'waygate_ai_request_timeout', 120.0 );

		return is_numeric( $timeout ) && (float) $timeout > 0 ? (float) $timeout : 120.0;
	}

	/**
	 * Start an AI Client prompt with Waygate's model preferences and request timeout.
	 *
	 * @param string $text   User prompt.
	 * @param string $system System instruction.
	 * @param array  $schema JSON schema of the expected answer.
	 * @param string $task   Task for get_model_preferences().
	 * @return string|\WP_Error The generated text.
	 */
	private static function ask( string $text, string $system, array $schema, string $task = 'select' ) {
		$builder = wp_ai_client_prompt( $text )
			->using_system_instruction( $system )
			->as_json_response( $schema )
			->using_model_preference( ...self::get_model_preferences( $task ) );

		if ( class_exists( '\WordPress\AiClient\Providers\Http\DTO\RequestOptions' ) ) {
			$builder = $builder->using_request_options(
				\WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray( array( 'timeout' => self::request_timeout() ) )
			);
		}

		return $builder->generate_text();
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
	 * Whether a text is a person's name or initials, as in testimonials ("Sarah J.", "SJ").
	 * Those stay as the pattern's placeholders, so the AI cannot invent reviewers.
	 *
	 * @param string $text Pattern text.
	 * @return bool
	 */
	public static function is_person_placeholder( string $text ): bool {
		return (bool) preg_match( '/^(\p{Lu}{1,3}|\p{Lu}\p{Ll}+(?: \p{Lu}\p{Ll}+)* \p{Lu}\.)$/u', trim( $text ) );
	}

	/**
	 * Phone numbers and email addresses in a text, normalised for comparison.
	 *
	 * @param string $text Text.
	 * @return string[] Sorted list; phone numbers as digits only.
	 */
	public static function contact_tokens( string $text ): array {
		preg_match_all( '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', $text, $emails );
		preg_match_all( '/\+?\(?\d[\d\s().\/-]{5,}\d/', $text, $phones );

		$tokens = array_map( 'strtolower', $emails[0] );
		foreach ( $phones[0] as $phone ) {
			$digits = preg_replace( '/\D/', '', $phone );
			if ( strlen( $digits ) >= 7 ) {
				$tokens[] = $digits;
			}
		}

		sort( $tokens );

		return $tokens;
	}

	/**
	 * The texts of each pattern the AI may rewrite: every replaceable text with at least one
	 * letter. Numbers and punctuation are left out, so the AI cannot invent statistics, and so
	 * are people's names and initials.
	 *
	 * @param string[] $slugs Ordered, validated pattern slugs.
	 * @return array<string,string[]> Slug => texts, numbered from 0. Patterns without texts are left out.
	 */
	public static function rewritable_texts( array $slugs ): array {
		$out = array();

		foreach ( $slugs as $slug ) {
			$pattern = Pattern_Lab::get_pattern( $slug );
			$texts   = array_values( array_filter( $pattern['texts'] ?? array(), fn( string $t ): bool => (bool) preg_match( '/\p{L}/u', $t ) && ! self::is_person_placeholder( $t ) ) );

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
	 * @param string                 $language      Language to write in, e.g. "English"; empty to
	 *                                              leave it to the model.
	 * @return string
	 */
	public static function pairs_prompt( array $texts_by_slug, string $theme, string $language = '' ): string {
		$prompt = "Theme/topic: {$theme}\n";

		if ( '' !== $language ) {
			$prompt .= "Write in: {$language}\n";
		}

		$prompt .= "\nRewrite the texts of these WordPress block patterns for this theme.\n\n";

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
	 * out-of-range or repeated numbers, empty texts and unchanged texts are dropped, and so is a
	 * text that adds, drops or changes a phone number or email address.
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

				if ( self::contact_tokens( $text ) !== self::contact_tokens( $texts[ $n ] ) ) {
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
	 * @param string[] $slugs    Ordered list of validated pattern slugs.
	 * @param string   $theme    The user's page description.
	 * @param string   $language Language to write in, e.g. "English"; empty to leave it to the model.
	 * @return array<int,string|array>|\WP_Error Sections for Pattern_Lab::create_page(), or why
	 *                                           the texts could not be rewritten.
	 */
	public static function personalize_sections( array $slugs, string $theme, string $language = '' ) {
		$texts_by_slug = self::rewritable_texts( $slugs );

		if ( empty( $texts_by_slug ) ) {
			return new \WP_Error( 'no_texts', 'The selected patterns have no texts to rewrite.' );
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
			. "- Rewrite every line that belongs to the old topic, so nothing from the old topic is left; leave out lines that already fit\n"
			. "- Plain text only, no HTML or Markdown\n"
			. "- Keep each text about as long as the original, and keep its role: a button label stays a short label, a form field label stays a field label\n"
			. "- Consecutive lines can be parts of one sentence split by emphasis (e.g. \"Built for\", \"families\", \".\"); rewrite the parts so the joined sentence reads naturally\n"
			. "- Do not invent facts. Leave phone numbers, email addresses, street addresses, opening hours, people's names, initials, ratings, review counts and review sources exactly as they are; they are placeholders for the site owner\n"
			. "- Testimonial quotes may be rewritten for the topic, but keep the names and sources unchanged\n"
			. '- Write in the language given after "Write in:"; otherwise in the language the topic description is written in, even if it names a place where another language is spoken';

		try {
			$raw = self::ask( self::pairs_prompt( $texts_by_slug, $theme, $language ), $system, $schema, 'rewrite' );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'ai_failed', 'AI request failed: ' . $e->getMessage() );
		}

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'ai_bad_response', 'AI returned no JSON object for the texts.' );
		}

		$sections = self::sections_from_response( $slugs, $texts_by_slug, $data );

		if ( ! array_filter( $sections, 'is_array' ) ) {
			return new \WP_Error( 'ai_no_changes', 'AI changed none of the texts.' );
		}

		return $sections;
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
	 * @return array{title:string,patterns:string[],pattern_count:int,reasoning:string,personalized:bool,warnings:string[],text_error:string,edit_url:string,view_url:string}|array{error:string}
	 */
	public static function generate_page( string $description, bool $personalize_text = true, string $vertical = '' ): array {
		// Two AI calls of up to request_timeout() each can outlast PHP's limit on a web request.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( (int) ceil( 2 * self::request_timeout() ) + 60 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- two AI calls can outlast the default limit.
		}

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
				'language'  => array(
					'type'        => 'string',
					'description' => 'The language the user request is written in, in English, e.g. "English" or "Dutch".',
				),
			),
			'required'   => array( 'title', 'patterns', 'reasoning', 'language' ),
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
			. "- Return a descriptive page title, in the language of the user request\n"
			. '- Return the language the user request is written in (not the language of a place it names)';

		try {
			$raw = self::ask( $prompt, $system, $schema );
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
		$text_error   = '';

		if ( $personalize_text ) {
			$language  = is_string( $data['language'] ?? null ) ? sanitize_text_field( $data['language'] ) : '';
			$rewritten = self::personalize_sections( $slugs, $description, $language );

			if ( is_wp_error( $rewritten ) ) {
				$text_error = $rewritten->get_error_message();
			} else {
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
			'text_error'    => $text_error,
			'edit_url'      => get_edit_post_link( $post_id, 'raw' ),
			'view_url'      => get_permalink( $post_id ),
		);
	}
}
