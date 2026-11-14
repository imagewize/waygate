<?php
/**
 * WP-CLI commands: build pages from a pattern map, list patterns and show a pattern's texts.
 *
 * @package Imagewize\Waygate
 */

namespace Imagewize\Waygate;

defined( 'ABSPATH' ) || exit;

/**
 * Builds pages from patterns without AI, lists the pattern catalog and shows a pattern's
 * replaceable texts and links.
 */
class CLI {

	/**
	 * Parse and validate a build map.
	 *
	 * Format: {"pages":[{"id":5,"patterns":["elayne/legal-hero-form"]},
	 * {"slug":"about","title":"About","patterns":["elayne/page-legal-about"],"template":"default","status":"draft"}]}
	 * A page needs an `id` or a `slug`; `title`, `template` and `status` are optional.
	 * A pattern is a slug, or {"pattern":"elayne/hero","texts":[{"find":"…","replace":"…"}],"links":[…]}.
	 *
	 * @param string $json Raw map file contents.
	 * @return array[]|\WP_Error List of page entries, or WP_Error describing the first problem.
	 */
	public static function parse_map( string $json ) {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || empty( $data['pages'] ) || ! is_array( $data['pages'] ) ) {
			return new \WP_Error( 'invalid_map', 'Map must be a JSON object with a non-empty "pages" array.' );
		}

		$pages = array();

		foreach ( array_values( $data['pages'] ) as $i => $page ) {
			$label = 'pages[' . $i . ']';

			if ( ! is_array( $page ) ) {
				return new \WP_Error( 'invalid_map', "{$label} must be an object." );
			}

			$id   = isset( $page['id'] ) ? (int) $page['id'] : 0;
			$slug = isset( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : '';

			if ( $id <= 0 && '' === $slug ) {
				return new \WP_Error( 'invalid_map', "{$label} needs an \"id\" or a \"slug\"." );
			}

			if ( empty( $page['patterns'] ) || ! is_array( $page['patterns'] ) ) {
				return new \WP_Error( 'invalid_map', "{$label} needs a non-empty \"patterns\" array." );
			}

			$sections = array();
			foreach ( array_values( $page['patterns'] ) as $j => $entry ) {
				$section = Pattern_Lab::normalize_section( $entry );
				if ( is_wp_error( $section ) ) {
					return new \WP_Error( 'invalid_map', "{$label}.patterns[{$j}]: " . $section->get_error_message() );
				}
				$sections[] = $section;
			}

			$status = $page['status'] ?? 'draft';
			if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
				return new \WP_Error( 'invalid_map', "{$label} status must be \"draft\" or \"publish\"." );
			}

			$pages[] = array(
				'id'       => $id,
				'slug'     => $slug,
				'title'    => isset( $page['title'] ) ? sanitize_text_field( (string) $page['title'] ) : '',
				'patterns' => $sections,
				'template' => isset( $page['template'] ) ? sanitize_text_field( (string) $page['template'] ) : '',
				'status'   => $status,
			);
		}

