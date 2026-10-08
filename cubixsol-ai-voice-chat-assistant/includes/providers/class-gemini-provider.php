<?php
/**
 * Google Gemini AI Provider Implementation.
 *
 * @package Cubixsol_AI_Voice_Chat_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemini chat and embedding provider.
 */
class AIVA_Gemini_Provider extends AIVA_Provider_Base {

	/**
	 * Default chat model.
	 */
	const DEFAULT_MODEL = 'gemini-3.5-flash';

	/**
	 * Embedding model.
	 */
	const EMBEDDING_MODEL = 'gemini-embedding-001';

	/**
	 * Embedding vector size. 768 keeps the index small and fast while keeping good retrieval quality.
	 */
	const EMBEDDING_DIMENSIONS = 768;

	/**
	 * Provider ID.
	 *
	 * @var string
	 */
	protected $id = 'gemini';

	/**
	 * Provider name.
	 *
	 * @var string
	 */
	protected $name = 'Google Gemini';

	/**
	 * Get active Gemini model ID from options or fallback to recommended default.
	 *
	 * @return string Model ID.
	 */
	public function get_effective_model() {
		$model_opt = get_option( 'aiva_gemini_model', self::DEFAULT_MODEL );
		if ( 'custom' === $model_opt ) {
			$model_opt = get_option( 'aiva_gemini_custom_model', self::DEFAULT_MODEL );
		}
		return ! empty( $model_opt ) ? trim( $model_opt ) : self::DEFAULT_MODEL;
	}

