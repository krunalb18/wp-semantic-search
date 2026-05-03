<?php
/**
 * OpenAI and Gemini API communication.
 *
 * @package Embedix_AI_Search_For_Posts
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Embedix_EmbeddingClient {
	private $provider;
	private $openai_api_key;
	private $gemini_api_key;
	private $model;
	private $openai_api_url  = 'https://api.openai.com/v1/embeddings';
	private $gemini_api_base = 'https://generativelanguage.googleapis.com/v1beta/models/';

	public function __construct() {
		$this->provider       = (string) get_option( 'embedix_embedding_provider', 'openai' );
		$this->openai_api_key = (string) get_option( 'embedix_openai_api_key', '' );
		$this->gemini_api_key = (string) get_option( 'embedix_gemini_api_key', '' );

		$configured_model = (string) get_option( 'embedix_embedding_model', '' );
		$this->model      = $configured_model !== ''
			? $configured_model
			: $this->default_model_for_provider( $this->provider );
	}

	public function is_configured(): bool {
		if ( $this->provider === 'gemini' ) {
			return $this->gemini_api_key !== '';
		}

		return $this->openai_api_key !== '';
	}

	public function embed( string $text ): array {
		$results = $this->embed_batch( array( $text ) );
		return isset( $results[0] ) ? $results[0] : array();
	}

	public function embed_text( $text ) {
		return $this->embed( (string) $text );
	}

	public function embed_batch( array $texts ): array {
		$texts = array_values(
			array_filter(
				array_map( 'strval', $texts ),
				function ( $t ) {
					return trim( $t ) !== '';
				}
			)
		);

		if ( empty( $texts ) || ! $this->is_configured() ) {
			return array();
		}

		if ( $this->provider === 'gemini' ) {
			return $this->embed_batch_with_gemini( $texts );
		}

		return $this->embed_batch_with_openai( $texts );
	}

	private function request_with_retry( string $url, array $args, int $max_retries = 2 ) {
		$attempt  = 0;
		$response = null;

		while ( $attempt <= $max_retries ) {
			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				++$attempt;
				if ( $attempt <= $max_retries ) {
					sleep( (int) pow( 2, $attempt ) );
				}
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status === 429 || $status >= 500 ) {
				++$attempt;
				if ( $attempt <= $max_retries ) {
					sleep( (int) pow( 2, $attempt ) );
				}
				continue;
			}

			return $response;
		}

		return $response ?: new WP_Error( 'embedix_request_failed', 'All retry attempts failed.' );
	}

	private function embed_batch_with_openai( array $texts ): array {
		$model = $this->normalize_model_for_provider( $this->model, 'openai' );

		$response = $this->request_with_retry(
			$this->openai_api_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->openai_api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model' => $model,
						'input' => $texts,
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_msg = 'OpenAI API error: ' . $response->get_error_message();
			error_log( $error_msg );
			update_option( 'embedix_last_embedding_error', $error_msg );
			return array();
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( (int) $status !== 200 ) {
			$body      = json_decode( wp_remote_retrieve_body( $response ), true );
			$error_msg = 'OpenAI API returned HTTP ' . $status;
			if ( ! empty( $body['error']['message'] ) ) {
				$error_msg .= ': ' . $body['error']['message'];
			}
			error_log( $error_msg );
			update_option( 'embedix_last_embedding_error', $error_msg );
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return array();
		}

		usort(
			$body['data'],
			function ( $a, $b ) {
				return ( (int) $a['index'] ) <=> ( (int) $b['index'] );
			}
		);

		return array_map(
			function ( $item ) {
				return isset( $item['embedding'] ) && is_array( $item['embedding'] ) ? $item['embedding'] : array();
			},
			$body['data']
		);
	}

	private function embed_batch_with_gemini( array $texts ): array {
		$model      = $this->normalize_model_for_provider( $this->model, 'gemini' );
		$model_name = strpos( $model, 'models/' ) === 0 ? str_replace( 'models/', '', $model ) : $model;
		$url        = $this->gemini_api_base
			. rawurlencode( $model_name )
			. ':batchEmbedContents?key='
			. rawurlencode( $this->gemini_api_key );

		$requests = array_map(
			function ( $text ) use ( $model_name ) {
				return array(
					'model'   => 'models/' . $model_name,
					'content' => array(
						'parts' => array(
							array( 'text' => $text ),
						),
					),
				);
			},
			$texts
		);

		$response = $this->request_with_retry(
			$url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'requests' => $requests,
					)
				),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_msg = 'Gemini API error: ' . $response->get_error_message();
			error_log( $error_msg );
			update_option( 'embedix_last_embedding_error', $error_msg );
			return array();
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status !== 200 ) {
			$body      = json_decode( wp_remote_retrieve_body( $response ), true );
			$error_msg = 'Gemini API returned HTTP ' . $status;
			if ( ! empty( $body['error']['message'] ) ) {
				$error_msg .= ': ' . $body['error']['message'];
			}
			error_log( $error_msg );
			update_option( 'embedix_last_embedding_error', $error_msg );
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['embeddings'] ) || ! is_array( $body['embeddings'] ) ) {
			return array();
		}

		return array_map(
			function ( $item ) {
				return isset( $item['values'] ) && is_array( $item['values'] ) ? $item['values'] : array();
			},
			$body['embeddings']
		);
	}

	private function default_model_for_provider( string $provider ): string {
		return $provider === 'gemini' ? 'gemini-embedding-001' : 'text-embedding-3-small';
	}

	private function normalize_model_for_provider( string $model, string $provider ): string {
		if ( $provider === 'gemini' ) {
			if ( $model === '' || strpos( $model, 'text-embedding-3-' ) === 0 ) {
				return 'gemini-embedding-001';
			}
			return $model;
		}

		if ( $model === '' || strpos( $model, 'gemini-' ) === 0 || strpos( $model, 'models/' ) === 0 ) {
			return 'text-embedding-3-small';
		}

		return $model;
	}
}
