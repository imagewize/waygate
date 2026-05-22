<?php

namespace Imagewize\Waygate;

defined( 'ABSPATH' ) || exit;

class PatternLab {

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
		$patterns = [];

		foreach ( $all as $p ) {
			if ( empty( $p['slug'] ) || ! str_starts_with( $p['slug'], 'elayne/' ) ) {
				continue;
			}

			$patterns[] = [
				'slug'        => $p['slug'],
				'title'       => $p['title'] ?? '',
				'description' => $p['description'] ?? '',
				'categories'  => $p['categories'] ?? [],
				'keywords'    => $p['keywords'] ?? [],
			];
		}

		return $patterns;
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

			if ( ! preg_match( '/^elayne\/[a-z0-9-]+$/', $slug ) ) {
				continue;
			}

			if ( ! in_array( $slug, $registered_slugs, true ) ) {
				continue;
			}

			$content .= '<!-- wp:pattern {"slug":"' . esc_attr( $slug ) . '"} /-->' . "\n\n";
		}

		if ( empty( $content ) ) {
			return new \WP_Error( 'no_valid_patterns', 'No valid Elayne patterns were selected.' );
		}

		return wp_insert_post(
			[
				'post_title'   => sanitize_text_field( $title ),
				'post_content' => $content,
				'post_status'  => in_array( $status, [ 'draft', 'publish' ], true ) ? $status : 'draft',
				'post_type'    => 'page',
			],
			true
		);
	}
}
