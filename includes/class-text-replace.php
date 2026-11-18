<?php
/**
 * Swap copy and links in pattern markup without breaking block validation.
 *
 * @package Imagewize\Waygate
 */

namespace Imagewize\Waygate;

defined( 'ABSPATH' ) || exit;

/**
 * Exact find/replace of text runs, text attributes and link targets in block markup.
 *
 * A find string matches a whole text run (the text between two tags) or a whole text
 * attribute in a block comment, never part of one, so the markup and the block's
 * attributes stay in step and the block still validates in the editor. Replacement text
 * is escaped and can never add markup.
 *
 * Ported from Aludra's aludra/get-pattern and aludra/compose-page, and kept compatible
 * with them: the texts and links this class lists are valid find strings for both.
 * Pure PHP on the raw markup (no parse_blocks()), so it runs in unit tests.
 */
class Text_Replace {

	/**
	 * Block attributes that hold structure, not copy. Never listed or replaced as text.
	 *
	 * @var string[]
	 */
	private const STRUCTURAL_ATTRS = array(
		'className',
		'anchor',
		'align',
		'style',
		'layout',
		'metadata',
		'lock',
		'tagName',
		'textAlign',
		'fontSize',
		'fontFamily',
		'backgroundColor',
		'textColor',
		'gradient',
		'ref',
		'slug',
		'theme',
		'area',
		'id',
		'url',
		'href',
		'linkTarget',
		'rel',
		'sizeSlug',
		'linkDestination',
		'displayMode',
		'engine',
		'layoutMode',
		'menuSlug',
		'width',
		'height',
		'scale',
		'size',
		'kind',
		'type',
		'term',
		'service',
		'icon',
		'overlayMenu',
		'imageSizing',
	);

	/**
	 * Attribute name endings that mark structure: colours, units, alignment, positions, ratios,
	 * style and class names.
	 */
	private const STRUCTURAL_SUFFIX = '/(Color|ColorValue|Unit|Alignment|Position|Ratio|Style|Class)$/';

	/**
	 * Values that are never copy: CSS lengths with a unit and hex colours. Bare numbers and
	 * percentages are left alone, since a stat such as "-71%" is copy.
	 */
	private const STRUCTURAL_VALUE = '/^\s*(-?\d+(\.\d+)?(px|em|rem|vh|vw|ch)|#[0-9a-f]{3,8})\s*$/i';

	/**
	 * Block attributes that hold a link target.
	 *
	 * @var string[]
	 */
	private const LINK_ATTRS = array( 'url', 'href' );

