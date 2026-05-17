<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package VecPost_AI_Semantic_Search_For_Posts
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}vecpost_embeddings" );

$options = array(
	'vecpost_embedding_provider',
	'vecpost_openai_api_key',
	'vecpost_gemini_api_key',
	'vecpost_embedding_model',
	'vecpost_post_types',
	'vecpost_semantic_weight',
	'vecpost_min_final_score',
	'vecpost_min_semantic_score',
	'vecpost_keyword_gate_threshold',
	'vecpost_indexed_with_model',
	'vecpost_last_embedding_error',
	'vecpost_index_queue',
	'vecpost_index_status',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'vecpost_embed_post' );
wp_clear_scheduled_hook( 'vecpost_process_bulk_batch' );
