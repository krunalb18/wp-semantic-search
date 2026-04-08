<?php
/**
 * Plugin Name:       AI Semantic Search for Posts
 * Plugin URI:        https://github.com/krunalbalas/ai-semantic-search-for-posts
 * Description:       Replaces WordPress default SQL search with AI-powered semantic search using OpenAI or Google Gemini embeddings. Understands meaning, not just keywords.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Krunal Balas
 * Author URI:        https://github.com/krunalbalas
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-semantic-search-for-posts
 * Domain Path:       /languages
 * @package           AI_Semantic_Search_For_Posts
 */
if (!defined('ABSPATH')) {
	exit;
}

define('SS_VERSION', '1.0.0');
define('SS_PLUGIN_FILE', __FILE__);
define('SS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SS_PLUGIN_DIR . 'includes/class-embedding-client.php';
require_once SS_PLUGIN_DIR . 'includes/class-vector-store.php';
require_once SS_PLUGIN_DIR . 'includes/class-search.php';
require_once SS_PLUGIN_DIR . 'includes/class-indexer.php';
require_once SS_PLUGIN_DIR . 'includes/class-bulk-indexer.php';
require_once SS_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once SS_PLUGIN_DIR . 'includes/class-cache.php';
require_once SS_PLUGIN_DIR . 'includes/class-admin.php';
require_once SS_PLUGIN_DIR . 'cli/class-cli-commands.php';
require_once SS_PLUGIN_DIR . 'migrations/ss_create_db_tables.php';

function ss_bootstrap() {
	static $bootstrapped = false;

	if ($bootstrapped) {
		return;
	}

	$bootstrapped = true;

	new SemanticSearch_EmbeddingClient();
	new SemanticSearch_VectorStore();
	new SemanticSearch_Search();
	new SemanticSearch_Indexer();
	new SemanticSearch_BulkIndexer();
	new SemanticSearch_RestAPI();
	new SemanticSearch_Cache();
	new SemanticSearch_Admin();
	new SemanticSearch_CLI_Commands();

	add_action('init', 'ss_register_block');
	add_shortcode('semantic_search', 'ss_render_search_shortcode');
}
add_action('plugins_loaded', 'ss_bootstrap');
add_action('admin_init', 'ss_check_model_version');
add_action('plugins_loaded', 'ss_load_textdomain');

function ss_load_textdomain(): void {
	load_plugin_textdomain(
		'ai-semantic-search-for-posts',
		false,
		dirname(plugin_basename(__FILE__)) . '/languages'
	);
}

function ss_register_block() {
	if (!function_exists('register_block_type')) {
		return;
	}

	$block_path = SS_PLUGIN_DIR . 'blocks/search-block';

	if (file_exists($block_path . '/block.json')) {
		register_block_type($block_path, array(
			'render_callback' => 'ss_render_search_block',
		));
	}
}

function ss_render_search_block($attributes = array()): string {
	$placeholder = isset($attributes['placeholder']) ? sanitize_text_field($attributes['placeholder']) : 'Search...';
	ss_enqueue_frontend_assets();

	return '<div class="ss-shortcode-search" data-placeholder="' . esc_attr($placeholder) . '"></div>';
}

function ss_render_search_shortcode($atts = array()): string {
	$atts = shortcode_atts(array('placeholder' => 'Search...'), $atts, 'semantic_search');
	$placeholder = sanitize_text_field($atts['placeholder']);
	ss_enqueue_frontend_assets();

	return '<div class="ss-shortcode-search" data-placeholder="' . esc_attr($placeholder) . '"></div>';
}

function ss_enqueue_frontend_assets(): void {
	wp_enqueue_style('ai-semantic-search-for-posts-frontend', SS_PLUGIN_URL . 'assets/search.css', array(), SS_VERSION);
	wp_enqueue_script('ai-semantic-search-for-posts-frontend', SS_PLUGIN_URL . 'assets/search.js', array(), SS_VERSION, true);
	wp_localize_script('ai-semantic-search-for-posts-frontend', 'SSFront', array(
		'restUrl' => esc_url_raw(rest_url('ss/v1/search')),
	));
}

function ss_activate() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'ss_embeddings';
	$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));

	if (!$table_exists && function_exists('ss_create_db_tables')) {
		ss_create_db_tables();
	}

	$current_signature = ss_current_embedding_signature();
	update_option('ss_indexed_with_model', $current_signature);

	flush_rewrite_rules();
}

function ss_check_model_version(): void {
	$stored_model = (string) get_option('ss_indexed_with_model', '');
	$current_model = ss_current_embedding_signature();

	if ($stored_model && $stored_model !== $current_model) {
		global $wpdb;

		$wpdb->query("UPDATE {$wpdb->prefix}ss_embeddings SET stale = 1");

		$indexer = new SemanticSearch_BulkIndexer();
		$indexer->start_full_index();
	}

	update_option('ss_indexed_with_model', $current_model);
}

function ss_current_embedding_signature(): string {
	$provider = (string) get_option('ss_embedding_provider', 'openai');
	$model = ss_current_embedding_model();
	return $provider . ':' . $model;
}

function ss_current_embedding_model(): string {
	$provider = (string) get_option('ss_embedding_provider', 'openai');
	$configured = (string) get_option('ss_embedding_model', '');

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

function ss_deactivate() {
	flush_rewrite_rules();
}

register_activation_hook(__FILE__, 'ss_activate');
register_deactivation_hook(__FILE__, 'ss_deactivate');
