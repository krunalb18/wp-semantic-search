<?php
/**
 * Database read/write for embedding vectors.
 *
 * @package Embedix_AI_Search_For_Posts
 * @license GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

class Embedix_VectorStore {
	public function nearest(array $query_vector, int $k = 20, array $filters = array()): array {
		global $wpdb;

		$where = 'WHERE e.stale = %d';
		$params = array(0);

		if (!empty($filters['post_type'])) {
			$post_types = array_values(array_filter((array) $filters['post_type'], function ($value) {
				return $value !== '';
			}));

			if (!empty($post_types)) {
				$placeholders = implode(',', array_fill(0, count($post_types), '%s'));
				$where .= " AND p.post_type IN ($placeholders)";
				$params = array_merge($params, $post_types);
			}
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.post_id, e.chunk_text, e.vector_blob
				FROM {$wpdb->prefix}embedix_embeddings e
				INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
				{$where}",
				...$params
			),
			'ARRAY_A'
		);

		if (!is_array($rows)) {
			return array();
		}

		$results = array();
		foreach ($rows as $row) {
			$stored_vector = array_values(unpack('f*', $row['vector_blob']));
			$similarity = $this->cosine_similarity($query_vector, $stored_vector);
			$results[] = array(
				'post_id' => (int) $row['post_id'],
				'chunk_text' => (string) $row['chunk_text'],
				'similarity' => $similarity,
			);
		}

		usort($results, function ($a, $b) {
			return $b['similarity'] <=> $a['similarity'];
		});

		return array_slice($results, 0, $k);
	}

	public function search(array $vector, $limit = 10) {
		return $this->nearest($vector, (int) $limit);
	}

	public function upsert(int $post_id, array $chunks, array $vectors): int {
		global $wpdb;
		$model = function_exists('embedix_current_embedding_model')
			? embedix_current_embedding_model()
			: $this->default_model_for_current_provider();
		$inserted = 0;

		foreach ($chunks as $index => $chunk) {
			if (!isset($vectors[$index]) || !is_array($vectors[$index])) {
				continue;
			}

			$vector_blob = pack('f*', ...$vectors[$index]);
			$wpdb->replace(
				$wpdb->prefix . 'embedix_embeddings',
				array(
					'post_id' => $post_id,
					'chunk_index' => $index,
					'chunk_text' => $chunk,
					'model' => $model,
					'vector_blob' => $vector_blob,
					'stale' => 0,
				),
				array('%d', '%d', '%s', '%s', '%s', '%d')
			);

			if ($wpdb->last_error === '') {
				$inserted++;
			}
		}

		return $inserted;
	}

	public function upsert_vector($post_id, array $vector, array $metadata = array()) {
		$chunk_text = isset($metadata['chunk_text']) ? (string) $metadata['chunk_text'] : '';
		$chunk_index = isset($metadata['chunk_index']) ? (int) $metadata['chunk_index'] : 0;
		$this->upsert((int) $post_id, array($chunk_index => $chunk_text), array($chunk_index => $vector));
		return true;
	}

	public function delete_vectors_for_post($post_id) {
		global $wpdb;
		$wpdb->delete($wpdb->prefix . 'embedix_embeddings', array('post_id' => (int) $post_id), array('%d'));
		return true;
	}

	private function default_model_for_current_provider(): string {
		return 'text-embedding-3-small';
	}

	private function cosine_similarity(array $a, array $b): float {
		$len = min(count($a), count($b));
		if ($len === 0) {
			return 0.0;
		}

		$dot_product = 0.0;
		$magnitude_a = 0.0;
		$magnitude_b = 0.0;

		for ($i = 0; $i < $len; $i++) {
			$ai = (float) $a[$i];
			$bi = (float) $b[$i];
			$dot_product += $ai * $bi;
			$magnitude_a += $ai * $ai;
			$magnitude_b += $bi * $bi;
		}

		$magnitude = sqrt($magnitude_a) * sqrt($magnitude_b);
		if ($magnitude == 0.0) {
			return 0.0;
		}

		return $dot_product / $magnitude;
	}
}