	/**
	 * POST JSON to the Gemini API. The key is sent as a header, never in the URL.
	 *
	 * @param string $url     Endpoint URL.
	 * @param string $api_key API key.
	 * @param array  $body    Request body.
	 * @param int    $timeout Timeout in seconds.
	 * @return array|WP_Error
	 */
	private function request( $url, $api_key, $body, $timeout ) {
		return wp_remote_post(
			$url,
			array(
				'headers'     => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $api_key,
				),
				'body'        => wp_json_encode( $body ),
				'timeout'     => $timeout,
				'data_format' => 'body',
			)
		);
	}

	/**
	 * Generate chat response using Gemini provider.
	 *
	 * @param string $prompt User prompt text.
	 * @param array  $context_chunks Website database context chunks.
	 * @param string $system_instruction Custom system prompt.
	 * @param array  $history Previous conversation turns.
	 * @return string|WP_Error Response text or WP_Error.
	 */
	public function generate_chat_response( $prompt, $context_chunks, $system_instruction = '', $history = array() ) {
		$api_key = get_option( 'aiva_gemini_api_key', '' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_api_key', __( 'Gemini API Key is not configured.', 'cubixsol-ai-voice-chat-assistant' ) );
		}

		$model_id = $this->get_effective_model();
		// Accept both "gemini-x" and "models/gemini-x" style IDs (IDs are sanitized to URL-safe characters on save).
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . preg_replace( '#^models/#', '', $model_id ) . ':generateContent';

		$contents = array();
		foreach ( $this->normalize_history( $history ) as $turn ) {
			$contents[] = array(
				'role'  => ( 'assistant' === $turn['role'] ) ? 'model' : 'user',
				'parts' => array( array( 'text' => $turn['content'] ) ),
			);
		}
		$contents[] = array(
			'role'  => 'user',
			'parts' => array(
				array(
					'text' => $this->build_context_text( $context_chunks ) . 'User Question: ' . $prompt,
				),
			),
		);

		$generation_config = array(
			'maxOutputTokens' => 2048,
		);

		if ( 0 === strpos( $model_id, 'gemini-3' ) ) {
			// Gemini 3.x: keep thinking light for fast voice replies; Google recommends the default temperature.
			$generation_config['thinkingConfig'] = array( 'thinkingLevel' => 'low' );
		} else {
			$generation_config['temperature'] = 0.2;
		}

		$body = array(
			'contents'         => $contents,
			'generationConfig' => $generation_config,
		);

		if ( ! empty( $system_instruction ) ) {
			$body['systemInstruction'] = array(
				'parts' => array(
					array(
						'text' => $system_instruction,
					),
				),
			);
		}

		$response = $this->request( $url, $api_key, $body, 30 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error( 'gemini_error', $this->format_error( 'Gemini API', $code, $raw ) );
		}

		$data = json_decode( $raw, true );
		if ( isset( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
			$text = '';
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) && empty( $part['thought'] ) ) {
					$text .= $part['text'];
				}
			}
			if ( '' !== trim( $text ) ) {
				return trim( $text );
			}
		}

		return new WP_Error( 'invalid_response', __( 'Invalid response structure from Gemini API.', 'cubixsol-ai-voice-chat-assistant' ) );
	}

	/**
	 * Generate a 768-dimension vector embedding using gemini-embedding-001.
	 *
	 * @param string $text         Input text block to embed.
	 * @param string $task_type    RETRIEVAL_DOCUMENT for indexed content, RETRIEVAL_QUERY for visitor questions.
	 * @param int    $max_attempts How many times to try on rate limits / network errors.
	 * @return array|WP_Error Array of floats or WP_Error.
	 */
	public function generate_embedding( $text, $task_type = 'RETRIEVAL_DOCUMENT', $max_attempts = 3 ) {
		$api_key = get_option( 'aiva_gemini_api_key', '' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_api_key', __( 'Gemini API Key is missing for embeddings.', 'cubixsol-ai-voice-chat-assistant' ) );
		}

		$url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . self::EMBEDDING_MODEL . ':embedContent';
		$body = array(
			'model'                => 'models/' . self::EMBEDDING_MODEL,
			'content'              => array(
				'parts' => array(
					array(
						'text' => $text,
					),
				),
			),
			'taskType'             => in_array( $task_type, array( 'RETRIEVAL_DOCUMENT', 'RETRIEVAL_QUERY' ), true ) ? $task_type : 'RETRIEVAL_DOCUMENT',
			'outputDimensionality' => self::EMBEDDING_DIMENSIONS,
		);

		$max_attempts = max( 1, (int) $max_attempts );
		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$response = $this->request( $url, $api_key, $body, 20 );

			if ( is_wp_error( $response ) ) {
				if ( $attempt < $max_attempts ) {
					sleep( 2 );
					continue;
				}
				return $response;
			}

			$code     = wp_remote_retrieve_response_code( $response );
			$res_body = wp_remote_retrieve_body( $response );

			if ( 200 === $code ) {
				$data = json_decode( $res_body, true );
				if ( isset( $data['embedding']['values'] ) && is_array( $data['embedding']['values'] ) ) {
					return $data['embedding']['values'];
				}
				return new WP_Error( 'invalid_embedding', __( 'Invalid embedding response from Gemini API.', 'cubixsol-ai-voice-chat-assistant' ) );
			}

			// If rate limited (HTTP 429), pause for 3s (1st attempt) or 6s (2nd attempt) before retrying.
			if ( 429 === (int) $code && $attempt < $max_attempts ) {
				sleep( $attempt * 3 );
				continue;
			}

			return new WP_Error( 'embedding_error', $this->format_error( 'Gemini Embedding API', $code, $res_body ) );
		}

		return new WP_Error( 'embedding_error', __( 'Gemini Embedding API failed after retries.', 'cubixsol-ai-voice-chat-assistant' ) );
	}

	/**
	 * Test connection to Gemini API.
	 *
	 * @param string $api_key API Key to test.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function test_connection( $api_key ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'empty_key', __( 'Gemini API Key cannot be empty.', 'cubixsol-ai-voice-chat-assistant' ) );
		}

		$url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . self::EMBEDDING_MODEL . ':embedContent';
		$body = array(
			'model'                => 'models/' . self::EMBEDDING_MODEL,
			'content'              => array(
				'parts' => array(
					array(
						'text' => 'Test connection query',
					),
				),
			),
			'outputDimensionality' => self::EMBEDDING_DIMENSIONS,
		);

		$response = $this->request( $url, $api_key, $body, 15 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( 200 === $code ) {
			return true;
		}

		return new WP_Error( 'gemini_test_failed', $this->format_error( 'Gemini API Test', $code, $raw ) );
	}
}
