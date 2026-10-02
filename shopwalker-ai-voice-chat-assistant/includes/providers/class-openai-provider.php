<?php
/**
 * OpenAI Provider Implementation.
 *
 * @package Shopwalker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAI chat provider.
 */
class AIVA_OpenAI_Provider extends AIVA_Provider_Base {

	/**
	 * Provider ID.
	 *
	 * @var string
	 */
	protected $id = 'openai';

	/**
	 * Provider name.
	 *
	 * @var string
	 */
	protected $name = 'OpenAI';

	/**
	 * Get active OpenAI model ID from options or fallback to recommended default.
	 *
	 * @return string Model ID.
	 */
	public function get_effective_model() {
		$model_opt = get_option( 'aiva_openai_model', 'gpt-4o-mini' );
		if ( 'custom' === $model_opt ) {
			$model_opt = get_option( 'aiva_openai_custom_model', 'gpt-4o-mini' );
		}
		return ! empty( $model_opt ) ? trim( $model_opt ) : 'gpt-4o-mini';
	}

	/**
	 * Whether the model is a reasoning / GPT-5 family model.
	 * These reject "max_tokens" and custom temperature on Chat Completions.
	 *
	 * @param string $model Model ID.
	 * @return bool
	 */
	private function is_reasoning_model( $model ) {
		return (bool) preg_match( '/^(o\d|gpt-5)/i', $model );
	}

	/**
	 * Generate chat response using OpenAI provider.
	 *
	 * @param string $prompt User prompt text.
	 * @param array  $context_chunks Website database context chunks.
	 * @param string $system_instruction Custom system prompt.
	 * @param array  $history Previous conversation turns.
	 * @return string|WP_Error Response text or WP_Error.
	 */
	public function generate_chat_response( $prompt, $context_chunks, $system_instruction = '', $history = array() ) {
		$api_key = get_option( 'aiva_openai_api_key', '' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_openai_key', __( 'OpenAI API Key is not configured.', 'shopwalker-ai-voice-chat-assistant' ) );
		}

		$url   = 'https://api.openai.com/v1/chat/completions';
		$model = $this->get_effective_model();

		$messages = array();
		if ( ! empty( $system_instruction ) ) {
			$messages[] = array(
				'role'    => $this->is_reasoning_model( $model ) ? 'developer' : 'system',
				'content' => $system_instruction,
			);
		}

		foreach ( $this->normalize_history( $history ) as $turn ) {
			$messages[] = $turn;
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => $this->build_context_text( $context_chunks ) . 'User Question: ' . $prompt,
		);

		$body = array(
			'model'    => $model,
			'messages' => $messages,
		);

		if ( $this->is_reasoning_model( $model ) ) {
			// Reasoning tokens count against this budget, so it is set higher than the visible answer needs.
			$body['max_completion_tokens'] = 4000;
		} else {
			$body['temperature'] = 0.2;
			$body['max_tokens']  = 1000;
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers'     => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'        => wp_json_encode( $body ),
				'timeout'     => 30,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error( 'openai_error', $this->format_error( 'OpenAI API', $code, $raw ) );
		}

		$data = json_decode( $raw, true );
		if ( isset( $data['choices'][0]['message']['content'] ) && '' !== trim( (string) $data['choices'][0]['message']['content'] ) ) {
			return trim( $data['choices'][0]['message']['content'] );
		}

		return new WP_Error( 'invalid_response', __( 'Invalid response structure from OpenAI API.', 'shopwalker-ai-voice-chat-assistant' ) );
	}

	/**
	 * Test connection to OpenAI API.
	 *
	 * @param string $api_key API key to test.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function test_connection( $api_key ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'empty_key', __( 'OpenAI API Key cannot be empty.', 'shopwalker-ai-voice-chat-assistant' ) );
		}

		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 === $code ) {
			return true;
		}

		return new WP_Error( 'openai_test_failed', $this->format_error( 'OpenAI API Test', $code, $body ) );
	}
}
