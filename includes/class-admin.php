<?php
/**
 * WordPress admin UI for the Waygate plugin (Tools → Waygate).
 *
 * @package Imagewize\Waygate
 */

namespace Imagewize\Waygate;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Waygate admin page, handles form submission, and registers the meta box.
 */
class Admin {

	/**
	 * Registers admin menu and meta box hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'add_meta_boxes', array( self::class, 'register_meta_box' ) );
	}

	/**
	 * Registers the Tools → Waygate admin page.
	 */
	public static function register_menu(): void {
		add_management_page(
			'Waygate',
			'Waygate',
			'manage_options',
			'waygate',
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Renders the full Waygate admin page including status notices, AI form, and pattern catalog.
	 */
	public static function render_page(): void {
		$ai_available       = function_exists( 'wp_ai_client_prompt' );
		$text_gen_supported = AiIntegration::is_text_generation_supported();
		$result             = null;

		if (
			isset( $_POST['waygate_action'] ) &&
			'generate' === $_POST['waygate_action'] &&
			check_admin_referer( 'waygate_generate' )
		) {
			if ( ! current_user_can( 'publish_pages' ) ) {
				$result = array( 'error' => 'You do not have permission to create pages.' );
			} elseif ( ! $text_gen_supported ) {
				$result = array( 'error' => 'Text generation is not supported by the configured AI provider.' );
			} else {
				$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );

				if ( empty( $description ) ) {
					$result = array( 'error' => 'Please describe the page you want to create.' );
				} else {
					$result = AiIntegration::generate_page( $description );
				}
			}
		}

		$all_patterns = PatternLab::get_patterns();
		usort( $all_patterns, fn( $a, $b ) => strcmp( $a['slug'], $b['slug'] ) );

		// Build unique category list (strip namespace prefix for display/filtering).
		$all_categories = array();
		foreach ( $all_patterns as $p ) {
			foreach ( $p['categories'] as $cat ) {
				$parts                           = explode( '/', $cat );
				$all_categories[ end( $parts ) ] = true;
			}
		}
		ksort( $all_categories );

		$selected_category = isset( $_GET['waygate_category'] ) ? sanitize_key( $_GET['waygate_category'] ) : '';

		if ( $selected_category && isset( $all_categories[ $selected_category ] ) ) {
			$patterns = array_values(
				array_filter(
					$all_patterns,
					function ( $p ) use ( $selected_category ) {
						foreach ( $p['categories'] as $cat ) {
							$parts = explode( '/', $cat );
							if ( end( $parts ) === $selected_category ) {
								return true;
							}
						}
						return false;
					}
				)
			);
		} else {
			$patterns = $all_patterns;
		}

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
							<th scope="row"><label for="waygate-template">Quick template</label></th>
							<td>
								<select id="waygate-template" onchange="waygateApplyTemplate(this)" style="max-width:400px">
									<option value="">— Choose a starting template —</option>
									<?php foreach ( AiIntegration::get_prompt_templates() as $tpl ) : ?>
									<option value="<?php echo esc_attr( $tpl['prompt'] ); ?>">
										<?php echo esc_html( $tpl['label'] ); ?> — <?php echo esc_html( $tpl['description'] ); ?>
									</option>
									<?php endforeach; ?>
								</select>
								<p class="description">Optional. Selecting a template fills the description below — customize it before generating.</p>
							</td>
						</tr>
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
								><?php echo esc_textarea( sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ) ); ?></textarea>
								<p class="description">Be specific about industry, page type, and sections you need. Replace any <code>[placeholder]</code> text with your specifics.</p>
							</td>
						</tr>
					</table>

					<?php submit_button( 'Generate Page', 'primary', 'submit', false ); ?>
				</form>
			</div>
			<script>
			function waygateApplyTemplate( select ) {
				if ( ! select.value ) return;
				var textarea = document.getElementById( 'description' );
				if ( textarea.value.trim() && ! window.confirm( 'Replace your current description with this template?' ) ) {
					select.value = '';
					return;
				}
				textarea.value = select.value;
				select.value = '';
				textarea.focus();
			}
			</script>
			<?php endif; ?>

			<div class="card" style="max-width:100%;padding:20px 24px">
				<h2 style="margin-top:0">
					Available patterns
					<span style="color:#888;font-weight:400">
						(<?php echo count( $patterns ); ?><?php echo $selected_category ? ' filtered' : ''; ?> of <?php echo count( $all_patterns ); ?>)
					</span>
				</h2>

				<div style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
					<form method="get" style="margin:0;display:flex;align-items:center;gap:8px">
						<input type="hidden" name="page" value="waygate">
						<label for="waygate-category-filter" style="font-weight:600">Filter by category:</label>
						<select id="waygate-category-filter" name="waygate_category" onchange="this.form.submit()">
							<option value="">All categories</option>
							<?php foreach ( array_keys( $all_categories ) as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $selected_category, $cat ); ?>>
								<?php echo esc_html( $cat ); ?>
							</option>
							<?php endforeach; ?>
						</select>
						<?php if ( $selected_category ) : ?>
						<a href="<?php echo esc_url( admin_url( 'tools.php?page=waygate' ) ); ?>" class="button">Clear</a>
						<?php endif; ?>
					</form>
				</div>

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
								<?php
								$cats = array_map(
									function ( $c ) {
										$parts = explode( '/', $c );
										return end( $parts );
									},
									$p['categories']
								);
								echo esc_html( implode( ', ', $cats ) );
								?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the status indicator notices at the top of the admin page.
	 *
	 * @param bool $ai_available      Whether the WP AI Client function exists.
	 * @param bool $text_gen_supported Whether the active provider supports text generation.
	 */
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

	/**
	 * Returns true when the site is running in development mode.
	 */
	private static function is_dev(): bool {
		return defined( 'WP_ENV' ) && 'development' === WP_ENV;
	}

	/**
	 * Renders a success or error notice after page generation.
	 *
	 * @param array $result Return value from AiIntegration::generate_page().
	 */
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
			<?php if ( self::is_dev() ) : ?>
			<p style="margin:0 0 6px;font-size:12px;color:#888">
				<strong>Patterns (dev):</strong> <?php echo esc_html( implode( ' → ', $result['patterns'] ) ); ?>
			</p>
			<?php endif; ?>
			<p style="margin:0">
				<a href="<?php echo esc_url( $result['edit_url'] ); ?>" class="button button-primary">Edit page</a>
				&nbsp;
				<a href="<?php echo esc_url( $result['view_url'] ); ?>" class="button" target="_blank">Preview draft</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Registers the Waygate meta box on the page post type.
	 */
	public static function register_meta_box(): void {
		add_meta_box(
			'waygate-info',
			'Waygate',
			array( self::class, 'render_meta_box' ),
			'page',
			'side',
			'low'
		);
	}

	/**
	 * Renders the Waygate meta box content showing AI reasoning and pattern list.
	 *
	 * @param \WP_Post $post The current post object.
	 */
	public static function render_meta_box( \WP_Post $post ): void {
		$reasoning = get_post_meta( $post->ID, '_waygate_reasoning', true );

		if ( ! $reasoning ) {
			echo '<p style="color:#888;margin:0;font-size:12px">Not generated by Waygate.</p>';
			return;
		}

		echo '<p style="margin:0 0 8px;font-size:12px"><strong>AI reasoning</strong><br>' . esc_html( $reasoning ) . '</p>';

		if ( self::is_dev() ) {
			$patterns_json = get_post_meta( $post->ID, '_waygate_patterns', true );
			$generated_at  = get_post_meta( $post->ID, '_waygate_generated_at', true );
			$patterns      = $patterns_json ? json_decode( $patterns_json, true ) : array();

			if ( $generated_at ) {
				echo '<p style="margin:0 0 6px;font-size:11px;color:#888"><strong>Generated:</strong> ' . esc_html( $generated_at ) . '</p>';
			}

			if ( $patterns ) {
				echo '<p style="margin:0 0 4px;font-size:11px;color:#888"><strong>Patterns</strong></p>';
				echo '<ol style="margin:0;padding-left:16px;font-size:11px;color:#555">';
				foreach ( $patterns as $slug ) {
					echo '<li><code>' . esc_html( $slug ) . '</code></li>';
				}
				echo '</ol>';
			}
		}
	}
}