	/**
	 * Block comment delimiter, as matched by WordPress's block parser.
	 */
	private const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<name>[a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Raster image file extensions. Anything else (notably SVG) is decorative, not a slot.
	 */
	private const RASTER_IMAGE = '/\.(jpe?g|png|webp|gif|avif)([?#]|$)/i';

	/**
	 * Replaceable image slots in block markup, numbered from 0 in document order.
	 *
	 * A slot is a photo the user may swap for their own: the src of a core/image block's
	 * <img> tag, or the url of a block comment (core/cover's background). Decorative
	 * graphics are not slots: SVGs, and an image block sized below 100px (icons, stars).
	 * The same URL in several places is one slot; replacing it replaces every occurrence.
	 *
	 * @param string $content Block markup.
	 * @return array<int, array{url: string, alt: string}> Slots, numbered from 0.
	 */
	public static function images( string $content ): array {
		$slots = array();
		$small = false;

		foreach ( self::tokens( $content ) as $token ) {
			if ( is_string( $token ) ) {
				foreach ( self::html_images( $token, $small ) as $slot ) {
					$slots[ $slot['url'] ] = $slot;
				}
				continue;
			}

			$small = false;

			$is_opener = ! str_starts_with( $token['raw'], '<!-- /' );
			$url_attr  = $token['attrs']['url'] ?? null;

			if ( $is_opener && is_string( $url_attr ) && self::is_raster_image( $url_attr ) ) {
				$url = html_entity_decode( $url_attr, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( ! isset( $slots[ $url ] ) ) {
					$slots[ $url ] = array(
						'url' => $url,
						'alt' => (string) ( $token['attrs']['alt'] ?? '' ),
					);
				}
			}

			// A wp:image opener marks the <img> tag that follows it; remember icon sizes.
			if ( $is_opener && 'image' === $token['name'] ) {
				$w     = $token['attrs']['width'] ?? null;
				$h     = $token['attrs']['height'] ?? null;
				$small = ( null !== $w && self::px( $w ) < 100 ) || ( null !== $h && self::px( $h ) < 100 );
			}
		}

		return array_values( $slots );
	}

	/**
	 * Image slots in an HTML fragment, honoring the icon flag of the preceding block.
	 *
	 * @param string $html         HTML without block comments.
	 * @param bool   $small_before True when the preceding core/image block is icon-sized.
	 * @return array<int, array{url: string, alt: string}>
	 */
	private static function html_images( string $html, bool $small_before ): array {
		preg_match_all( '/<img\b[^>]*>/i', $html, $tags );

		$slots = array();

		foreach ( $tags[0] as $tag ) {
			if ( ! preg_match( '/\bsrc=(["\'])(.*?)\1/s', $tag, $src ) ) {
				continue;
			}

			$url = html_entity_decode( $src[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			if ( ! self::is_raster_image( $url ) || $small_before ) {
				continue;
			}

			$alt = '';
			if ( preg_match( '/\balt=(["\'])(.*?)\1/s', $tag, $alt_match ) ) {
				$alt = html_entity_decode( $alt_match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}

			$slots[] = array(
				'url' => $url,
				'alt' => $alt,
			);
		}

		return $slots;
	}

	/**
	 * Whether a URL points at a raster image file (not an SVG or other resource).
	 *
	 * @param string $url URL as written in the markup.
	 * @return bool
	 */
	private static function is_raster_image( string $url ): bool {
		return (bool) preg_match( self::RASTER_IMAGE, html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * The numeric value of an attribute such as "24px" or 24; 0 when absent or empty.
	 *
	 * @param mixed $value Attribute value.
	 * @return float
	 */
	private static function px( $value ): float {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return 0.0;
		}

		$number = (float) preg_replace( '/[^0-9.]/', '', (string) $value );

		return $number;
	}

	/**
	 * Replaceable copy in block markup: HTML text runs, then text attributes, in document
	 * order and without duplicates.
	 *
	 * @param string $content Block markup.
	 * @return string[]
	 */
	public static function texts( string $content ): array {
		$texts = array();

		foreach ( self::tokens( $content ) as $token ) {
			if ( is_string( $token ) ) {
				foreach ( self::html_texts( $token ) as $text ) {
					$texts[ $text ] = true;
				}
			}
		}

		foreach ( self::tokens( $content ) as $token ) {
			if ( is_array( $token ) ) {
				foreach ( $token['attrs'] as $key => $value ) {
					if ( self::is_text_attr( (string) $key, $value ) ) {
						$texts[ trim( $value ) ] = true;
					}
				}
			}
		}

		return array_map( 'strval', array_keys( $texts ) );
	}

	/**
	 * Distinct link targets in block markup: `href` on links and `url`/`href` block attributes.
	 *
	 * @param string $content Block markup.
	 * @return string[]
	 */
	public static function links( string $content ): array {
		$links = array();

		foreach ( self::tokens( $content ) as $token ) {
			if ( is_string( $token ) ) {
				preg_match_all( '/<a\b[^>]*?\shref=(["\'])(.*?)\1/is', $token, $matches );
				foreach ( $matches[2] as $href ) {
					$href = html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					if ( '' !== $href ) {
						$links[ $href ] = true;
					}
				}
				continue;
			}

			foreach ( self::LINK_ATTRS as $key ) {
				if ( isset( $token['attrs'][ $key ] ) && is_string( $token['attrs'][ $key ] ) && '' !== $token['attrs'][ $key ] ) {
					$links[ $token['attrs'][ $key ] ] = true;
				}
			}
		}

		return array_map( 'strval', array_keys( $links ) );
	}

	/**
	 * Apply text, link and image replacements to block markup.
	 *
	 * Block comments whose attributes do not change are kept byte for byte. An image
	 * replacement is keyed by the slot's current URL; its "alt" and "id" are optional.
	 *
	 * @param string                                              $content Block markup.
	 * @param array<string,string>                                $texts   Plain-text find => plain-text replace.
	 * @param array<string,string>                                $links   URL find => sanitized URL replace.
	 * @param array<string,array{url:string,alt?:string,id?:int}> $images Slot URL find => replacement.
	 * @return string
	 */
	public static function apply( string $content, array $texts, array $links, array $images = array() ): string {
		if ( empty( $texts ) && empty( $links ) && empty( $images ) ) {
			return $content;
		}

		$out = '';

		foreach ( self::tokens( $content ) as $token ) {
			if ( is_string( $token ) ) {
				$out .= self::replace_imgs( self::replace_hrefs( self::replace_html_text( $token, $texts ), $links ), $images );
				continue;
			}

			$attrs   = $token['attrs'];
			$changed = false;

			foreach ( $attrs as $key => $value ) {
				$key = (string) $key;
				if ( self::is_text_attr( $key, $value ) && array_key_exists( trim( (string) $value ), $texts ) ) {
					$attrs[ $key ] = $texts[ trim( (string) $value ) ];
					$changed       = true;
				} elseif ( in_array( $key, self::LINK_ATTRS, true ) && is_string( $value ) && array_key_exists( $value, $links ) ) {
					$attrs[ $key ] = $links[ $value ];
					$changed       = true;
				} elseif ( 'url' === $key && is_string( $value ) && self::has_image( $value, $images ) ) {
					$attrs[ $key ] = $images[ html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ]['url'];
					$changed       = true;
				}
			}

			$out .= $changed ? self::delimiter( $token['name'], $attrs, $token['void'] ) : $token['raw'];
		}

		return $out;
	}

	/**
	 * Whether a URL as written in the markup (entity-encoded, maybe quoted) is an image key.
	 *
	 * @param string $url    URL as written in the markup.
	 * @param array  $images Image replacement map.
	 * @return bool
	 */
	private static function has_image( string $url, array $images ): bool {
		$decoded = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return isset( $images[ $decoded ] ) && is_array( $images[ $decoded ] ) && ! empty( $images[ $decoded ]['url'] );
	}

	/**
	 * Find strings that match nothing in the markup, so a caller can report lost replacements.
	 *
	 * @param string               $content Block markup.
	 * @param array<string,string> $texts   Text find => replace.
	 * @param array<string,string> $links   URL find => replace.
	 * @return string[]
	 */
	public static function unmatched( string $content, array $texts, array $links ): array {
		return array_values(
			array_merge(
				array_diff( array_map( 'strval', array_keys( $texts ) ), self::texts( $content ) ),
				array_diff( array_map( 'strval', array_keys( $links ) ), self::links( $content ) )
			)
		);
	}

	/**
	 * Image find URLs that match no image slot, so a caller can report lost replacements.
	 *
	 * @param string                                              $content Block markup.
	 * @param array<string,array{url:string,alt?:string,id?:int}> $images Slot URL find => replacement.
	 * @return string[] Find URLs without a slot.
	 */
	public static function unmatched_images( string $content, array $images ): array {
		return array_values(
			array_diff( array_map( 'strval', array_keys( $images ) ), array_column( self::images( $content ), 'url' ) )
		);
	}

	/**
	 * Swap the src, alt and wp-image class of <img> tags whose slot is being replaced.
	 *
	 * The alt attribute is only touched when the replacement carries one, so a pattern
	 * alt survives when the caller has no better text. An "id" rewrites the wp-image-{id}
	 * class, which is how the editor recognizes media-library attachments.
	 *
	 * @param string                                              $html   HTML without block comments.
	 * @param array<string,array{url:string,alt?:string,id?:int}> $images Slot URL find => replacement.
	 * @return string
	 */
	private static function replace_imgs( string $html, array $images ): string {
		if ( empty( $images ) || ! str_contains( $html, '<img' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( array $m ) use ( $images ): string {
				$tag = $m[0];

				if ( ! preg_match( '/\bsrc=(["\'])(.*?)\1/s', $tag, $src ) ) {
					return $tag;
				}

				$url = html_entity_decode( $src[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				if ( ! isset( $images[ $url ] ) || ! is_array( $images[ $url ] ) || empty( $images[ $url ]['url'] ) ) {
					return $tag;
				}

				$new = $images[ $url ];

				$tag = (string) preg_replace_callback(
					'/\bsrc=(["\'])(.*?)\1/s',
					static function ( array $s ) use ( $new ): string {
						return 'src=' . $s[1] . htmlspecialchars( $new['url'], ENT_QUOTES, 'UTF-8' ) . $s[1];
					},
					$tag,
					1
				);

				if ( array_key_exists( 'alt', $new ) && is_string( $new['alt'] ) ) {
					$alt = htmlspecialchars( $new['alt'], ENT_QUOTES, 'UTF-8' );
					if ( preg_match( '/\balt=(["\'])(.*?)\1/s', $tag ) ) {
						$tag = (string) preg_replace_callback(
							'/\balt=(["\'])(.*?)\1/s',
							static fn( array $s ): string => 'alt=' . $s[1] . $alt . $s[1],
							$tag,
							1
						);
					} else {
						$tag = substr( $tag, 0, -1 ) . ' alt="' . $alt . '">';
					}
				}

				if ( ! empty( $new['id'] ) && preg_match( '/wp-image-(\d+)/', $tag, $class ) ) {
					$tag = str_replace( 'wp-image-' . $class[1], 'wp-image-' . (int) $new['id'], $tag );
				}

				return $tag;
			},
			$html
		);
	}

	/**
	 * Turn a list of {find, replace} pairs into a find => replace map.
	 *
	 * @param mixed    $pairs    List of pairs; anything else gives an empty map.
	 * @param callable $sanitize Applied to each replacement.
	 * @return array<string,string>
	 */
	public static function pairs_to_map( $pairs, callable $sanitize ): array {
		$map = array();

		foreach ( is_array( $pairs ) ? $pairs : array() as $pair ) {
			if ( is_array( $pair ) && isset( $pair['find'], $pair['replace'] ) && '' !== trim( (string) $pair['find'] ) ) {
				$map[ trim( (string) $pair['find'] ) ] = (string) $sanitize( (string) $pair['replace'] );
			}
		}

		return $map;
	}

	/**
	 * Whether a block attribute value is copy that replacement may touch.
	 *
	 * @param string $key   Attribute name.
	 * @param mixed  $value Attribute value.
	 * @return bool
	 */
	public static function is_text_attr( string $key, $value ): bool {
		return is_string( $value )
			&& '' !== trim( $value )
			&& ! in_array( $key, self::STRUCTURAL_ATTRS, true )
			&& ! preg_match( self::STRUCTURAL_SUFFIX, $key )
			&& ! preg_match( self::STRUCTURAL_VALUE, $value );
	}

	/**
	 * Replace exact text runs in an HTML fragment, keeping surrounding whitespace.
	 *
	 * @param string               $html HTML without block comments.
	 * @param array<string,string> $map  Plain-text find => plain-text replace.
	 * @return string
	 */
	public static function replace_html_text( string $html, array $map ): string {
		if ( '' === $html || empty( $map ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/>([^<>]+)</',
			static function ( array $m ) use ( $map ): string {
				$raw     = $m[1];
				$trimmed = trim( html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

				if ( '' === $trimmed || ! array_key_exists( $trimmed, $map ) ) {
					return $m[0];
				}

				preg_match( '/^\s*/u', $raw, $lead );
				preg_match( '/\s*$/u', $raw, $trail );

				return '>' . $lead[0] . htmlspecialchars( $map[ $trimmed ], ENT_NOQUOTES, 'UTF-8' ) . $trail[0] . '<';
			},
			$html
		);
	}

	/**
	 * Distinct text runs of an HTML fragment, HTML comments skipped, entities decoded.
	 *
	 * @param string $html HTML without block comments.
	 * @return string[]
	 */
	private static function html_texts( string $html ): array {
		preg_match_all( '/>([^<>]+)</', (string) preg_replace( '/<!--.*?-->/s', '', $html ), $matches );

		$texts = array();
		foreach ( $matches[1] as $raw ) {
			$text = trim( html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' !== $text ) {
				$texts[] = $text;
			}
		}

		return $texts;
	}

	/**
	 * Replace `href` targets on links in an HTML fragment.
	 *
	 * @param string               $html  HTML without block comments.
	 * @param array<string,string> $links URL find => replace.
	 * @return string
	 */
	private static function replace_hrefs( string $html, array $links ): string {
		if ( empty( $links ) || ! str_contains( $html, 'href' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/<a\b[^>]*?\shref=(["\'])(.*?)\1/is',
			static function ( array $m ) use ( $links ): string {
				$href = html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

				if ( ! array_key_exists( $href, $links ) ) {
					return $m[0];
				}

				// The match ends with the quoted value; swap only that.
				$value_at = strlen( $m[0] ) - strlen( $m[2] ) - 1;

				return substr( $m[0], 0, $value_at ) . htmlspecialchars( $links[ $href ], ENT_QUOTES, 'UTF-8' ) . $m[1];
			},
			$html
		);
	}

	/**
	 * Split block markup into HTML chunks (strings) and block comment delimiters (arrays).
	 *
	 * @param string $content Block markup.
	 * @return array<int,string|array{raw:string,name:string,attrs:array,void:bool}>
	 */
	private static function tokens( string $content ): array {
		$tokens = array();
		$offset = 0;

		preg_match_all( self::DELIMITER, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

		foreach ( $matches as $m ) {
			$start = $m[0][1];

			if ( $start > $offset ) {
				$tokens[] = substr( $content, $offset, $start - $offset );
			}

			$attrs = null;
			if ( isset( $m['attrs'] ) && '' !== $m['attrs'][0] ) {
				$attrs = json_decode( trim( $m['attrs'][0] ), true );
			}

			$tokens[] = array(
				'raw'   => $m[0][0],
				'name'  => $m['name'][0],
				'attrs' => is_array( $attrs ) && empty( $m['closer'][0] ) ? $attrs : array(),
				'void'  => isset( $m['void'] ) && '' !== $m['void'][0],
			);

			$offset = $start + strlen( $m[0][0] );
		}

		if ( $offset < strlen( $content ) ) {
			$tokens[] = substr( $content, $offset );
		}

		return $tokens;
	}

	/**
	 * An opening block comment with changed attributes, encoded as serialize_block_attributes() does.
	 *
	 * @param string $name  Block name, core/ prefix optional.
	 * @param array  $attrs Block attributes.
	 * @param bool   $self_closing Whether the block is self-closing.
	 * @return string
	 */
	private static function delimiter( string $name, array $attrs, bool $self_closing ): string {
		$json = (string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- mirrors serialize_block_attributes().
		$json = str_replace(
			array( '--', '<', '>', '&', '\\"' ),
			array( '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022' ),
			$json
		);

		return '<!-- wp:' . $name . ' ' . $json . ' ' . ( $self_closing ? '/-->' : '-->' );
	}
}
