<?php
/**
 * Database table creation on activation.
 *
 * @package Embedix_AI_Search_For_Posts
 * @license GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

function embedix_create_db_tables() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name = $wpdb->prefix . 'embedix_embeddings';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		post_id BIGINT UNSIGNED NOT NULL,
		chunk_index SMALLINT NOT NULL DEFAULT 0,
		chunk_text TEXT NOT NULL,
		model VARCHAR(64) NOT NULL,
		vector_blob MEDIUMBLOB NOT NULL,
		stale TINYINT(1) NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY idx_post_id (post_id),
		UNIQUE KEY uq_post_chunk (post_id, chunk_index)
	) ENGINE=InnoDB {$charset_collate};";

	dbDelta($sql);
}
