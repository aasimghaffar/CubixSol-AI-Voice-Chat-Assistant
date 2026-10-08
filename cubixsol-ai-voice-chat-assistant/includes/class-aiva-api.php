<?php
/**
 * API Wrapper Facade for Cubixsol AI Assistant.
 * Delegates calls to provider classes registered in AIVA_Provider_Registry.
 *
 * @package Cubixsol_AI_Voice_Chat_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIVA_API {

	/**
	 * Format API error messages cleanly for UI output.
	 *
	 * @param string $provider Name of provider.
	 * @param int    $response_code HTTP response code.
	 * @param string $response_body Raw HTTP response body.
	 * @return string Formatted error message.
	 */
	public static function format_api_error_message( $provider, $response_code, $response_body ) {
		$clean_msg = '';
		if ( ! empty( $response_body ) ) {
			$decoded = json_decode( $response_body, true );
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['detail']['message'] ) && is_string( $decoded['detail']['message'] ) ) {
					$clean_msg = $decoded['detail']['message'];
				} elseif ( isset( $decoded['detail']['status'] ) && is_string( $decoded['detail']['status'] ) ) {
					$clean_msg = $decoded['detail']['status'] . ( ! empty( $decoded['detail']['message'] ) && is_string( $decoded['detail']['message'] ) ? ': ' . $decoded['detail']['message'] : '' );
				} elseif ( isset( $decoded['detail'] ) ) {
					$clean_msg = is_string( $decoded['detail'] ) ? $decoded['detail'] : wp_json_encode( $decoded['detail'] );
				} elseif ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
					$clean_msg = $decoded['error']['message'];
				} elseif ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
					$clean_msg = $decoded['error'];
				} elseif ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
					$clean_msg = $decoded['message'];
				}
			}
		}

		if ( empty( $clean_msg ) ) {
			$clean_msg = ! empty( $response_body ) ? wp_strip_all_tags( $response_body ) : __( 'API request failed.', 'cubixsol-ai-voice-chat-assistant' );
		}

		return sprintf( '%s Error (%d): %s', $provider, $response_code, $clean_msg );
	}

	/**
	 * Generate 768-dimension vector embedding for text using Gemini provider.
	 *
	 * @param string $text         Input text block to embed.
	 * @param string $task_type    RETRIEVAL_DOCUMENT (indexing) or RETRIEVAL_QUERY (visitor question).
	 * @param int    $max_attempts Retry attempts on rate limits.
	 * @return array|WP_Error Embedding values array or WP_Error.
	 */
	public static function get_embedding( $text, $task_type = 'RETRIEVAL_DOCUMENT', $max_attempts = 3 ) {
		$gemini = AIVA_Provider_Registry::get_instance()->get_provider( 'gemini' );
		if ( $gemini && method_exists( $gemini, 'generate_embedding' ) ) {
			return $gemini->generate_embedding( $text, $task_type, $max_attempts );
		}
		return new WP_Error( 'missing_gemini_provider', __( 'Gemini provider is not available.', 'cubixsol-ai-voice-chat-assistant' ) );
	}

	/**
	 * Generate a conversational answer using the active AI Engine Provider.
	 *
	 * @param string $prompt User query text.
	 * @param array  $context_chunks Website database text chunks.
	 * @param string $system_instruction System instruction prompt.
	 * @param array  $history Previous conversation turns.
	 * @return string|WP_Error Answer string or WP_Error.
	 */
	public static function generate_chat_response( $prompt, $context_chunks, $system_instruction = '', $history = array() ) {
		$active_provider = AIVA_Provider_Registry::get_instance()->get_active_provider();
		return $active_provider->generate_chat_response( $prompt, $context_chunks, $system_instruction, $history );
	}

	/**
	 * Direct wrapper for Gemini chat response.
	 */
	public static function generate_gemini_chat_response( $prompt, $context_chunks, $system_instruction = '' ) {
		$gemini = AIVA_Provider_Registry::get_instance()->get_provider( 'gemini' );
		return $gemini->generate_chat_response( $prompt, $context_chunks, $system_instruction );
	}

	/**
	 * Direct wrapper for OpenAI chat response.
	 */
	public static function generate_openai_chat_response( $prompt, $context_chunks, $system_instruction = '' ) {
		$openai = AIVA_Provider_Registry::get_instance()->get_provider( 'openai' );
		return $openai->generate_chat_response( $prompt, $context_chunks, $system_instruction );
	}

	/**
	 * Direct wrapper for Anthropic chat response.
	 */
	public static function generate_anthropic_chat_response( $prompt, $context_chunks, $system_instruction = '' ) {
		$anthropic = AIVA_Provider_Registry::get_instance()->get_provider( 'anthropic' );
		return $anthropic->generate_chat_response( $prompt, $context_chunks, $system_instruction );
	}

	/**
	 * Generate TTS Audio URL using ElevenLabs Provider.
	 *
	 * @param string $text Text to speak.
	 * @return string|WP_Error Audio URL or WP_Error.
	 */
	public static function get_elevenlabs_tts_url( $text ) {
		$elevenlabs = AIVA_Provider_Registry::get_instance()->get_provider( 'elevenlabs' );
		if ( $elevenlabs && method_exists( $elevenlabs, 'generate_tts_audio' ) ) {
			return $elevenlabs->generate_tts_audio( $text );
		}
		return new WP_Error( 'missing_elevenlabs_provider', __( 'ElevenLabs provider is not available.', 'cubixsol-ai-voice-chat-assistant' ) );
	}

	/**
	 * Test connection to Gemini API.
	 */
	public static function test_gemini_connection( $key ) {
		$gemini = AIVA_Provider_Registry::get_instance()->get_provider( 'gemini' );
		return $gemini->test_connection( $key );
	}

	/**
	 * Test connection to OpenAI API.
	 */
	public static function test_openai_connection( $key ) {
		$openai = AIVA_Provider_Registry::get_instance()->get_provider( 'openai' );
		return $openai->test_connection( $key );
	}

	/**
	 * Test connection to Anthropic Claude API.
	 */
	public static function test_anthropic_connection( $key ) {
		$anthropic = AIVA_Provider_Registry::get_instance()->get_provider( 'anthropic' );
		return $anthropic->test_connection( $key );
	}

	/**
	 * Test connection to ElevenLabs API.
	 */
	public static function test_elevenlabs_connection( $key ) {
		$elevenlabs = AIVA_Provider_Registry::get_instance()->get_provider( 'elevenlabs' );
		return $elevenlabs->test_connection( $key );
	}

	/**
	 * Generate ElevenLabs sample audio with specified key and voice ID.
	 *
	 * @param string $key ElevenLabs API key.
	 * @param string $voice_id ElevenLabs Voice ID.
	 * @param string $text Text sample.
	 * @return string|WP_Error Audio data URI or WP_Error.
	 */
	public static function generate_elevenlabs_sample( $key, $voice_id, $text ) {
		$elevenlabs = AIVA_Provider_Registry::get_instance()->get_provider( 'elevenlabs' );
		if ( $elevenlabs && method_exists( $elevenlabs, 'generate_sample' ) ) {
			return $elevenlabs->generate_sample( $key, $voice_id, $text );
		}
		return new WP_Error( 'missing_elevenlabs_provider', __( 'ElevenLabs provider is not available.', 'cubixsol-ai-voice-chat-assistant' ) );
	}

	/**
	 * Clear cached ElevenLabs audio files.
	 *
	 * @return bool
	 */
	public static function clear_audio_cache() {
		$elevenlabs = AIVA_Provider_Registry::get_instance()->get_provider( 'elevenlabs' );
		if ( $elevenlabs && method_exists( $elevenlabs, 'clear_audio_cache' ) ) {
			return $elevenlabs->clear_audio_cache();
		}
		return true;
	}

	/**
	 * Remove old cached ElevenLabs audio files.
	 *
	 * @return void
	 */
	public static function prune_audio_cache() {
		$elevenlabs = AIVA_Provider_Registry::get_instance()->get_provider( 'elevenlabs' );
		if ( $elevenlabs && method_exists( $elevenlabs, 'prune_cache' ) ) {
			$elevenlabs->prune_cache();
		}
	}
}
