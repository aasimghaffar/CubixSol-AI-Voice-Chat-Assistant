<?php
/**
 * Provider Registry for Managing AI & TTS Engine Providers.
 *
 * @package Shopwalker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIVA_Provider_Registry {

	/**
	 * Singleton instance.
	 *
	 * @var AIVA_Provider_Registry
	 */
	private static $instance = null;

	/**
	 * Array of registered provider objects.
	 *
	 * @var AIVA_Provider_Base[]
	 */
	private $providers = array();

	/**
	 * Get singleton instance.
	 *
	 * @return AIVA_Provider_Registry
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — Loads provider files and registers instances.
	 */
	private function __construct() {
		$this->load_providers();
	}

	/**
	 * Include provider files and instantiate classes.
	 */
	private function load_providers() {
		require_once AIVA_PATH . 'includes/providers/class-provider-base.php';
		require_once AIVA_PATH . 'includes/providers/class-gemini-provider.php';
		require_once AIVA_PATH . 'includes/providers/class-openai-provider.php';
		require_once AIVA_PATH . 'includes/providers/class-anthropic-provider.php';
		require_once AIVA_PATH . 'includes/providers/class-elevenlabs-provider.php';

		$this->register_provider( new AIVA_Gemini_Provider() );
		$this->register_provider( new AIVA_OpenAI_Provider() );
		$this->register_provider( new AIVA_Anthropic_Provider() );
		$this->register_provider( new AIVA_ElevenLabs_Provider() );
	}

	/**
	 * Register a provider instance.
	 *
	 * @param AIVA_Provider_Base $provider Provider instance.
	 */
	public function register_provider( AIVA_Provider_Base $provider ) {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Get a specific provider instance by ID.
	 *
	 * @param string $id Provider ID (gemini, openai, anthropic, elevenlabs).
	 * @return AIVA_Provider_Base|null
	 */
	public function get_provider( $id ) {
		if ( isset( $this->providers[ $id ] ) ) {
			return $this->providers[ $id ];
		}
		return null;
	}

	/**
	 * Get currently selected active LLM provider.
	 *
	 * @return AIVA_Provider_Base
	 */
	public function get_active_provider() {
		$active_engine = get_option( 'aiva_active_llm_engine', 'gemini' );
		// Only chat engines can be active; ElevenLabs is a voice (TTS) provider.
		if ( in_array( $active_engine, array( 'gemini', 'openai', 'anthropic' ), true ) && isset( $this->providers[ $active_engine ] ) ) {
			return $this->providers[ $active_engine ];
		}
		return $this->providers['gemini'];
	}

	/**
	 * Get all registered providers.
	 *
	 * @return AIVA_Provider_Base[]
	 */
	public function get_all_providers() {
		return $this->providers;
	}
}
