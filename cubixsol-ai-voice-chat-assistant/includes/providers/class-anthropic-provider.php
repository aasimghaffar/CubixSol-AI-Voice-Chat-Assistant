<?php
/**
 * Anthropic Claude AI Provider Implementation.
 *
 * @package Cubixsol_AI_Voice_Chat_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anthropic Claude chat provider.
 */
class AIVA_Anthropic_Provider extends AIVA_Provider_Base {

	/**
	 * Default model.
	 */
	const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';

	/**
	 * Provider ID.
	 *
	 * @var string
	 */
	protected $id = 'anthropic';

	/**
	 * Provider name.
	 *
	 * @var string
	 */
	protected $name = 'Anthropic Claude';

	/**
	 * Get active Anthropic model ID from options or fallback to recommended default.
	 *
	 * @return string Model ID.
	 */
	public function get_effective_model() {
		$model_opt = get_option( 'aiva_anthropic_model', self::DEFAULT_MODEL );
		if ( 'custom' === $model_opt ) {
			$model_opt = get_option( 'aiva_anthropic_custom_model', self::DEFAULT_MODEL );
		}
		return ! empty( $model_opt ) ? trim( $model_opt ) : self::DEFAULT_MODEL;
	}

	/**
	 * Send a Messages API request.
	 *
	 * @param string $api_key API key.
	 * @param array  $body    Request body.
	 * @param int    $timeout Timeout in seconds.
	 * @return array|WP_Error
	 */
	private function request( $api_key, $body, $timeout ) {
		return wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'headers'     => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
				),
				'body'        => wp_json_encode( $body ),
				'timeout'     => $timeout,
				'data_format' => 'body',
			)
		);
	}

	/**
	 * Generate chat response using Anthropic Claude.
	 *
	 * @param string $prompt User prompt text.
	 * @param array  $context_chunks Website database context chunks.
	 * @param string $system_instruction Custom system prompt.
	 * @param array  $history Previous conversation turns.
	 * @return string|WP_Error Response text or WP_Error.
	 */
	public function generate_chat_response( $prompt, $context_chunks, $system_instruction = '', $history = array() ) {
		$api_key = get_option( 'aiva_anthropic_api_key', '' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_anthropic_key', __( 'Anthropic API Key is not configured.', 'cubixsol-ai-voice-chat-assistant' ) );
		}

		$messages   = $this->normalize_history( $history );
		$messages[] = array(
			'role'    => 'user',
			'content' => $this->build_context_text( $context_chunks ) . 'User Question: ' . $prompt,
		);

		$body = array(
			'model'       => $this->get_effective_model(),
			'max_tokens'  => 1000,
			'messages'    => $messages,
			'temperature' => 0.2,
		);

		if ( ! empty( $system_instruction ) ) {
			$body['system'] = $system_instruction;
		}

		$response = $this->request( $api_key, $body, 30 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error( 'anthropic_error', $this->format_error( 'Anthropic API', $code, $raw ) );
		}

		$data = json_decode( $raw, true );
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			$text = '';
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
			if ( '' !== trim( $text ) ) {
				return trim( $text );
			}
		}

		return new WP_Error( 'invalid_response', __( 'Invalid response structure from Anthropic API.', 'cubixsol-ai-voice-chat-assistant' ) );
	}

	/**
	 * Test connection to Anthropic Claude API.
	 *
	 * @param string $api_key API key to test.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function test_connection( $api_key ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'empty_key', __( 'Anthropic API Key cannot be empty.', 'cubixsol-ai-voice-chat-assistant' ) );
		}

		$body = array(
			'model'      => $this->get_effective_model(),
			'max_tokens' => 5,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => 'Ping',
				),
			),
		);

		$response = $this->request( $api_key, $body, 15 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( 200 === $code ) {
			return true;
		}

		return new WP_Error( 'anthropic_test_failed', $this->format_error( 'Anthropic API Test', $code, $raw ) );
	}
}