		return $pages;
	}

	/**
	 * Builds pages from a JSON map of patterns, without AI.
	 *
	 * Each page's content is replaced with its patterns' content, as a fresh insert in the
	 * block editor would store it, with each section's `texts` and `links` pairs applied. Pages found by `id` or `slug` are updated; a `slug` with no
	 * matching page creates one.
	 *
	 * ## OPTIONS
	 *
	 * <map>
	 * : Path to the JSON map file.
	 *
	 * [--dry-run]
	 * : Show what would change without writing.
	 *
	 * [--references]
	 * : Store `wp:pattern` reference blocks instead of inlining the pattern content. Fails a
	 * page whose sections carry texts or links.
	 *
	 * ## EXAMPLES
	 *
	 *     wp waygate build legal.json --dry-run
	 *     wp waygate build legal.json --url=demo.imagewize.test/legal/
	 *
	 * @param string[] $args       Positional arguments.
	 * @param array    $assoc_args Flags.
	 */
	public function build( array $args, array $assoc_args ): void {
		$file = $args[0];

		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( "Cannot read map file: {$file}" );
		}

		$pages = self::parse_map( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

		if ( is_wp_error( $pages ) ) {
			\WP_CLI::error( $pages->get_error_message() );
		}

		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$expand  = ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'references', false );
		$failed  = 0;

		foreach ( $pages as $page ) {
			$composed = Pattern_Lab::compose( $page['patterns'], $expand );
			$label    = $page['id'] > 0 ? "page {$page['id']}" : "page /{$page['slug']}/";

			if ( is_wp_error( $composed ) ) {
				\WP_CLI::warning( "{$label}: " . $composed->get_error_message() );
				++$failed;
				continue;
			}

			$content = $composed['content'];

			foreach ( $composed['warnings'] as $warning ) {
				\WP_CLI::warning( "{$label}: {$warning}" );
			}

			$post = $page['id'] > 0 ? get_post( $page['id'] ) : get_page_by_path( $page['slug'] );

			if ( $page['id'] > 0 && ( ! $post || 'page' !== $post->post_type ) ) {
				\WP_CLI::warning( "{$label}: no page with this ID." );
				++$failed;
				continue;
			}

			$source = implode( ' + ', array_column( $page['patterns'], 'pattern' ) );

			if ( $dry_run ) {
				$action = $post ? 'update' : 'create';
				\WP_CLI::log( "DRY-RUN {$action} {$label} ← {$source} (" . strlen( $content ) . ' bytes)' );
				continue;
			}

			$postarr = array( 'post_content' => $content );

			if ( '' !== $page['template'] ) {
				$postarr['page_template'] = $page['template'];
			}

			if ( $post ) {
				$postarr['ID'] = $post->ID;
				// wp_update_post() unslashes its input; slash it so backslashes in block JSON survive.
				$result = wp_update_post( wp_slash( $postarr ), true );
			} else {
				$postarr['post_type']   = 'page';
				$postarr['post_name']   = $page['slug'];
				$postarr['post_title']  = '' !== $page['title'] ? $page['title'] : ucwords( str_replace( '-', ' ', $page['slug'] ) );
				$postarr['post_status'] = $page['status'];
				$result                 = wp_insert_post( wp_slash( $postarr ), true );
			}

			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( "{$label}: " . $result->get_error_message() );
				++$failed;
				continue;
			}

			\WP_CLI::success( ( $post ? 'Updated' : 'Created' ) . " page {$result} ← {$source}" );
		}

		if ( $failed > 0 ) {
			\WP_CLI::error( "{$failed} page(s) failed." );
		}
	}

	/**
	 * A build-map section for a pattern, with every text and link as a pair whose replace
	 * equals its find: ready to edit.
	 *
	 * @param array $pattern From Pattern_Lab::get_pattern().
	 * @return array{pattern:string,texts:array[],links:array[]}
	 */
	public static function section_stub( array $pattern ): array {
		$pair = fn( string $value ): array => array(
			'find'    => $value,
			'replace' => $value,
		);

		return array(
			'pattern' => $pattern['slug'],
			'texts'   => array_map( $pair, $pattern['texts'] ),
			'links'   => array_map( $pair, $pattern['links'] ),
		);
	}

	/**
	 * Shows a pattern's replaceable texts and links.
	 *
	 * The texts and links are the exact find strings for `texts` and `links` pairs in a build
	 * map or in waygate/create-page.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Pattern slug, e.g. elayne/legal-hero-form.
	 *
	 * [--stub]
	 * : Print a build-map section with every text and link as a {find, replace} pair, ready to edit.
	 *
	 * [--content]
	 * : With --format=json, include the pattern's block markup.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp waygate get elayne/legal-hero-form
	 *     wp waygate get elayne/legal-hero-form --stub > hero.json
	 *
	 * @param string[] $args       Positional arguments.
	 * @param array    $assoc_args Flags.
	 */
	public function get( array $args, array $assoc_args ): void {
		$pattern = Pattern_Lab::get_pattern( $args[0] );

		if ( null === $pattern ) {
			\WP_CLI::error( "Unknown pattern: {$args[0]}. Run `wp waygate list` for valid slugs." );
		}

		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'stub', false ) ) {
			\WP_CLI::line( (string) wp_json_encode( self::section_stub( $pattern ), $flags ) );
			return;
		}

		if ( 'json' === \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'content', false ) ) {
				unset( $pattern['content'] );
			}
			\WP_CLI::line( (string) wp_json_encode( $pattern, $flags ) );
			return;
		}

		$rows = array();
		foreach ( array(
			'text' => $pattern['texts'],
			'link' => $pattern['links'],
		) as $type => $values ) {
			foreach ( $values as $value ) {
				$rows[] = array(
					'type'  => $type,
					'value' => $value,
				);
			}
		}

		\WP_CLI::log( "{$pattern['slug']} — {$pattern['title']}" );
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'type', 'value' ) );
	}

	/**
	 * Lists registered patterns.
	 *
	 * ## OPTIONS
	 *
	 * [--vertical=<vertical>]
	 * : Only patterns in this vertical, e.g. legal.
	 *
	 * [--sections]
	 * : Only patterns that can be placed as a page section (no headers, footers, templates or page composites).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp waygate list --vertical=legal --sections
	 *
	 * @subcommand list
	 *
	 * @param string[] $args       Positional arguments.
	 * @param array    $assoc_args Flags.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$patterns = Pattern_Lab::get_patterns(
			array(
				'sections_only' => (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'sections', false ),
				'vertical'      => (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'vertical', '' ),
			)
		);

		usort( $patterns, fn( $a, $b ) => strcmp( $a['slug'], $b['slug'] ) );

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( 'ids' === $format ) {
			\WP_CLI::log( implode( ' ', array_column( $patterns, 'slug' ) ) );
			return;
		}

		$rows = array_map(
			fn( $p ) => array(
				'slug'       => $p['slug'],
				'title'      => $p['title'],
				'categories' => implode( ', ', $p['categories'] ),
			),
			$patterns
		);

		\WP_CLI\Utils\format_items( $format, $rows, array( 'slug', 'title', 'categories' ) );
	}
}
