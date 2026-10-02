<?php
/**
 * Abstract Base Class for AI Engine Providers.
 *
 * @package Shopwalker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class AIVA_Provider_Base {

	/**
	 * Provider unique slug ID (e.g. gemini, openai, anthropic, elevenlabs).
	 *
	 * @var string
	 */
	protected $id = '';

	/**
	 * Provider display label name.
	 *
	 * @var string
	 */
	protected $name = '';

	/**
	 * Get Provider ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get Provider Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Generate a chat completion response from user prompt and context.
	 *
	 * @param string $prompt User prompt text query.
	 * @param array  $context_chunks Array of relevant database text chunks.
	 * @param string $system_instruction Custom system prompt.
	 * @param array  $history Previous turns as array( array( 'role' => 'user'|'assistant', 'content' => '...' ) ).
	 * @return string|WP_Error Response text or WP_Error.
	 */
	abstract public function generate_chat_response( $prompt, $context_chunks, $system_instruction = '', $history = array() );

	/**
	 * Test provider API connection key.
	 *
	 * @param string $api_key API key to test.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	abstract public function test_connection( $api_key );

	/**
	 * Build the website-context block that is prepended to the user question.
	 *
	 * @param array $context_chunks Matched knowledge base chunks.
	 * @return string
	 */
	protected function build_context_text( $context_chunks ) {
		if ( empty( $context_chunks ) || ! is_array( $context_chunks ) ) {
			return '';
		}

		$context_text = "Relevant Information from our website:\n";
		$i            = 0;
		foreach ( $context_chunks as $chunk ) {
			++$i;
			$context_text .= '[' . $i . '] Type: ' . ( isset( $chunk['content_type'] ) ? $chunk['content_type'] : '' ) . "\n";
			if ( ! empty( $chunk['source_url'] ) ) {
				$context_text .= 'Link: ' . $chunk['source_url'] . "\n";
			}
			$context_text .= 'Content: ' . ( isset( $chunk['chunk_text'] ) ? $chunk['chunk_text'] : '' ) . "\n\n";
		}
		$context_text .= "Please answer the user's question using ONLY the website facts provided above. If the matching facts do not contain the answer, politely say you don't know the exact answer but can help them with other shop questions.\n\n";

		return $context_text;
	}

	/**
	 * Normalise conversation history into alternating user/assistant turns that start with "user".
	 *
	 * @param array $history Raw history.
	 * @return array
	 */
	protected function normalize_history( $history ) {
		$clean = array();
		if ( empty( $history ) || ! is_array( $history ) ) {
			return $clean;
		}

		foreach ( $history as $turn ) {
			if ( ! is_array( $turn ) || empty( $turn['content'] ) || ! isset( $turn['role'] ) ) {
				continue;
			}
			$role = ( 'assistant' === $turn['role'] ) ? 'assistant' : 'user';

			// Merge consecutive turns from the same role so providers that require alternation accept it.
			$last = count( $clean ) - 1;
			if ( $last >= 0 && $clean[ $last ]['role'] === $role ) {
				$clean[ $last ]['content'] .= "\n" . $turn['content'];
				continue;
			}
			$clean[] = array(
				'role'    => $role,
				'content' => (string) $turn['content'],
			);
		}

		// Must start with a user turn and end with an assistant turn (the new question is appended after).
		while ( ! empty( $clean ) && 'user' !== $clean[0]['role'] ) {
			array_shift( $clean );
		}
		while ( ! empty( $clean ) && 'assistant' !== $clean[ count( $clean ) - 1 ]['role'] ) {
			array_pop( $clean );
		}

		return $clean;
	}

	/**
	 * Get the currently configured model ID.
	 *
	 * @return string
	 */
	public function get_effective_model() {
		return '';
	}

	/**
	 * Helper method to format human readable API errors.
	 *
	 * @param string $provider Name of provider.
	 * @param int    $code HTTP response status code.
	 * @param string $body Raw HTTP response body.
	 * @return string Formatted error string.
	 */
	protected function format_error( $provider, $code, $body ) {
		$msg = '';
		if ( ! empty( $body ) ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['detail']['message'] ) && is_string( $decoded['detail']['message'] ) ) {
					$msg = $decoded['detail']['message'];
				} elseif ( isset( $decoded['detail']['status'] ) && is_string( $decoded['detail']['status'] ) ) {
					$msg = $decoded['detail']['status'] . ( ! empty( $decoded['detail']['message'] ) && is_string( $decoded['detail']['message'] ) ? ': ' . $decoded['detail']['message'] : '' );
				} elseif ( isset( $decoded['detail'] ) ) {
					$msg = is_string( $decoded['detail'] ) ? $decoded['detail'] : wp_json_encode( $decoded['detail'] );
				} elseif ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
					$msg = $decoded['error']['message'];
				} elseif ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
					$msg = $decoded['error'];
				} elseif ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
					$msg = $decoded['message'];
				}
			}
		}

		if ( empty( $msg ) ) {
			$msg = ! empty( $body ) ? wp_strip_all_tags( $body ) : __( 'Request failed.', 'shopwalker-ai-voice-chat-assistant' );
		}

		return sprintf( '%s Error (%d): %s', $provider, $code, $msg );
	}
}
