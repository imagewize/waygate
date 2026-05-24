<?php

define( 'ABSPATH', __DIR__ . '/../' );

// --- Minimal WordPress function stubs ---

$GLOBALS['wp_filters'] = [];

function add_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['wp_filters'][ $tag ][] = $callback;
	return true;
}

function add_action( string $tag, callable|array $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return true;
}

function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
	foreach ( $GLOBALS['wp_filters'][ $tag ] ?? [] as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function remove_all_filters( string $tag ): void {
	unset( $GLOBALS['wp_filters'][ $tag ] );
}

function sanitize_text_field( string $str ): string {
	return trim( strip_tags( $str ) );
}

function esc_attr( string $str ): string {
	return htmlspecialchars( $str, ENT_QUOTES );
}

function is_wp_error( mixed $thing ): bool {
	return $thing instanceof WP_Error;
}

function wp_insert_post( array $args, bool $wp_error = false ): int|WP_Error {
	return 1;
}

function current_user_can( string $capability ): bool {
	return true;
}

// --- WordPress class stubs ---

class WP_Error {
	public function __construct(
		private string $code = '',
		private string $message = ''
	) {}

	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}

class WP_Block_Patterns_Registry {
	private static ?self $instance = null;
	private array $patterns        = [];

	private function __construct() {}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register( array $pattern ): void {
		$this->patterns[] = $pattern;
	}

	public function get_all_registered(): array {
		return $this->patterns;
	}

	public function reset(): void {
		$this->patterns = [];
	}
}

require_once __DIR__ . '/../vendor/autoload.php';
