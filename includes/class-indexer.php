<?php
/**
 * Post indexing and embedding job processor.
 *
 * @package AI_Semantic_Search_For_Posts
 * @license GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

class SemanticSearch_Indexer {

	public function __construct() {
		add_action('save_post', array($this, 'on_save_post'), 10, 3);
		add_action('ss_embed_post', array($this, 'process_embedding_job'));
		add_action('before_delete_post', array($this, 'delete_embeddings'));
		add_action('wp_trash_post', array($this, 'delete_embeddings'));
		add_action('untrashed_post', array($this, 'on_save_post'));
	}

	public function on_save_post(int $post_id, ?WP_Post $post = null, bool $update = false): void {
		if (wp_is_post_revision($post_id)) {
			return;
		}

		if (wp_is_post_autosave($post_id)) {
			return;
		}

		if (!$post) {
			$post = get_post($post_id);
		}

		if (!$post || $post->post_status !== 'publish') {
			return;
		}

		$allowed_types = get_option('ss_post_types', array('post', 'page'));
		if (!in_array($post->post_type, $allowed_types, true)) {
			return;
		}

		$existing = wp_next_scheduled('ss_embed_post', array(array('post_id' => $post_id)));
		if ($existing) {
			wp_unschedule_event($existing, 'ss_embed_post', array(array('post_id' => $post_id)));
		}

		wp_schedule_single_event(
			time(),
			'ss_embed_post',
			array(array('post_id' => $post_id))
		);
	}

	public function process_embedding_job(array $args): bool {
		$post_id = isset($args['post_id']) ? (int) $args['post_id'] : 0;
		$post = get_post($post_id);

		if (!$post || $post->post_status !== 'publish') {
			return false;
		}

		$title = (string) $post->post_title;
		$body = wp_strip_all_tags($post->post_content);

		if (trim($body) === '') {
			$chunks = trim($title) === '' ? array() : array($title);
		} else {
			$chunks = $this->chunk_text($body, 380, 50);
			$chunks = array_map(function ($chunk) use ($title) {
				return $title . "\n\n" . $chunk;
			}, $chunks);
		}

		if (empty($chunks)) {
			return false;
		}

		$this->delete_embeddings($post_id);

		$client = new SemanticSearch_EmbeddingClient();
		$vectors = $client->embed_batch($chunks);
		if (empty($vectors) || count($vectors) !== count($chunks)) {			
			return false;
		}

		$store = new SemanticSearch_VectorStore();
		$inserted = $store->upsert($post_id, $chunks, $vectors);

		if (class_exists('SemanticSearch_Cache')) {
			SemanticSearch_Cache::flush();
		}

		return $inserted > 0;
	}

	public function delete_embeddings(int $post_id): void {
		global $wpdb;

		$wpdb->delete(
			$wpdb->prefix . 'ss_embeddings',
			array('post_id' => $post_id),
			array('%d')
		);

		if (class_exists('SemanticSearch_Cache')) {
			SemanticSearch_Cache::flush();
		}
	}

	private function chunk_text(string $text, int $chunk_size = 512, int $overlap = 64): array {
		$text = trim($text);
		if ($text === '') {
			return array();
		}

		$words = preg_split('/\s+/', $text);
		if (!$words) {
			return array();
		}

		$chunks = array();
		$total = count($words);
		$step = max(1, $chunk_size - $overlap);

		for ($start = 0; $start < $total; $start += $step) {
			$slice = array_slice($words, $start, $chunk_size);
			if (empty($slice)) {
				break;
			}

			$chunks[] = implode(' ', $slice);
			if ($start + $chunk_size >= $total) {
				break;
			}
		}

		return $chunks;
	}
}
