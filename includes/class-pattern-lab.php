<?php
/**
 * Pattern data layer: queries registered block patterns for the active theme.
 *
 * @package Imagewize\Waygate
 */

namespace Imagewize\Waygate;

defined( 'ABSPATH' ) || exit;

/**
 * Provides pattern discovery and page-assembly utilities.
 */
class Pattern_Lab {

	/**
	 * Registers hooks. No hooks needed at this level; called for consistency.
	 */
	public static function init(): void {
		// Core class — no hooks needed at this level.
	}

	/**
	 * Pattern categories that hold site structure or whole pages rather than page sections.
	 *
	 * @var string[]
	 */
	private static array $structural_categories = array(
		'header',
		'footer',
		'elayne/header',
		'elayne/footer',
		'elayne/page',
		'elayne/pages',
		'elayne/page-layouts',
		'aludra-pages',
	);

	/**
	 * Built-in vertical slugs, matching Elayne's vertical pattern categories (`elayne/{slug}`).
	 *
	 * @var string[]
	 */
	private static array $verticals = array( 'food-beverage', 'legal', 'plumbing', 'salon', 'spa' );

	/**
	 * Option that lets Waygate build pages from Aludra patterns through aludra/compose-page.
	 */
	public const ALUDRA_OPTION = 'waygate_use_aludra';

	/**
	 * Whether Waygate should use Aludra: the option is on and Aludra's compose-page ability
	 * is registered (Aludra active, its abilities not filtered off).
	 *
	 * @return bool
	 */
	public static function use_aludra(): bool {
		if ( ! get_option( self::ALUDRA_OPTION, false ) || ! function_exists( 'wp_has_ability' ) ) {
			return false;
		}

		// wp_has_ability(), not wp_get_ability(): the latter raises a notice for a missing ability.
		return wp_has_ability( 'aludra/compose-page' );
	}

	/**
	 * Pattern slug prefixes Waygate offers before the waygate_pattern_prefixes filter.
	 *
	 * @return string[]
	 */
	public static function default_prefixes(): array {
		return self::use_aludra() ? array( 'elayne/', 'aludra/' ) : array( 'elayne/' );
	}

	/**
	 * Map an ordered slug list to aludra/compose-page sections, when Aludra can build all of it.
	 *
	 * Aludra composes only its own patterns, so a page that mixes in any other pattern
	 * returns null and is built the usual way.
	 *
	 * @param string[] $slugs Ordered, validated pattern slugs.
	 * @return array[]|null Sections for aludra/compose-page, or null.
	 */
	public static function aludra_sections( array $slugs ): ?array {
		if ( empty( $slugs ) ) {
			return null;
		}

		foreach ( $slugs as $slug ) {
			if ( ! str_starts_with( $slug, 'aludra/' ) ) {
				return null;
			}
		}

		return array_map( fn( string $slug ): array => array( 'pattern' => $slug ), array_values( $slugs ) );
	}

	/**
	 * Build a page with aludra/compose-page.
	 *
	 * Aludra inlines its pattern markup and refuses patterns that use a block the site has
	 * disabled, so the page passes block validation when opened in the editor.
	 *
	 * @param string  $title    Page title.
	 * @param array[] $sections Sections from aludra_sections().
	 * @param string  $status   Post status ('draft' or 'publish').
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function create_page_with_aludra( string $title, array $sections, string $status = 'draft' ) {
		$ability = function_exists( 'wp_has_ability' ) && wp_has_ability( 'aludra/compose-page' )
			? wp_get_ability( 'aludra/compose-page' )
			: null;

		if ( null === $ability ) {
			return new \WP_Error( 'aludra_unavailable', 'The aludra/compose-page ability is not registered.' );
		}

		$result = $ability->execute(
			array(
				'title'    => sanitize_text_field( $title ),
				'status'   => in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft',
				'sections' => $sections,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['page_id'] ) ) {
			return new \WP_Error( 'aludra_failed', 'aludra/compose-page returned no page.' );
		}

		return (int) $result['page_id'];
	}

	/**
	 * Return registered patterns with key metadata.
	 *
	 * @param array{sections_only?:bool,vertical?:string} $args {
	 *     Optional filters.
	 *
	 *     @type bool   $sections_only Only return patterns that can be placed as a page section
	 *                                 (no headers, footers, templates or full-page composites).
	 *     @type string $vertical      Only return patterns in this vertical's category, e.g. "legal".
	 * }
	 * @return array[]
	 */
	public static function get_patterns( array $args = array() ): array {
		$all      = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();
		$prefixes = apply_filters( 'waygate_pattern_prefixes', self::default_prefixes() );
		$vertical = sanitize_key( $args['vertical'] ?? '' );
		$patterns = array();

		foreach ( $all as $p ) {
			// The registry keys every pattern by "name". Only theme file patterns also carry
			// "slug"; a plugin's register_block_pattern() call does not.
			$p['slug'] = $p['name'] ?? $p['slug'] ?? '';

			if ( '' === $p['slug'] ) {
				continue;
			}

			$matches = false;
			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( $p['slug'], $prefix ) ) {
					$matches = true;
					break;
				}
			}

