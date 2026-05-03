<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Embedix_AI_Search_For_Posts
 * @license GPL-2.0-or-later
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}embedix_embeddings");

$options = array(
	'embedix_embedding_provider',
	'embedix_openai_api_key',
	'embedix_gemini_api_key',
	'embedix_embedding_model',
	'embedix_post_types',
	'embedix_semantic_weight',
	'embedix_min_final_score',
	'embedix_min_semantic_score',
	'embedix_keyword_gate_threshold',
	'embedix_indexed_with_model',
	'embedix_last_embedding_error',
	'embedix_index_queue',
	'embedix_index_status',
);

foreach ($options as $option) {
	delete_option($option);
}

wp_clear_scheduled_hook('embedix_embed_post');
wp_clear_scheduled_hook('embedix_process_bulk_batch');