<?php
/**
 * WordPress object cache wrapper.
 *
 * @package WP_Semantic_Search
 * @license GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

class SemanticSearch_Cache {
	public static function set(string $query, array $results, int $ttl = 3600): void {
		wp_cache_set(self::key($query), $results, 'semantic_search', $ttl);
	}

	public static function get(string $query) {
		return wp_cache_get(self::key($query), 'semantic_search');
	}

	public static function flush(): void {
		if (function_exists('wp_cache_flush_group')) {
			wp_cache_flush_group('semantic_search');
			return;
		}

		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}
	}

	private static function key(string $query): string {
		return 'ss_q_' . md5(strtolower(trim($query)));
	}
}
