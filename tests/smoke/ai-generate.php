<?php
/**
 * Smoke test: generate a page with the configured AI provider and report what came back.
 *
 * Needs a site with Waygate active and a working AI provider. Not part of the PHPUnit suite.
 *
 *     wp eval-file tests/smoke/ai-generate.php "<description>" [vertical] --url=<site>
 *
 * Creates a draft page; trash it when done.
 *
 * @package Imagewize\Waygate
 */

use Imagewize\Waygate\AI_Integration;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.

$description = $args[0] ?? 'Homepage for a carpenter: kitchens, staircases, doors and repairs. Hero, services, social proof and a call to action.';
$vertical    = $args[1] ?? '';

wp_set_current_user( 1 );

$start  = microtime( true );
$result = AI_Integration::generate_page( $description, true, $vertical );

WP_CLI::log( sprintf( 'Time: %.1fs', microtime( true ) - $start ) );
WP_CLI::log( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

if ( empty( $result['edit_url'] ) || ! preg_match( '/post=(\d+)/', $result['edit_url'], $m ) ) {
	WP_CLI::error( 'No page was created.' );
}

$content = get_post( (int) $m[1] )->post_content;

WP_CLI::log( 'wp:pattern references: ' . substr_count( $content, '<!-- wp:pattern ' ) );
WP_CLI::log( 'Texts on the page:' );

preg_match_all( '#<(h1|h2|h3|h4|p|a|label)\b[^>]*>(.*?)</\1>#s', $content, $matches );

foreach ( $matches[2] as $text ) {
	$text = trim( wp_strip_all_tags( $text ) );
	if ( '' !== $text ) {
		WP_CLI::log( '- ' . $text );
	}
}
