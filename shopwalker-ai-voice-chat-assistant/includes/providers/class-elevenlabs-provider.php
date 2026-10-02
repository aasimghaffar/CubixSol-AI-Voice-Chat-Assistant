<?php
/**
 * ElevenLabs Voice TTS Provider Implementation.
 *
 * @package Shopwalker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIVA_ElevenLabs_Provider extends AIVA_Provider_Base {

	protected $id   = 'elevenlabs';
	protected $name = 'ElevenLabs Text-to-Speech';

	/**
	 * Get active ElevenLabs model ID from options or fallback to recommended default.
	 *
	 * @return string Model ID.
	 */
	public function get_effective_model() {
		$model_opt = get_option( 'aiva_elevenlabs_model', 'eleven_flash_v2_5' );
		if ( 'custom' === $model_opt ) {
			$model_opt = get_option( 'aiva_elevenlabs_custom_model', 'eleven_flash_v2_5' );
		}
		return ! empty( $model_opt ) ? trim( $model_opt ) : 'eleven_flash_v2_5';
	}

	/**
	 * Max number of cached MP3 files kept on disk.
	 */
	const MAX_CACHE_FILES = 300;

	/**
	 * Max age of cached MP3 files in days.
	 */
	const MAX_CACHE_DAYS = 30;

	/**
	 * Get the cache directory path and URL.
	 *
	 * @return array|WP_Error array( 'dir' => ..., 'url' => ... ) or error.
	 */
	private function get_cache_location() {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'upload_dir_error', $upload_dir['error'] );
		}
		return array(
			'dir' => trailingslashit( $upload_dir['basedir'] ) . 'aiva-cache/',
			'url' => trailingslashit( $upload_dir['baseurl'] ) . 'aiva-cache/',
		);
	}

	/**
	 * Get an initialised WP_Filesystem instance (direct method only), or null.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private function get_filesystem() {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method() ) {
			return null;
		}
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return null;
		}
		return $wp_filesystem;
	}

	/**
	 * Unused for chat responses (ElevenLabs is a TTS voice provider).
	 */
	public function generate_chat_response( $prompt, $context_chunks, $system_instruction = '', $history = array() ) {
		return new WP_Error( 'not_chat_provider', __( 'ElevenLabs is a Text-to-Speech provider.', 'shopwalker-ai-voice-chat-assistant' ) );
	}

	/**
	 * Generate audio file URL or base64 data URI for given text using ElevenLabs TTS.
	 *
	 * @param string $text Text to convert to spoken audio.
	 * @param string $custom_api_key Optional custom API key.
	 * @param string $custom_voice_id Optional custom Voice ID.
	 * @return string|WP_Error Audio file URL (or data URI fallback) or WP_Error.
	 */
	public function generate_tts_audio( $text, $custom_api_key = '', $custom_voice_id = '' ) {
		$api_key  = ! empty( $custom_api_key ) ? $custom_api_key : get_option( 'aiva_elevenlabs_api_key', '' );
		$voice_id = ! empty( $custom_voice_id ) ? $custom_voice_id : get_option( 'aiva_elevenlabs_voice_id', '21m00Tcm4TlvDq8ikWAM' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_elevenlabs_key', __( 'ElevenLabs API Key is not configured.', 'shopwalker-ai-voice-chat-assistant' ) );
		}

		$clean_text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$clean_text = str_replace( '*', '', $clean_text );
		$clean_text = trim( $clean_text );

		if ( '' === $clean_text ) {
			return new WP_Error( 'empty_text', __( 'TTS text input is empty.', 'shopwalker-ai-voice-chat-assistant' ) );
		}

		$location = $this->get_cache_location();
		$fs       = is_wp_error( $location ) ? null : $this->get_filesystem();

		$hash      = md5( $clean_text . '_' . $voice_id . '_' . $this->get_effective_model() );
		$file_name = $hash . '.mp3';

		// Serve from cache when this exact sentence/voice/model was generated before.
		if ( $fs && $fs->exists( $location['dir'] . $file_name ) && $fs->size( $location['dir'] . $file_name ) > 1024 ) {
			return set_url_scheme( $location['url'] . $file_name );
		}

		$url  = 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode( $voice_id );
		$body = array(
			'text'           => $clean_text,
			'model_id'       => $this->get_effective_model(),
			'voice_settings' => array(
				'stability'        => 0.5,
				'similarity_boost' => 0.75,
			),
		);

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'xi-api-key'   => $api_key,
					'Accept'       => 'audio/mpeg',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 25,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code  = wp_remote_retrieve_response_code( $response );
		$audio = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error( 'elevenlabs_error', $this->format_error( 'ElevenLabs API', $code, $audio ) );
		}

		// Save to the uploads cache and return a normal file URL (much lighter than a base64 payload).
		if ( $fs ) {
			if ( ! $fs->is_dir( $location['dir'] ) ) {
				wp_mkdir_p( $location['dir'] );
				$fs->put_contents( $location['dir'] . 'index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
			}
			if ( $fs->put_contents( $location['dir'] . $file_name, $audio, FS_CHMOD_FILE ) ) {
				$this->prune_cache( self::MAX_CACHE_FILES );
				return set_url_scheme( $location['url'] . $file_name );
			}
		}

		// Fallback when the filesystem is not writable directly: inline data URI.
		return 'data:audio/mpeg;base64,' . base64_encode( $audio ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes binary audio for a data: URI, not code obfuscation.
	}

	/**
	 * Generate ElevenLabs voice sample using specified API key and voice ID.
	 *
	 * @param string $key ElevenLabs API Key.
	 * @param string $voice_id ElevenLabs Voice ID.
	 * @param string $text Sample text to speak.
	 * @return string|WP_Error Base64 data URI or WP_Error.
	 */
	public function generate_sample( $key, $voice_id, $text ) {
		return $this->generate_tts_audio( $text, $key, $voice_id );
	}

	/**
	 * Clear all cached ElevenLabs audio MP3 files in the aiva-cache upload directory.
	 *
	 * @return bool True on success.
	 */
	public function clear_audio_cache() {
		$location = $this->get_cache_location();
		if ( is_wp_error( $location ) ) {
			return true;
		}
		$files = glob( $location['dir'] . '*.mp3' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				wp_delete_file( $file );
			}
		}
		return true;
	}

	/**
	 * Delete cached files older than MAX_CACHE_DAYS and keep at most $max_files newest files.
	 *
	 * @param int $max_files Max files to keep.
	 * @return void
	 */
	public function prune_cache( $max_files = self::MAX_CACHE_FILES ) {
		$location = $this->get_cache_location();
		if ( is_wp_error( $location ) ) {
			return;
		}
		$files = glob( $location['dir'] . '*.mp3' );
		if ( ! is_array( $files ) || empty( $files ) ) {
			return;
		}

		$cutoff = time() - ( self::MAX_CACHE_DAYS * DAY_IN_SECONDS );
		$keep   = array();
		foreach ( $files as $file ) {
			$mtime = (int) filemtime( $file );
			if ( $mtime < $cutoff ) {
				wp_delete_file( $file );
			} else {
				$keep[ $file ] = $mtime;
			}
		}

		if ( count( $keep ) > $max_files ) {
			arsort( $keep );
			foreach ( array_slice( array_keys( $keep ), $max_files ) as $file ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Test connection to ElevenLabs API by querying voices list.
	 *
	 * @param string $api_key API key to test.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function test_connection( $api_key ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'empty_key', __( 'ElevenLabs API Key cannot be empty.', 'shopwalker-ai-voice-chat-assistant' ) );
		}

		$url      = 'https://api.elevenlabs.io/v1/voices';
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'xi-api-key' => $api_key,
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

		return new WP_Error( 'elevenlabs_test_failed', $this->format_error( 'ElevenLabs API Test', $code, $body ) );
	}
}
