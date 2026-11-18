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

	private const IMAGE_MARKUP = <<<'HTML'
<!-- wp:image {"sizeSlug":"large","className":"is-style-default"} -->
<figure class="wp-block-image size-large is-style-default"><img src="https://example.test/patterns/images/spa/massage.webp" alt="Back massage treatment"/></figure>
<!-- /wp:image -->

<!-- wp:image {"width":"24px","height":"24px","sizeSlug":"full"} -->
<figure class="wp-block-image size-full"><img src="https://example.test/patterns/images/icon-phone.png" alt="Phone"/></figure>
<!-- /wp:image -->

<!-- wp:image {"sizeSlug":"full"} -->
<figure class="wp-block-image size-full"><img src="https://example.test/patterns/images/arrow.svg" alt="Arrow"/></figure>
<!-- /wp:image -->

<!-- wp:cover {"url":"https://example.test/patterns/images/desktop.webp","dimRatio":0,"alt":"Studio"} -->
<span class="wp-block-cover__background"></span>
<!-- /wp:cover -->
HTML;

	public function test_images_lists_img_and_cover_slots_in_document_order(): void {
		$this->assertSame(
			[
				[ 'url' => 'https://example.test/patterns/images/spa/massage.webp', 'alt' => 'Back massage treatment' ],
				[ 'url' => 'https://example.test/patterns/images/desktop.webp', 'alt' => 'Studio' ],
			],
			Text_Replace::images( self::IMAGE_MARKUP )
		);
	}

	public function test_images_skips_svgs_and_small_decorative_icons(): void {
		$this->assertNotContains( 'https://example.test/patterns/images/icon-phone.png', array_column( Text_Replace::images( self::IMAGE_MARKUP ), 'url' ) );
		$this->assertNotContains( 'https://example.test/patterns/images/arrow.svg', array_column( Text_Replace::images( self::IMAGE_MARKUP ), 'url' ) );
	}

	public function test_images_deduplicates_repeated_urls(): void {
		$markup = '<!-- wp:image --><figure><img src="https://example.test/a.webp" alt="A"/></figure><!-- /wp:image -->'
			. '<!-- wp:image --><figure><img src="https://example.test/a.webp" alt="A again"/></figure><!-- /wp:image -->';

		$this->assertCount( 1, Text_Replace::images( $markup ) );
	}

	public function test_apply_swaps_img_src_and_alt(): void {
		$out = Text_Replace::apply(
			self::IMAGE_MARKUP,
			[],
			[],
			[
				'https://example.test/patterns/images/spa/massage.webp' => [
					'url' => 'https://example.test/wp-content/uploads/2026/11/hot-stones.webp',
					'alt' => 'Hot stone massage',
					'id'  => 42,
				],
			]
		);

		$this->assertStringContainsString( '<img src="https://example.test/wp-content/uploads/2026/11/hot-stones.webp" alt="Hot stone massage"/>', $out );
		$this->assertStringNotContainsString( 'spa/massage.webp', $out );
	}

	public function test_apply_adds_wp_image_class_for_attachments(): void {
		$markup = '<!-- wp:image {"id":7,"sizeSlug":"full"} --><figure><img src="https://example.test/old.webp" alt="Old" class="wp-image-7"/></figure><!-- /wp:image -->';

		$out = Text_Replace::apply( $markup, [], [], [ 'https://example.test/old.webp' => [ 'url' => 'https://example.test/new.webp', 'alt' => 'New', 'id' => 88 ] ] );

		$this->assertStringContainsString( 'class="wp-image-88"', $out );
	}

	public function test_apply_swaps_cover_url_attribute(): void {
		$out = Text_Replace::apply(
			self::IMAGE_MARKUP,
			[],
			[],
			[ 'https://example.test/patterns/images/desktop.webp' => [ 'url' => 'https://example.test/wp-content/uploads/2026/11/team.webp', 'alt' => '' ] ]
		);

		$this->assertStringContainsString( '"url":"https://example.test/wp-content/uploads/2026/11/team.webp"', $out );
	}

	public function test_apply_keeps_unmatched_images_byte_identical(): void {
		$map = [ 'https://nowhere.test/missing.webp' => [ 'url' => 'https://example.test/new.webp', 'alt' => 'X' ] ];

		$this->assertSame( self::IMAGE_MARKUP, Text_Replace::apply( self::IMAGE_MARKUP, [], [], $map ) );
	}

	public function test_unmatched_images_reports_urls_without_slots(): void {
		$map = [
			'https://example.test/patterns/images/desktop.webp' => [ 'url' => 'https://example.test/new.webp' ],
			'https://nowhere.test/missing.webp'                  => [ 'url' => 'https://example.test/new.webp' ],
		];

		$this->assertSame( [ 'https://nowhere.test/missing.webp' ], Text_Replace::unmatched_images( self::IMAGE_MARKUP, $map ) );
	}

	public function test_every_listed_image_is_a_valid_slot(): void {
		$slots = Text_Replace::images( self::IMAGE_MARKUP );
		$map   = array_combine(
			array_column( $slots, 'url' ),
			array_map( fn( $i ) => [ 'url' => "https://example.test/new-{$i}.webp", 'alt' => "Alt {$i}" ], array_keys( $slots ) )
		);

		$this->assertSame( [], Text_Replace::unmatched_images( self::IMAGE_MARKUP, $map ) );
		$this->assertSame( array_values( array_column( $map, 'url' ) ), array_column( Text_Replace::images( Text_Replace::apply( self::IMAGE_MARKUP, [], [], $map ) ), 'url' ) );
	}
}
