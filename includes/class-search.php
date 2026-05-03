<?php
/**
 * Query processing, similarity ranking, and hybrid re-ranking.
 *
 * @package Embedix_AI_Search_For_Posts
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Embedix_Search {
	public function search( string $query, int $limit = 10, array $filters = array() ): array {
		if ( trim( $query ) === '' ) {
			return array();
		}

		$alpha                  = (float) get_option( 'embedix_semantic_weight', 0.7 );
		$alpha                  = max( 0.0, min( 1.0, $alpha ) );
		$min_final_score        = (float) get_option( 'embedix_min_final_score', 0.18 );
		$min_semantic_score     = (float) get_option( 'embedix_min_semantic_score', 0.20 );
		$keyword_gate_threshold = (float) get_option( 'embedix_keyword_gate_threshold', 0.35 );
		$min_final_score        = max( 0.0, min( 1.0, $min_final_score ) );
		$min_semantic_score     = max( 0.0, min( 1.0, $min_semantic_score ) );
		$keyword_gate_threshold = max( 0.0, min( 1.0, $keyword_gate_threshold ) );

		$cache_key = 'embedix_' . md5( $query . '|' . $limit . '|' . $alpha . '|' . $min_final_score . '|' . $min_semantic_score . '|' . $keyword_gate_threshold . '|' . maybe_serialize( $filters ) );
		$cached    = wp_cache_get( $cache_key, 'embedix_search' );
		if ( $cached !== false ) {
			return $cached;
		}

		$client       = new Embedix_EmbeddingClient();
		$query_vector = $client->embed( $query );

		if ( empty( $query_vector ) ) {
			return array();
		}

		$store       = new Embedix_VectorStore();
		$candidates  = $store->nearest( $query_vector, 20, $filters );
		$query_terms = $this->extract_query_terms( $query );

		$ranked  = $this->hybrid_rerank( $candidates, $query, $alpha );
		$ranked  = $this->apply_score_filter( $ranked, $alpha, $min_final_score, $min_semantic_score, $keyword_gate_threshold );
		$deduped = $this->dedup_by_post( $ranked, $limit );
		$results = $this->hydrate( $deduped, $query_terms );

		wp_cache_set( $cache_key, $results, 'embedix_search', 3600 );

		return $results;
	}

	private function hybrid_rerank( array $candidates, string $query, float $alpha ): array {
		$keywords = preg_split( '/\s+/', strtolower( $query ) );

		foreach ( $candidates as &$candidate ) {
			$keyword_score               = $this->bm25_score( $candidate['chunk_text'], $keywords );
			$candidate['keyword_score']  = $keyword_score;
			$candidate['semantic_score'] = (float) $candidate['similarity'];
			$candidate['final_score']    = ( $alpha * (float) $candidate['similarity'] ) + ( ( 1 - $alpha ) * $keyword_score );
		}
		unset( $candidate );

		usort(
			$candidates,
			function ( $a, $b ) {
				return $b['final_score'] <=> $a['final_score'];
			}
		);

		return $candidates;
	}

	private function apply_score_filter( array $candidates, float $alpha, float $min_final_score, float $min_semantic_score, float $keyword_gate_threshold ): array {
		if ( $alpha <= 0.0001 ) {
			return array_values(
				array_filter(
					$candidates,
					function ( $candidate ) {
						return isset( $candidate['keyword_score'] ) && (float) $candidate['keyword_score'] > 0.0;
					}
				)
			);
		}

		$filtered = array();
		foreach ( $candidates as $candidate ) {
			$semantic = isset( $candidate['semantic_score'] ) ? (float) $candidate['semantic_score'] : 0.0;
			$keyword  = isset( $candidate['keyword_score'] ) ? (float) $candidate['keyword_score'] : 0.0;
			$final    = isset( $candidate['final_score'] ) ? (float) $candidate['final_score'] : 0.0;

			if ( $semantic < $min_semantic_score ) {
				continue;
			}

			if ( $final < $min_final_score ) {
				continue;
			}

			if ( $final < $keyword_gate_threshold && $keyword < 0.05 ) {
				continue;
			}

			$filtered[] = $candidate;
		}

		return $filtered;
	}

	private function bm25_score( string $text, array $keywords ): float {
		$text  = strtolower( $text );
		$words = preg_split( '/\s+/', $text );
		$total = count( $words );
		$score = 0.0;

		foreach ( $keywords as $keyword ) {
			if ( $keyword === '' ) {
				continue;
			}

			$count = preg_match_all( '/\b' . preg_quote( $keyword, '/' ) . '\b/i', $text );
			if ( $count === false ) {
				$count = 0;
			}
			$tf     = $count / max( 1, $total );
			$score += $tf;
		}

		return min( 1.0, $score );
	}

	private function dedup_by_post( array $candidates, int $limit ): array {
		$seen    = array();
		$results = array();

		foreach ( $candidates as $candidate ) {
			$post_id = (int) $candidate['post_id'];
			if ( isset( $seen[ $post_id ] ) ) {
				continue;
			}

			$seen[ $post_id ] = true;
			$results[]        = $candidate;

			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	private function hydrate( array $candidates, array $query_terms = array() ): array {
		$results = array();

		foreach ( $candidates as $candidate ) {
			$post = get_post( (int) $candidate['post_id'] );
			if ( ! $post || $post->post_status !== 'publish' ) {
				continue;
			}

			$search_text  = $post->post_title . ' ' . $candidate['chunk_text'];
			$matched_word = $this->find_best_matching_word( $search_text, $query_terms );

			$results[] = array(
				'post_id'      => $post->ID,
				'title'        => $post->post_title,
				'excerpt'      => get_the_excerpt( $post ),
				'url'          => get_permalink( $post ),
				'score'        => round( (float) $candidate['final_score'], 4 ),
				'matched_word' => $matched_word,
			);
		}

		return $results;
	}

	private function extract_query_terms( string $query ): array {
		$parts = preg_split( '/\s+/', strtolower( trim( $query ) ) );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$terms = array();
		foreach ( $parts as $part ) {
			$part = preg_replace( '/[^a-z0-9]+/i', '', (string) $part );
			if ( $part === '' || strlen( $part ) < 3 ) {
				continue;
			}

			$terms[] = $part;
		}

		return array_values( array_unique( $terms ) );
	}

	private function find_best_matching_word( string $text, array $query_terms ): string {
		$text  = strtolower( $text );
		$words = preg_split( '/[^a-z0-9]+/i', $text );
		if ( ! is_array( $words ) || empty( $words ) || empty( $query_terms ) ) {
			return '';
		}

		$words = array_values(
			array_filter(
				array_map( 'trim', $words ),
				function ( $word ) {
					return $word !== '' && strlen( $word ) >= 3;
				}
			)
		);

		$words = array_slice( array_unique( $words ), 0, 150 );

		foreach ( $query_terms as $query_term ) {
			foreach ( $words as $word ) {
				if ( $word === $query_term ) {
					return $word;
				}
			}
		}

		$best_word     = '';
		$best_distance = PHP_INT_MAX;
		foreach ( $query_terms as $query_term ) {
			foreach ( $words as $word ) {
				$distance = levenshtein( $query_term, $word );
				if ( $distance < $best_distance ) {
					$best_distance = $distance;
					$best_word     = $word;
				}
			}
		}

		return $best_word;
	}
}
