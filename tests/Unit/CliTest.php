<?php

namespace Imagewize\Waygate\Tests\Unit;

use Imagewize\Waygate\CLI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

class CliTest extends TestCase {

	public function test_parse_map_accepts_id_and_slug_entries(): void {
		$pages = CLI::parse_map(
			json_encode(
				[
					'pages' => [
						[ 'id' => 5, 'patterns' => [ 'elayne/legal-hero-form' ] ],
						[
							'slug'     => 'about',
							'title'    => 'About us',
							'patterns' => [ 'elayne/page-legal-about' ],
							'template' => 'page-no-title',
						],
					],
				]
			)
		);

		$this->assertIsArray( $pages );
		$this->assertCount( 2, $pages );
		$this->assertSame( 5, $pages[0]['id'] );
		$this->assertSame( 'draft', $pages[0]['status'] );
		$this->assertSame( 'about', $pages[1]['slug'] );
		$this->assertSame( 'About us', $pages[1]['title'] );
		$this->assertSame( 'page-no-title', $pages[1]['template'] );
	}

	public function test_parse_map_accepts_sections_with_pairs(): void {
		$pages = CLI::parse_map(
			json_encode(
				[
					'pages' => [
						[
							'slug'     => 'home',
							'patterns' => [
								[
									'pattern' => 'aludra/section-hero-split',
									'texts'   => [ [ 'find' => 'Fast Sites.', 'replace' => 'Good Nails.' ] ],
								],
								'elayne/salon-services',
							],
						],
					],
				]
			)
		);

		$this->assertIsArray( $pages );
		$this->assertSame(
			[
				[
					'pattern' => 'aludra/section-hero-split',
					'texts'   => [ [ 'find' => 'Fast Sites.', 'replace' => 'Good Nails.' ] ],
					'links'   => [],
					'images'  => [],
				],
				[ 'pattern' => 'elayne/salon-services', 'texts' => [], 'links' => [], 'images' => [] ],
			],
			$pages[0]['patterns']
		);
	}

	public function test_section_stub_pairs_every_text_and_link_with_itself(): void {
		$stub = CLI::section_stub(
			[
				'slug'  => 'elayne/hero',
				'texts' => [ 'Built for', 'Call us' ],
				'links' => [ '/contact' ],
			]
		);

		$this->assertSame(
			[
				'pattern' => 'elayne/hero',
				'texts'   => [ [ 'find' => 'Built for', 'replace' => 'Built for' ], [ 'find' => 'Call us', 'replace' => 'Call us' ] ],
				'links'   => [ [ 'find' => '/contact', 'replace' => '/contact' ] ],
			],
			$stub
		);
	}

	#[DataProvider( 'invalid_maps' )]
	public function test_parse_map_rejects_invalid_maps( string $json, string $expected ): void {
		$result = CLI::parse_map( $json );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( $expected, $result->get_error_message() );
	}

	public static function invalid_maps(): array {
		return [
			'not json'         => [ 'nope', '"pages" array' ],
			'no pages'         => [ '{"pages":[]}', '"pages" array' ],
			'no id or slug'    => [ '{"pages":[{"patterns":["elayne/a"]}]}', '"id" or a "slug"' ],
			'no patterns'      => [ '{"pages":[{"id":5}]}', '"patterns" array' ],
			'bad status'       => [ '{"pages":[{"id":5,"patterns":["elayne/a"],"status":"trash"}]}', 'status' ],
			'page not object'  => [ '{"pages":["elayne/a"]}', 'must be an object' ],
			'section no slug'  => [ '{"pages":[{"id":5,"patterns":[{"texts":[]}]}]}', 'pages[0].patterns[0]' ],
			'bad pair'         => [ '{"pages":[{"id":5,"patterns":[{"pattern":"elayne/a","texts":[{"find":"x"}]}]}]}', 'find' ],
		];
	}
}
