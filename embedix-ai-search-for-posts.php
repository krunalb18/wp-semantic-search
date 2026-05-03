<?php
/**
 * Plugin Name:       Embedix AI Search for Posts
 * Plugin URI:        https://github.com/krunalb18/ai-semantic-search-for-posts
 * Description:       Replaces WordPress default SQL search with AI-powered semantic search using OpenAI or Google Gemini embeddings. Understands meaning, not just keywords.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Krunal Balas
 * Author URI:        https://github.com/krunalb18
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-semantic-search-for-posts
 * Domain Path:       /languages
 * @package           Embedix_AI_Search_For_Posts
 */
if (!defined('ABSPATH')) {
	exit;
}

define('EMBEDIX_VERSION', '1.0.0');
define('EMBEDIX_PLUGIN_FILE', __FILE__);
define('EMBEDIX_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EMBEDIX_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once EMBEDIX_PLUGIN_DIR . 'includes/class-embedding-client.php';
require_once EMBEDIX_PLUGIN_DIR . 'includes/class-vector-store.php';
require_once EMBEDIX_PLUGIN_DIR . 'includes/class-search.php';
require_once EMBEDIX_PLUGIN_DIR . 'includes/class-indexer.php';
require_once EMBEDIX_PLUGIN_DIR . 'includes/class-bulk-indexer.php';
require_once EMBEDIX_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once EMBEDIX_PLUGIN_DIR . 'includes/class-cache.php';
require_once EMBEDIX_PLUGIN_DIR . 'includes/class-admin.php';
require_once EMBEDIX_PLUGIN_DIR . 'cli/class-cli-commands.php';
require_once EMBEDIX_PLUGIN_DIR . 'migrations/embedix_create_db_tables.php';

function embedix_bootstrap() {
	static $bootstrapped = false;

	if ($bootstrapped) {
		return;
	}

	$bootstrapped = true;

	new Embedix_EmbeddingClient();
	new Embedix_VectorStore();
	new Embedix_Search();
	new Embedix_Indexer();
	new Embedix_BulkIndexer();
	new Embedix_RestAPI();
	new Embedix_Cache();
	new Embedix_Admin();
	new Embedix_CLI_Commands();

	add_action('init', 'embedix_register_block');
	add_shortcode('embedix_semantic_search', 'embedix_render_search_shortcode');
}
add_action('plugins_loaded', 'embedix_bootstrap');
add_action('admin_init', 'embedix_check_model_version');

function embedix_register_block() {
	if (!function_exists('register_block_type')) {
		return;
	}

	$block_path = EMBEDIX_PLUGIN_DIR . 'blocks/search-block';

	if (file_exists($block_path . '/block.json')) {
		register_block_type($block_path, array(
			'render_callback' => 'embedix_render_search_block',
		));
	}
}

function embedix_render_search_block($attributes = array()): string {
	$placeholder = isset($attributes['placeholder']) ? sanitize_text_field($attributes['placeholder']) : 'Search...';
	embedix_enqueue_frontend_assets();

	return '<div class="embedix-shortcode-search" data-placeholder="' . esc_attr($placeholder) . '"></div>';
}

function embedix_render_search_shortcode($atts = array()): string {
	$atts = shortcode_atts(array('placeholder' => 'Search...'), $atts, 'embedix_semantic_search');
	$placeholder = sanitize_text_field($atts['placeholder']);
	embedix_enqueue_frontend_assets();

	return '<div class="embedix-shortcode-search" data-placeholder="' . esc_attr($placeholder) . '"></div>';
}

function embedix_enqueue_frontend_assets(): void {
	wp_enqueue_style('embedix-ai-search-for-posts-frontend', EMBEDIX_PLUGIN_URL . 'assets/search.css', array(), EMBEDIX_VERSION);
	wp_enqueue_script('embedix-ai-search-for-posts-frontend', EMBEDIX_PLUGIN_URL . 'assets/search.js', array(), EMBEDIX_VERSION, true);
	wp_localize_script('embedix-ai-search-for-posts-frontend', 'EmbedixFront', array(
		'restUrl' => esc_url_raw(rest_url('embedix/v1/search')),
	));
}

function embedix_activate() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'embedix_embeddings';
	$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));

	if (!$table_exists && function_exists('embedix_create_db_tables')) {
		embedix_create_db_tables();
	}

	$current_signature = embedix_current_embedding_signature();
	update_option('embedix_indexed_with_model', $current_signature);

	flush_rewrite_rules();
}

function embedix_check_model_version(): void {
	$stored_model = (string) get_option('embedix_indexed_with_model', '');
	$current_model = embedix_current_embedding_signature();

	if ($stored_model && $stored_model !== $current_model) {
		global $wpdb;

		$wpdb->query("UPDATE {$wpdb->prefix}embedix_embeddings SET stale = 1");

		$indexer = new Embedix_BulkIndexer();
		$indexer->start_full_index();
	}

	update_option('embedix_indexed_with_model', $current_model);
}

function embedix_current_embedding_signature(): string {
	$provider = (string) get_option('embedix_embedding_provider', 'openai');
	$model = embedix_current_embedding_model();
	return $provider . ':' . $model;
}

function embedix_current_embedding_model(): string {
	$provider = (string) get_option('embedix_embedding_provider', 'openai');
	$configured = (string) get_option('embedix_embedding_model', '');

	if ($provider === 'gemini') {
		if ($configured === '' || strpos($configured, 'text-embedding-3-') === 0) {
			return 'gemini-embedding-001';
		}

		return $configured;
	}

	if ($configured === '' || strpos($configured, 'gemini-') === 0 || strpos($configured, 'models/') === 0) {
		return 'text-embedding-3-small';
	}

	return $configured;
}

function embedix_deactivate() {
	flush_rewrite_rules();
}

register_activation_hook(__FILE__, 'embedix_activate');
register_deactivation_hook(__FILE__, 'embedix_deactivate');
