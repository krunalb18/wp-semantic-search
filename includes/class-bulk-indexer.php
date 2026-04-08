<?php
/**
 * Bulk indexing queue and cron batch processor.
 *
 * @package WP_Semantic_Search
 * @license GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

class SemanticSearch_BulkIndexer {
	const BATCH_SIZE = 20;
	const DELAY_SECONDS = 3;
	const QUEUE_OPTION = 'ss_index_queue';
	const STATUS_OPTION = 'ss_index_status';

	public function __construct() {
		add_action('ss_process_bulk_batch', array($this, 'process_batch'));
	}

	public function start_full_index(?array $post_types = null, bool $force = false): array {
		if (!$post_types) {
			$post_types = get_option('ss_post_types', array('post', 'page'));
		}

		$all_ids = get_posts(array(
			'post_type' => $post_types,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
		));

		$current_model = function_exists('ss_current_embedding_model')
			? ss_current_embedding_model()
			: (string) get_option('ss_embedding_model', 'text-embedding-3-small');
		$indexed_ids = $this->get_indexed_post_ids($current_model);
		$pending = $force ? $all_ids : array_values(array_diff($all_ids, $indexed_ids));

		while ($timestamp = wp_next_scheduled('ss_process_bulk_batch')) {
			wp_unschedule_event($timestamp, 'ss_process_bulk_batch');
		}

		update_option(self::QUEUE_OPTION, $pending, false);
		update_option(self::STATUS_OPTION, array(
			'total' => count($pending),
			'done' => 0,
			'failed' => 0,
			'remaining' => count($pending),
			'started_at' => time(),
			'status' => 'running',
		), false);

		wp_schedule_single_event(time(), 'ss_process_bulk_batch');

		return array(
			'total' => count($all_ids),
			'pending' => count($pending),
			'skipped' => $force ? 0 : count($indexed_ids),
			'force' => $force,
		);
	}

	public function process_batch(): void {
		$queue = get_option(self::QUEUE_OPTION, array());
		if (!is_array($queue)) {
			$queue = array();
		}

		if (empty($queue)) {
			$this->mark_complete();
			return;
		}

		$batch = array_splice($queue, 0, self::BATCH_SIZE);
		update_option(self::QUEUE_OPTION, $queue, false);

		$indexer = new SemanticSearch_Indexer();
		$successful = 0;
		$failed = 0;
		foreach ($batch as $post_id) {
			try {
				if ($indexer->process_embedding_job(array('post_id' => (int) $post_id))) {
					$successful++;
				} else {
					$failed++;
				}
			} catch (Exception $e) {
				$failed++;
			}
		}

		$status = get_option(self::STATUS_OPTION, array());
		$status['status'] = 'running';
		$status['done'] = (isset($status['done']) ? (int) $status['done'] : 0) + $successful;
		$status['failed'] = (isset($status['failed']) ? (int) $status['failed'] : 0) + $failed;
		$status['remaining'] = count($queue);
		update_option(self::STATUS_OPTION, $status, false);

		if (!empty($queue)) {
			wp_schedule_single_event(time() + self::DELAY_SECONDS, 'ss_process_bulk_batch');
		} else {
			$this->mark_complete();
		}
	}

	public function get_status(): array {
		$status = get_option(self::STATUS_OPTION, array());
		$queue = get_option(self::QUEUE_OPTION, array());
		if (!is_array($queue)) {
			$queue = array();
		}

		$total = isset($status['total']) ? (int) $status['total'] : 0;
		$done = isset($status['done']) ? (int) $status['done'] : 0;
		$failed = isset($status['failed']) ? (int) $status['failed'] : 0;
		$remaining = isset($status['remaining']) ? (int) $status['remaining'] : count($queue);
		$status_text = isset($status['status']) ? (string) $status['status'] : 'idle';

		if ($remaining === 0 && !empty($queue)) {
			$remaining = count($queue);
		}

		if ($remaining === 0 && $status_text === 'running' && $total > ($done + $failed)) {
			$remaining = max(0, $total - ($done + $failed));
		}

		$running = ($status_text === 'running' && $remaining > 0);

		if (!$running && $status_text === 'running') {
			$status_text = 'complete';
		}

		return array(
			'total' => $total,
			'done' => $done,
			'failed' => $failed,
			'remaining' => $remaining,
			'running' => $running,
			'status' => $status_text,
			'started_at' => isset($status['started_at']) ? $status['started_at'] : null,
		);
	}

	private function mark_complete(): void {
		$status = get_option(self::STATUS_OPTION, array());
		$status['status'] = 'complete';
		$status['done'] = isset($status['done']) ? (int) $status['done'] : 0;
		$status['failed'] = isset($status['failed']) ? (int) $status['failed'] : 0;
		$status['remaining'] = 0;
		update_option(self::STATUS_OPTION, $status, false);
		delete_option(self::QUEUE_OPTION);
	}

	private function get_indexed_post_ids(string $model): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->prefix}ss_embeddings WHERE model = %s AND stale = 0",
				$model
			)
		);

		return is_array($ids) ? array_map('intval', $ids) : array();
	}
}
