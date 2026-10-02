<?php
/**
 * Admin pages, settings, meta box and notices.
 *
 * @package Shopwalker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin table; the table name is built from $wpdb->prefix and a constant string.

/**
 * Admin controller.
 */
class AIVA_Admin {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'display_review_notice' ) );
		add_action( 'wp_ajax_aiva_dismiss_review_notice', array( $this, 'ajax_dismiss_review_notice' ) );
		add_action( 'wp_ajax_aiva_index_single_post', array( $this, 'ajax_index_single_post' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_indexing_meta_box' ) );
		add_action( 'save_post', array( $this, 'auto_index_on_post_save' ), 20, 2 );
		add_action( 'update_option_aiva_included_types', array( 'AIVA_Scanner', 'purge_disabled_chunks' ) );
		add_action( 'add_option_aiva_included_types', array( 'AIVA_Scanner', 'purge_disabled_chunks' ) );
		add_action( 'update_option_aiva_privacy_include_menus', array( 'AIVA_Scanner', 'purge_disabled_chunks' ) );
		add_action( 'update_option_aiva_privacy_include_contact', array( 'AIVA_Scanner', 'purge_disabled_chunks' ) );
		add_action( 'update_option_aiva_privacy_include_faqs', array( 'AIVA_Scanner', 'purge_disabled_chunks' ) );
		add_action( 'update_option_aiva_privacy_include_policies', array( 'AIVA_Scanner', 'purge_disabled_chunks' ) );
	}

	/**
	 * Register admin menu pages.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		// Register top-level parent menu
		add_menu_page(
			__( 'Shopwalker', 'shopwalker-ai-voice-chat-assistant' ),
			__( 'Shopwalker', 'shopwalker-ai-voice-chat-assistant' ),
			'manage_options',
			'aiva-dashboard',
			array( $this, 'render_dashboard_page' ),
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjEuNSAxLjUgMjEgMjEiIGZpbGw9IiNmZmZmZmYiPjxwYXRoIGQ9Ik0xMiAxNGMxLjY2IDAgMy0xLjM0IDMtM1Y1YzAtMS42Ni0xLjM0LTMtMy0zUzkgMy4zNCA5IDV2NmMwIDEuNjYgMS4zNCAzIDMgM3ptNS4zLTNjMCAzLTIuNTQgNS4xLTUuMyA1LjFTNi43IDE0IDYuNyAxMUg1YzAgMy40MSAyLjcyIDYuMjMgNiA2LjcyVjIxaDJ2LTMuMjhjMy4yOC0uNDkgNi0zLjMxIDYtNi43MmgtMS43eiIvPjwvc3ZnPg==',
			58
		);

		// Add submenu for Dashboard - same slug as parent to make it default
		add_submenu_page(
			'aiva-dashboard',
			__( 'Dashboard', 'shopwalker-ai-voice-chat-assistant' ),
			__( 'Dashboard', 'shopwalker-ai-voice-chat-assistant' ),
			'manage_options',
			'aiva-dashboard',
			array( $this, 'render_dashboard_page' )
		);

		// Add submenu for Settings
		add_submenu_page(
			'aiva-dashboard',
			__( 'Settings', 'shopwalker-ai-voice-chat-assistant' ),
			__( 'Settings', 'shopwalker-ai-voice-chat-assistant' ),
			'manage_options',
			'aiva-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue admin assets on plugin pages and edit screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		// Load on plugin pages and post edit screens
		if ( false === strpos( $hook, 'aiva-settings' ) && false === strpos( $hook, 'aiva-dashboard' ) && 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		// Fonts are bundled locally (no third-party font requests).
		wp_enqueue_style( 'aiva-fonts', AIVA_URL . 'assets/fonts/fonts.css', array(), AIVA_VERSION );
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'aiva-admin-css', AIVA_URL . 'admin/css/admin-style.css', array( 'dashicons', 'aiva-fonts' ), AIVA_VERSION );
		wp_enqueue_script( 'aiva-admin-js', AIVA_URL . 'admin/js/admin-script.js', array( 'jquery' ), AIVA_VERSION, true );

		// Localize script for AJAX scanning.
		wp_localize_script(
			'aiva-admin-js',
			'aivaAdmin',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'aiva_admin_nonce' ),
			)
		);
	}

	/**
	 * Settings and their sanitize callbacks.
	 *
	 * @return array option_name => callable
	 */
	private function get_settings_schema() {
		$text     = array( $this, 'sanitize_text' );
		$textarea = array( $this, 'sanitize_textarea' );
		$model    = array( $this, 'sanitize_model_id' );
		$toggle   = array( $this, 'sanitize_toggle' );

		return array(
			'aiva_active_llm_engine'             => array( $this, 'sanitize_engine' ),
			'aiva_gemini_api_key'                => array( $this, 'sanitize_api_key' ),
			'aiva_gemini_model'                  => $model,
			'aiva_gemini_custom_model'           => $model,
			'aiva_openai_api_key'                => array( $this, 'sanitize_api_key' ),
			'aiva_openai_model'                  => $model,
			'aiva_openai_custom_model'           => $model,
			'aiva_anthropic_api_key'             => array( $this, 'sanitize_api_key' ),
			'aiva_anthropic_model'               => $model,
			'aiva_anthropic_custom_model'        => $model,
			'aiva_tts_engine'                    => array( $this, 'sanitize_tts_engine' ),
			'aiva_elevenlabs_api_key'            => array( $this, 'sanitize_api_key' ),
			'aiva_elevenlabs_voice_id'           => $model,
			'aiva_elevenlabs_model'              => $model,
			'aiva_elevenlabs_custom_model'       => $model,
			'aiva_included_types'                => array( $this, 'sanitize_post_types' ),
			'aiva_excluded_urls'                 => $textarea,
			'aiva_business_name'                 => $text,
			'aiva_business_desc'                 => $textarea,
			'aiva_business_hours'                => $textarea,
			'aiva_business_contact'              => $textarea,
			'aiva_business_phone'                => $text,
			'aiva_business_email'                => 'sanitize_email',
			'aiva_business_address'              => $textarea,
			'aiva_business_instructions'         => $textarea,
			'aiva_business_services'             => $textarea,
			'aiva_business_faqs'                 => $textarea,
			'aiva_privacy_include_faqs'          => $toggle,
			'aiva_privacy_include_contact'       => $toggle,
			'aiva_privacy_include_policies'      => $toggle,
			'aiva_privacy_include_menus'         => $toggle,
			'aiva_widget_enable'                 => $toggle,
			'aiva_widget_trigger_style'          => array( $this, 'sanitize_trigger_style' ),
			'aiva_widget_position'               => array( $this, 'sanitize_position' ),
			'aiva_widget_cta_text'               => $text,
			'aiva_widget_title_text'             => $text,
			'aiva_widget_greeting_text'          => 'wp_kses_post',
			'aiva_widget_languages'              => array( 'AIVA_Languages', 'sanitize_codes' ),
			'aiva_widget_default_language'       => array( 'AIVA_Languages', 'sanitize_code' ),
			'aiva_widget_show_language_selector' => $toggle,
			'aiva_widget_auto_detect_language'   => $toggle,
			'aiva_quick_replies_enable'          => $toggle,
			'aiva_quick_replies'                 => array( $this, 'sanitize_quick_replies' ),
			'aiva_quick_replies_auto_categories' => $toggle,
			'aiva_widget_display_mode'           => array( $this, 'sanitize_display_mode' ),
			'aiva_widget_target_post_types'      => array( $this, 'sanitize_widget_targets' ),
			'aiva_widget_excluded_slugs'         => $textarea,
			'aiva_active_tab'                    => array( $this, 'sanitize_active_tab' ),
		);
	}

	/**
	 * Register settings in the WordPress database options, each with a sanitize callback.
	 *
	 * @return void
	 */
	public function register_settings() {
		foreach ( $this->get_settings_schema() as $option => $callback ) {
			register_setting(
				'aiva_options_group',
				$option,
				array(
					'sanitize_callback' => $callback,
					'show_in_rest'      => false,
				)
			);
		}
	}

	/**
	 * Sanitize a single-line text value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_text( $value ) {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Sanitize a multi-line text value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_textarea( $value ) {
		return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
	}

	/**
	 * Sanitize the quick reply lines (max 8 lines, "Label | Message").
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_quick_replies( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$lines = preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( (string) $value ) );
		$lines = array_filter( array_map( 'trim', $lines ), 'strlen' );
		return implode( "\n", array_slice( $lines, 0, 8 ) );
	}

	/**
	 * Sanitize an API key (no spaces or control characters).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_api_key( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return preg_replace( '/[^A-Za-z0-9_\-\.:]/', '', trim( (string) $value ) );
	}

	/**
	 * Sanitize a model / voice ID.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_model_id( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return substr( preg_replace( '/[^A-Za-z0-9_\-\.:\/@]/', '', trim( (string) $value ) ), 0, 120 );
	}

	/**
	 * Sanitize an on/off toggle to "1" or "0".
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_toggle( $value ) {
		return ( '1' === (string) $value || 'on' === $value || true === $value ) ? '1' : '0';
	}

	/**
	 * Pick a value from an allowed list.
	 *
	 * @param mixed  $value   Raw value.
	 * @param array  $allowed Allowed values.
	 * @param string $fallback Value used when the input is not allowed.
	 * @return string
	 */
	private function sanitize_choice( $value, $allowed, $fallback ) {
		$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Sanitize the active chat engine.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_engine( $value ) {
		return $this->sanitize_choice( $value, array( 'gemini', 'openai', 'anthropic' ), 'gemini' );
	}

	/**
	 * Sanitize the TTS engine.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_tts_engine( $value ) {
		return $this->sanitize_choice( $value, array( 'browser', 'elevenlabs' ), 'browser' );
	}

	/**
	 * Sanitize the launcher style.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_trigger_style( $value ) {
		return $this->sanitize_choice( $value, array( 'circle', 'pill', 'badge' ), 'circle' );
	}

	/**
	 * Sanitize the widget position.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_position( $value ) {
		return $this->sanitize_choice( $value, array( 'bottom-right', 'bottom-left' ), 'bottom-right' );
	}

	/**
	 * Sanitize the widget display mode.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_display_mode( $value ) {
		return $this->sanitize_choice( $value, array( 'all', 'include', 'exclude' ), 'all' );
	}

	/**
	 * Sanitize the remembered settings tab.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_active_tab( $value ) {
		return $this->sanitize_choice( $value, array( 'profile', 'api', 'content', 'widget' ), 'profile' );
	}

	/**
	 * Sanitize a list of post type keys (only registered post types are kept).
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public function sanitize_post_types( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$registered = get_post_types();
		$clean      = array();
		foreach ( $value as $type ) {
			$type = sanitize_key( (string) $type );
			if ( isset( $registered[ $type ] ) ) {
				$clean[] = $type;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitize the widget target list (post types plus the special "home" value and theme listing aliases).
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public function sanitize_widget_targets( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$registered = get_post_types();
		$specials   = array( 'home', 'listing', 'estate_property' );
		$clean      = array();
		foreach ( $value as $type ) {
			$type = sanitize_key( (string) $type );
			if ( isset( $registered[ $type ] ) || in_array( $type, $specials, true ) ) {
				$clean[] = $type;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Suggested privacy policy text (Settings > Privacy).
	 *
	 * @return void
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . esc_html__( 'This site uses an AI voice and chat assistant. When you ask it a question, the text of your question (or the transcript of your voice question), recent messages from the same conversation and relevant content from this website are sent to the AI service configured by the site owner (Google Gemini, OpenAI or Anthropic) to generate an answer. If premium voice is enabled, the answer text is sent to ElevenLabs to create audio. Voice recognition runs in your browser using its built-in speech service. The plugin does not store your conversations on this website.', 'shopwalker-ai-voice-chat-assistant' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Shopwalker – AI Voice & Chat Assistant', 'shopwalker-ai-voice-chat-assistant' ), wp_kses_post( $content ) );
	}

	// Get count of currently scanned text chunks from DB.
	private function get_total_chunks() {
		global $wpdb;
		$table_name = esc_sql( $wpdb->prefix . 'aiva_knowledge_base' );

		// Ensure table exists.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
	}

	/**
	 * Render the main AI Studio (Dashboard) Page
	 */
	public function render_dashboard_page() {
		global $wp_version;
		$total_chunks = $this->get_total_chunks();

		$active_engine = get_option( 'aiva_active_llm_engine', 'gemini' );

		$gemini_provider    = AIVA_Provider_Registry::get_instance()->get_provider( 'gemini' );
		$openai_provider    = AIVA_Provider_Registry::get_instance()->get_provider( 'openai' );
		$anthropic_provider = AIVA_Provider_Registry::get_instance()->get_provider( 'anthropic' );

		$gemini_eff_model    = $gemini_provider ? $gemini_provider->get_effective_model() : get_option( 'aiva_gemini_model', 'gemini-3.5-flash' );
		$openai_eff_model    = $openai_provider ? $openai_provider->get_effective_model() : get_option( 'aiva_openai_model', 'gpt-4o-mini' );
		$anthropic_eff_model = $anthropic_provider ? $anthropic_provider->get_effective_model() : get_option( 'aiva_anthropic_model', 'claude-haiku-4-5-20251001' );

		if ( 'openai' === $active_engine ) {
			$api_key     = get_option( 'aiva_openai_api_key', '' );
			$engine_name = 'OpenAI ' . $openai_eff_model;
			$api_label   = __( 'OpenAI API status', 'shopwalker-ai-voice-chat-assistant' );
		} elseif ( 'anthropic' === $active_engine ) {
			$api_key     = get_option( 'aiva_anthropic_api_key', '' );
			$engine_name = 'Anthropic Claude ' . $anthropic_eff_model;
			$api_label   = __( 'Anthropic API status', 'shopwalker-ai-voice-chat-assistant' );
		} else {
			$api_key     = get_option( 'aiva_gemini_api_key', '' );
			$engine_name = 'Google Gemini ' . $gemini_eff_model;
			$api_label   = __( 'Google Gemini API status', 'shopwalker-ai-voice-chat-assistant' );
		}

		$api_status         = ! empty( $api_key ) ? 'Connected' : 'Not configured';
		$api_status_class   = ! empty( $api_key ) ? 'online' : 'offline';
		$engine_status_text = ! empty( $api_key ) ? $engine_name . ' &bull; Ready' : $engine_name . ' &bull; Not Configured';
		$engine_dot_class   = ! empty( $api_key ) ? 'aiva-status-dot-green' : 'aiva-status-dot-orange';
		?>
		<div class="wrap aiva-admin-wrap">
			<h1 class="wp-heading-inline" style="display:none;"></h1>
			<div class="aiva-notices-container"></div>
			<!-- Hero Header -->
			<div class="aiva-header">
				<div class="aiva-header-left">
					<h1><span class="aiva-header-icon-box"><img src="<?php echo esc_url( AIVA_URL . 'admin/images/ai-voice-agent-icon.png' ); ?>" alt="Shopwalker" class="aiva-header-custom-icon" /></span> <?php esc_html_e( 'Shopwalker', 'shopwalker-ai-voice-chat-assistant' ); ?></h1>
					<p class="description"><?php esc_html_e( 'Build RAG knowledge base indexes, execute website scans, and monitor AI engine status.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
				</div>
				<div class="aiva-header-right">
					<span class="aiva-badge-version"><?php echo esc_html( 'v' . AIVA_VERSION ); ?></span>
					<span class="aiva-badge-engine"><span class="<?php echo esc_attr( $engine_dot_class ); ?>"></span> <?php echo wp_kses_post( $engine_status_text ); ?></span>
					<a href="<?php echo esc_url( home_url() ); ?>" target="_blank" class="button aiva-btn-preview"><span class="dashicons dashicons-welcome-view-site"></span> <?php esc_html_e( 'Open Voice Widget', 'shopwalker-ai-voice-chat-assistant' ); ?></a>
				</div>
			</div>

			<!-- Stats Grid -->
			<div class="aiva-dashboard-grid">
				<!-- Card 1: Knowledge Chunks -->
				<div class="aiva-stat-card">
					<div class="aiva-stat-header">
						<span class="aiva-stat-title"><?php esc_html_e( 'Knowledge Chunks', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
						<span class="dashicons dashicons-database aiva-stat-icon"></span>
					</div>
					<div class="aiva-stat-body">
						<span class="aiva-stat-value" id="aiva-chunks-count"><?php echo esc_html( $total_chunks ); ?></span>
						<span class="aiva-stat-meta"><?php esc_html_e( 'Scanned data fragments in DB', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
					</div>
				</div>

				<!-- Card 2: Scanner Status -->
				<div class="aiva-stat-card">
					<div class="aiva-stat-header">
						<span class="aiva-stat-title"><?php esc_html_e( 'Scanner Status', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
						<span class="dashicons dashicons-performance aiva-stat-icon"></span>
					</div>
					<div class="aiva-stat-body">
						<span class="aiva-stat-value text-green" id="aiva-scanner-status-val"><?php esc_html_e( 'Ready', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
						<span class="aiva-stat-meta"><?php esc_html_e( 'Scanner is idle and ready', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
					</div>
				</div>

				<!-- Card 3: API Connection -->
				<div class="aiva-stat-card">
					<div class="aiva-stat-header">
						<span class="aiva-stat-title"><?php esc_html_e( 'API Connection', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
						<span class="dashicons dashicons-cloud aiva-stat-icon"></span>
					</div>
					<div class="aiva-stat-body">
						<span class="aiva-stat-value"><span class="aiva-status-dot <?php echo esc_attr( $api_status_class ); ?>"></span> <?php echo esc_html( $api_status ); ?></span>
						<span class="aiva-stat-meta"><?php echo esc_html( $api_label ); ?></span>
					</div>
				</div>

				<!-- Card 4: Active LLM Engine -->
				<div class="aiva-stat-card">
					<div class="aiva-stat-header">
						<span class="aiva-stat-title"><?php esc_html_e( 'Active LLM Engine', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
						<span class="dashicons dashicons-admin-plugins aiva-stat-icon"></span>
					</div>
					<div class="aiva-stat-body">
						<span class="aiva-stat-value text-indigo"><?php echo esc_html( $engine_name ); ?></span>
						<span class="aiva-stat-meta"><?php esc_html_e( 'Conversational agent model', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Main Panel Split -->
			<div class="aiva-panel-row">
				<!-- Left: Knowledge base scanner -->
				<div class="aiva-panel-col-left">
					<div class="card">
						<h2><?php esc_html_e( 'AI Knowledge Base Scanner', 'shopwalker-ai-voice-chat-assistant' ); ?></h2>
						<?php
						$active_engine_name = 'Google Gemini';
						$active_engine      = get_option( 'aiva_active_llm_engine', 'gemini' );
						if ( 'openai' === $active_engine ) {
							$active_engine_name = 'OpenAI';
						} elseif ( 'anthropic' === $active_engine ) {
							$active_engine_name = 'Anthropic Claude';
						}
						?>
						<p>
						<?php
						printf(
							/* translators: %s: name of the active AI engine. */
							esc_html__( 'This scans the selected WordPress custom post types, WooCommerce products, menus, and business info, generates vector embeddings with the Google Gemini embedding API (a Gemini key is always required for indexing), and updates your local RAG search index. Answers are then written by %s.', 'shopwalker-ai-voice-chat-assistant' ),
							esc_html( $active_engine_name )
						);
						?>
						</p>
						
						<div class="aiva-scanner-btn-grid">
							<div class="aiva-btn-row">
								<button type="button" id="aiva-start-scan" class="button button-primary button-large aiva-grid-btn"><svg class="aiva-btn-svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg> <?php esc_html_e( 'Scan & Index Website', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
								<button type="button" id="aiva-clear-index" class="button button-secondary button-large aiva-grid-btn aiva-btn-wipe"><svg class="aiva-btn-svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a2 2 0 00-2 2v1H2a1 1 0 100 2h1v10a2 2 0 002 2h10a2 2 0 002-2V7h1a1 1 0 100-2h-2V4a2 2 0 00-2-2H6zm2 3a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 10-2 0v5a1 1 0 102 0v-5zm4 0a1 1 0 10-2 0v5a1 1 0 102 0v-5z" clip-rule="evenodd"/></svg> <?php esc_html_e( 'Wipe Index', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
							</div>
							<div class="aiva-btn-row">
								<button type="button" id="aiva-open-data-inspector" class="button button-secondary button-large aiva-grid-btn"><svg class="aiva-btn-svg" viewBox="0 0 20 20" fill="currentColor"><path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z"/><path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z"/><path d="M10 2C6.134 2 3 3.343 3 5s3.134 3 7 3 7-1.343 7-3-3.134-3-7-3z"/></svg> <?php esc_html_e( 'Inspect Database Data', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
								<button type="button" id="aiva-open-scan-modal" class="button button-secondary button-large aiva-grid-btn" style="display: none;"><svg class="aiva-btn-svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a2 2 0 012-2h10a2 2 0 012 2v8a2 2 0 01-2 2h-4v1a1 1 0 011 1H7a1 1 0 111-1v-1H5a2 2 0 01-2-2V5zm2 0h10v8H5V5z" clip-rule="evenodd"/></svg> <?php esc_html_e( 'Open Live Monitor', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
							</div>
						</div>

						<!-- Progress indicator -->
						<div id="aiva-scan-progress-container" class="aiva-progress-container" style="display:none; margin-top: 25px;">
							<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
								<p id="aiva-scan-status-text" style="font-weight:600; margin: 0;"><?php esc_html_e( 'Initializing scanner...', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
								<button type="button" id="aiva-expand-modal-btn" class="button button-small"><svg class="aiva-btn-svg" style="width:13px;height:13px;margin-right:4px;vertical-align:-1.5px;" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M11 3h6v6M10 10l7-7M17 11v6a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h6"/></svg> <?php esc_html_e( 'Expand Terminal', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
							</div>
							<div class="aiva-progress-bar-wrapper">
								<div id="aiva-scan-progress-bar"></div>
							</div>
							<div id="aiva-scan-logs" class="aiva-scan-logs-box"></div>
						</div>
					</div>
				</div>

				<!-- Live Scan Terminal Monitor Modal -->
				<div id="aiva-scan-modal-overlay" class="aiva-modal-overlay" style="display: none;">
					<div class="aiva-scan-modal-window">
						<div class="aiva-modal-header">
							<div class="aiva-modal-title">
								<div class="aiva-modal-icon-box"><span class="dashicons dashicons-desktop"></span></div>
								<h3><?php esc_html_e( 'AI Knowledge Scanner — Live Monitor', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>
							</div>
							<div class="aiva-modal-controls">
								<button type="button" id="aiva-stop-scan-modal-btn" class="button button-secondary button-small aiva-modal-stop-btn" style="display: none;" title="Stop active scan"><svg class="aiva-btn-svg" style="width:13px;height:13px;margin-right:4px;vertical-align:-1px;" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg> <?php esc_html_e( 'Stop Scan', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
								<button type="button" id="aiva-copy-modal-logs" class="button button-secondary button-small" title="Copy logs to clipboard"><span class="dashicons dashicons-clipboard"></span> Copy Logs</button>
								<button type="button" id="aiva-minimize-modal" class="button button-secondary button-small" title="Minimize monitor"><span class="dashicons dashicons-minus"></span> Minimize</button>
								<button type="button" id="aiva-close-modal" class="button button-secondary button-small" title="Close modal"><span class="dashicons dashicons-no-alt"></span></button>
							</div>
						</div>
						<div class="aiva-modal-body">
							<!-- Metrics Grid -->
							<div class="aiva-modal-metrics-grid">
								<div class="aiva-modal-metric-card">
									<span class="metric-label"><?php esc_html_e( 'Total Items', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
									<span class="metric-val" id="aiva-modal-stat-total">0</span>
								</div>
								<div class="aiva-modal-metric-card">
									<span class="metric-label"><?php esc_html_e( 'Processed', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
									<span class="metric-val text-green" id="aiva-modal-stat-processed">0</span>
								</div>
								<div class="aiva-modal-metric-card">
									<span class="metric-label"><?php esc_html_e( 'Progress', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
									<span class="metric-val text-indigo" id="aiva-modal-stat-percent">0%</span>
								</div>
								<div class="aiva-modal-metric-card">
									<span class="metric-label"><?php esc_html_e( 'Elapsed Time', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
									<span class="metric-val" id="aiva-modal-stat-time">00:00</span>
								</div>
							</div>

							<!-- Progress bar -->
							<div class="aiva-modal-progress-wrapper" style="margin-bottom: 14px;">
								<div id="aiva-modal-progress-bar" class="aiva-modal-progress-bar" style="width: 0%;"></div>
							</div>

							<!-- Live Terminal Stream Header Box -->
							<div class="aiva-terminal-box-header" style="margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; gap: 12px;">
								<div style="display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1;">
									<span class="dashicons dashicons-editor-code" style="font-size: 16px; color: #2dd4bf; width: 16px; height: 16px; line-height: 16px; display: inline-block; flex-shrink: 0;"></span>
									<strong style="color: #f8fafc; font-size: 13px; font-weight: 600; white-space: nowrap; flex-shrink: 0;"><?php esc_html_e( 'Real-Time Indexing Console', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
									<span style="color: #475569; font-weight: 400; font-size: 12px; flex-shrink: 0;">|</span>
									<span id="aiva-modal-status-text" class="aiva-modal-status-text" style="font-size: 12px; color: #94a3b8; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 380px;"><?php esc_html_e( 'Initializing queue...', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
								</div>
								<span id="aiva-modal-live-badge" class="aiva-live-pulse-badge" style="flex-shrink: 0;"><span class="aiva-pulse-dot"></span> <?php esc_html_e( 'LIVE STREAM', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
							</div>
							<div id="aiva-modal-terminal-logs" class="aiva-modal-terminal-logs"></div>
						</div>
					</div>
				</div>

				<!-- Floating Docked Scan Bar (stuck at bottom of screen while scanning) -->
				<div id="aiva-floating-scan-bar" class="aiva-floating-scan-bar" style="display: none;">
					<div class="aiva-float-content">
						<div class="aiva-float-pulse"></div>
						<div class="aiva-float-info">
							<strong id="aiva-float-title"><?php esc_html_e( 'Scanning Website Knowledge Base...', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
							<span id="aiva-float-meta">0 of 0 items (0%)</span>
						</div>
					</div>
					<div class="aiva-float-actions" style="display: flex; gap: 8px; align-items: center;">
						<button type="button" id="aiva-float-stop-scan" class="button button-small aiva-float-stop-btn"><svg class="aiva-btn-svg" style="width:13px;height:13px;margin-right:4px;vertical-align:-1.5px;" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg> <?php esc_html_e( 'Stop Scan', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
						<button type="button" id="aiva-float-open-modal" class="button button-small button-primary"><span class="dashicons dashicons-desktop"></span> <?php esc_html_e( 'Open Monitor', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
					</div>
				</div>

				<!-- Right: System status checks -->
				<div class="aiva-panel-col-right">
					<div class="card">
						<h2><?php esc_html_e( 'System Status', 'shopwalker-ai-voice-chat-assistant' ); ?></h2>
						<p><?php esc_html_e( 'Compatibility health checks for local RAG operations.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
						
						<ul class="aiva-system-status-list">
							<li>
								<span class="aiva-status-dot online"></span>
								<span class="status-label"><?php esc_html_e( 'PHP version', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
								<span class="status-val"><?php echo esc_html( phpversion() ); ?></span>
							</li>
							<li>
								<span class="aiva-status-dot online"></span>
								<span class="status-label"><?php esc_html_e( 'WordPress version', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
								<span class="status-val"><?php echo esc_html( $wp_version ); ?></span>
							</li>
							<li>
								<?php
								global $wpdb;
								$table_name   = esc_sql( $wpdb->prefix . 'aiva_knowledge_base' );
								$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
								$table_status = $table_exists ? 'online' : 'offline';
								$table_val    = $table_exists ? 'Created' : 'Missing';
								?>
								<span class="aiva-status-dot <?php echo esc_attr( $table_status ); ?>"></span>
								<span class="status-label"><?php esc_html_e( 'Vector Index Table', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
								<span class="status-val"><?php echo esc_html( $table_val ); ?></span>
							</li>
							<li>
								<?php
								$ssl_status = extension_loaded( 'openssl' ) ? 'online' : 'offline';
								$ssl_val    = extension_loaded( 'openssl' ) ? 'Enabled' : 'Disabled';
								?>
								<span class="aiva-status-dot <?php echo esc_attr( $ssl_status ); ?>"></span>
								<span class="status-label"><?php esc_html_e( 'Key encryption (OpenSSL)', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
								<span class="status-val"><?php echo esc_html( $ssl_val ); ?></span>
							</li>
							<li>
								<?php
								$http_status = wp_http_supports() ? 'online' : 'offline';
								$http_val    = wp_http_supports() ? 'Allowed' : 'Blocked';
								?>
								<span class="aiva-status-dot <?php echo esc_attr( $http_status ); ?>"></span>
								<span class="status-label"><?php esc_html_e( 'Outbound HTTP requests', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
								<span class="status-val"><?php echo esc_html( $http_val ); ?></span>
							</li>
						</ul>
					</div>
				</div>
			</div><!-- /end .aiva-panel-row -->

			<!-- High-Tech Data Inspector Popout Modal -->
			<div id="aiva-inspector-modal-overlay" class="aiva-modal-overlay" style="display: none;">
				<div class="aiva-scan-modal-window" style="max-width: 960px;">
					<div class="aiva-modal-header">
						<div class="aiva-modal-title">
							<div class="aiva-modal-icon-box"><span class="dashicons dashicons-database"></span></div>
							<h3><?php esc_html_e( 'Indexed Knowledge Base — Database Data Inspector', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>
							<span class="aiva-inspector-header-badge"><span class="aiva-status-dot online"></span> <span id="aiva-inspector-badge-count">0</span> Chunk Records</span>
						</div>
						<div class="aiva-modal-controls">
							<button type="button" id="aiva-close-inspector-modal" class="button button-secondary button-small" title="Close"><span class="dashicons dashicons-no-alt"></span></button>
						</div>
					</div>
					<div class="aiva-modal-body" style="background: #ffffff; color: #1e293b; padding: 25px;">
						<!-- Filters Header -->
						<div class="aiva-inspector-filters" style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
							<input type="text" id="aiva-inspector-search" placeholder="<?php esc_attr_e( 'Search indexed records...', 'shopwalker-ai-voice-chat-assistant' ); ?>" class="regular-text" style="flex: 1; min-width: 200px; height: 38px; font-size: 13px;" />
							<select id="aiva-inspector-type-filter" class="aiva-select-admin" style="height: 38px; font-size: 13px;">
								<option value="all"><?php esc_html_e( 'All Content Types', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
								<option value="listing"><?php esc_html_e( 'Listings & Properties', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
								<option value="product"><?php esc_html_e( 'Products', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
								<option value="page"><?php esc_html_e( 'Pages', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
								<option value="post"><?php esc_html_e( 'Posts', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
								<option value="business_info"><?php esc_html_e( 'Business Info', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
								<option value="menu_info"><?php esc_html_e( 'Menus', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
								<?php
								global $wpdb;
								$db_table_name    = esc_sql( $wpdb->prefix . 'aiva_knowledge_base' );
								$distinct_types   = $wpdb->get_col( "SELECT DISTINCT content_type FROM {$db_table_name} WHERE content_type IS NOT NULL AND content_type != '' ORDER BY content_type ASC" );
								$known_types      = array( 'all', 'listing', 'estate_property', 'property', 'product', 'page', 'post', 'business_info', 'menu_info' );
								$ignored_keywords = array( 'elementor', 'e-', 'ha_', 'wpestate_message', 'revision', 'nav_menu_item', 'custom_css', 'oembed_cache' );

								if ( ! empty( $distinct_types ) ) {
									foreach ( $distinct_types as $dtype ) {
										$dtype_lower = strtolower( $dtype );
										if ( in_array( $dtype_lower, $known_types, true ) ) {
											continue;
										}
										$skip = false;
										foreach ( $ignored_keywords as $ig_kw ) {
											if ( false !== strpos( $dtype_lower, $ig_kw ) ) {
												$skip = true;
												break;
											}
										}
										if ( $skip ) {
											continue;
										}
										$formatted_label = ucwords( str_replace( array( '-', '_' ), ' ', $dtype ) );
										echo '<option value="' . esc_attr( $dtype ) . '">' . esc_html( $formatted_label ) . '</option>';
									}
								}
								?>
							</select>
							<button type="button" id="aiva-refresh-inspector" class="button button-secondary" style="height: 38px;"><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Refresh Data', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
						</div>

						<!-- Table View -->
						<div class="aiva-inspector-table-wrapper" style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
							<table class="wp-list-table widefat fixed striped aiva-inspector-table">
								<thead>
									<tr>
										<th style="width: 70px;">ID</th>
										<th style="width: 110px;">Type</th>
										<th style="width: 220px;">Title / Permalink Source</th>
										<th>Text Chunk Snippet</th>
										<th style="width: 90px;">Hash</th>
										<th style="width: 70px; text-align: center;">Action</th>
									</tr>
								</thead>
								<tbody id="aiva-inspector-tbody">
									<tr>
										<td colspan="6" style="text-align: center; padding: 25px; color: #64748b;">
											<?php esc_html_e( 'Loading database records...', 'shopwalker-ai-voice-chat-assistant' ); ?>
										</td>
									</tr>
								</tbody>
							</table>
						</div>

						<!-- Pagination Footer -->
						<div class="aiva-inspector-pagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 18px;">
							<span id="aiva-inspector-count-info" style="font-size: 13px; color: #64748b; font-weight: 500;">Showing 0 of 0 records</span>
							<div id="aiva-inspector-nav-btns" style="display: flex; gap: 8px;">
								<button type="button" id="aiva-inspector-prev-page" class="button button-secondary" disabled>&laquo; Previous</button>
								<span id="aiva-inspector-page-indicator" style="align-self: center; font-size: 13px; font-weight: 600; color: #334155;">Page 1</span>
								<button type="button" id="aiva-inspector-next-page" class="button button-secondary" disabled>Next &raquo;</button>
							</div>
						</div>
					</div>
				</div>
			</div><!-- /end #aiva-inspector-modal-overlay -->

			<!-- Custom Wipe Index Confirmation Modal Overlay -->
			<div id="aiva-wipe-modal-overlay" class="aiva-modal-overlay" style="display: none;">
				<div class="aiva-scan-modal-window aiva-wipe-modal-window" style="max-width: 500px;">
					<div class="aiva-modal-header">
						<div class="aiva-modal-title">
							<div class="aiva-modal-icon-box">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="width: 20px; height: 20px; color: #2dd4bf;">
									<path d="M3 6h18"/>
									<path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
									<path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
									<line x1="10" y1="11" x2="10" y2="17"/>
									<line x1="14" y1="11" x2="14" y2="17"/>
								</svg>
							</div>
							<div style="display: flex; flex-direction: column;">
								<h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #ffffff;"><?php esc_html_e( 'Wipe AI Vector Index?', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>
								<span style="font-size: 12px; color: #2dd4bf; font-weight: 500; margin-top: 2px;"><?php esc_html_e( 'Database Management Action', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
							</div>
						</div>
						<div class="aiva-modal-controls">
							<button type="button" class="aiva-modal-close-btn aiva-wipe-cancel-btn" title="<?php esc_attr_e( 'Close Modal', 'shopwalker-ai-voice-chat-assistant' ); ?>">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="width: 14px; height: 14px; color: #ffffff;">
									<line x1="18" y1="6" x2="6" y2="18"/>
									<line x1="6" y1="6" x2="18" y2="18"/>
								</svg>
							</button>
						</div>
					</div>
					<div class="aiva-modal-body" style="padding: 24px; text-align: left;">
						<p style="margin: 0 0 18px 0; color: #f8fafc; font-size: 14.5px; font-weight: 600; line-height: 1.5;">
							<?php esc_html_e( 'Are you sure you want to clear the AI Knowledge base index?', 'shopwalker-ai-voice-chat-assistant' ); ?><br />
							<span style="color: #cbd5e1; font-size: 13px; font-weight: 400; display: inline-block; margin-top: 4px;"><?php esc_html_e( 'This action will permanently delete all stored vector embeddings from your database.', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
						</p>
						<div style="background: rgba(13, 148, 136, 0.16); border: 1.5px solid rgba(45, 212, 191, 0.35); border-radius: 10px; padding: 14px 16px; margin-bottom: 12px; display: flex; align-items: center; gap: 12px;">
							<svg style="width: 20px; height: 20px; color: #2dd4bf; flex-shrink: 0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<circle cx="12" cy="12" r="10"/>
								<line x1="12" y1="16" x2="12" y2="12"/>
								<line x1="12" y1="8" x2="12.01" y2="8"/>
							</svg>
							<span style="font-size: 13px; color: #f0fdfa; line-height: 1.5; font-weight: 600;">
								<?php esc_html_e( 'The AI assistant will not be able to answer website queries until you run a new scan.', 'shopwalker-ai-voice-chat-assistant' ); ?>
							</span>
						</div>
						<div id="aiva-wipe-feedback" class="aiva-api-feedback" style="display: none; margin-top: 12px; margin-bottom: 14px;"></div>
						<div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 14px; padding-top: 16px; border-top: 1px solid rgba(255, 255, 255, 0.08);">
							<button type="button" class="aiva-btn-cancel-ghost aiva-wipe-cancel-btn">
								<?php esc_html_e( 'Cancel', 'shopwalker-ai-voice-chat-assistant' ); ?>
							</button>
							<button type="button" id="aiva-confirm-wipe-btn" class="aiva-btn-confirm-wipe">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 15px; height: 15px; margin-right: 4px;">
									<path d="M3 6h18"/>
									<path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
									<path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
								</svg>
								<?php esc_html_e( 'Yes, Wipe Index', 'shopwalker-ai-voice-chat-assistant' ); ?>
							</button>
						</div>
					</div>
				</div>
			</div><!-- /end #aiva-wipe-modal-overlay -->
		</div><!-- /end .aiva-admin-wrap -->
		<?php
	}

	/**
	 * Render the settings page with a vertical sidebar tab menu layout.
	 */
	public function render_settings_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag set by options.php after a nonce-checked save.
		if ( isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) ) {
			add_settings_error( 'aiva_messages', 'aiva_message', __( 'Settings Saved Successfully.', 'shopwalker-ai-voice-chat-assistant' ), 'updated' );
		}
		settings_errors( 'aiva_messages' );

		$included_types = get_option( 'aiva_included_types', array( 'page', 'product' ) );
		if ( ! is_array( $included_types ) ) {
			$included_types = array();
		}

		$active_engine      = get_option( 'aiva_active_llm_engine', 'gemini' );
		$gemini_provider    = AIVA_Provider_Registry::get_instance()->get_provider( 'gemini' );
		$openai_provider    = AIVA_Provider_Registry::get_instance()->get_provider( 'openai' );
		$anthropic_provider = AIVA_Provider_Registry::get_instance()->get_provider( 'anthropic' );

		$gemini_eff_model    = $gemini_provider ? $gemini_provider->get_effective_model() : get_option( 'aiva_gemini_model', 'gemini-3.5-flash' );
		$openai_eff_model    = $openai_provider ? $openai_provider->get_effective_model() : get_option( 'aiva_openai_model', 'gpt-4o-mini' );
		$anthropic_eff_model = $anthropic_provider ? $anthropic_provider->get_effective_model() : get_option( 'aiva_anthropic_model', 'claude-haiku-4-5-20251001' );

		if ( 'openai' === $active_engine ) {
			$settings_engine_name = 'OpenAI (' . $openai_eff_model . ')';
		} elseif ( 'anthropic' === $active_engine ) {
			$settings_engine_name = 'Anthropic Claude (' . $anthropic_eff_model . ')';
		} else {
			$settings_engine_name = 'Google Gemini (' . $gemini_eff_model . ')';
		}
		?>
		<div class="wrap aiva-admin-wrap">
			<h1 class="wp-heading-inline" style="display:none;"></h1>
			<div class="aiva-notices-container"></div>
			<!-- Hero Header -->
			<div class="aiva-header">
				<div class="aiva-header-left">
					<h1><span class="aiva-header-icon-box"><img src="<?php echo esc_url( AIVA_URL . 'admin/images/ai-voice-agent-icon.png' ); ?>" alt="Shopwalker" class="aiva-header-custom-icon" /></span> <?php esc_html_e( 'Shopwalker Settings', 'shopwalker-ai-voice-chat-assistant' ); ?></h1>
					<p class="description"><?php esc_html_e( 'Configure AI engine models, specify scanned post types, and define custom AI agent rules.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
				</div>
				<div class="aiva-header-right">
					<span class="aiva-badge-version"><?php echo esc_html( 'v' . AIVA_VERSION ); ?></span>
					<span class="aiva-badge-engine"><span class="aiva-status-dot-green"></span> <?php echo esc_html( $settings_engine_name ); ?></span>
					<a href="<?php echo esc_url( home_url() ); ?>" target="_blank" class="button aiva-btn-preview"><span class="dashicons dashicons-welcome-view-site"></span> <?php esc_html_e( 'Open Voice Widget', 'shopwalker-ai-voice-chat-assistant' ); ?></a>
				</div>
			</div>

			<?php
			$active_tab = get_option( 'aiva_active_tab', 'profile' );
			if ( empty( $active_tab ) ) {
				$active_tab = 'profile';
			}
			?>
			<!-- Vertical Menu Sidebar Layout -->
			<form method="post" action="options.php" class="aiva-settings-layout-wrapper">
				<?php settings_fields( 'aiva_options_group' ); ?>
				<input type="hidden" name="aiva_active_tab" id="aiva_active_tab" value="<?php echo esc_attr( $active_tab ); ?>" />
				
				<!-- Left Sidebar -->
				<div class="aiva-settings-sidebar">
					<ul class="aiva-sidebar-tabs">
						<li class="<?php echo ( $active_tab === 'profile' ) ? 'active' : ''; ?>"><a href="#profile"><svg class="aiva-tab-svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1a1 1 0 011-1h1a1 1 0 011 1v1a1 1 0 01-1 1H8a1 1 0 01-1-1V5zm1 4a1 1 0 100 2h1a1 1 0 100-2H8zm-1 5a1 1 0 011-1h1a1 1 0 011 1v1a1 1 0 01-1 1H8a1 1 0 01-1-1v-1zm5-9a1 1 0 100 2h1a1 1 0 100-2h-1zm-1 5a1 1 0 011-1h1a1 1 0 011 1v1a1 1 0 01-1 1h-1a1 1 0 01-1-1v-1z" clip-rule="evenodd" /></svg> <?php esc_html_e( 'Business Profile', 'shopwalker-ai-voice-chat-assistant' ); ?></a></li>
						<li class="<?php echo ( $active_tab === 'api' ) ? 'active' : ''; ?>"><a href="#api"><svg class="aiva-tab-svg" viewBox="0 0 20 20" fill="currentColor"><path d="M13 7H7v6h6V7z" /><path fill-rule="evenodd" d="M7 2a1 1 0 011 1v1h4V3a1 1 0 112 0v1h1a2 2 0 012 2v1h1a1 1 0 110 2h-1v4h1a1 1 0 110 2h-1v1a2 2 0 01-2 2h-1v1a1 1 0 11-2 0v-1H8v1a1 1 0 11-2 0v-1H5a2 2 0 01-2-2v-1H2a1 1 0 110-2h1V9H2a1 1 0 010-2h1V6a2 2 0 012-2h1V3a1 1 0 011-1zm0 4H5v8h10V6H7z" clip-rule="evenodd" /></svg> <?php esc_html_e( 'AI Engines', 'shopwalker-ai-voice-chat-assistant' ); ?></a></li>
						<li class="<?php echo ( $active_tab === 'content' ) ? 'active' : ''; ?>"><a href="#content"><svg class="aiva-tab-svg" viewBox="0 0 20 20" fill="currentColor"><path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z" /><path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z" /><path d="M10 2C6.134 2 3 3.343 3 5s3.134 3 7 3 7-1.343 7-3-3.134-3-7-3z" /></svg> <?php esc_html_e( 'Content Selection', 'shopwalker-ai-voice-chat-assistant' ); ?></a></li>
						<li class="<?php echo ( $active_tab === 'widget' ) ? 'active' : ''; ?>"><a href="#widget"><svg class="aiva-tab-svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9a1 1 0 00-1 1v1a1 1 0 102 0v-1a1 1 0 00-1-1zm3-2a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1zm3 2a1 1 0 00-1 1v1a1 1 0 102 0v-1a1 1 0 00-1-1z" clip-rule="evenodd" /></svg> <?php esc_html_e( 'Widget Customization', 'shopwalker-ai-voice-chat-assistant' ); ?></a></li>
					</ul>
					
					<div class="aiva-sidebar-footer">
						<?php submit_button( __( 'Save all settings', 'shopwalker-ai-voice-chat-assistant' ), 'button-primary button-large', 'submit', false ); ?>
					</div>
				</div>

				<!-- Right Form Content Panels -->
				<div class="aiva-settings-content">
					
					<!-- TAB: API Configuration -->
					<div id="tab-api" class="aiva-settings-panel <?php echo ( $active_tab === 'api' ) ? 'active' : ''; ?>">
						<div class="aiva-tab-header-flex">
							<div>
								<h2><?php esc_html_e( 'AI Engine Configuration', 'shopwalker-ai-voice-chat-assistant' ); ?></h2>
								<p class="tab-subtitle"><?php esc_html_e( 'Select the primary model for your voice agent and manage API keys for your engines.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
							</div>
							<?php
							$gemini_key    = get_option( 'aiva_gemini_api_key' );
							$openai_key    = get_option( 'aiva_openai_api_key' );
							$anthropic_key = get_option( 'aiva_anthropic_api_key' );

							$ready_count = 0;
							if ( ! empty( $gemini_key ) ) {
								++$ready_count;
							}
							if ( ! empty( $openai_key ) ) {
								++$ready_count;
							}
							if ( ! empty( $anthropic_key ) ) {
								++$ready_count;
							}
							?>
							<div class="aiva-engines-ready-badge">
								<span>
								<?php
								/* translators: %d: number of configured AI engines. */
								printf( esc_html__( '%d of 3 engines configured', 'shopwalker-ai-voice-chat-assistant' ), (int) $ready_count );
								?>
								</span>
							</div>
						</div>

						<?php $current_engine = get_option( 'aiva_active_llm_engine', 'gemini' ); ?>

						<!-- Sleek Engine Selector Chips (Horizontal Selection buttons) -->
						<div class="aiva-selector-chips-grid">
							<!-- Card 1: Google Gemini -->
							<div class="aiva-engine-chip <?php echo ( $current_engine === 'gemini' ) ? 'active gemini' : ''; ?>" data-engine="gemini">
								<div class="aiva-chip-select">
									<input type="radio" name="aiva_active_llm_engine" value="gemini" <?php checked( $current_engine, 'gemini' ); ?> />
									<span class="aiva-custom-radio"></span>
								</div>
								<div class="aiva-chip-info">
									<div class="aiva-chip-title-row">
										<div class="aiva-chip-logo gemini">G</div>
										<h4><?php esc_html_e( 'Google Gemini', 'shopwalker-ai-voice-chat-assistant' ); ?></h4>
										<div class="aiva-chip-badges">
											<span class="aiva-chip-badge badge-api"><?php esc_html_e( 'API key', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
											<span class="aiva-chip-badge badge-status <?php echo ! empty( $gemini_key ) ? 'ready' : 'needs-key'; ?>">
												<?php echo ! empty( $gemini_key ) ? esc_html__( 'Ready', 'shopwalker-ai-voice-chat-assistant' ) : esc_html__( 'Needs key', 'shopwalker-ai-voice-chat-assistant' ); ?>
											</span>
										</div>
									</div>
									<p class="aiva-chip-desc">
									<?php
									/* translators: %s: AI model ID. */
									printf( esc_html__( 'Google Gemini (%s) — fast, high quality, free tier available. Key required.', 'shopwalker-ai-voice-chat-assistant' ), esc_html( $gemini_eff_model ) );
									?>
									</p>
								</div>
							</div>

							<!-- Card 2: OpenAI -->
							<div class="aiva-engine-chip <?php echo ( $current_engine === 'openai' ) ? 'active openai' : ''; ?>" data-engine="openai">
								<div class="aiva-chip-select">
									<input type="radio" name="aiva_active_llm_engine" value="openai" <?php checked( $current_engine, 'openai' ); ?> />
									<span class="aiva-custom-radio"></span>
								</div>
								<div class="aiva-chip-info">
									<div class="aiva-chip-title-row">
										<div class="aiva-chip-logo openai">O</div>
										<h4><?php esc_html_e( 'OpenAI', 'shopwalker-ai-voice-chat-assistant' ); ?></h4>
										<div class="aiva-chip-badges">
											<span class="aiva-chip-badge badge-api"><?php esc_html_e( 'API key', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
											<span class="aiva-chip-badge badge-status <?php echo ! empty( $openai_key ) ? 'ready' : 'needs-key'; ?>">
												<?php echo ! empty( $openai_key ) ? esc_html__( 'Ready', 'shopwalker-ai-voice-chat-assistant' ) : esc_html__( 'Needs key', 'shopwalker-ai-voice-chat-assistant' ); ?>
											</span>
										</div>
									</div>
									<p class="aiva-chip-desc">
									<?php
									/* translators: %s: AI model ID. */
									printf( esc_html__( 'OpenAI (%s) — high speed, cost-effective, premium model. Key required.', 'shopwalker-ai-voice-chat-assistant' ), esc_html( $openai_eff_model ) );
									?>
									</p>
								</div>
							</div>

							<!-- Card 3: Anthropic Claude -->
							<div class="aiva-engine-chip <?php echo ( $current_engine === 'anthropic' ) ? 'active anthropic' : ''; ?>" data-engine="anthropic">
								<div class="aiva-chip-select">
									<input type="radio" name="aiva_active_llm_engine" value="anthropic" <?php checked( $current_engine, 'anthropic' ); ?> />
									<span class="aiva-custom-radio"></span>
								</div>
								<div class="aiva-chip-info">
									<div class="aiva-chip-title-row">
										<div class="aiva-chip-logo anthropic">A</div>
										<h4><?php esc_html_e( 'Anthropic Claude', 'shopwalker-ai-voice-chat-assistant' ); ?></h4>
										<div class="aiva-chip-badges">
											<span class="aiva-chip-badge badge-api"><?php esc_html_e( 'API key', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
											<span class="aiva-chip-badge badge-status <?php echo ! empty( $anthropic_key ) ? 'ready' : 'needs-key'; ?>">
												<?php echo ! empty( $anthropic_key ) ? esc_html__( 'Ready', 'shopwalker-ai-voice-chat-assistant' ) : esc_html__( 'Needs key', 'shopwalker-ai-voice-chat-assistant' ); ?>
											</span>
										</div>
									</div>
									<p class="aiva-chip-desc">
									<?php
									/* translators: %s: AI model ID. */
									printf( esc_html__( 'Anthropic Claude (%s) — exceptional reasoning, smart conversations. Key required.', 'shopwalker-ai-voice-chat-assistant' ), esc_html( $anthropic_eff_model ) );
									?>
									</p>
								</div>
							</div>
						</div>

						<!-- Unified Credentials Form Section -->
						<?php
						$gemini_model      = get_option( 'aiva_gemini_model', 'gemini-3.5-flash' );
						$gemini_custom     = get_option( 'aiva_gemini_custom_model', '' );
						$openai_model      = get_option( 'aiva_openai_model', 'gpt-4o-mini' );
						$openai_custom     = get_option( 'aiva_openai_custom_model', '' );
						$anthropic_model   = get_option( 'aiva_anthropic_model', 'claude-haiku-4-5-20251001' );
						$anthropic_custom  = get_option( 'aiva_anthropic_custom_model', '' );
						$elevenlabs_model  = get_option( 'aiva_elevenlabs_model', 'eleven_flash_v2_5' );
						$elevenlabs_custom = get_option( 'aiva_elevenlabs_custom_model', '' );
						?>
						<div class="aiva-form-section aiva-credentials-section">
							<h3><?php esc_html_e( 'AI Text Model API Keys & Model Selection', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>
							<p class="section-desc"><?php esc_html_e( 'Configure API credentials and target AI model versions for text reasoning and vector search.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
							
							<!-- Gemini API Key Row -->
							<div class="aiva-credential-row">
								<div class="aiva-cred-label-col">
									<strong>
										<span class="aiva-chip-logo gemini">G</span>
										<?php esc_html_e( 'Google Gemini API Key', 'shopwalker-ai-voice-chat-assistant' ); ?>
									</strong>
									<div style="display: flex; align-items: center; gap: 10px; margin-top: 2px;">
										<a href="https://aistudio.google.com/" target="_blank" class="aiva-get-key-link"><?php esc_html_e( 'Get key', 'shopwalker-ai-voice-chat-assistant' ); ?> <span class="dashicons dashicons-external"></span></a>
										<span class="aiva-chip-badge badge-status <?php echo ! empty( $gemini_key ) ? 'ready' : 'needs-key'; ?>">
											<?php echo ! empty( $gemini_key ) ? esc_html__( 'READY', 'shopwalker-ai-voice-chat-assistant' ) : esc_html__( 'NEEDS KEY', 'shopwalker-ai-voice-chat-assistant' ); ?>
										</span>
									</div>
								</div>
								<div class="aiva-cred-input-col">
									<div class="aiva-input-group">
										<input type="password" name="aiva_gemini_api_key" id="aiva_gemini_api_key" value="<?php echo esc_attr( $gemini_key ); ?>" placeholder="AIzaSy..." />
										<button type="button" id="aiva-test-gemini" class="button button-primary" <?php disabled( empty( $gemini_key ) ); ?>><?php esc_html_e( 'Test Connection', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
										<?php if ( $gemini_key ) : ?>
											<button type="button" class="aiva-key-remove-btn" data-target="aiva_gemini_api_key" title="<?php esc_attr_e( 'Clear saved key', 'shopwalker-ai-voice-chat-assistant' ); ?>"><span class="dashicons dashicons-no-alt"></span> <?php esc_html_e( 'Clear', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
										<?php endif; ?>
									</div>
									<div id="aiva-gemini-test-result" class="aiva-api-feedback"></div>
								</div>
							</div>

							<!-- Gemini Model Selection Row -->
							<div class="aiva-credential-row">
								<div class="aiva-cred-label-col">
									<strong>
										<span class="dashicons dashicons-performance" style="color: #0d9488;"></span>
										<?php esc_html_e( 'Google Gemini Model', 'shopwalker-ai-voice-chat-assistant' ); ?>
									</strong>
								</div>
								<div class="aiva-cred-input-col">
									<div style="display: flex; gap: 10px;">
										<select name="aiva_gemini_model" class="aiva-model-select aiva-select-admin-full" data-target-custom="aiva_gemini_custom_wrap" style="flex: 1; margin: 0;">
											<option value="gemini-3.5-flash" <?php selected( $gemini_model, 'gemini-3.5-flash' ); ?>><?php esc_html_e( 'Gemini 3.5 Flash (Stable & Balanced – Recommended)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gemini-3.8-flash" <?php selected( $gemini_model, 'gemini-3.8-flash' ); ?>><?php esc_html_e( 'Gemini 3.8 Flash (Newest)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gemini-3.7-flash" <?php selected( $gemini_model, 'gemini-3.7-flash' ); ?>><?php esc_html_e( 'Gemini 3.7 Flash', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gemini-3.6-flash" <?php selected( $gemini_model, 'gemini-3.6-flash' ); ?>><?php esc_html_e( 'Gemini 3.6 Flash', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gemini-3.5-flash-lite" <?php selected( $gemini_model, 'gemini-3.5-flash-lite' ); ?>><?php esc_html_e( 'Gemini 3.5 Flash-Lite', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gemini-3.1-flash-lite" <?php selected( $gemini_model, 'gemini-3.1-flash-lite' ); ?>><?php esc_html_e( 'Gemini 3.1 Flash-Lite (Ultra Fast, Lowest Token Cost)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gemini-flash-latest" <?php selected( $gemini_model, 'gemini-flash-latest' ); ?>><?php esc_html_e( 'gemini-flash-latest (Always Newest Flash Alias)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="custom" <?php selected( $gemini_model, 'custom' ); ?>><?php esc_html_e( 'Custom model ID...', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
										</select>
										<div id="aiva_gemini_custom_wrap" class="aiva-custom-model-wrap" style="flex: 1; <?php echo ( $gemini_model === 'custom' ) ? '' : 'display: none;'; ?>">
											<input type="text" name="aiva_gemini_custom_model" value="<?php echo esc_attr( $gemini_custom ); ?>" placeholder="e.g. gemini-3.5-flash" class="regular-text" style="width: 100%; margin: 0;" />
										</div>
									</div>
								</div>
							</div>

							<!-- OpenAI API Key Row -->
							<div class="aiva-credential-row">
								<div class="aiva-cred-label-col">
									<strong>
										<span class="aiva-chip-logo openai">O</span>
										<?php esc_html_e( 'OpenAI API Key', 'shopwalker-ai-voice-chat-assistant' ); ?>
									</strong>
									<div style="display: flex; align-items: center; gap: 10px; margin-top: 2px;">
										<a href="https://platform.openai.com/api-keys" target="_blank" class="aiva-get-key-link"><?php esc_html_e( 'Get key', 'shopwalker-ai-voice-chat-assistant' ); ?> <span class="dashicons dashicons-external"></span></a>
										<span class="aiva-chip-badge badge-status <?php echo ! empty( $openai_key ) ? 'ready' : 'needs-key'; ?>">
											<?php echo ! empty( $openai_key ) ? esc_html__( 'READY', 'shopwalker-ai-voice-chat-assistant' ) : esc_html__( 'NEEDS KEY', 'shopwalker-ai-voice-chat-assistant' ); ?>
										</span>
									</div>
								</div>
								<div class="aiva-cred-input-col">
									<div class="aiva-input-group">
										<input type="password" name="aiva_openai_api_key" id="aiva_openai_api_key" value="<?php echo esc_attr( $openai_key ); ?>" placeholder="sk-..." />
										<button type="button" id="aiva-test-openai" class="button button-primary" <?php disabled( empty( $openai_key ) ); ?>><?php esc_html_e( 'Test Connection', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
										<?php if ( $openai_key ) : ?>
											<button type="button" class="aiva-key-remove-btn" data-target="aiva_openai_api_key" title="<?php esc_attr_e( 'Clear saved key', 'shopwalker-ai-voice-chat-assistant' ); ?>"><span class="dashicons dashicons-no-alt"></span> <?php esc_html_e( 'Clear', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
										<?php endif; ?>
									</div>
									<div id="aiva-openai-test-result" class="aiva-api-feedback"></div>
								</div>
							</div>

							<!-- OpenAI Model Selection Row -->
							<div class="aiva-credential-row">
								<div class="aiva-cred-label-col">
									<strong>
										<span class="dashicons dashicons-performance" style="color: #0d9488;"></span>
										<?php esc_html_e( 'OpenAI Model', 'shopwalker-ai-voice-chat-assistant' ); ?>
									</strong>
								</div>
								<div class="aiva-cred-input-col">
									<div style="display: flex; gap: 10px;">
										<select name="aiva_openai_model" class="aiva-model-select aiva-select-admin-full" data-target-custom="aiva_openai_custom_wrap" style="flex: 1; margin: 0;">
											<option value="gpt-4o-mini" <?php selected( $openai_model, 'gpt-4o-mini' ); ?>><?php esc_html_e( 'GPT-4o mini (Fast, Low Cost – Recommended)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gpt-5.4-mini" <?php selected( $openai_model, 'gpt-5.4-mini' ); ?>><?php esc_html_e( 'GPT-5.4 mini (Fast, Current Generation)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gpt-5.4" <?php selected( $openai_model, 'gpt-5.4' ); ?>><?php esc_html_e( 'GPT-5.4', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gpt-5.5" <?php selected( $openai_model, 'gpt-5.5' ); ?>><?php esc_html_e( 'GPT-5.5 (Flagship)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gpt-4o" <?php selected( $openai_model, 'gpt-4o' ); ?>><?php esc_html_e( 'GPT-4o (Flagship Multimodal Intelligence)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gpt-5.6-terra" <?php selected( $openai_model, 'gpt-5.6-terra' ); ?>><?php esc_html_e( 'GPT-5.6 Terra (Balanced)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gpt-5.6-luna" <?php selected( $openai_model, 'gpt-5.6-luna' ); ?>><?php esc_html_e( 'GPT-5.6 Luna (Efficient, High Volume)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gpt-5.6-sol" <?php selected( $openai_model, 'gpt-5.6-sol' ); ?>><?php esc_html_e( 'GPT-5.6 Sol (Flagship)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gpt-5-mini" <?php selected( $openai_model, 'gpt-5-mini' ); ?>><?php esc_html_e( 'GPT-5 mini', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gpt-4.1-mini" <?php selected( $openai_model, 'gpt-4.1-mini' ); ?>><?php esc_html_e( 'GPT-4.1 mini', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="o3-mini" <?php selected( $openai_model, 'o3-mini' ); ?>><?php esc_html_e( 'o3-mini (High Speed Reasoning)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="o1-mini" <?php selected( $openai_model, 'o1-mini' ); ?>><?php esc_html_e( 'o1-mini (Reasoning Model)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gpt-4-turbo" <?php selected( $openai_model, 'gpt-4-turbo' ); ?>><?php esc_html_e( 'GPT-4 Turbo (High Capability)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="gpt-3.5-turbo" <?php selected( $openai_model, 'gpt-3.5-turbo' ); ?>><?php esc_html_e( 'GPT-3.5 Turbo (Legacy Fast)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="custom" <?php selected( $openai_model, 'custom' ); ?>><?php esc_html_e( 'Custom model ID...', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
										</select>
										<div id="aiva_openai_custom_wrap" class="aiva-custom-model-wrap" style="flex: 1; <?php echo ( $openai_model === 'custom' ) ? '' : 'display: none;'; ?>">
											<input type="text" name="aiva_openai_custom_model" value="<?php echo esc_attr( $openai_custom ); ?>" placeholder="e.g. gpt-4o-2024-08-06" class="regular-text" style="width: 100%; margin: 0;" />
										</div>
									</div>
								</div>
							</div>

							<!-- Anthropic API Key Row -->
							<div class="aiva-credential-row">
								<div class="aiva-cred-label-col">
									<strong>
										<span class="aiva-chip-logo anthropic">A</span>
										<?php esc_html_e( 'Anthropic Claude API Key', 'shopwalker-ai-voice-chat-assistant' ); ?>
									</strong>
									<div style="display: flex; align-items: center; gap: 10px; margin-top: 2px;">
										<a href="https://console.anthropic.com/" target="_blank" class="aiva-get-key-link"><?php esc_html_e( 'Get key', 'shopwalker-ai-voice-chat-assistant' ); ?> <span class="dashicons dashicons-external"></span></a>
										<span class="aiva-chip-badge badge-status <?php echo ! empty( $anthropic_key ) ? 'ready' : 'needs-key'; ?>">
											<?php echo ! empty( $anthropic_key ) ? esc_html__( 'READY', 'shopwalker-ai-voice-chat-assistant' ) : esc_html__( 'NEEDS KEY', 'shopwalker-ai-voice-chat-assistant' ); ?>
										</span>
									</div>
								</div>
								<div class="aiva-cred-input-col">
									<div class="aiva-input-group">
										<input type="password" name="aiva_anthropic_api_key" id="aiva_anthropic_api_key" value="<?php echo esc_attr( $anthropic_key ); ?>" placeholder="sk-ant-..." />
										<button type="button" id="aiva-test-anthropic" class="button button-primary" <?php disabled( empty( $anthropic_key ) ); ?>><?php esc_html_e( 'Test Connection', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
										<?php if ( $anthropic_key ) : ?>
											<button type="button" class="aiva-key-remove-btn" data-target="aiva_anthropic_api_key" title="<?php esc_attr_e( 'Clear saved key', 'shopwalker-ai-voice-chat-assistant' ); ?>"><span class="dashicons dashicons-no-alt"></span> <?php esc_html_e( 'Clear', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
										<?php endif; ?>
									</div>
									<div id="aiva-anthropic-test-result" class="aiva-api-feedback"></div>
								</div>
							</div>

							<!-- Anthropic Model Selection Row -->
							<div class="aiva-credential-row">
								<div class="aiva-cred-label-col">
									<strong>
										<span class="dashicons dashicons-performance" style="color: #0d9488;"></span>
										<?php esc_html_e( 'Anthropic Claude Model', 'shopwalker-ai-voice-chat-assistant' ); ?>
									</strong>
								</div>
								<div class="aiva-cred-input-col">
									<div style="display: flex; gap: 10px;">
										<select name="aiva_anthropic_model" class="aiva-model-select aiva-select-admin-full" data-target-custom="aiva_anthropic_custom_wrap" style="flex: 1; margin: 0;">
											<option value="claude-haiku-4-5-20251001" <?php selected( $anthropic_model, 'claude-haiku-4-5-20251001' ); ?>><?php esc_html_e( 'Claude Haiku 4.5 (Fast, Low Latency – Recommended)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="claude-sonnet-5" <?php selected( $anthropic_model, 'claude-sonnet-5' ); ?>><?php esc_html_e( 'Claude Sonnet 5 (Higher Intelligence)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="claude-sonnet-4-6" <?php selected( $anthropic_model, 'claude-sonnet-4-6' ); ?>><?php esc_html_e( 'Claude Sonnet 4.6', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											<option value="custom" <?php selected( $anthropic_model, 'custom' ); ?>><?php esc_html_e( 'Custom model ID...', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
										</select>
										<div id="aiva_anthropic_custom_wrap" class="aiva-custom-model-wrap" style="flex: 1; <?php echo ( $anthropic_model === 'custom' ) ? '' : 'display: none;'; ?>">
											<input type="text" name="aiva_anthropic_custom_model" value="<?php echo esc_attr( $anthropic_custom ); ?>" placeholder="e.g. claude-haiku-4-5-20251001" class="regular-text" style="width: 100%; margin: 0;" />
										</div>
									</div>
								</div>
							</div>
						</div>

						<!-- Separate Dedicated ElevenLabs Voice Synthesis Section -->
						<div class="aiva-form-section aiva-tts-section">
							<h3><?php esc_html_e( 'Voice Synthesis Engine (ElevenLabs TTS)', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>
							<p class="section-desc"><?php esc_html_e( 'Configure realistic ElevenLabs AI voice response playback and model version for website visitors.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>

							<?php $tts_engine = get_option( 'aiva_tts_engine', 'elevenlabs' ); ?>
							<?php $elevenlabs_key = get_option( 'aiva_elevenlabs_api_key' ); ?>
							<?php $elevenlabs_voice = get_option( 'aiva_elevenlabs_voice_id', '21m00Tcm4TlvDq8ikWAM' ); ?>

							<!-- Row 1: Playback Mode 2-Card Selection -->
							<div class="form-row" style="margin-bottom: 22px;">
								<label style="display: block; margin-bottom: 10px;"><strong><?php esc_html_e( 'Playback Mode', 'shopwalker-ai-voice-chat-assistant' ); ?></strong></label>
								<div class="aiva-mode-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
									<div class="aiva-mode-card <?php echo ( $tts_engine === 'browser' ) ? 'active' : ''; ?>" data-tts="browser">
										<input type="radio" name="aiva_tts_engine" value="browser" <?php checked( $tts_engine, 'browser' ); ?> style="display: none;" />
										<div class="aiva-mode-icon"><span class="dashicons dashicons-microphone"></span></div>
										<div class="aiva-mode-text">
											<strong><?php esc_html_e( 'Browser Native Speech', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
											<span><?php esc_html_e( 'Free, built-in browser speech synthesis', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
										</div>
									</div>

									<div class="aiva-mode-card <?php echo ( $tts_engine === 'elevenlabs' ) ? 'active' : ''; ?>" data-tts="elevenlabs">
										<input type="radio" name="aiva_tts_engine" value="elevenlabs" <?php checked( $tts_engine, 'elevenlabs' ); ?> style="display: none;" />
										<div class="aiva-mode-icon"><span class="dashicons dashicons-controls-volumeon"></span></div>
										<div class="aiva-mode-text">
											<strong><?php esc_html_e( 'ElevenLabs AI Voice', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
											<span><?php esc_html_e( 'Ultra-realistic studio voice (API Key Required)', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
										</div>
									</div>
								</div>
							</div>

							<!-- Wrapper for ElevenLabs Settings (Hidden when Browser Native is selected) -->
							<div id="aiva-elevenlabs-settings-wrapper" style="<?php echo ( $tts_engine === 'browser' ) ? 'display: none;' : ''; ?>">
								<!-- Row 2: ElevenLabs API Key -->
								<div class="aiva-credential-row">
									<div class="aiva-cred-label-col">
										<strong>
											<span class="aiva-chip-logo elevenlabs">E</span>
											<?php esc_html_e( 'ElevenLabs Voice Key', 'shopwalker-ai-voice-chat-assistant' ); ?>
										</strong>
										<div style="display: flex; align-items: center; gap: 10px; margin-top: 2px;">
											<a href="https://elevenlabs.io/app/developers/api-keys" target="_blank" class="aiva-get-key-link"><?php esc_html_e( 'Get key', 'shopwalker-ai-voice-chat-assistant' ); ?> <span class="dashicons dashicons-external"></span></a>
											<span class="aiva-chip-badge badge-status <?php echo ! empty( $elevenlabs_key ) ? 'ready' : 'needs-key'; ?>">
												<?php echo ! empty( $elevenlabs_key ) ? esc_html__( 'READY', 'shopwalker-ai-voice-chat-assistant' ) : esc_html__( 'NEEDS KEY', 'shopwalker-ai-voice-chat-assistant' ); ?>
											</span>
										</div>
									</div>
									<div class="aiva-cred-input-col">
										<div class="aiva-input-group">
											<input type="password" name="aiva_elevenlabs_api_key" id="aiva_elevenlabs_api_key" value="<?php echo esc_attr( $elevenlabs_key ); ?>" placeholder="xi-..." />
											<button type="button" id="aiva-test-elevenlabs" class="button button-primary" <?php disabled( empty( $elevenlabs_key ) ); ?>><?php esc_html_e( 'Test Connection', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
											<?php if ( $elevenlabs_key ) : ?>
												<button type="button" class="aiva-key-remove-btn" data-target="aiva_elevenlabs_api_key" title="<?php esc_attr_e( 'Clear saved key', 'shopwalker-ai-voice-chat-assistant' ); ?>"><span class="dashicons dashicons-no-alt"></span> <?php esc_html_e( 'Clear', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
											<?php endif; ?>
										</div>
										<div id="aiva-elevenlabs-test-result" class="aiva-api-feedback"></div>
									</div>
								</div>

								<!-- Row 3: ElevenLabs Voice Model Selection -->
								<div class="aiva-credential-row">
									<div class="aiva-cred-label-col">
										<strong>
											<span class="dashicons dashicons-performance" style="color: #0d9488;"></span>
											<?php esc_html_e( 'ElevenLabs TTS Model', 'shopwalker-ai-voice-chat-assistant' ); ?>
										</strong>
									</div>
									<div class="aiva-cred-input-col">
										<div style="display: flex; gap: 10px;">
											<select name="aiva_elevenlabs_model" class="aiva-model-select aiva-select-admin-full" data-target-custom="aiva_elevenlabs_custom_wrap" style="flex: 1; margin: 0;">
												<option value="eleven_flash_v2_5" <?php selected( $elevenlabs_model, 'eleven_flash_v2_5' ); ?>><?php esc_html_e( 'Eleven Flash v2.5 (75ms Ultra Low Latency – Recommended)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
												<option value="eleven_multilingual_v2" <?php selected( $elevenlabs_model, 'eleven_multilingual_v2' ); ?>><?php esc_html_e( 'Eleven Multilingual v2 (Highest Natural Quality)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
												<option value="eleven_turbo_v2_5" <?php selected( $elevenlabs_model, 'eleven_turbo_v2_5' ); ?>><?php esc_html_e( 'Eleven Turbo v2.5 (Low Latency & High Quality)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
												<option value="eleven_monolingual_v1" <?php selected( $elevenlabs_model, 'eleven_monolingual_v1' ); ?>><?php esc_html_e( 'Eleven Monolingual v1 (Legacy English)', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
												<option value="custom" <?php selected( $elevenlabs_model, 'custom' ); ?>><?php esc_html_e( 'Custom model ID...', 'shopwalker-ai-voice-chat-assistant' ); ?></option>
											</select>
											<div id="aiva_elevenlabs_custom_wrap" class="aiva-custom-model-wrap" style="flex: 1; <?php echo ( $elevenlabs_model === 'custom' ) ? '' : 'display: none;'; ?>">
												<input type="text" name="aiva_elevenlabs_custom_model" value="<?php echo esc_attr( $elevenlabs_custom ); ?>" placeholder="e.g. eleven_turbo_v2" class="regular-text" style="width: 100%; margin: 0;" />
											</div>
										</div>
									</div>
								</div>

								<!-- Row 3: ElevenLabs Voice Selection Dropdown & Custom ID -->
								<?php
								$preset_voices   = array(
									'21m00Tcm4TlvDq8ikWAM' => __( 'Rachel — Calm & Professional Female (Default)', 'shopwalker-ai-voice-chat-assistant' ),
									'EXAVITQu4vr4xnSDxMaL' => __( 'Bella — Soft & Warm Female', 'shopwalker-ai-voice-chat-assistant' ),
									'pNInz6obpgDQGcFmaJgB' => __( 'Adam — Conversational Male', 'shopwalker-ai-voice-chat-assistant' ),
									'ErXwobaYiN019PkySvjV' => __( 'Antoni — Warm & Friendly Male', 'shopwalker-ai-voice-chat-assistant' ),
									'VR6AewLTigWG4xSOukaG' => __( 'Arnold — Crisp & Deep Male', 'shopwalker-ai-voice-chat-assistant' ),
									'2EiwWnXFnvU5JabPnv8n' => __( 'Clyde — Deep Conversational Male', 'shopwalker-ai-voice-chat-assistant' ),
									'IKne3meq5aSn9XLyUdCD' => __( 'Charlie — Natural Male', 'shopwalker-ai-voice-chat-assistant' ),
									'custom'               => __( 'Custom Voice ID (Enter ID manually from Voice Library)', 'shopwalker-ai-voice-chat-assistant' ),
								);
								$selected_preset = isset( $preset_voices[ $elevenlabs_voice ] ) ? $elevenlabs_voice : 'custom';
								?>
								<!-- Row 3: ElevenLabs Voice Selection Dropdown & Custom ID -->
								<div class="aiva-credential-row">
									<div class="aiva-cred-label-col">
										<strong>
											<span class="dashicons dashicons-microphone" style="color: #0d9488;"></span>
											<?php esc_html_e( 'ElevenLabs Voice Preset', 'shopwalker-ai-voice-chat-assistant' ); ?>
										</strong>
										<a href="https://elevenlabs.io/app/voice-library" target="_blank" class="aiva-get-key-link"><?php esc_html_e( 'Voice Library', 'shopwalker-ai-voice-chat-assistant' ); ?> <span class="dashicons dashicons-external"></span></a>
									</div>
									<div class="aiva-cred-input-col">
										<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 4px;">
											<select id="aiva_voice_preset_select" class="aiva-select-admin-full" style="margin-bottom: 0;">
												<?php foreach ( $preset_voices as $voice_key => $voice_name ) : ?>
													<option value="<?php echo esc_attr( $voice_key ); ?>" <?php selected( $selected_preset, $voice_key ); ?>>
														<?php echo esc_html( $voice_name ); ?>
													</option>
												<?php endforeach; ?>
											</select>
											<input type="text" name="aiva_elevenlabs_voice_id" id="aiva_elevenlabs_voice_id" value="<?php echo esc_attr( $elevenlabs_voice ); ?>" placeholder="Voice ID: 21m00Tcm4..." class="aiva-full-select" style="margin-bottom: 0;" />
										</div>
										<p class="description" style="margin-top: 4px; margin-bottom: 0;"><?php esc_html_e( 'Select a pre-made ElevenLabs voice or enter a custom Voice ID from your ElevenLabs library.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
									</div>
								</div>

								<!-- Row 4: Voice Actions & Cache Tools -->
								<div class="aiva-credential-row">
									<div class="aiva-cred-label-col">
										<strong>
											<span class="dashicons dashicons-controls-play" style="color: #0d9488;"></span>
											<?php esc_html_e( 'Voice Actions', 'shopwalker-ai-voice-chat-assistant' ); ?>
										</strong>
										<span class="description" style="font-size: 11.5px;"><?php esc_html_e( 'Test sample & cache tools', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
									</div>
									<div class="aiva-cred-input-col">
										<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
											<button type="button" id="aiva-test-voice-sample" class="button button-primary" style="width: 100%; justify-content: center;">
												<span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Listen Voice Sample', 'shopwalker-ai-voice-chat-assistant' ); ?>
											</button>
											<button type="button" id="aiva-clear-audio-cache" class="button button-secondary" style="width: 100%; justify-content: center;">
												<span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Purge Audio Cache', 'shopwalker-ai-voice-chat-assistant' ); ?>
											</button>
										</div>
										<div id="aiva-voice-sample-feedback" class="aiva-api-feedback"></div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- TAB: Content Selection -->
					<div id="tab-content" class="aiva-settings-panel <?php echo ( $active_tab === 'content' ) ? 'active' : ''; ?>">
						<h2><?php esc_html_e( 'Content Selection', 'shopwalker-ai-voice-chat-assistant' ); ?></h2>
						<p><?php esc_html_e( 'Select which database content types and elements are compiled into the AI knowledge base.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
						
						<div class="aiva-form-section">
							<h3><?php esc_html_e( 'Enable Post Types', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>
							<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
								<?php
								$post_types = get_post_types( array( 'public' => true ), 'objects' );
								unset( $post_types['attachment'] );

								foreach ( $post_types as $post_type_name => $post_type_object ) {
									$is_checked = in_array( $post_type_name, $included_types, true );

									$icon = 'dashicons-admin-post';
									if ( 'page' === $post_type_name ) {
										$icon = 'dashicons-admin-page';
									} elseif ( 'product' === $post_type_name ) {
										$icon = 'dashicons-cart';
									} elseif ( 'estate_property' === $post_type_name || 'listing' === $post_type_name || 'property' === $post_type_name ) {
										$icon = 'dashicons-building';
									} elseif ( 'estate_agent' === $post_type_name || 'agent' === $post_type_name || 'owner' === $post_type_name ) {
										$icon = 'dashicons-admin-users';
									} elseif ( 'wpestate_booking' === $post_type_name || 'booking' === $post_type_name || 'event' === $post_type_name ) {
										$icon = 'dashicons-calendar-alt';
									} elseif ( 'wpestate_message' === $post_type_name || 'message' === $post_type_name ) {
										$icon = 'dashicons-email-alt';
									} elseif ( 'wp_navigation' === $post_type_name ) {
										$icon = 'dashicons-menu-alt3';
									} elseif ( 'e-floating-buttons' === $post_type_name ) {
										$icon = 'dashicons-share-alt2';
									} elseif ( 'elementor_library' === $post_type_name || 'ha_library' === $post_type_name ) {
										$icon = 'dashicons-layout';
									} elseif ( 'shop_order' === $post_type_name ) {
										$icon = 'dashicons-cart';
									} elseif ( 'shop_coupon' === $post_type_name ) {
										$icon = 'dashicons-tickets-alt';
									}
									?>
									<div class="aiva-target-card <?php echo $is_checked ? 'active' : ''; ?>">
										<span class="aiva-target-title">
											<span class="aiva-target-icon"><span class="dashicons <?php echo esc_attr( $icon ); ?>"></span></span>
											<span style="display: flex; flex-direction: column;">
												<strong><?php echo esc_html( $post_type_object->labels->name ); ?></strong>
												<small style="color: #64748b; font-size: 11px; font-weight: 400;"><?php echo esc_html( $post_type_name ); ?></small>
											</span>
										</span>
										<label class="aiva-switch">
											<input type="checkbox" name="aiva_included_types[]" value="<?php echo esc_attr( $post_type_name ); ?>" <?php checked( $is_checked ); ?> />
											<span class="aiva-slider round"></span>
										</label>
									</div>
									<?php
								}
								?>
							</div>
						</div>

						<div class="aiva-form-section">
							<h3><?php esc_html_e( 'Privacy & Content Exclusions', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>
							<p class="section-desc"><?php esc_html_e( 'Configure toggles to exclude general business elements from vector indexing.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
							
							<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
								<?php $privacy_menus = get_option( 'aiva_privacy_include_menus', '1' ) === '1'; ?>
								<div class="aiva-target-card <?php echo $privacy_menus ? 'active' : ''; ?>">
									<span class="aiva-target-title">
										<span class="aiva-target-icon"><span class="dashicons dashicons-menu-alt3"></span></span>
										<?php esc_html_e( 'Include Navigation Menus', 'shopwalker-ai-voice-chat-assistant' ); ?>
									</span>
									<label class="aiva-switch">
										<input type="checkbox" name="aiva_privacy_include_menus" value="1" <?php checked( $privacy_menus ); ?> />
										<span class="aiva-slider round"></span>
									</label>
								</div>

								<?php $privacy_faqs = get_option( 'aiva_privacy_include_faqs', '1' ) === '1'; ?>
								<div class="aiva-target-card <?php echo $privacy_faqs ? 'active' : ''; ?>">
									<span class="aiva-target-title">
										<span class="aiva-target-icon"><span class="dashicons dashicons-editor-help"></span></span>
										<?php esc_html_e( 'Include General FAQs', 'shopwalker-ai-voice-chat-assistant' ); ?>
									</span>
									<label class="aiva-switch">
										<input type="checkbox" name="aiva_privacy_include_faqs" value="1" <?php checked( $privacy_faqs ); ?> />
										<span class="aiva-slider round"></span>
									</label>
								</div>

								<?php $privacy_contact = get_option( 'aiva_privacy_include_contact', '1' ) === '1'; ?>
								<div class="aiva-target-card <?php echo $privacy_contact ? 'active' : ''; ?>">
									<span class="aiva-target-title">
										<span class="aiva-target-icon"><span class="dashicons dashicons-email-alt"></span></span>
										<?php esc_html_e( 'Include Contact Info (Phone, Email)', 'shopwalker-ai-voice-chat-assistant' ); ?>
									</span>
									<label class="aiva-switch">
										<input type="checkbox" name="aiva_privacy_include_contact" value="1" <?php checked( $privacy_contact ); ?> />
										<span class="aiva-slider round"></span>
									</label>
								</div>

								<?php $privacy_policies = get_option( 'aiva_privacy_include_policies', '1' ) === '1'; ?>
								<div class="aiva-target-card <?php echo $privacy_policies ? 'active' : ''; ?>">
									<span class="aiva-target-title">
										<span class="aiva-target-icon"><span class="dashicons dashicons-shield"></span></span>
										<?php esc_html_e( 'Include Shipping & Return Policies', 'shopwalker-ai-voice-chat-assistant' ); ?>
									</span>
									<label class="aiva-switch">
										<input type="checkbox" name="aiva_privacy_include_policies" value="1" <?php checked( $privacy_policies ); ?> />
										<span class="aiva-slider round"></span>
									</label>
								</div>
							</div>
						</div>

						<div class="aiva-form-section">
							<h3><?php esc_html_e( 'Exclude Paths', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>
							<p class="section-desc"><?php esc_html_e( 'Block indexing of specific URL paths or patterns from RAG scans.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
							
							<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 8px;">
								<span style="font-size: 12px; color: #64748b; font-weight: 500; display: inline-flex; align-items: center; gap: 4px;"><?php esc_html_e( 'Quick Insert:', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
								<button type="button" class="aiva-quick-path-btn" data-label="/checkout/" data-path="/checkout/"><?php esc_html_e( '+ /checkout/', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
								<button type="button" class="aiva-quick-path-btn" data-label="/my-account/" data-path="/my-account/"><?php esc_html_e( '+ /my-account/', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
								<button type="button" class="aiva-quick-path-btn" data-label="/favorites/" data-path="/favorites/"><?php esc_html_e( '+ /favorites/', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
								<button type="button" class="aiva-quick-path-btn" data-label="/private-*" data-path="/private-*"><?php esc_html_e( '+ /private-*', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
							</div>

							<textarea name="aiva_excluded_urls" id="aiva_excluded_urls" rows="4" class="aiva-full-textarea code" placeholder="/my-account/&#10;/checkout/&#10;/private-*"><?php echo esc_textarea( get_option( 'aiva_excluded_urls' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Add slug patterns (one per line) to block pages from RAG scans.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
						</div>
					</div>

					<!-- TAB: Widget Customization -->
					<div id="tab-widget" class="aiva-settings-panel <?php echo ( $active_tab === 'widget' ) ? 'active' : ''; ?>">
						<h2><?php esc_html_e( 'Frontend Widget Customization', 'shopwalker-ai-voice-chat-assistant' ); ?></h2>
						<p><?php esc_html_e( 'Customize how the Shopwalker floating button and popup window appear to visitors on your site.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>

						<?php $widget_enabled = '0' !== (string) get_option( 'aiva_widget_enable', '1' ); ?>
						<div class="aiva-form-section">
							<div class="aiva-target-card <?php echo $widget_enabled ? 'active' : ''; ?>">
								<span class="aiva-target-title">
									<span class="aiva-target-icon"><span class="dashicons dashicons-visibility"></span></span>
									<span style="display: flex; flex-direction: column;">
										<strong><?php esc_html_e( 'Show Voice Widget on Website', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
										<small style="color: #64748b; font-size: 11px; font-weight: 400;"><?php esc_html_e( 'Turn off to hide the widget for all visitors without losing your settings.', 'shopwalker-ai-voice-chat-assistant' ); ?></small>
									</span>
								</span>
								<label class="aiva-switch">
									<input type="hidden" name="aiva_widget_enable" value="0" />
									<input type="checkbox" name="aiva_widget_enable" value="1" <?php checked( $widget_enabled ); ?> />
									<span class="aiva-slider round"></span>
								</label>
							</div>
						</div>

						<div class="aiva-form-section">
							<h3><?php esc_html_e( 'Trigger Button Style', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>
							<p class="section-desc"><?php esc_html_e( 'Select the visual style of the floating button that visitors click to talk with the AI.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>

							<?php $current_style = get_option( 'aiva_widget_trigger_style', 'circle' ); ?>
							<div class="aiva-trigger-chips-grid">
								<div class="aiva-trigger-chip <?php echo ( $current_style === 'circle' ) ? 'active' : ''; ?>" data-style="circle">
									<div class="aiva-chip-select">
										<input type="radio" name="aiva_widget_trigger_style" value="circle" <?php checked( $current_style, 'circle' ); ?> />
										<span class="aiva-custom-radio"></span>
									</div>
									<div class="aiva-trigger-preview circle">
										<span class="dashicons dashicons-microphone"></span>
									</div>
									<div class="aiva-trigger-info">
										<strong><?php esc_html_e( 'Floating Circle', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
										<span><?php esc_html_e( 'Classic round microphone button', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
									</div>
								</div>

								<div class="aiva-trigger-chip <?php echo ( $current_style === 'pill' ) ? 'active' : ''; ?>" data-style="pill">
									<div class="aiva-chip-select">
										<input type="radio" name="aiva_widget_trigger_style" value="pill" <?php checked( $current_style, 'pill' ); ?> />
										<span class="aiva-custom-radio"></span>
									</div>
									<div class="aiva-trigger-preview pill">
										<span class="dashicons dashicons-microphone"></span>
										<span class="preview-text">Talk with AI</span>
									</div>
									<div class="aiva-trigger-info">
										<strong><?php esc_html_e( 'Floating Pill', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
										<span><?php esc_html_e( 'Expanded button with text CTA', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
									</div>
								</div>

								<div class="aiva-trigger-chip <?php echo ( $current_style === 'badge' ) ? 'active' : ''; ?>" data-style="badge">
									<div class="aiva-chip-select">
										<input type="radio" name="aiva_widget_trigger_style" value="badge" <?php checked( $current_style, 'badge' ); ?> />
										<span class="aiva-custom-radio"></span>
									</div>
									<div class="aiva-trigger-preview badge">
										<span class="preview-text">Speak with AI</span>
										<span class="dashicons dashicons-microphone"></span>
									</div>
									<div class="aiva-trigger-info">
										<strong><?php esc_html_e( 'Compact Badge', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
										<span><?php esc_html_e( 'Short text badge with icon on right', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
									</div>
								</div>
							</div>
						</div>

						<div class="aiva-form-section">
							<h3><?php esc_html_e( 'Widget Position & Alignment', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>
							<p class="section-desc"><?php esc_html_e( 'Select which side of the screen the voice widget should attach to.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>

							<?php $current_pos = get_option( 'aiva_widget_position', 'bottom-right' ); ?>
							<div class="aiva-mode-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
								<div class="aiva-mode-card <?php echo ( $current_pos === 'bottom-left' ) ? 'active' : ''; ?>" data-pos="bottom-left">
									<input type="radio" name="aiva_widget_position" value="bottom-left" <?php checked( $current_pos, 'bottom-left' ); ?> style="display: none;" />
									<div class="aiva-mode-icon"><span class="dashicons dashicons-editor-alignleft"></span></div>
									<div class="aiva-mode-text">
										<strong><?php esc_html_e( 'Bottom Left', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
										<span><?php esc_html_e( 'Attach to bottom left screen corner', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
									</div>
								</div>

								<div class="aiva-mode-card <?php echo ( $current_pos === 'bottom-right' ) ? 'active' : ''; ?>" data-pos="bottom-right">
									<input type="radio" name="aiva_widget_position" value="bottom-right" <?php checked( $current_pos, 'bottom-right' ); ?> style="display: none;" />
									<div class="aiva-mode-icon"><span class="dashicons dashicons-editor-alignright"></span></div>
									<div class="aiva-mode-text">
										<strong><?php esc_html_e( 'Bottom Right', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
										<span><?php esc_html_e( 'Attach to bottom right screen corner', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
									</div>
								</div>
							</div>
						</div>

						<div class="aiva-form-section">
							<h3><?php esc_html_e( 'Custom Content & Text Labels', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>

							<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 18px;">
								<div class="form-row" style="margin-bottom: 0;">
									<label for="aiva_widget_cta_text" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
										<span class="dashicons dashicons-tag" style="color: #0d9488;"></span>
										<strong><?php esc_html_e( 'Pill Button CTA Label', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
									</label>
									<input name="aiva_widget_cta_text" type="text" id="aiva_widget_cta_text" value="<?php echo esc_attr( get_option( 'aiva_widget_cta_text', 'Need Help? Speak with our AI Assistant' ) ); ?>" class="aiva-full-select" />
									<p class="description"><?php esc_html_e( 'The call-to-action text displayed inside the Pill Trigger button.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
								</div>

								<div class="form-row" style="margin-bottom: 0;">
									<label for="aiva_widget_title_text" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
										<span class="dashicons dashicons-heading" style="color: #0d9488;"></span>
										<strong><?php esc_html_e( 'Widget Header Title', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
									</label>
									<input name="aiva_widget_title_text" type="text" id="aiva_widget_title_text" value="<?php echo esc_attr( get_option( 'aiva_widget_title_text', 'AI Voice' ) ); ?>" class="aiva-full-select" />
									<p class="description"><?php esc_html_e( 'The main header title displayed inside the popup top bar.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
								</div>
							</div>

							<div class="form-row">
								<label for="aiva_widget_greeting_text" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
									<span class="dashicons dashicons-format-chat" style="color: #0d9488;"></span>
									<strong><?php esc_html_e( 'Welcome Greeting Message', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
								</label>
								<textarea name="aiva_widget_greeting_text" id="aiva_widget_greeting_text" rows="3" class="aiva-full-textarea" placeholder="Hello! I am your AI Voice Assistant. Click the microphone button below and speak to ask me about products, pricing, or our services!"><?php echo esc_textarea( get_option( 'aiva_widget_greeting_text', '' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Initial welcome greeting shown to visitors when they open the chat. Leave blank to use automated store greeting.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
							</div>
						</div>

						<div class="aiva-form-section">
							<h3><?php esc_html_e( 'Visitor Languages', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>
							<p class="section-desc"><?php esc_html_e( 'Choose which languages visitors can pick in the widget. The assistant listens, answers and speaks in the selected language. Voice input and browser voices depend on the visitor\'s browser and device (Chrome and Edge support the most languages); typing works in every language, and ElevenLabs multilingual voices can speak most of them.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>

							<?php
							$aiva_all_langs     = AIVA_Languages::all();
							$aiva_enabled_langs = AIVA_Languages::enabled_codes();
							$aiva_default_lang  = AIVA_Languages::default_code();
							$aiva_show_selector = '0' !== (string) get_option( 'aiva_widget_show_language_selector', '1' );
							$aiva_auto_detect   = '0' !== (string) get_option( 'aiva_widget_auto_detect_language', '1' );
							?>
							<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 16px;">
								<div class="aiva-target-card <?php echo $aiva_show_selector ? 'active' : ''; ?>">
									<span class="aiva-target-title">
										<span class="aiva-target-icon"><span class="dashicons dashicons-translation"></span></span>
										<span style="display: flex; flex-direction: column;">
											<strong><?php esc_html_e( 'Show Language Selector', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
											<small style="color: #64748b; font-size: 11px; font-weight: 400;"><?php esc_html_e( 'Off = the widget always uses the default language.', 'shopwalker-ai-voice-chat-assistant' ); ?></small>
										</span>
									</span>
									<label class="aiva-switch">
										<input type="hidden" name="aiva_widget_show_language_selector" value="0" />
										<input type="checkbox" name="aiva_widget_show_language_selector" value="1" <?php checked( $aiva_show_selector ); ?> />
										<span class="aiva-slider round"></span>
									</label>
								</div>
								<div class="aiva-target-card <?php echo $aiva_auto_detect ? 'active' : ''; ?>">
									<span class="aiva-target-title">
										<span class="aiva-target-icon"><span class="dashicons dashicons-location-alt"></span></span>
										<span style="display: flex; flex-direction: column;">
											<strong><?php esc_html_e( 'Match Visitor\'s Browser Language', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
											<small style="color: #64748b; font-size: 11px; font-weight: 400;"><?php esc_html_e( 'If it is one of the languages below, it is pre-selected.', 'shopwalker-ai-voice-chat-assistant' ); ?></small>
										</span>
									</span>
									<label class="aiva-switch">
										<input type="hidden" name="aiva_widget_auto_detect_language" value="0" />
										<input type="checkbox" name="aiva_widget_auto_detect_language" value="1" <?php checked( $aiva_auto_detect ); ?> />
										<span class="aiva-slider round"></span>
									</label>
								</div>
							</div>

							<div class="form-row" style="margin-bottom: 16px;">
								<label for="aiva_widget_default_language" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
									<span class="dashicons dashicons-star-filled" style="color: #0d9488;"></span>
									<strong><?php esc_html_e( 'Default Language', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
								</label>
								<select name="aiva_widget_default_language" id="aiva_widget_default_language" class="aiva-select-admin-full" style="max-width: 360px;">
									<?php foreach ( $aiva_all_langs as $aiva_code => $aiva_lang ) : ?>
										<option value="<?php echo esc_attr( $aiva_code ); ?>" <?php selected( $aiva_code, $aiva_default_lang ); ?>><?php echo esc_html( $aiva_lang['english'] . ( $aiva_lang['english'] !== $aiva_lang['native'] ? ' — ' . $aiva_lang['native'] : '' ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Used when the visitor has not chosen a language. It is always included in the selector.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
							</div>

							<div class="form-row">
								<label style="display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 8px;">
									<span style="display: flex; align-items: center; gap: 6px;">
										<span class="dashicons dashicons-admin-site-alt3" style="color: #0d9488;"></span>
										<strong><?php esc_html_e( 'Languages in the Selector', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
									</span>
									<span>
										<button type="button" class="button button-small aiva-lang-select-all"><?php esc_html_e( 'Select all', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
										<button type="button" class="button button-small aiva-lang-select-none"><?php esc_html_e( 'Clear', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
									</span>
								</label>
								<input type="hidden" name="aiva_widget_languages[]" value="" />
								<div class="aiva-lang-admin-grid">
									<?php foreach ( $aiva_all_langs as $aiva_code => $aiva_lang ) : ?>
										<label class="aiva-lang-admin-item">
											<input type="checkbox" name="aiva_widget_languages[]" value="<?php echo esc_attr( $aiva_code ); ?>" <?php checked( in_array( $aiva_code, $aiva_enabled_langs, true ) ); ?> />
											<span><?php echo esc_html( $aiva_lang['english'] ); ?></span>
											<?php if ( $aiva_lang['english'] !== $aiva_lang['native'] ) : ?>
												<small lang="<?php echo esc_attr( $aiva_code ); ?>"><?php echo esc_html( $aiva_lang['native'] ); ?></small>
											<?php endif; ?>
										</label>
									<?php endforeach; ?>
								</div>
							</div>
						</div>

						<div class="aiva-form-section">
							<h3><?php esc_html_e( 'Quick Reply Buttons', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>
							<p class="section-desc"><?php esc_html_e( 'Tappable suggestion buttons shown under the greeting, so visitors know what they can ask. After shopping answers, smart follow-up buttons (cheapest option, other categories) are shown too.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>

							<?php
							$qr_enabled   = '0' !== (string) get_option( 'aiva_quick_replies_enable', '1' );
							$qr_auto_cats = '0' !== (string) get_option( 'aiva_quick_replies_auto_categories', '1' );
							$qr_lines     = get_option( 'aiva_quick_replies', false );
							if ( false === $qr_lines ) {
								$qr_lines = AIVA_Widget::default_quick_replies();
							}
							?>
							<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 16px;">
								<div class="aiva-target-card <?php echo $qr_enabled ? 'active' : ''; ?>">
									<span class="aiva-target-title">
										<span class="aiva-target-icon"><span class="dashicons dashicons-format-status"></span></span>
										<?php esc_html_e( 'Show Quick Reply Buttons', 'shopwalker-ai-voice-chat-assistant' ); ?>
									</span>
									<label class="aiva-switch">
										<input type="hidden" name="aiva_quick_replies_enable" value="0" />
										<input type="checkbox" name="aiva_quick_replies_enable" value="1" <?php checked( $qr_enabled ); ?> />
										<span class="aiva-slider round"></span>
									</label>
								</div>
								<div class="aiva-target-card <?php echo $qr_auto_cats ? 'active' : ''; ?>">
									<span class="aiva-target-title">
										<span class="aiva-target-icon"><span class="dashicons dashicons-category"></span></span>
										<span style="display: flex; flex-direction: column;">
											<strong><?php esc_html_e( 'Add "Browse Category" Buttons', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
											<small style="color: #64748b; font-size: 11px; font-weight: 400;"><?php esc_html_e( 'Top 3 WooCommerce categories, updated automatically.', 'shopwalker-ai-voice-chat-assistant' ); ?></small>
										</span>
									</span>
									<label class="aiva-switch">
										<input type="hidden" name="aiva_quick_replies_auto_categories" value="0" />
										<input type="checkbox" name="aiva_quick_replies_auto_categories" value="1" <?php checked( $qr_auto_cats ); ?> />
										<span class="aiva-slider round"></span>
									</label>
								</div>
							</div>

							<div class="form-row">
								<label for="aiva_quick_replies" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
									<span class="dashicons dashicons-editor-ul" style="color: #0d9488;"></span>
									<strong><?php esc_html_e( 'Starter Buttons (one per line, max 8)', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
								</label>
								<textarea name="aiva_quick_replies" id="aiva_quick_replies" rows="5" class="aiva-full-textarea" placeholder="<?php esc_attr_e( 'Best sellers | What are your best selling products?', 'shopwalker-ai-voice-chat-assistant' ); ?>"><?php echo esc_textarea( $qr_lines ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Format: Button label | Question sent to the assistant. If you leave out the "|" part, the label itself is sent. Leave empty to show only the category buttons.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
							</div>
						</div>

						<div class="aiva-form-section">
							<h3><?php esc_html_e( 'Widget Visibility & Page Display Rules', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>
							<p class="section-desc"><?php esc_html_e( 'Control exactly which pages and post types display the floating AI Voice Assistant widget.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>

							<?php
							$display_mode = get_option( 'aiva_widget_display_mode', 'all' );
							$target_types = get_option( 'aiva_widget_target_post_types', array( 'home', 'page', 'post', 'listing', 'estate_property', 'product' ) );
							if ( ! is_array( $target_types ) ) {
								$target_types = array();
							}
							$excluded_slugs = get_option( 'aiva_widget_excluded_slugs', '' );
							?>

							<div class="form-row" style="margin-bottom: 22px;">
								<label style="display: block; margin-bottom: 10px;"><strong><?php esc_html_e( 'Display Mode', 'shopwalker-ai-voice-chat-assistant' ); ?></strong></label>
								<div class="aiva-mode-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
									<div class="aiva-mode-card <?php echo ( $display_mode === 'all' ) ? 'active' : ''; ?>" data-mode="all">
										<input type="radio" name="aiva_widget_display_mode" value="all" <?php checked( $display_mode, 'all' ); ?> style="display: none;" />
										<div class="aiva-mode-icon"><span class="dashicons dashicons-admin-site-alt3"></span></div>
										<div class="aiva-mode-text">
											<strong><?php esc_html_e( 'All Pages', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
											<span><?php esc_html_e( 'Show everywhere', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
										</div>
									</div>

									<div class="aiva-mode-card <?php echo ( $display_mode === 'include' ) ? 'active' : ''; ?>" data-mode="include">
										<input type="radio" name="aiva_widget_display_mode" value="include" <?php checked( $display_mode, 'include' ); ?> style="display: none;" />
										<div class="aiva-mode-icon"><span class="dashicons dashicons-filter"></span></div>
										<div class="aiva-mode-text">
											<strong><?php esc_html_e( 'Targeted Pages', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
											<span><?php esc_html_e( 'Selected types only', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
										</div>
									</div>

									<div class="aiva-mode-card <?php echo ( $display_mode === 'exclude' ) ? 'active' : ''; ?>" data-mode="exclude">
										<input type="radio" name="aiva_widget_display_mode" value="exclude" <?php checked( $display_mode, 'exclude' ); ?> style="display: none;" />
										<div class="aiva-mode-icon"><span class="dashicons dashicons-hidden"></span></div>
										<div class="aiva-mode-text">
											<strong><?php esc_html_e( 'Hide Excluded', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
											<span><?php esc_html_e( 'Hide on selected types', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
										</div>
									</div>
								</div>
							</div>

							<div class="form-row" style="margin-bottom: 22px;">
								<label style="display: block; margin-bottom: 10px;"><strong><?php esc_html_e( 'Target Content Types', 'shopwalker-ai-voice-chat-assistant' ); ?></strong></label>
								<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;">
									<?php $is_home = in_array( 'home', $target_types, true ); ?>
									<div class="aiva-target-card <?php echo $is_home ? 'active' : ''; ?>">
										<span class="aiva-target-title">
											<span class="aiva-target-icon"><span class="dashicons dashicons-admin-home"></span></span>
											<?php esc_html_e( 'Homepage', 'shopwalker-ai-voice-chat-assistant' ); ?>
										</span>
										<label class="aiva-switch">
											<input type="checkbox" name="aiva_widget_target_post_types[]" value="home" <?php checked( $is_home ); ?> />
											<span class="aiva-slider round"></span>
										</label>
									</div>

									<?php $is_page = in_array( 'page', $target_types, true ); ?>
									<div class="aiva-target-card <?php echo $is_page ? 'active' : ''; ?>">
										<span class="aiva-target-title">
											<span class="aiva-target-icon"><span class="dashicons dashicons-admin-page"></span></span>
											<?php esc_html_e( 'Pages', 'shopwalker-ai-voice-chat-assistant' ); ?>
										</span>
										<label class="aiva-switch">
											<input type="checkbox" name="aiva_widget_target_post_types[]" value="page" <?php checked( $is_page ); ?> />
											<span class="aiva-slider round"></span>
										</label>
									</div>

									<?php $is_post = in_array( 'post', $target_types, true ); ?>
									<div class="aiva-target-card <?php echo $is_post ? 'active' : ''; ?>">
										<span class="aiva-target-title">
											<span class="aiva-target-icon"><span class="dashicons dashicons-admin-post"></span></span>
											<?php esc_html_e( 'Posts', 'shopwalker-ai-voice-chat-assistant' ); ?>
										</span>
										<label class="aiva-switch">
											<input type="checkbox" name="aiva_widget_target_post_types[]" value="post" <?php checked( $is_post ); ?> />
											<span class="aiva-slider round"></span>
										</label>
									</div>

									<?php $is_listing = in_array( 'listing', $target_types, true ) || in_array( 'estate_property', $target_types, true ); ?>
									<div class="aiva-target-card <?php echo $is_listing ? 'active' : ''; ?>">
										<span class="aiva-target-title">
											<span class="aiva-target-icon"><span class="dashicons dashicons-building"></span></span>
											<?php esc_html_e( 'Listings', 'shopwalker-ai-voice-chat-assistant' ); ?>
										</span>
										<label class="aiva-switch">
											<input type="checkbox" name="aiva_widget_target_post_types[]" value="listing" <?php checked( $is_listing ); ?> />
											<span class="aiva-slider round"></span>
										</label>
									</div>

									<?php $is_product = in_array( 'product', $target_types, true ); ?>
									<div class="aiva-target-card <?php echo $is_product ? 'active' : ''; ?>">
										<span class="aiva-target-title">
											<span class="aiva-target-icon"><span class="dashicons dashicons-cart"></span></span>
											<?php esc_html_e( 'Products', 'shopwalker-ai-voice-chat-assistant' ); ?>
										</span>
										<label class="aiva-switch">
											<input type="checkbox" name="aiva_widget_target_post_types[]" value="product" <?php checked( $is_product ); ?> />
											<span class="aiva-slider round"></span>
										</label>
									</div>
								</div>
							</div>

							<div class="form-row">
								<label for="aiva_widget_excluded_slugs" style="display: block; margin-bottom: 8px;"><strong><?php esc_html_e( 'Exclude Specific Page Slugs or IDs', 'shopwalker-ai-voice-chat-assistant' ); ?></strong></label>
								<textarea name="aiva_widget_excluded_slugs" id="aiva_widget_excluded_slugs" rows="3" class="aiva-full-textarea code" placeholder="checkout&#10;my-account&#10;privacy-policy"><?php echo esc_textarea( $excluded_slugs ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Enter page slugs or IDs (one per line or comma-separated) to ALWAYS hide the voice widget on those specific pages.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
							</div>
						</div>
					</div>

					<!-- TAB: Business Profile -->
					<div id="tab-profile" class="aiva-settings-panel <?php echo ( $active_tab === 'profile' ) ? 'active' : ''; ?>">
						<h2><?php esc_html_e( 'Business Information Profile', 'shopwalker-ai-voice-chat-assistant' ); ?></h2>
						<p><?php esc_html_e( 'Fill out key details about your business. The AI uses this data directly to answer business-related FAQs.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
						
						<div class="aiva-form-section">
							<!-- Row 1: Company Name & Working Hours -->
							<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 18px;">
								<div class="form-row" style="margin-bottom: 0;">
									<label for="aiva_business_name" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
										<span class="dashicons dashicons-building" style="color: #0d9488;"></span>
										<strong><?php esc_html_e( 'Company Name', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
									</label>
									<input name="aiva_business_name" type="text" id="aiva_business_name" value="<?php echo esc_attr( get_option( 'aiva_business_name', get_bloginfo( 'name' ) ) ); ?>" class="aiva-full-select" />
								</div>
								<div class="form-row" style="margin-bottom: 0;">
									<label for="aiva_business_hours" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
										<span class="dashicons dashicons-clock" style="color: #0d9488;"></span>
										<strong><?php esc_html_e( 'Working Hours', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
									</label>
									<input name="aiva_business_hours" type="text" id="aiva_business_hours" value="<?php echo esc_attr( get_option( 'aiva_business_hours' ) ); ?>" class="aiva-full-select" placeholder="Mon-Fri: 9:00 AM - 6:00 PM (GST)" />
								</div>
							</div>

							<!-- Row 2: Phone & Email -->
							<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 18px;">
								<div class="form-row" style="margin-bottom: 0;">
									<label for="aiva_business_phone" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
										<span class="dashicons dashicons-phone" style="color: #0d9488;"></span>
										<strong><?php esc_html_e( 'Company Phone', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
									</label>
									<input name="aiva_business_phone" type="text" id="aiva_business_phone" value="<?php echo esc_attr( get_option( 'aiva_business_phone' ) ); ?>" class="aiva-full-select" placeholder="+971 52 255 5504" />
								</div>
								<div class="form-row" style="margin-bottom: 0;">
									<label for="aiva_business_email" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
										<span class="dashicons dashicons-email-alt" style="color: #0d9488;"></span>
										<strong><?php esc_html_e( 'Company Email', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
									</label>
									<input name="aiva_business_email" type="text" id="aiva_business_email" value="<?php echo esc_attr( get_option( 'aiva_business_email' ) ); ?>" class="aiva-full-select" placeholder="support@example.com" />
								</div>
							</div>

							<!-- Row 3: Address -->
							<div class="form-row">
								<label for="aiva_business_address" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
									<span class="dashicons dashicons-location-alt" style="color: #0d9488;"></span>
									<strong><?php esc_html_e( 'Company Address', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
								</label>
								<input name="aiva_business_address" type="text" id="aiva_business_address" value="<?php echo esc_attr( get_option( 'aiva_business_address' ) ); ?>" class="aiva-full-select" placeholder="OnsStay FZCO - Port Saeed Dubai, United Arab Emirates" />
							</div>

							<!-- Row 4: Business Description -->
							<div class="form-row">
								<label for="aiva_business_desc" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
									<span class="dashicons dashicons-format-aside" style="color: #0d9488;"></span>
									<strong><?php esc_html_e( 'Business Description', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
								</label>
								<textarea name="aiva_business_desc" id="aiva_business_desc" rows="3" class="aiva-full-textarea" placeholder="Discover handpicked apartments, villas, and unique homes. Experience Arabian hospitality with every booking."><?php echo esc_textarea( get_option( 'aiva_business_desc' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'A brief overview of your business niche, mission, or products.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
							</div>

							<!-- Row 5: Services & FAQs side-by-side -->
							<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-top: 18px;">
								<div class="form-row" style="margin-bottom: 0;">
									<label for="aiva_business_services" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
										<span class="dashicons dashicons-clipboard" style="color: #0d9488;"></span>
										<strong><?php esc_html_e( 'Services / Shipping Policies', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
									</label>
									<textarea name="aiva_business_services" id="aiva_business_services" rows="4" class="aiva-full-textarea" placeholder="- Free standard shipping on orders over $50.&#10;- Fast overnight delivery options.&#10;- 30-day money back returns."><?php echo esc_textarea( get_option( 'aiva_business_services' ) ); ?></textarea>
								</div>

								<div class="form-row" style="margin-bottom: 0;">
									<label for="aiva_business_faqs" style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
										<span class="dashicons dashicons-editor-help" style="color: #0d9488;"></span>
										<strong><?php esc_html_e( 'General FAQ Items', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
									</label>
									<textarea name="aiva_business_faqs" id="aiva_business_faqs" rows="4" class="aiva-full-textarea" placeholder="Q: Are your materials sustainable?&#10;A: Yes, 100% of our apparel uses organic cotton."><?php echo esc_textarea( get_option( 'aiva_business_faqs' ) ); ?></textarea>
								</div>
							</div>
						</div>

						<!-- Important Instructions -->
						<div class="aiva-form-section">
							<h3><?php esc_html_e( 'Important Instructions', 'shopwalker-ai-voice-chat-assistant' ); ?></h3>
							<p class="section-desc"><?php esc_html_e( 'Configure behavior rules, promotional guidelines, or tone instructions for the voice agent.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
							<div class="form-row">
								<label for="aiva_business_instructions" style="display: flex; align-items: center; gap: 6px; margin-bottom: 8px;">
									<span class="dashicons dashicons-lightbulb" style="color: #0d9488;"></span>
									<strong><?php esc_html_e( 'AI Behavior Guidelines', 'shopwalker-ai-voice-chat-assistant' ); ?></strong>
								</label>

								<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px;">
									<span style="font-size: 12px; color: #64748b; font-weight: 500; display: inline-flex; align-items: center; gap: 4px;"><?php esc_html_e( 'Persona Templates:', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
									<button type="button" class="aiva-persona-btn" data-label="Friendly & Welcoming" data-persona="Greet visitors warmly and keep explanations friendly, patient and welcoming. Only mention discounts or offers that appear in the website information."><?php esc_html_e( '+ Friendly & Welcoming', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
									<button type="button" class="aiva-persona-btn" data-label="Concise & Direct" data-persona="Be extremely concise, direct, and factual. Limit responses to 2-3 sentences max."><?php esc_html_e( '+ Concise & Direct', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
									<button type="button" class="aiva-persona-btn" data-label="Sales & Lead Capture" data-persona="When a visitor is interested in buying or booking, guide them to the relevant product or page and invite them to contact our team using the phone number, email or contact page listed in the business information."><?php esc_html_e( '+ Sales & Lead Capture', 'shopwalker-ai-voice-chat-assistant' ); ?></button>
								</div>

								<textarea name="aiva_business_instructions" id="aiva_business_instructions" rows="5" class="aiva-full-textarea" placeholder="e.g. Always mention free shipping on orders over $50. Recommend the bundle deals page when visitors ask about saving money."><?php echo esc_textarea( get_option( 'aiva_business_instructions' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Special instructions fed directly into the system prompt of the active AI model to shape the chatbot\'s personality.', 'shopwalker-ai-voice-chat-assistant' ); ?></p>
							</div>
						</div>
					</div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Display standard review reminder notice in WordPress Admin.
	 */
	public function display_review_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if permanently dismissed
		if ( get_option( 'aiva_review_notice_dismissed' ) ) {
			return;
		}

		// Check if temporarily dismissed
		$temp_dismiss = get_option( 'aiva_review_notice_temp_dismiss' );
		if ( $temp_dismiss && time() < $temp_dismiss ) {
			return;
		}

		// Get first activation time
		$activation_time = get_option( 'aiva_first_activation_time' );
		if ( ! $activation_time ) {
			$activation_time = time();
			update_option( 'aiva_first_activation_time', $activation_time, false );
		}

		// Show notice only after 3 days of activation
		if ( time() - $activation_time < 3 * DAY_IN_SECONDS ) {
			return;
		}

		// Also check if they have scanned some database records to know they are using the plugin
		if ( $this->get_total_chunks() === 0 ) {
			return;
		}

		?>
		<div class="notice notice-info is-dismissible aiva-review-notice" style="position: relative; padding-right: 38px;">
			<p style="font-size: 14px; line-height: 1.5; margin-top: 15px;">
				<strong><?php esc_html_e( 'Loving Shopwalker?', 'shopwalker-ai-voice-chat-assistant' ); ?></strong><br>
				<?php esc_html_e( 'It looks like you have successfully set up your voice agent knowledge base! If you find it helpful, please consider leaving us a 5-star review on WordPress.org. It helps us support and improve the plugin.', 'shopwalker-ai-voice-chat-assistant' ); ?>
			</p>
			<p style="margin-bottom: 15px;">
				<a href="https://wordpress.org/support/plugin/shopwalker-ai-voice-chat-assistant/reviews/#new-post" target="_blank" class="button button-primary aiva-dismiss-review" data-dismiss="permanent"><?php esc_html_e( 'Leave a Review', 'shopwalker-ai-voice-chat-assistant' ); ?></a>
				<a href="#" class="button button-secondary aiva-dismiss-review" data-dismiss="temporary" style="margin-left: 10px;"><?php esc_html_e( 'Maybe Later', 'shopwalker-ai-voice-chat-assistant' ); ?></a>
				<a href="#" class="aiva-dismiss-review" data-dismiss="permanent" style="margin-left: 15px; text-decoration: none; vertical-align: middle; color: #64748b; font-size: 13px;"><?php esc_html_e( 'No thanks, don\'t show this again', 'shopwalker-ai-voice-chat-assistant' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * AJAX callback to dismiss the review notice.
	 */
	public function ajax_dismiss_review_notice() {
		check_ajax_referer( 'aiva_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$dismiss_type = isset( $_POST['dismiss_type'] ) ? sanitize_text_field( wp_unslash( $_POST['dismiss_type'] ) ) : 'permanent';

		if ( 'temporary' === $dismiss_type ) {
			// Dismiss for 14 days
			update_option( 'aiva_review_notice_temp_dismiss', time() + 14 * DAY_IN_SECONDS );
		} else {
			// Dismiss permanently
			update_option( 'aiva_review_notice_dismissed', 1 );
		}

		wp_send_json_success();
	}

	/**
	 * Register Meta Box on public edit screens (Posts, Pages, Products, Listings).
	 */
	public function add_indexing_meta_box() {
		$post_types = get_post_types( array( 'public' => true ) );
		foreach ( $post_types as $pt ) {
			add_meta_box(
				'aiva_indexing_meta_box',
				'<span class="dashicons dashicons-microphone" style="color: #0d9488; margin-right: 6px; font-size: 17px; width: 17px; height: 17px; vertical-align: text-bottom;"></span>' . __( 'AI Voice Index', 'shopwalker-ai-voice-chat-assistant' ),
				array( $this, 'render_indexing_meta_box' ),
				$pt,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render the Meta Box UI on edit screens.
	 */
	public function render_indexing_meta_box( $post ) {
		global $wpdb;
		$table_name  = esc_sql( $wpdb->prefix . 'aiva_knowledge_base' );
		$chunk_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE post_id = %d", $post->ID ) );
		$auto_index  = get_post_meta( $post->ID, '_aiva_auto_index', true );
		if ( '' === $auto_index ) {
			$auto_index = '1'; // Enabled by default
		}
		wp_nonce_field( 'aiva_meta_box_nonce', 'aiva_meta_box_nonce' );
		?>
		<div style="font-family: var(--aiva-font-primary); font-size: 13px; padding-top: 4px;">
			<div style="margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
				<span style="font-weight: 600; color: #475569; font-size: 12px;"><?php esc_html_e( 'Index Status:', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
				<?php if ( $chunk_count > 0 ) : ?>
					<span style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 12px; display: inline-flex; align-items: center; gap: 5px;">
						<span style="width: 6px; height: 6px; background: #16a34a; border-radius: 50%; display: inline-block;"></span>
						<?php
						/* translators: %d: number of indexed text chunks. */
						printf( esc_html__( 'Indexed (%d Chunks)', 'shopwalker-ai-voice-chat-assistant' ), (int) $chunk_count );
						?>
					</span>
				<?php else : ?>
					<span style="background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 12px;">
						<?php esc_html_e( 'Not Indexed', 'shopwalker-ai-voice-chat-assistant' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<div style="margin-bottom: 14px;">
				<button type="button" id="aiva-single-index-btn" class="button button-primary" data-post-id="<?php echo esc_attr( $post->ID ); ?>" style="width: 100%; background: #0d9488 !important; border: 1px solid #0d9488 !important; color: #ffffff !important; font-weight: 600; border-radius: 8px; padding: 6px 12px; height: 36px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; box-shadow: 0 2px 6px rgba(13,148,136,0.25);">
					<span class="dashicons dashicons-update" style="font-size: 14px; width: 14px; height: 14px; line-height: 14px; color: #ffffff;"></span>
					<span style="color: #ffffff;"><?php esc_html_e( 'Index / Re-index Now', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
				</button>
				<div id="aiva-single-index-feedback" style="display: none; margin-top: 8px; font-size: 11px; padding: 8px 10px; border-radius: 6px; line-height: 1.4; word-break: break-word; overflow-wrap: anywhere; word-wrap: break-word; max-width: 100%; box-sizing: border-box; max-height: 140px; overflow-y: auto;"></div>
			</div>

			<label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 12px; color: #475569; font-weight: 500;">
				<input type="checkbox" name="aiva_auto_index" value="1" <?php checked( $auto_index, '1' ); ?> style="accent-color: #0d9488; width: 16px; height: 16px; cursor: pointer;" />
				<span><?php esc_html_e( 'Auto-index vectors on Save / Update', 'shopwalker-ai-voice-chat-assistant' ); ?></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Save the per-item "Auto-index on Save / Update" toggle from the meta box.
	 * The actual (background) indexing is handled by AIVA_Listener, which reads this toggle.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function auto_index_on_post_save( $post_id, $post ) {
		unset( $post );

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['aiva_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aiva_meta_box_nonce'] ) ), 'aiva_meta_box_nonce' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$auto_index = isset( $_POST['aiva_auto_index'] ) ? '1' : '0';
		update_post_meta( $post_id, '_aiva_auto_index', $auto_index );
	}

	/**
	 * AJAX callback to index a single post/listing from the Meta Box.
	 */
	public function ajax_index_single_post() {
		check_ajax_referer( 'aiva_admin_nonce', 'security' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			wp_send_json_error( __( 'Invalid item ID.', 'shopwalker-ai-voice-chat-assistant' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'shopwalker-ai-voice-chat-assistant' ) );
		}

		if ( '' === (string) get_option( 'aiva_gemini_api_key', '' ) ) {
			wp_send_json_error( __( 'Please add a Google Gemini API key first (it is used to create the search index).', 'shopwalker-ai-voice-chat-assistant' ) );
		}

		$res = AIVA_Scanner::scan_item( $post_id );
		if ( ! empty( $res['success'] ) ) {
			global $wpdb;
			$table_name = esc_sql( $wpdb->prefix . 'aiva_knowledge_base' );
			$chunks     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE post_id = %d", $post_id ) );
			if ( 0 === $chunks ) {
				$skip_msg = ( isset( $res['logs'][0] ) ) ? $res['logs'][0] : __( 'Nothing was indexed for this item.', 'shopwalker-ai-voice-chat-assistant' );
				wp_send_json_error( $skip_msg );
			}
			wp_send_json_success(
				array(
					/* translators: %d: number of stored text chunks. */
					'message' => sprintf( __( 'Indexed successfully! (%d Chunks stored)', 'shopwalker-ai-voice-chat-assistant' ), $chunks ),
					'chunks'  => $chunks,
				)
			);
		} else {
			$err_msg = ! empty( $res['error'] ) ? $res['error'] : ( ( isset( $res['logs'] ) && is_array( $res['logs'] ) && isset( $res['logs'][0] ) ) ? $res['logs'][0] : __( 'Error indexing item.', 'shopwalker-ai-voice-chat-assistant' ) );
			wp_send_json_error( $err_msg );
		}
	}
}