			if ( ! $matches ) {
				continue;
			}

			if ( ! empty( $args['sections_only'] ) && ! self::is_page_section( $p ) ) {
				continue;
			}

			if ( '' !== $vertical && ! self::in_vertical( $p, $vertical ) ) {
				continue;
			}

			$patterns[] = array(
				'slug'        => $p['slug'],
				'title'       => $p['title'] ?? '',
				'description' => $p['description'] ?? '',
				'categories'  => $p['categories'] ?? array(),
				'keywords'    => $p['keywords'] ?? array(),
			);
		}

		return $patterns;
	}

	/**
	 * Whether a registered pattern can be placed as a section of a page.
	 *
	 * Excludes patterns hidden from the inserter, template and template-part patterns,
	 * and anything in a header, footer or full-page category.
	 *
	 * @param array $pattern Raw pattern data from the registry.
	 * @return bool
	 */
	public static function is_page_section( array $pattern ): bool {
		if ( isset( $pattern['inserter'] ) && false === $pattern['inserter'] ) {
			return false;
		}

		if ( ! empty( $pattern['templateTypes'] ) ) {
			return false;
		}

		foreach ( $pattern['blockTypes'] ?? array() as $block_type ) {
			if ( str_starts_with( $block_type, 'core/template-part' ) ) {
				return false;
			}
		}

		$excluded = apply_filters( 'waygate_structural_categories', self::$structural_categories );

		return empty( array_intersect( $pattern['categories'] ?? array(), $excluded ) );
	}

	/**
	 * Whether a pattern belongs to a vertical, by a category ending in "/{vertical}".
	 *
	 * @param array  $pattern  Raw pattern data from the registry.
	 * @param string $vertical Vertical slug, e.g. "legal".
	 * @return bool
	 */
	private static function in_vertical( array $pattern, string $vertical ): bool {
		foreach ( $pattern['categories'] ?? array() as $category ) {
			if ( $category === $vertical || str_ends_with( $category, '/' . $vertical ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return the known vertical slugs, filterable via waygate_verticals.
	 *
	 * @return string[]
	 */
	public static function get_verticals(): array {
		return apply_filters( 'waygate_verticals', self::$verticals );
	}

	/**
	 * Return the vertical of the active child theme, or an empty string.
	 *
	 * A child theme named "{parent}-{vertical}" (e.g. "elayne-legal") maps to that vertical.
	 *
	 * @return string
	 */
	public static function get_default_vertical(): string {
		$stylesheet = get_stylesheet();
		$template   = get_template();

		if ( $stylesheet === $template || ! str_starts_with( $stylesheet, $template . '-' ) ) {
			return '';
		}

		$vertical = substr( $stylesheet, strlen( $template ) + 1 );

		return in_array( $vertical, self::get_verticals(), true ) ? $vertical : '';
	}

	/**
	 * Replace nested `wp:pattern` reference blocks with the referenced pattern's content.
	 *
	 * Mirrors what the block editor stores when a pattern is inserted. References to
	 * unregistered slugs are left in place.
	 *
	 * @param string $content Block markup.
	 * @param int    $depth   Remaining recursion depth, guards against self-referencing patterns.
	 * @return string
	 */
	public static function expand_pattern_references( string $content, int $depth = 5 ): string {
		if ( $depth <= 0 || ! str_contains( $content, '<!-- wp:pattern ' ) ) {
			return $content;
		}

		return preg_replace_callback(
			'#<!-- wp:pattern \{"slug":"([a-z0-9_-]+/[a-z0-9_-]+)"\} /-->#',
			function ( array $m ) use ( $depth ): string {
				$inner = self::get_pattern_content( $m[1] );
				return '' === $inner ? $m[0] : self::expand_pattern_references( $inner, $depth - 1 );
			},
			$content
		);
	}

	/**
	 * Concatenate the content of an ordered list of registered patterns into page markup.
	 *
	 * @param string[] $slugs  Ordered pattern slugs. Each must be a registered pattern matching
	 *                         the waygate_pattern_prefixes filter.
	 * @param bool     $expand True to inline each pattern's content (nested references expanded),
	 *                         false to emit `wp:pattern` reference blocks.
	 * @return string|\WP_Error Block markup, or WP_Error naming the first unknown slug.
	 */
	public static function build_content( array $slugs, bool $expand = true ) {
		$registered = array_column( self::get_patterns(), 'slug' );
		$chunks     = array();

		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || ! in_array( $slug, $registered, true ) ) {
				return new \WP_Error( 'unknown_pattern', 'Pattern is not registered: ' . ( is_string( $slug ) ? $slug : gettype( $slug ) ) );
			}

			$chunks[] = $expand
				? self::expand_pattern_references( self::get_pattern_content( $slug ) )
				: '<!-- wp:pattern {"slug":"' . esc_attr( $slug ) . '"} /-->';
		}

		$content = implode( "\n\n", array_filter( array_map( 'trim', $chunks ) ) );

		if ( '' === $content ) {
			return new \WP_Error( 'no_content', 'The patterns produced no content.' );
		}

		return $content;
	}

	/**
	 * Return the raw block content of a registered pattern, or an empty string if not found.
	 *
	 * @param string $slug Pattern slug (e.g. "elayne/hero-split").
	 * @return string Block HTML/comment markup.
	 */
	public static function get_pattern_content( string $slug ): string {
		$pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered( $slug );
		return $pattern['content'] ?? '';
	}

	/**
	 * Create a WordPress page from pre-rendered block content strings.
	 *
	 * Used when pattern text has been personalised by AI before page assembly.
	 *
	 * @param string   $title         Page title.
	 * @param string[] $block_contents Ordered list of raw block markup strings.
	 * @param string   $status        Post status ('draft' or 'publish').
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function create_page_from_content( string $title, array $block_contents, string $status = 'draft' ) {
		$sanitized = array_map( 'wp_kses_post', array_filter( $block_contents ) );
		$content   = implode( "\n\n", $sanitized );

		if ( empty( $content ) ) {
			return new \WP_Error( 'no_content', 'No block content was provided.' );
		}

		// wp_insert_post() unslashes its input; slash it so backslashes in block JSON survive.
		return wp_insert_post(
			wp_slash(
				array(
					'post_title'   => sanitize_text_field( $title ),
					'post_content' => $content,
					'post_status'  => in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft',
					'post_type'    => 'page',
				)
			),
			true
		);
	}

	/**
	 * Create a WordPress page from an ordered list of pattern slugs.
	 *
	 * When every slug is an Aludra pattern and Waygate is set to use Aludra, the page is
	 * built by aludra/compose-page instead of from wp:pattern references.
	 *
	 * @param string   $title         Page title.
	 * @param string[] $pattern_slugs Ordered list of validated pattern slugs.
	 * @param string   $status        Post status ('draft' or 'publish').
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function create_page( string $title, array $pattern_slugs, string $status = 'draft' ) {
		$registered_slugs = array_column( self::get_patterns(), 'slug' );
		$valid_slugs      = array();
		$content          = '';

		foreach ( $pattern_slugs as $slug ) {
			$slug = sanitize_text_field( $slug );

			if ( ! preg_match( '/^[a-z0-9_-]+\/[a-z0-9_-]+$/', $slug ) ) {
				continue;
			}

			if ( ! in_array( $slug, $registered_slugs, true ) ) {
				continue;
			}

			$valid_slugs[] = $slug;
			$content      .= '<!-- wp:pattern {"slug":"' . esc_attr( $slug ) . '"} /-->' . "\n\n";
		}

		$aludra_sections = self::aludra_sections( $valid_slugs );

		if ( null !== $aludra_sections && self::use_aludra() ) {
			return self::create_page_with_aludra( $title, $aludra_sections, $status );
		}

		if ( empty( $content ) ) {
			return new \WP_Error( 'no_valid_patterns', 'No valid patterns were selected.' );
		}

		// wp_insert_post() unslashes its input; slash it so backslashes in block JSON survive.
		return wp_insert_post(
			wp_slash(
				array(
					'post_title'   => sanitize_text_field( $title ),
					'post_content' => $content,
					'post_status'  => in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft',
					'post_type'    => 'page',
				)
			),
			true
		);
	}
}
