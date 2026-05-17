<?php
/**
 * Plugin Name:       VecPost - AI Semantic Search for Posts
 * Plugin URI:        https://github.com/krunalb18/vecpost-ai-semantic-search
 * Description:       Replaces WordPress default SQL search with AI-powered semantic search using OpenAI or Google Gemini embeddings. Understands meaning, not just keywords.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Krunal Balas
 * Author URI:        https://github.com/krunalb18
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vecpost-ai-semantic-search
 * Domain Path:       /languages
 *
 * @package           VecPost_AI_Semantic_Search_For_Posts
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VECPOST_VERSION', '1.0.0' );
define( 'VECPOST_PLUGIN_FILE', __FILE__ );
define( 'VECPOST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VECPOST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once VECPOST_PLUGIN_DIR . 'includes/class-embedding-client.php';
require_once VECPOST_PLUGIN_DIR . 'includes/class-vector-store.php';
require_once VECPOST_PLUGIN_DIR . 'includes/class-search.php';
require_once VECPOST_PLUGIN_DIR . 'includes/class-indexer.php';
require_once VECPOST_PLUGIN_DIR . 'includes/class-bulk-indexer.php';
require_once VECPOST_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once VECPOST_PLUGIN_DIR . 'includes/class-cache.php';
require_once VECPOST_PLUGIN_DIR . 'includes/class-admin.php';
require_once VECPOST_PLUGIN_DIR . 'cli/class-cli-commands.php';
require_once VECPOST_PLUGIN_DIR . 'migrations/vecpost_create_db_tables.php';

function vecpost_bootstrap() {
	static $bootstrapped = false;

	if ( $bootstrapped ) {
		return;
	}

	$bootstrapped = true;

	new VecPost_EmbeddingClient();
	new VecPost_VectorStore();
	new VecPost_Search();
	new VecPost_Indexer();
	new VecPost_BulkIndexer();
	new VecPost_RestAPI();
	new VecPost_Cache();
	new VecPost_Admin();
	new VecPost_CLI_Commands();

	add_action( 'init', 'vecpost_register_block' );
	add_shortcode( 'vecpost_semantic_search', 'vecpost_render_search_shortcode' );
}
add_action( 'plugins_loaded', 'vecpost_bootstrap' );
add_action( 'admin_init', 'vecpost_check_model_version' );

function vecpost_register_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	$block_path = VECPOST_PLUGIN_DIR . 'blocks/search-block';

	if ( file_exists( $block_path . '/block.json' ) ) {
		register_block_type(
			$block_path,
			array(
				'render_callback' => 'vecpost_render_search_block',
			)
		);
	}
}

function vecpost_render_search_block( $attributes = array() ): string {
	$placeholder = isset( $attributes['placeholder'] ) ? sanitize_text_field( $attributes['placeholder'] ) : 'Search...';
	vecpost_enqueue_frontend_assets();

	return '<div class="vecpost-shortcode-search" data-placeholder="' . esc_attr( $placeholder ) . '"></div>';
}

function vecpost_render_search_shortcode( $atts = array() ): string {
	$atts        = shortcode_atts( array( 'placeholder' => 'Search...' ), $atts, 'vecpost_semantic_search' );
	$placeholder = sanitize_text_field( $atts['placeholder'] );
	vecpost_enqueue_frontend_assets();

	return '<div class="vecpost-shortcode-search" data-placeholder="' . esc_attr( $placeholder ) . '"></div>';
}

function vecpost_enqueue_frontend_assets(): void {
	wp_enqueue_style( 'vecpost-ai-search-for-posts-frontend', VECPOST_PLUGIN_URL . 'assets/search.css', array(), VECPOST_VERSION );
	wp_enqueue_script( 'vecpost-ai-search-for-posts-frontend', VECPOST_PLUGIN_URL . 'assets/search.js', array(), VECPOST_VERSION, true );
	wp_localize_script(
		'vecpost-ai-search-for-posts-frontend',
		'VecPostFront',
		array(
			'restUrl' => esc_url_raw( rest_url( 'vecpost/v1/search' ) ),
		)
	);
}

function vecpost_activate() {
	global $wpdb;

	$table_name   = $wpdb->prefix . 'vecpost_embeddings';
	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

	if ( ! $table_exists && function_exists( 'vecpost_create_db_tables' ) ) {
		vecpost_create_db_tables();
	}

	$current_signature = vecpost_current_embedding_signature();
	update_option( 'vecpost_indexed_with_model', $current_signature );

	flush_rewrite_rules();
}

function vecpost_check_model_version(): void {
	$stored_model  = (string) get_option( 'vecpost_indexed_with_model', '' );
	$current_model = vecpost_current_embedding_signature();

	if ( $stored_model && $stored_model !== $current_model ) {
		global $wpdb;

		$wpdb->query( "UPDATE {$wpdb->prefix}vecpost_embeddings SET stale = 1" );

		$indexer = new VecPost_BulkIndexer();
		$indexer->start_full_index();
	}

	update_option( 'vecpost_indexed_with_model', $current_model );
}

function vecpost_current_embedding_signature(): string {
	$provider = (string) get_option( 'vecpost_embedding_provider', 'openai' );
	$model    = vecpost_current_embedding_model();
	return $provider . ':' . $model;
}

function vecpost_current_embedding_model(): string {
	$provider   = (string) get_option( 'vecpost_embedding_provider', 'openai' );
	$configured = (string) get_option( 'vecpost_embedding_model', '' );

	if ( $provider === 'gemini' ) {
		if ( $configured === '' || strpos( $configured, 'text-embedding-3-' ) === 0 ) {
			return 'gemini-embedding-001';
		}

		return $configured;
	}

	if ( $configured === '' || strpos( $configured, 'gemini-' ) === 0 || strpos( $configured, 'models/' ) === 0 ) {
		return 'text-embedding-3-small';
	}

	return $configured;
}

function vecpost_deactivate() {
	flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'vecpost_activate' );
register_deactivation_hook( __FILE__, 'vecpost_deactivate' );
