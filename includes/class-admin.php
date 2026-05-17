<?php
/**
 * Admin settings page and bulk index UI.
 *
 * @package VecPost_AI_Semantic_Search_For_Posts
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VecPost_Admin {
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_clear_error' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu(): void {
		add_options_page(
			'VecPost - AI Semantic Search for Posts',
			'VecPost - AI Semantic Search for Posts',
			'manage_options',
			'semantic-search',
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting( 'vecpost_settings', 'vecpost_embedding_provider', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'vecpost_settings', 'vecpost_openai_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'vecpost_settings', 'vecpost_gemini_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'vecpost_settings', 'vecpost_embedding_model', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting(
			'vecpost_settings',
			'vecpost_post_types',
			array(
				'sanitize_callback' => function ( $value ) {
					return array_map( 'sanitize_text_field', (array) $value );
				},
			)
		);
		register_setting( 'vecpost_settings', 'vecpost_semantic_weight', array( 'sanitize_callback' => 'floatval' ) );
		register_setting( 'vecpost_settings', 'vecpost_min_final_score', array( 'sanitize_callback' => 'floatval' ) );
		register_setting( 'vecpost_settings', 'vecpost_min_semantic_score', array( 'sanitize_callback' => 'floatval' ) );
		register_setting( 'vecpost_settings', 'vecpost_keyword_gate_threshold', array( 'sanitize_callback' => 'floatval' ) );
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'settings_page_semantic-search' ) {
			return;
		}

		wp_enqueue_style( 'vecpost-ai-search-for-posts-admin', VECPOST_PLUGIN_URL . 'assets/admin.css', array(), VECPOST_VERSION );
		wp_enqueue_script( 'vecpost-ai-search-for-posts-admin', VECPOST_PLUGIN_URL . 'assets/admin.js', array(), VECPOST_VERSION, true );
		wp_localize_script(
			'vecpost-ai-search-for-posts-admin',
			'VecPost',
			array(
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'restUrl' => rest_url( 'vecpost/v1/' ),
				'i18n'    => array(
					'testing'        => __( 'Testing...', 'vecpost-ai-semantic-search' ),
					'testOk'         => __( 'Connection successful!', 'vecpost-ai-semantic-search' ),
					'testFail'       => __( 'Connection failed. Check your API key and try again.', 'vecpost-ai-semantic-search' ),
					'indexDone'      => __( 'Indexing complete!', 'vecpost-ai-semantic-search' ),
					'indexStartFail' => __( 'Failed to start indexing. Please try again.', 'vecpost-ai-semantic-search' ),
				),
			)
		);
	}

	public function render_page(): void {
		$last_error = (string) get_option( 'vecpost_last_embedding_error', '' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'VecPost - AI Semantic Search for Posts Settings', 'vecpost-ai-semantic-search' ); ?></h1>

			<div class="notice notice-info">
				<p>
					<?php esc_html_e( 'This plugin sends your post content to external AI services (OpenAI or Google Gemini) to generate search embeddings. Data is transmitted to and processed by these third parties. Please review their privacy policies before use:', 'vecpost-ai-semantic-search' ); ?>
					<a href="https://openai.com/policies/privacy-policy" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'OpenAI Privacy Policy', 'vecpost-ai-semantic-search' ); ?></a>
					&nbsp;|&nbsp;
					<a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Privacy Policy', 'vecpost-ai-semantic-search' ); ?></a>
				</p>
			</div>

			<?php if ( $last_error !== '' ) : ?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'Last embedding error:', 'vecpost-ai-semantic-search' ); ?></strong>
						<?php echo esc_html( $last_error ); ?>
						&nbsp;<a href="<?php echo esc_url( add_query_arg( 'vecpost_clear_error', '1' ) ); ?>"><?php esc_html_e( 'Dismiss', 'vecpost-ai-semantic-search' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'vecpost_settings' ); ?>
				<?php $provider = get_option( 'vecpost_embedding_provider', 'openai' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Embedding Provider', 'vecpost-ai-semantic-search' ); ?></th>
						<td>
							<select name="vecpost_embedding_provider">
								<option value="openai" <?php selected( $provider, 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'vecpost-ai-semantic-search' ); ?></option>
								<option value="gemini" <?php selected( $provider, 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'vecpost-ai-semantic-search' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'OpenAI API Key', 'vecpost-ai-semantic-search' ); ?></th>
						<td><input type="password" name="vecpost_openai_api_key" value="<?php echo esc_attr( get_option( 'vecpost_openai_api_key', '' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Gemini API Key', 'vecpost-ai-semantic-search' ); ?></th>
						<td><input type="password" name="vecpost_gemini_api_key" value="<?php echo esc_attr( get_option( 'vecpost_gemini_api_key', '' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Embedding Model', 'vecpost-ai-semantic-search' ); ?></th>
						<td>
							<select name="vecpost_embedding_model">
								<option value="text-embedding-3-small" <?php selected( get_option( 'vecpost_embedding_model', 'text-embedding-3-small' ), 'text-embedding-3-small' ); ?>><?php esc_html_e( 'text-embedding-3-small (recommended)', 'vecpost-ai-semantic-search' ); ?></option>
								<option value="text-embedding-3-large" <?php selected( get_option( 'vecpost_embedding_model', '' ), 'text-embedding-3-large' ); ?>><?php esc_html_e( 'text-embedding-3-large (higher quality)', 'vecpost-ai-semantic-search' ); ?></option>
								<option value="gemini-embedding-001" <?php selected( get_option( 'vecpost_embedding_model', '' ), 'gemini-embedding-001' ); ?>><?php esc_html_e( 'gemini-embedding-001 (Gemini)', 'vecpost-ai-semantic-search' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Use OpenAI models with OpenAI provider, and Gemini embedding models with Gemini provider.', 'vecpost-ai-semantic-search' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Semantic Weight', 'vecpost-ai-semantic-search' ); ?></th>
						<td>
							<input type="range" name="vecpost_semantic_weight" min="0" max="1" step="0.1" value="<?php echo esc_attr( get_option( 'vecpost_semantic_weight', 0.7 ) ); ?>" />
							<p class="description"><?php esc_html_e( '0 = pure keyword, 1 = pure semantic. Default 0.7.', 'vecpost-ai-semantic-search' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Minimum Final Score', 'vecpost-ai-semantic-search' ); ?></th>
						<td>
							<input type="number" name="vecpost_min_final_score" min="0" max="1" step="0.01" value="<?php echo esc_attr( get_option( 'vecpost_min_final_score', 0.28 ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Drop results below this blended score.', 'vecpost-ai-semantic-search' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Minimum Semantic Score', 'vecpost-ai-semantic-search' ); ?></th>
						<td>
							<input type="number" name="vecpost_min_semantic_score" min="0" max="1" step="0.01" value="<?php echo esc_attr( get_option( 'vecpost_min_semantic_score', 0.30 ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Drop semantically weak matches.', 'vecpost-ai-semantic-search' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Low-Confidence Keyword Gate', 'vecpost-ai-semantic-search' ); ?></th>
						<td>
							<input type="number" name="vecpost_keyword_gate_threshold" min="0" max="1" step="0.01" value="<?php echo esc_attr( get_option( 'vecpost_keyword_gate_threshold', 0.35 ) ); ?>" />
							<p class="description"><?php esc_html_e( 'If final score is below this, require at least one keyword match.', 'vecpost-ai-semantic-search' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Test API Connection', 'vecpost-ai-semantic-search' ); ?></h2>
			<p><?php esc_html_e( 'Sends a test string to your configured embedding provider to verify the API key is working.', 'vecpost-ai-semantic-search' ); ?></p>
			<button id="ss-test-connection" class="button"><?php esc_html_e( 'Test Connection', 'vecpost-ai-semantic-search' ); ?></button>
			<span id="ss-test-result" style="margin-left:12px;"></span>

			<hr />
			<h2><?php esc_html_e( 'Index Existing Posts', 'vecpost-ai-semantic-search' ); ?></h2>
			<div id="ss-progress-wrap" style="display:none;max-width:500px;">
				<div class="ss-progress-track">
					<div id="ss-progress-bar" class="ss-progress-bar"></div>
				</div>
				<p id="ss-progress-label"><?php esc_html_e( 'Starting...', 'vecpost-ai-semantic-search' ); ?></p>
			</div>
			<div style="margin-bottom:10px;">
				<label>
					<input type="checkbox" id="ss-force-reindex" />
					<?php esc_html_e( 'Force re-index all posts (includes already-indexed posts; uses more API calls)', 'vecpost-ai-semantic-search' ); ?>
				</label>
			</div>
			<button id="ss-start-index" class="button button-primary"><?php esc_html_e( 'Start Bulk Index', 'vecpost-ai-semantic-search' ); ?></button>
		</div>
		<?php
	}

	public function handle_clear_error(): void {
		if (
			isset( $_GET['vecpost_clear_error'], $_GET['page'] ) &&
			$_GET['page'] === 'semantic-search' &&
			current_user_can( 'manage_options' )
		) {
			delete_option( 'vecpost_last_embedding_error' );
			wp_safe_redirect( remove_query_arg( 'vecpost_clear_error' ) );
			exit;
		}
	}
}
