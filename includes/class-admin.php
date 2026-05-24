<?php

namespace Imagewize\Waygate;

defined( 'ABSPATH' ) || exit;

class Admin {

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_management_page(
			'Waygate',
			'Waygate',
			'manage_options',
			'waygate',
			[ self::class, 'render_page' ]
		);
	}

	public static function render_page(): void {
		$ai_available      = function_exists( 'wp_ai_client_prompt' );
		$text_gen_supported = AiIntegration::is_text_generation_supported();
		$result            = null;

		if (
			isset( $_POST['waygate_action'] ) &&
			$_POST['waygate_action'] === 'generate' &&
			check_admin_referer( 'waygate_generate' )
		) {
			if ( ! current_user_can( 'publish_pages' ) ) {
				$result = [ 'error' => 'You do not have permission to create pages.' ];
			} elseif ( ! $text_gen_supported ) {
				$result = [ 'error' => 'Text generation is not supported by the configured AI provider.' ];
			} else {
				$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );

				if ( empty( $description ) ) {
					$result = [ 'error' => 'Please describe the page you want to create.' ];
				} else {
					$result = AiIntegration::generate_page( $description );
				}
			}
		}

		$patterns = PatternLab::get_patterns();
		usort( $patterns, fn( $a, $b ) => strcmp( $a['slug'], $b['slug'] ) );

		?>
		<div class="wrap" style="max-width:900px">
			<h1>Waygate <span style="font-size:.7em;font-weight:400;color:#888">— Pattern Page Builder</span></h1>

			<?php self::status_notices( $ai_available, $text_gen_supported ); ?>

			<?php if ( $result ) : ?>
				<?php self::result_notice( $result ); ?>
			<?php endif; ?>

			<?php if ( $text_gen_supported ) : ?>
			<div class="card" style="max-width:100%;padding:20px 24px;margin-bottom:24px">
				<h2 style="margin-top:0">Generate a page with AI</h2>
				<p>Describe the page you want. The AI will select appropriate Elayne patterns and create a draft.</p>

				<form method="post">
					<?php wp_nonce_field( 'waygate_generate' ); ?>
					<input type="hidden" name="waygate_action" value="generate">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="description">Page description</label></th>
							<td>
								<textarea
									id="description"
									name="description"
									rows="3"
									class="large-text"
									placeholder="E.g.: A homepage for a luxury spa with hero, team, testimonials and booking CTA"
									required
								><?php echo esc_textarea( wp_unslash( $_POST['description'] ?? '' ) ); ?></textarea>
								<p class="description">Be specific about industry, page type, and sections you need.</p>
							</td>
						</tr>
					</table>

					<?php submit_button( 'Generate Page', 'primary', 'submit', false ); ?>
				</form>
			</div>
			<?php endif; ?>

			<div class="card" style="max-width:100%;padding:20px 24px">
				<h2 style="margin-top:0">
					Available Elayne patterns
					<span style="color:#888;font-weight:400">(<?php echo count( $patterns ); ?>)</span>
				</h2>
				<p>These pattern slugs are registered and available for page assembly.</p>

				<table class="widefat striped" style="width:100%">
					<thead>
						<tr>
							<th>Slug</th>
							<th>Title</th>
							<th>Categories</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $patterns as $p ) : ?>
						<tr>
							<td><code style="font-size:12px"><?php echo esc_html( $p['slug'] ); ?></code></td>
							<td><?php echo esc_html( $p['title'] ); ?></td>
							<td style="color:#666;font-size:12px">
								<?php echo esc_html( implode( ', ', array_map( fn( $c ) => str_replace( 'elayne/', '', $c ), $p['categories'] ) ) ); ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private static function status_notices( bool $ai_available, bool $text_gen_supported ): void {
		$abilities_available = function_exists( 'wp_register_ability' );
		$mistral_available   = class_exists( 'SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral' );
		$mistral_key_set     = ! empty( getenv( 'MISTRAL_API_KEY' ) );
		?>
		<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px">
			<div class="notice notice-<?php echo $text_gen_supported ? 'success' : ( $ai_available ? 'warning' : 'error' ); ?>" style="margin:0;flex:1;min-width:180px;padding:8px 12px">
				<strong><?php echo $text_gen_supported ? '✓' : '✗'; ?> WP AI Client</strong>
				<span style="color:#666;font-size:12px;display:block">
					<?php
					if ( $text_gen_supported ) {
						echo 'Text generation supported';
					} elseif ( $ai_available ) {
						echo 'Installed — no provider supports text generation';
					} else {
						echo 'Not available — install a provider via Settings → Connectors';
					}
					?>
				</span>
			</div>
			<div class="notice notice-<?php echo $abilities_available ? 'success' : 'info'; ?>" style="margin:0;flex:1;min-width:180px;padding:8px 12px">
				<strong><?php echo $abilities_available ? '✓' : '–'; ?> Abilities API</strong>
				<span style="color:#666;font-size:12px;display:block">
					<?php echo $abilities_available ? 'wp_register_ability() available' : 'Not available on this WP version'; ?>
				</span>
			</div>
			<div class="notice notice-<?php echo $mistral_available ? ( $mistral_key_set ? 'success' : 'warning' ) : 'error'; ?>" style="margin:0;flex:1;min-width:180px;padding:8px 12px">
				<strong><?php echo $mistral_available ? ( $mistral_key_set ? '✓' : '⚠' ) : '✗'; ?> Mistral</strong>
				<span style="color:#666;font-size:12px;display:block">
					<?php
					if ( $mistral_available && $mistral_key_set ) {
						echo 'Provider + API key configured';
					} elseif ( $mistral_available ) {
						echo 'Plugin loaded — set <code>MISTRAL_API_KEY</code> in your site .env';
					} else {
						echo 'Not loaded — activate <strong>AI Provider for Mistral</strong> or install via Composer';
					}
					?>
				</span>
			</div>
			<div class="notice notice-success" style="margin:0;flex:1;min-width:180px;padding:8px 12px">
				<strong>✓ Elayne Patterns</strong>
				<span style="color:#666;font-size:12px;display:block">
					<?php echo count( PatternLab::get_patterns() ); ?> patterns registered
				</span>
			</div>
		</div>
		<?php
	}

	private static function result_notice( array $result ): void {
		if ( isset( $result['error'] ) ) {
			echo '<div class="notice notice-error"><p><strong>Error:</strong> ' . esc_html( $result['error'] ) . '</p></div>';
			return;
		}
		?>
		<div class="notice notice-success" style="padding:12px 16px">
			<p style="margin:0 0 8px"><strong>Page created successfully!</strong></p>
			<p style="margin:0 0 6px">
				<strong>Title:</strong> <?php echo esc_html( $result['title'] ); ?> &nbsp;|&nbsp;
				<strong>Patterns used:</strong> <?php echo (int) $result['pattern_count']; ?>
			</p>
			<p style="margin:0 0 6px;font-size:12px;color:#555">
				<strong>AI reasoning:</strong> <?php echo esc_html( $result['reasoning'] ); ?>
			</p>
			<p style="margin:0">
				<a href="<?php echo esc_url( $result['edit_url'] ); ?>" class="button button-primary">Edit page</a>
				&nbsp;
				<a href="<?php echo esc_url( $result['view_url'] ); ?>" class="button" target="_blank">Preview draft</a>
			</p>
		</div>
		<?php
	}
}
