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

function get_current_user_id(): int {
	return 1;
}

$GLOBALS['wp_transients'] = [];

function get_transient( string $key ): mixed {
	return $GLOBALS['wp_transients'][ $key ] ?? false;
}

function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
	$GLOBALS['wp_transients'][ $key ] = $value;
	return true;
}

function sanitize_key( string $key ): string {
	return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $key ) );
}

function get_edit_post_link( int $id, string $context = 'display' ): string {
	return "https://example.com/wp-admin/post.php?post={$id}&action=edit";
}

function get_permalink( int $id ): string {
	return "https://example.com/?page_id={$id}";
}

function rest_ensure_response( mixed $data ): WP_REST_Response {
	if ( $data instanceof WP_REST_Response ) {
		return $data;
	}
	return new WP_REST_Response( $data );
}

$GLOBALS['wp_rest_routes'] = [];

function register_rest_route( string $namespace, string $route, array $args ): bool {
	$GLOBALS['wp_rest_routes'][ $namespace . $route ] = $args;
	return true;
}

// --- WordPress class stubs ---

class WP_Error {
	public function __construct(
		private string $code = '',
		private string $message = '',
		private mixed $data = null
	) {}

	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

class WP_REST_Server {
	const READABLE  = 'GET';
	const CREATABLE = 'POST';
}

class WP_REST_Request {
	private array $params;

	public function __construct( array $params = [] ) {
		$this->params = $params;
	}

	public function get_param( string $key ): mixed {
		return $this->params[ $key ] ?? null;
	}
}

class WP_REST_Response {
	public function __construct(
		private mixed $data = null,
		private int $status = 200
	) {}

	public function get_data(): mixed { return $this->data; }
	public function get_status(): int { return $this->status; }
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
