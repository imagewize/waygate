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
	 * Map an ordered section list to aludra/compose-page sections, when Aludra can build all of it.
	 *
	 * Aludra composes only its own patterns, so a page that mixes in any other pattern
	 * returns null and is built the usual way. Text and link pairs are passed on unchanged.
	 *
	 * @param array<int,string|array> $sections Ordered, validated pattern slugs or sections
	 *                                          from normalize_section().
	 * @return array[]|null Sections for aludra/compose-page, or null.
	 */
	public static function aludra_sections( array $sections ): ?array {
		if ( empty( $sections ) ) {
			return null;
		}

		$out = array();

		foreach ( $sections as $section ) {
			$section = is_array( $section ) ? $section : array( 'pattern' => (string) $section );

			if ( ! str_starts_with( $section['pattern'] ?? '', 'aludra/' ) ) {
				return null;
			}

			$item = array( 'pattern' => $section['pattern'] );
			foreach ( array( 'texts', 'links' ) as $key ) {
				if ( ! empty( $section[ $key ] ) ) {
					$item[ $key ] = $section[ $key ];
				}
			}
			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Validate one page section: a pattern slug, or {pattern, texts?, links?, images?} where
	 * texts and links are lists of {find, replace} pairs and images a list of {n, id} pairs.
	 *
	 * @param mixed $entry Section as given in a map or ability input.
	 * @return array{pattern:string,texts:array[],links:array[],images:array}|\WP_Error
	 */
	public static function normalize_section( $entry ) {
		if ( is_string( $entry ) ) {
			$entry = array( 'pattern' => $entry );
		}

		if ( ! is_array( $entry ) || ! isset( $entry['pattern'] ) || ! is_string( $entry['pattern'] ) || '' === $entry['pattern'] ) {
			return new \WP_Error( 'invalid_section', 'A section must be a pattern slug or an object with a "pattern" slug.' );
		}

		$section = array(
			'pattern' => $entry['pattern'],
			'texts'   => array(),
			'links'   => array(),
			'images'  => array(),
		);

		foreach ( array( 'texts', 'links' ) as $key ) {
			if ( ! isset( $entry[ $key ] ) ) {
				continue;
			}

			if ( ! is_array( $entry[ $key ] ) || ! array_is_list( $entry[ $key ] ) ) {
				return new \WP_Error( 'invalid_section', "\"{$key}\" of {$entry['pattern']} must be a list of {\"find\", \"replace\"} pairs." );
			}

			foreach ( $entry[ $key ] as $pair ) {
				if ( ! is_array( $pair ) || ! isset( $pair['find'], $pair['replace'] ) || ! is_string( $pair['find'] ) || ! is_string( $pair['replace'] ) ) {
					return new \WP_Error( 'invalid_section', "\"{$key}\" of {$entry['pattern']} must be a list of {\"find\", \"replace\"} pairs." );
				}

				$section[ $key ][] = array(
					'find'    => $pair['find'],
					'replace' => $pair['replace'],
				);
			}
		}

		if ( isset( $entry['images'] ) ) {
			if ( ! is_array( $entry['images'] ) || ! array_is_list( $entry['images'] ) ) {
				return new \WP_Error( 'invalid_section', 'Images of ' . $entry['pattern'] . ' must be a list of image pairs.' );
			}

			foreach ( $entry['images'] as $pair ) {
				if ( ! is_array( $pair ) || ! isset( $pair['n'], $pair['id'] ) || ! is_numeric( $pair['n'] ) || ! is_numeric( $pair['id'] ) ) {
					return new \WP_Error( 'invalid_section', 'Images of ' . $entry['pattern'] . ' must be a list of image pairs.' );
				}

				$section['images'][] = array(
					'n'  => (int) $pair['n'],
					'id' => absint( $pair['id'] ),
				);
			}
		}

		return $section;
	}

	/**
	 * One offered pattern with its markup and the exact texts and links that can be replaced.
	 *
	 * @param string $slug Pattern slug.
	 * @return array|null Pattern data, or null when Waygate does not offer this pattern.
	 */
	public static function get_pattern( string $slug ): ?array {
		foreach ( self::get_patterns() as $pattern ) {
			if ( $pattern['slug'] !== $slug ) {
				continue;
			}

			$content = self::expand_pattern_references( self::get_pattern_content( $slug ) );

			return array_merge(
				$pattern,
				array(
					'content' => $content,
					'texts'   => Text_Replace::texts( $content ),
					'links'   => Text_Replace::links( $content ),
					'images'  => Text_Replace::images( $content ),
				)
			);
		}

		return null;
	}

	/**
	 * The swap-in URL of a media-library attachment, or an empty string when it does not exist.
	 *
	 * The large size is plenty for a pattern slot and lighter than the full file;
	 * core falls back to the full URL when no large version exists.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function attachment_image_url( int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}

		$url = function_exists( 'wp_get_attachment_image_url' ) ? wp_get_attachment_image_url( $id, 'large' ) : false;

		if ( ( false === $url || '' === $url ) && function_exists( 'wp_get_attachment_url' ) ) {
			$url = wp_get_attachment_url( $id );
		}

		return is_string( $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * The alt text stored on a media-library attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function attachment_alt( int $id ): string {
		$alt = function_exists( 'get_post_meta' ) ? get_post_meta( $id, '_wp_attachment_image_alt', true ) : '';

		return is_string( $alt ) ? $alt : '';
	}

	/**
	 * One section's markup: the pattern inlined, with its text, link and image pairs applied.
	 *
	 * @param array{pattern:string,texts:array[],links:array[],images:array} $section From normalize_section().
	 * @param int                                                            $index   Section position, for warnings.
	 * @return array{markup:string,warnings:string[]}
	 */
	private static function section_markup( array $section, int $index ): array {
		$content  = self::expand_pattern_references( self::get_pattern_content( $section['pattern'] ) );
		$slots    = Text_Replace::images( $content );
		$texts    = Text_Replace::pairs_to_map( $section['texts'], 'sanitize_text_field' );
		$links    = Text_Replace::pairs_to_map( $section['links'], 'esc_url_raw' );
		$warnings = array();
		$images   = array();

		foreach ( $section['images'] as $pair ) {
			if ( ! isset( $slots[ $pair['n'] ] ) ) {
				$warnings[] = sprintf( 'Section %d (%s): image slot %d does not exist; see waygate/get-pattern for its image slots.', $index, $section['pattern'], $pair['n'] );
				continue;
			}

			$url = self::attachment_image_url( $pair['id'] );

			if ( '' === $url ) {
				$warnings[] = sprintf( 'Section %d (%s): image slot %d attachment %d was not found; keeping the original image.', $index, $section['pattern'], $pair['n'], $pair['id'] );
				continue;
			}

			$images[ $slots[ $pair['n'] ]['url'] ] = array(
				'url' => $url,
				'alt' => self::attachment_alt( $pair['id'] ),
				'id'  => $pair['id'],
			);
		}

		if ( ! empty( $images ) && count( $images ) < count( $slots ) ) {
			$warnings[] = sprintf( 'Section %d (%s): kept the original image for %d of %d image slots.', $index, $section['pattern'], count( $slots ) - count( $images ), count( $slots ) );
		}

		foreach ( Text_Replace::unmatched( $content, $texts, $links ) as $find ) {
			$warnings[] = sprintf( 'Section %d (%s): "%s" matched nothing; see waygate/get-pattern for its exact texts and links.', $index, $section['pattern'], $find );
		}

		return array(
			'markup'   => Text_Replace::apply( $content, $texts, $links, $images ),
			'warnings' => $warnings,
		);
	}

	/**
	 * Build a page with aludra/compose-page.
	 *
	 * Aludra inlines its pattern markup and refuses patterns that use a block the site has
	 * disabled, so the page passes block validation when opened in the editor.
	 *
	 * @param string        $title    Page title.
	 * @param array[]       $sections Sections from aludra_sections().
	 * @param string        $status   Post status ('draft' or 'publish').
	 * @param string[]|null $warnings Set to compose-page's warnings for find strings that matched nothing.
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function create_page_with_aludra( string $title, array $sections, string $status = 'draft', ?array &$warnings = null ) {
		$warnings = array();

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

		$warnings = array_values( array_map( 'strval', (array) ( $result['warnings'] ?? array() ) ) );

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
	 * Assemble page markup from an ordered list of sections, applying their text and link pairs.
	 *
	 * @param array<int,string|array> $sections Ordered pattern slugs or {pattern, texts, links}
	 *                                          sections. Each pattern must be registered and match
	 *                                          the waygate_pattern_prefixes filter.
	 * @param bool                    $expand   True to inline each pattern's content (nested
	 *                                          references expanded), false to emit `wp:pattern`
	 *                                          reference blocks, which cannot carry replacements.
	 * @return array{content:string,warnings:string[]}|\WP_Error Markup plus warnings for find
	 *                                                          strings that matched nothing.
	 */
	public static function compose( array $sections, bool $expand = true ) {
		$registered = array_column( self::get_patterns(), 'slug' );
		$chunks     = array();
		$warnings   = array();

		foreach ( array_values( $sections ) as $index => $entry ) {
			$section = self::normalize_section( $entry );

			if ( is_wp_error( $section ) ) {
				return $section;
			}

			$slug = $section['pattern'];

			if ( ! in_array( $slug, $registered, true ) ) {
				return new \WP_Error( 'unknown_pattern', 'Pattern is not registered: ' . $slug );
			}

			if ( ! $expand ) {
				if ( ! empty( $section['texts'] ) || ! empty( $section['links'] ) || ! empty( $section['images'] ) ) {
					return new \WP_Error( 'references_with_replacements', "Pattern references cannot carry texts, links or images: {$slug}." );
				}
				$chunks[] = '<!-- wp:pattern {"slug":"' . esc_attr( $slug ) . '"} /-->';
				continue;
			}

			$built    = self::section_markup( $section, $index );
			$chunks[] = $built['markup'];
			$warnings = array_merge( $warnings, $built['warnings'] );
		}

		$content = implode( "\n\n", array_filter( array_map( 'trim', $chunks ) ) );

		if ( '' === $content ) {
			return new \WP_Error( 'no_content', 'The patterns produced no content.' );
		}

		return array(
			'content'  => $content,
			'warnings' => $warnings,
		);
	}

	/**
	 * Concatenate the content of an ordered list of registered patterns into page markup.
	 *
	 * @param array<int,string|array> $sections Ordered pattern slugs or sections, as for compose().
	 * @param bool                    $expand   As for compose().
	 * @return string|\WP_Error Block markup, or WP_Error naming the first problem.
	 */
	public static function build_content( array $sections, bool $expand = true ) {
		$composed = self::compose( $sections, $expand );

		return is_wp_error( $composed ) ? $composed : $composed['content'];
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
	 * The markup is not checked against any pattern; prefer create_page() with text pairs, which
	 * keeps the patterns' markup valid in the editor.
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
	 * Create a WordPress page from an ordered list of patterns.
	 *
	 * Plain slugs are stored as `wp:pattern` references. Once any section carries texts or
	 * links, every pattern is inlined so the replacements can be applied. When every pattern is
	 * an Aludra pattern and Waygate is set to use Aludra, the page is built by
	 * aludra/compose-page instead, with the pairs passed on.
	 *
	 * Invalid or unregistered sections are skipped.
	 *
	 * @param string                  $title    Page title.
	 * @param array<int,string|array> $sections Ordered pattern slugs or {pattern, texts, links}.
	 * @param string                  $status   Post status ('draft' or 'publish').
	 * @param string[]|null           $warnings Set to warnings for find strings that matched nothing.
	 * @param string                  $template Page template slug, e.g. 'page-no-title' or 'default'.
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function create_page( string $title, array $sections, string $status = 'draft', ?array &$warnings = null, string $template = '' ) {
		$warnings         = array();
		$registered_slugs = array_column( self::get_patterns(), 'slug' );
		$valid            = array();
		$replacing        = false;

		foreach ( $sections as $entry ) {
			$section = self::normalize_section( $entry );

			if ( is_wp_error( $section ) ) {
				continue;
			}

			$slug = sanitize_text_field( $section['pattern'] );

			if ( ! preg_match( '/^[a-z0-9_-]+\/[a-z0-9_-]+$/', $slug ) || ! in_array( $slug, $registered_slugs, true ) ) {
				continue;
			}

			$section['pattern'] = $slug;
			$valid[]            = $section;
			$replacing          = $replacing || ! empty( $section['texts'] ) || ! empty( $section['links'] ) || ! empty( $section['images'] );
		}

		$aludra_sections = self::aludra_sections( $valid );

		if ( null !== $aludra_sections && self::use_aludra() ) {
			$post_id = self::create_page_with_aludra( $title, $aludra_sections, $status, $warnings );

			if ( ! is_wp_error( $post_id ) ) {
				foreach ( $valid as $i => $section ) {
					if ( ! empty( $section['images'] ) ) {
						$warnings[] = sprintf( 'Section %d (%s): image pairs are not applied by aludra/compose-page; assign the images in the editor.', $i, $section['pattern'] );
					}
				}
			}
		} elseif ( empty( $valid ) ) {
			return new \WP_Error( 'no_valid_patterns', 'No valid patterns were selected.' );
		} else {
			$composed = self::compose( $valid, $replacing );

			if ( is_wp_error( $composed ) ) {
				return $composed;
			}

			$warnings = $composed['warnings'];

			// wp_insert_post() unslashes its input; slash it so backslashes in block JSON survive.
			$post_id = wp_insert_post(
				wp_slash(
					array(
						'post_title'   => sanitize_text_field( $title ),
						'post_content' => $composed['content'] . "\n\n",
						'post_status'  => in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft',
						'post_type'    => 'page',
					)
				),
				true
			);
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return $post_id;
		}

		$template = sanitize_file_name( $template );
		if ( '' !== $template && 'default' !== $template ) {
			update_post_meta( $post_id, '_wp_page_template', $template );
		}

		return $post_id;
	}
}
