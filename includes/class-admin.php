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
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_media' ) );
		add_action( 'add_meta_boxes', array( self::class, 'register_meta_box' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_abilities' ) );
	}

	/**
	 * Loads the media manager on the Tools → Waygate page, for choosing page images.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue_media( string $hook_suffix ): void {
		if ( 'tools_page_waygate' === $hook_suffix ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Enqueues the Waygate client-side abilities script module for the block editor.
	 */
	public static function enqueue_editor_abilities(): void {
		if ( ! function_exists( 'wp_enqueue_script_module' ) ) {
			return;
		}

		wp_enqueue_script_module( '@wordpress/core-abilities' );

		wp_register_script_module(
			'waygate-editor-abilities',
			WAYGATE_PLUGIN_URL . 'assets/js/abilities.js',
			array( '@wordpress/abilities' ),
			WAYGATE_VERSION
		);

		wp_enqueue_script_module( 'waygate-editor-abilities' );
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
		$text_gen_supported = AI_Integration::is_text_generation_supported();
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
				$description      = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
				$personalize_text = ! empty( $_POST['personalize_text'] );
				$vertical         = sanitize_key( wp_unslash( $_POST['vertical'] ?? '' ) );
				$template         = sanitize_text_field( wp_unslash( $_POST['template'] ?? '' ) );

				if ( '' !== $vertical && ! in_array( $vertical, Pattern_Lab::get_verticals(), true ) ) {
					$vertical = '';
				}

				if ( empty( $description ) ) {
					$result = array( 'error' => 'Please describe the page you want to create.' );
				} else {
					$result = AI_Integration::generate_page( $description, $personalize_text, $vertical, $template );
				}
			}
		}

		if (
			isset( $_POST['waygate_action'] ) &&
			'settings' === $_POST['waygate_action'] &&
			check_admin_referer( 'waygate_settings' ) &&
			current_user_can( 'manage_options' )
		) {
			update_option( Pattern_Lab::ALUDRA_OPTION, empty( $_POST['use_aludra'] ) ? '0' : '1' );
		}

		$all_patterns = Pattern_Lab::get_patterns();
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

			<?php self::aludra_settings(); ?>

			<?php if ( $result ) : ?>
				<?php self::result_notice( $result ); ?>
			<?php endif; ?>

			<?php if ( $text_gen_supported ) : ?>
			<div class="card" style="max-width:100%;padding:20px 24px;margin-bottom:24px">
				<h2 style="margin-top:0">Generate a page with AI</h2>
				<p>Describe the page you want. The AI will select appropriate Elayne patterns and create a draft.</p>

				<form method="post" id="waygate-generate-form">
					<?php wp_nonce_field( 'waygate_generate' ); ?>
					<input type="hidden" name="waygate_action" value="generate">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="waygate-template">Quick template</label></th>
							<td>
								<select id="waygate-template" onchange="waygateApplyTemplate(this)" style="max-width:400px">
									<option value="">— Choose a starting template —</option>
									<?php foreach ( AI_Integration::get_prompt_templates() as $tpl ) : ?>
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
						<tr>
							<th scope="row"><label for="waygate-vertical">Vertical</label></th>
							<td>
								<?php $selected_vertical = isset( $_POST['vertical'] ) ? sanitize_key( wp_unslash( $_POST['vertical'] ) ) : Pattern_Lab::get_default_vertical(); ?>
								<select id="waygate-vertical" name="vertical" style="max-width:400px">
									<option value="">All patterns</option>
									<?php foreach ( Pattern_Lab::get_verticals() as $vertical_slug ) : ?>
									<option value="<?php echo esc_attr( $vertical_slug ); ?>" <?php selected( $selected_vertical, $vertical_slug ); ?>>
										<?php echo esc_html( ucwords( str_replace( '-', ' ', $vertical_slug ) ) ); ?>
									</option>
									<?php endforeach; ?>
								</select>
								<p class="description">Only offer this vertical's patterns to the AI, so a law-firm page doesn't get a spa hero. Defaults to the active child theme's vertical. Falls back to all patterns if the vertical has fewer than three sections.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="waygate-page-template">Page template</label></th>
							<td>
								<?php $selected_template = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : ''; ?>
								<select id="waygate-page-template" name="template" style="max-width:400px">
									<option value="">Default</option>
									<?php foreach ( get_page_templates() as $name => $file ) : ?>
									<option value="<?php echo esc_attr( $file ); ?>" <?php selected( $selected_template, $file ); ?>>
										<?php echo esc_html( $name ); ?>
									</option>
									<?php endforeach; ?>
								</select>
								<p class="description">Optional page template, for example a no-title template for a full-width homepage.</p>
							</td>
						</tr>
							<tr>
								<th scope="row">Text personalization</th>
								<td>
									<label>
										<input
											type="checkbox"
											name="personalize_text"
											value="1"
											<?php checked( empty( $_POST['waygate_action'] ) || ! empty( $_POST['personalize_text'] ) ); ?>
										>
										Rewrite pattern text to match my description
									</label>
									<p class="description">The AI rewrites headings, paragraphs and button labels for your topic. Only text is swapped, so the page keeps the patterns' markup and stays valid in the editor. Adds a second AI call; uncheck for faster generation with the original pattern text.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="waygate-images-add">Images</label></th>
								<td>
									<div id="waygate-images-list" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px"></div>
									<button type="button" class="button" id="waygate-images-add">Choose images from the Media Library</button>
									<input type="hidden" name="image_ids" id="waygate-image-ids" value="">
									<p class="description">Optional. Pick or upload pictures for your page; the AI places them in the patterns' image slots. Slots left unfilled keep the patterns' original images. Needs JavaScript; the no-JS fallback generates without them.</p>
								</td>
							</tr>
						</table>

					<div id="waygate-generate-actions">
						<?php submit_button( 'Generate Page', 'primary', 'submit', false ); ?>
					</div>
					<div id="waygate-generate-status" style="display:none;margin-top:12px">
						<span class="spinner" style="float:none;margin:0 6px 0 0;visibility:visible"></span>
						<strong id="waygate-generate-message">Generating page…</strong>
						<p class="description" id="waygate-generate-hint" style="margin:4px 0 0">Each step runs as its own server request, so a slow AI answer cannot hit your server's request timeout. A failed step can be retried where it stopped.</p>
					</div>
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

			( function() {
				var form     = document.getElementById( 'waygate-generate-form' );
				var actions  = document.getElementById( 'waygate-generate-actions' );
				var status   = document.getElementById( 'waygate-generate-status' );
				var spinner  = status ? status.querySelector( '.spinner' ) : null;
				var message  = document.getElementById( 'waygate-generate-message' );
				var hint     = document.getElementById( 'waygate-generate-hint' );
				var textarea = document.getElementById( 'description' );

				if ( ! form || ! actions || ! status || ! message ) {
					return;
				}

				if ( ! window.fetch ) {
					// No fetch support: keep the plain form submit (one long server request).
					form.addEventListener( 'submit', function() {
						message.textContent = 'Generating page…';
						actions.style.display = 'none';
						status.style.display  = 'block';
					} );
					return;
				}

				var root  = <?php echo wp_json_encode( esc_url_raw( rest_url( Rest_API::NAMESPACE . '/' ) ) ); ?>;
				var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

				var state = null;

				function post( route, body ) {
					return fetch( root + route, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
						body: JSON.stringify( body )
					} ).then( function ( response ) {
						return response.json().then( function ( data ) {
							return { ok: response.ok, data: data };
						} );
					} );
				}

				function errorMessage( result ) {
					if ( result.data ) {
						if ( result.data.error ) {
							return result.data.error;
						}
						if ( ! result.ok && result.data.message ) {
							return result.data.message;
						}
					}
					return 'Unexpected response from the server.';
				}

				function setHint( text ) {
					if ( hint ) {
						hint.innerHTML = text;
					}
				}

				function step( label ) {
					state.lastLabel = label;
					if ( spinner ) {
						spinner.style.visibility = 'visible';
					}
					message.textContent = label + '…';
					setHint( '' );

					var retry = document.getElementById( 'waygate-generate-retry' );
					if ( retry ) {
						retry.parentNode.removeChild( retry );
					}
				}

				function fail( text, resume ) {
					if ( spinner ) {
						spinner.style.visibility = 'hidden';
					}
					message.textContent = 'Generation failed: ' + text;

					var retry = document.getElementById( 'waygate-generate-retry' );
					if ( ! retry ) {
						retry = document.createElement( 'button' );
						retry.type = 'button';
						retry.className = 'button';
						retry.id = 'waygate-generate-retry';
						retry.style.marginTop = '8px';
						retry.textContent = 'Retry this step';
						status.appendChild( retry );
					}
					retry.onclick = resume;
				}

				function finish( data ) {
					if ( spinner ) {
						spinner.style.visibility = 'hidden';
					}
					message.textContent = 'Done. Draft page "' + state.title + '" created.';
					var html = '<a class="button button-primary" href="' + data.edit_url + '">Edit draft</a> <a class="button" href="' + data.view_url + '">View page</a>';
					if ( data.warnings && data.warnings.length ) {
						html += '<br>' + data.warnings.length + ' replacement' + ( data.warnings.length > 1 ? 's' : '' ) + ' could not be placed.';
					}
					setHint( html );
					actions.style.display = '';
					if ( textarea ) {
						textarea.readOnly = false;
					}
				}

				function select() {
					step( 'Choosing patterns' );
					post( 'generate/select', { description: state.description, vertical: state.vertical } ).then( function ( result ) {
						if ( ! result.ok || result.data.error ) {
							fail( errorMessage( result ), select );
							return;
						}
						state.title     = result.data.title;
						state.slugs     = result.data.patterns;
						state.language  = result.data.language || '';
						state.reasoning = result.data.reasoning || '';
						state.sections  = new Array( state.slugs.length );
						state.sectionIndex = 0;
						if ( state.personalize ) {
							pairs();
						} else if ( state.imageIds.length ) {
							images();
						} else {
							build();
						}
					} ).catch( function () {
						fail( 'Network or server error.', select );
					} );
				}

				function pairs() {
					if ( state.sectionIndex >= state.slugs.length ) {
						state.sectionIndex = 0;
						if ( state.imageIds.length ) {
							images();
						} else {
							build();
						}
						return;
					}
					var slug = state.slugs[ state.sectionIndex ];

					step( 'Rewriting section ' + ( state.sectionIndex + 1 ) + ' of ' + state.slugs.length );
					post( 'generate/pairs', { description: state.description, slug: slug, language: state.language } ).then( function ( result ) {
						if ( ! result.ok || result.data.error ) {
							fail( errorMessage( result ), pairs );
							return;
						}
						state.sections[ state.sectionIndex ] = result.data.section || { pattern: slug };
						state.sectionIndex++;
						pairs();
					} ).catch( function () {
						fail( 'Network or server error.', pairs );
					} );
				}

				function images() {
					if ( state.sectionIndex >= state.slugs.length ) {
						build();
						return;
					}
					var slug = state.slugs[ state.sectionIndex ];

					step( 'Placing images in section ' + ( state.sectionIndex + 1 ) + ' of ' + state.slugs.length );
					post( 'generate/images', { description: state.description, slug: slug, ids: state.imageIds } ).then( function ( result ) {
						if ( ! result.ok || result.data.error ) {
							fail( errorMessage( result ), images );
							return;
						}
						var section = state.sections[ state.sectionIndex ] || { pattern: slug };
						if ( result.data.section && result.data.section.images && result.data.section.images.length ) {
							section.images = result.data.section.images;
						}
						state.sections[ state.sectionIndex ] = section;
						state.sectionIndex++;
						images();
					} ).catch( function () {
						fail( 'Network or server error.', images );
					} );
				}

				function build() {
					step( 'Creating the draft page' );
					var needsSections = state.personalize || state.imageIds.length > 0;
					post( 'generate/build', {
						title: state.title,
						sections: needsSections ? state.sections : state.slugs,
						slugs: state.slugs,
						reasoning: state.reasoning,
						personalized: state.personalize && state.sections.some( function ( section ) {
							return section && section.texts && section.texts.length;
						} ),
						template: state.template
					} ).then( function ( result ) {
						if ( ! result.ok || result.data.error ) {
							fail( errorMessage( result ), build );
							return;
						}
						finish( result.data );
					} ).catch( function () {
						fail( 'Network or server error.', build );
					} );
				}

				form.addEventListener( 'submit', function ( event ) {
					event.preventDefault();

					var description = textarea ? textarea.value.trim() : '';
					if ( ! description ) {
						message.textContent = 'Please describe the page you want to create.';
						status.style.display = 'block';
						return;
					}

					var personalize = form.querySelector( 'input[name="personalize_text"]' );
					var verticalEl   = document.getElementById( 'waygate-vertical' );
					var templateEl   = document.getElementById( 'waygate-page-template' );
					var idsEl        = document.getElementById( 'waygate-image-ids' );
					var imageIds     = idsEl && idsEl.value ? idsEl.value.split( ',' ).map( Number ).filter( function ( n ) {
						return n > 0;
					} ) : [];

					state = {
						description: description,
						personalize: ! personalize || personalize.checked,
						vertical:    verticalEl ? verticalEl.value : '',
						template:    templateEl ? templateEl.value : '',
						imageIds:    imageIds
					};

					status.style.display = 'block';
					actions.style.display = 'none';
					if ( textarea ) {
						textarea.readOnly = true;
					}

					select();
				} );
			} )();

			( function() {
				var addBtn = document.getElementById( 'waygate-images-add' );
				var list   = document.getElementById( 'waygate-images-list' );
				var idsEl  = document.getElementById( 'waygate-image-ids' );
				var frame  = null;
				var chosen = [];

				if ( ! addBtn || ! list || ! idsEl ) {
					return;
				}

				function render() {
					list.innerHTML = '';
					idsEl.value = chosen.map( function ( image ) {
						return image.id;
					} ).join( ',' );

					chosen.forEach( function ( image ) {
						var wrap = document.createElement( 'span' );
						wrap.style.display = 'inline-flex';
						wrap.style.alignItems = 'flex-end';

						var thumb = document.createElement( 'img' );
						thumb.src = image.thumb;
						thumb.alt = image.alt || '';
						thumb.style.width = '60px';
						thumb.style.height = '60px';
						thumb.style.objectFit = 'cover';
						thumb.style.display = 'block';

						var remove = document.createElement( 'button' );
						remove.type = 'button';
						remove.className = 'button';
						remove.textContent = '×';
						remove.style.padding = '0 6px';
						remove.setAttribute( 'aria-label', 'Remove this image' );
						remove.onclick = function () {
							chosen = chosen.filter( function ( other ) {
								return other.id !== image.id;
							} );
							render();
						};

						wrap.appendChild( thumb );
						wrap.appendChild( remove );
						list.appendChild( wrap );
					} );
				}

				addBtn.addEventListener( 'click', function () {
					if ( ! window.wp || ! wp.media ) {
						return;
					}

					if ( ! frame ) {
						frame = wp.media( {
							title: 'Choose images for your page',
							button: { text: 'Use these images' },
							library: { type: 'image' },
							multiple: 'add'
						} );

						frame.on( 'select', function () {
							chosen = frame.state().get( 'selection' ).map( function ( model ) {
								var data = model.toJSON();
								return {
									id: data.id,
									thumb: ( data.sizes && data.sizes.thumbnail && data.sizes.thumbnail.url ) || data.url,
									alt: data.alt || ''
								};
							} );
							render();
						} );
					}

					frame.open();
				} );
			} )();
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
	 * Renders the "Use Aludra" setting, only when Aludra's compose-page ability exists.
	 */
	private static function aludra_settings(): void {
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( 'aludra/compose-page' ) ) {
			return;
		}
		?>
		<div class="card" style="max-width:100%;padding:12px 24px;margin-bottom:24px">
			<form method="post">
				<?php wp_nonce_field( 'waygate_settings' ); ?>
				<input type="hidden" name="waygate_action" value="settings">
				<label>
					<input type="checkbox" name="use_aludra" value="1" <?php checked( (bool) get_option( Pattern_Lab::ALUDRA_OPTION, false ) ); ?>>
					<strong>Use Aludra patterns</strong>
				</label>
				<p class="description">
					Offer Aludra's section patterns alongside the theme's. A page made only of Aludra patterns is built by
					<code>aludra/compose-page</code>, which inlines the pattern markup so the page stays valid in the editor.
					Mixed pages are built as before.
				</p>
				<?php submit_button( 'Save', 'secondary', 'submit', false ); ?>
			</form>
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
				<strong>✓ Patterns</strong>
				<span style="color:#666;font-size:12px;display:block">
					<?php echo count( Pattern_Lab::get_patterns() ); ?> patterns registered
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
	 * @param array $result Return value from AI_Integration::generate_page().
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
				<strong>Patterns used:</strong> <?php echo (int) $result['pattern_count']; ?> &nbsp;|&nbsp;
				<strong>Text:</strong> <?php echo ! empty( $result['personalized'] ) ? 'Personalized to your topic' : 'Original pattern placeholders'; ?>
			</p>
			<p style="margin:0 0 6px;font-size:12px;color:#555">
				<strong>AI reasoning:</strong> <?php echo esc_html( $result['reasoning'] ); ?>
			</p>
			<?php if ( ! empty( $result['text_error'] ) ) : ?>
			<p style="margin:0 0 6px;font-size:12px;color:#8a6d00">
				<strong>Text not rewritten:</strong> <?php echo esc_html( $result['text_error'] ); ?>
			</p>
			<?php endif; ?>
			<?php if ( ! empty( $result['warnings'] ) ) : ?>
			<p style="margin:0 0 6px;font-size:12px;color:#8a6d00">
				<strong>Not replaced:</strong> <?php echo esc_html( implode( ' ', $result['warnings'] ) ); ?>
			</p>
			<?php endif; ?>
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
