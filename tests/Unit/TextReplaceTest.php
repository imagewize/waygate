<?php

namespace Imagewize\Waygate\Tests\Unit;

use Imagewize\Waygate\Text_Replace;
use PHPUnit\Framework\TestCase;

class TextReplaceTest extends TestCase {

	private const MARKUP = <<<'HTML'
<!-- wp:group {"className":"is-style-hero","layout":{"type":"constrained"}} -->
<div class="wp-block-group is-style-hero"><!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Fast Sites. <em>Real Results.</em></h1>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>WordPress &amp; WooCommerce</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact?a=1&amp;b=2">Get in touch</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:imagewize/faq {"question":"What do you offer?","url":"https://example.com/faq"} /--></div>
<!-- /wp:group -->
HTML;

	public function test_texts_lists_runs_then_text_attributes(): void {
		$this->assertSame(
			[ 'Fast Sites.', 'Real Results.', 'WordPress & WooCommerce', 'Get in touch', 'What do you offer?' ],
			Text_Replace::texts( self::MARKUP )
		);
	}

	public function test_links_lists_hrefs_decoded_and_url_attributes(): void {
		$this->assertSame( [ '/contact?a=1&b=2', 'https://example.com/faq' ], Text_Replace::links( self::MARKUP ) );
	}

	public function test_texts_skip_html_comments_and_deduplicate(): void {
		$this->assertSame( [ 'A', 'B' ], Text_Replace::texts( '<p>A</p><!-- note --><p> A </p><p>B</p>' ) );
	}

	public function test_layout_attributes_are_not_copy(): void {
		$markup = '<!-- wp:column {"width":"58%","verticalAlignment":"top","borderColor":"border-light"} --><div class="wp-block-column"></div><!-- /wp:column -->'
			. '<!-- wp:cover {"overlayColor":"primary","contentPosition":"center left","minHeightUnit":"px","alt":"Harbor at sunset"} --><div class="wp-block-cover"></div><!-- /wp:cover -->'
			. '<!-- wp:social-link {"service":"instagram","iconColorValue":"#fff"} /-->'
			. '<!-- wp:image {"aspectRatio":"3/4","width":"48px","height":"20px"} /-->';

		$this->assertSame( [ 'Harbor at sunset' ], Text_Replace::texts( $markup ) );
	}

	public function test_stat_values_stay_copy(): void {
		$markup = '<!-- wp:aludra/stat-item {"number":"-71%","caption":"Page weight"} /-->'
			. '<!-- wp:aludra/stat-item {"number":"24/7","caption":"Support"} /-->'
			. '<!-- wp:aludra/stat-item {"number":"20","caption":"Years"} /-->'
			. '<!-- wp:woocommerce/customer-account {"iconStyle":"line","iconClass":"x-icon","imageSizing":"thumbnail"} /-->';

		$this->assertSame( [ '-71%', 'Page weight', '24/7', 'Support', '20', 'Years' ], Text_Replace::texts( $markup ) );
	}

	public function test_apply_replaces_whole_runs_and_escapes(): void {
		$out = Text_Replace::apply( self::MARKUP, [ 'Fast Sites.' => 'Fish & <Chips>', 'Real Results.' => 'Fair prices.' ], [] );

		$this->assertStringContainsString( '<h1 class="wp-block-heading">Fish &amp; &lt;Chips&gt; <em>Fair prices.</em></h1>', $out );
	}

	public function test_apply_matches_entity_encoded_text(): void {
		$out = Text_Replace::apply( self::MARKUP, [ 'WordPress & WooCommerce' => 'Cuts and colour' ], [] );

		$this->assertStringContainsString( '<p>Cuts and colour</p>', $out );
	}

	public function test_apply_ignores_partial_matches(): void {
		$this->assertSame( self::MARKUP, Text_Replace::apply( self::MARKUP, [ 'Fast' => 'Slow', 'touch' => 'x' ], [] ) );
	}

	public function test_apply_never_touches_structural_attributes(): void {
		$out = Text_Replace::apply( self::MARKUP, [ 'is-style-hero' => 'evil', 'constrained' => 'evil' ], [] );

		$this->assertSame( self::MARKUP, $out );
	}

	public function test_apply_rewrites_text_attribute_and_reencodes_like_core(): void {
		$out = Text_Replace::apply( self::MARKUP, [ 'What do you offer?' => 'Do you take walk-ins -- or <bookings> & "groups"?' ], [] );

		$this->assertStringContainsString(
			'<!-- wp:imagewize/faq {"question":"Do you take walk-ins \u002d\u002d or \u003cbookings\u003e \u0026 \u0022groups\u0022?","url":"https://example.com/faq"} /-->',
			$out
		);
		// Unchanged delimiters keep their exact bytes.
		$this->assertStringContainsString( '<!-- wp:group {"className":"is-style-hero","layout":{"type":"constrained"}} -->', $out );
	}

	public function test_apply_replaces_links_in_html_and_attributes(): void {
		$out = Text_Replace::apply(
			self::MARKUP,
			[],
			[
				'/contact?a=1&b=2'        => '/book?x=1&y=2',
				'https://example.com/faq' => 'https://salon.test/faq',
			]
		);

		$this->assertStringContainsString( 'href="/book?x=1&amp;y=2">Get in touch</a>', $out );
		$this->assertStringContainsString( '"url":"https://salon.test/faq"', $out );
	}

	public function test_apply_without_pairs_returns_markup_unchanged(): void {
		$this->assertSame( self::MARKUP, Text_Replace::apply( self::MARKUP, [], [] ) );
	}

	public function test_unmatched_reports_finds_that_match_nothing(): void {
		$this->assertSame(
			[ 'Fast', '/nowhere' ],
			Text_Replace::unmatched( self::MARKUP, [ 'Fast' => 'x', 'Get in touch' => 'Book' ], [ '/nowhere' => '/x', '/contact?a=1&b=2' => '/y' ] )
		);
	}

	public function test_pairs_to_map_trims_finds_and_sanitizes_replacements(): void {
		$map = Text_Replace::pairs_to_map(
			[
				[ 'find' => ' Old ', 'replace' => '<b>New</b>' ],
				[ 'find' => '', 'replace' => 'dropped' ],
				[ 'find' => 'no replace' ],
				'not a pair',
			],
			'sanitize_text_field'
		);

		$this->assertSame( [ 'Old' => 'New' ], $map );
	}

	public function test_every_listed_text_is_a_valid_find(): void {
		$texts = Text_Replace::texts( self::MARKUP );
		$map   = array_combine( $texts, array_map( fn( $i ) => "T{$i}", array_keys( $texts ) ) );

		$this->assertSame( [], Text_Replace::unmatched( self::MARKUP, $map, [] ) );
		$this->assertSame( array_values( $map ), Text_Replace::texts( Text_Replace::apply( self::MARKUP, $map, [] ) ) );
	}
}
