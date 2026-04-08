<?php
/**
 * REST API endpoint registration and handlers.
 *
 * @package WP_Semantic_Search
 * @license GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

class SemanticSearch_RestAPI {
	public function __construct() {
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	public function register_routes() {
		register_rest_route('ss/v1', '/search', array(
			'methods' => 'GET',
			'callback' => array($this, 'handle_search'),
			'permission_callback' => '__return_true',
			'args' => array(
				'q' => array(
					'required' => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'limit' => array(
					'required' => false,
					'default' => 10,
					'sanitize_callback' => 'absint',
				),
			),
		));

		register_rest_route('ss/v1', '/index-status', array(
			'methods' => 'GET',
			'callback' => array($this, 'handle_index_status'),
			'permission_callback' => function (WP_REST_Request $request) {
				$nonce = $request->get_header('X-WP-Nonce');
				return current_user_can('manage_options') && wp_verify_nonce($nonce, 'wp_rest');
			},
		));

		register_rest_route('ss/v1', '/start-index', array(
			'methods' => 'POST',
			'callback' => array($this, 'handle_start_index'),
			'permission_callback' => function (WP_REST_Request $request) {
				$nonce = $request->get_header('X-WP-Nonce');
				return current_user_can('manage_options') && wp_verify_nonce($nonce, 'wp_rest');
			},
		));

		register_rest_route('ss/v1', '/test-connection', array(
			'methods' => 'POST',
			'callback' => array($this, 'handle_test_connection'),
			'permission_callback' => function (WP_REST_Request $request) {
				$nonce = $request->get_header('X-WP-Nonce');
				return current_user_can('manage_options') && wp_verify_nonce($nonce, 'wp_rest');
			},
		));
	}

	public function handle_search(WP_REST_Request $request): WP_REST_Response {
		$query = (string) $request->get_param('q');
		$limit = min(20, (int) $request->get_param('limit'));

		$search = new SemanticSearch_Search();
		$results = $search->search($query, $limit);
		$safe_results = array_map(function ($result) {
			unset($result['chunk_text']);
			return $result;
		}, $results);

		return new WP_REST_Response(array(
			'results' => $safe_results,
			'query' => $query,
		), 200);
	}

	public function handle_index_status(): WP_REST_Response {
		$indexer = new SemanticSearch_BulkIndexer();
		return new WP_REST_Response($indexer->get_status(), 200);
	}

	public function handle_start_index(WP_REST_Request $request): WP_REST_Response {
		$indexer = new SemanticSearch_BulkIndexer();
		$force_raw = $request->get_param('force');
		$force = in_array($force_raw, array(1, '1', true, 'true', 'yes', 'on'), true);
		$result = $indexer->start_full_index(null, $force);
		return new WP_REST_Response($result, 200);
	}

	public function handle_test_connection(): WP_REST_Response {
		$client = new SemanticSearch_EmbeddingClient();

		if (!$client->is_configured()) {
			return new WP_REST_Response(array(
				'success' => false,
				'message' => __('No API key configured.', 'wp-semantic-search'),
			), 200);
		}

		$result = $client->embed('semantic search test');

		if (empty($result)) {
			$last_error = get_option('ss_last_embedding_error', __('Unknown error.', 'wp-semantic-search'));
			return new WP_REST_Response(array(
				'success' => false,
				'message' => $last_error,
			), 200);
		}

		return new WP_REST_Response(array(
			'success' => true,
			'dimensions' => count($result),
		), 200);
	}
}
