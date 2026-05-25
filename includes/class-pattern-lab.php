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
	 * Return all registered Elayne patterns with key metadata.
	 *
	 * @return array[]
	 */
	public static function get_patterns(): array {
		$all      = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();
		$prefixes = apply_filters( 'waygate_pattern_prefixes', array( 'elayne/' ) );
		$patterns = array();

		foreach ( $all as $p ) {
			if ( empty( $p['slug'] ) ) {
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
		$content = implode( "\n\n", array_filter( $block_contents ) );

		if ( empty( $content ) ) {
			return new \WP_Error( 'no_content', 'No block content was provided.' );
		}

		return wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( $title ),
				'post_content' => $content,
				'post_status'  => in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft',
				'post_type'    => 'page',
			),
			true
		);
	}

	/**
	 * Create a WordPress page from an ordered list of Elayne pattern slugs.
	 *
	 * @param string   $title         Page title.
	 * @param string[] $pattern_slugs Ordered list of validated pattern slugs.
	 * @param string   $status        Post status ('draft' or 'publish').
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function create_page( string $title, array $pattern_slugs, string $status = 'draft' ) {
		$registered_slugs = array_column( self::get_patterns(), 'slug' );
		$content          = '';

		foreach ( $pattern_slugs as $slug ) {
			$slug = sanitize_text_field( $slug );

			if ( ! preg_match( '/^[a-z0-9_-]+\/[a-z0-9_-]+$/', $slug ) ) {
				continue;
			}

			if ( ! in_array( $slug, $registered_slugs, true ) ) {
				continue;
			}

			$content .= '<!-- wp:pattern {"slug":"' . esc_attr( $slug ) . '"} /-->' . "\n\n";
		}

		if ( empty( $content ) ) {
			return new \WP_Error( 'no_valid_patterns', 'No valid patterns were selected.' );
		}

		return wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( $title ),
				'post_content' => $content,
				'post_status'  => in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft',
				'post_type'    => 'page',
			),
			true
		);
	}
}
