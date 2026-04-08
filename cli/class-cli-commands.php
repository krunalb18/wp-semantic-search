<?php
/**
 * WP-CLI command registration.
 *
 * @package AI_Semantic_Search_For_Posts
 * @license GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!function_exists('__')) {
	function __($text, $domain = 'default') {
		return $text;
	}
}

if (!class_exists('WP_CLI')) {
	class WP_CLI {
		public static function success($message): void {}
		public static function line($message): void {}
		public static function error($message): void {}
		public static function add_command($name, $class): void {}
	}
}

class SemanticSearch_CLI {
	public function index(array $args, array $assoc_args): void {
		$post_types = isset($assoc_args['post-type'])
			? explode(',', $assoc_args['post-type'])
			: array('post', 'page');
		$force = isset($assoc_args['force']) && in_array($assoc_args['force'], array('1', 1, true, 'true', 'yes', 'on'), true);

		$indexer = new SemanticSearch_BulkIndexer();
		$result = $indexer->start_full_index($post_types, $force);

		WP_CLI::success(sprintf(
			/* translators: 1: number of queued posts, 2: number of skipped posts, 3: force flag */
			__('Queued %1$d posts for indexing. Skipped %2$d already indexed. Force: %3$s.', 'ai-semantic-search-for-posts'),
			(int) $result['pending'],
			(int) $result['skipped'],
			$force ? __('yes', 'ai-semantic-search-for-posts') : __('no', 'ai-semantic-search-for-posts')
		));
	}

	public function status(): void {
		$indexer = new SemanticSearch_BulkIndexer();
		$status = $indexer->get_status();
		WP_CLI::line(sprintf(
			/* translators: 1: total posts, 2: completed posts, 3: remaining posts, 4: status text */
			__('Total: %1$d | Done: %2$d | Remaining: %3$d | Status: %4$s', 'ai-semantic-search-for-posts'),
			(int) $status['total'],
			(int) $status['done'],
			(int) $status['remaining'],
			(string) $status['status']
		));
	}

	public function search(array $args): void {
		if (empty($args[0])) {
			WP_CLI::error(__('Please provide a search query.', 'ai-semantic-search-for-posts'));
		}

		$searcher = new SemanticSearch_Search();
		$results = $searcher->search((string) $args[0], 5);

		foreach ($results as $result) {
			WP_CLI::line('[' . $result['score'] . '] ' . $result['title'] . ' - ' . $result['url']);
		}
	}
}

class SemanticSearch_CLI_Commands {
	public function __construct() {
		if (class_exists('WP_CLI')) {
			WP_CLI::add_command('semantic-search', 'SemanticSearch_CLI');
		}
	}
}
