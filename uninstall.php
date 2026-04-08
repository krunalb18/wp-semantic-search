<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package AI_Semantic_Search_For_Posts
 * @license GPL-2.0-or-later
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ss_embeddings");

$options = array(
	'ss_embedding_provider',
	'ss_openai_api_key',
	'ss_gemini_api_key',
	'ss_embedding_model',
	'ss_post_types',
	'ss_semantic_weight',
	'ss_min_final_score',
	'ss_min_semantic_score',
	'ss_keyword_gate_threshold',
	'ss_indexed_with_model',
	'ss_last_embedding_error',
	'ss_index_queue',
	'ss_index_status',
);

foreach ($options as $option) {
	delete_option($option);
}

wp_clear_scheduled_hook('ss_embed_post');
wp_clear_scheduled_hook('ss_process_bulk_batch');