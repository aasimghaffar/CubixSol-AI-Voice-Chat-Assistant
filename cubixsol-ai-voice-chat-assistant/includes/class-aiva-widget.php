<?php
/**
 * Front-end voice widget.
 *
 * @package Cubixsol_AI_Voice_Chat_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Renders the floating voice agent widget.
 */
class AIVA_Widget {
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_voice_widget' ) );
	}

	/**
	 * Whether the widget should be shown on the current request.
	 *
	 * @return bool
	 */
	public function should_display_widget() {
		// Master on/off switch (enabled unless explicitly turned off).
		if ( '0' === (string) get_option( 'aiva_widget_enable', '1' ) ) {
			return false;
		}

		$display_mode       = get_option( 'aiva_widget_display_mode', 'all' );
		$excluded_slugs_raw = get_option( 'aiva_widget_excluded_slugs', '' );

		// 1. Always evaluate excluded slugs / IDs first
		if ( ! empty( $excluded_slugs_raw ) ) {
			$excluded_slugs = array_filter( array_map( 'trim', explode( "\n", str_replace( ',', "\n", $excluded_slugs_raw ) ) ) );
			global $post;
			$current_slug = is_singular() && $post ? $post->post_name : '';
			$current_id   = is_singular() && $post ? (string) $post->ID : '';

			foreach ( $excluded_slugs as $slug ) {
				if ( empty( $slug ) ) {
					continue;
				}
				if ( is_page( $slug ) || is_single( $slug ) || $current_slug === $slug || $current_id === $slug ) {
					return false;
				}
				$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
				if ( 0 === strpos( $slug, '/' ) && '' !== $request_uri && false !== strpos( $request_uri, $slug ) ) {
					return false;
				}
			}
		}

		if ( 'all' === $display_mode ) {
			return true;
		}

		$target_types = get_option( 'aiva_widget_target_post_types', array( 'home', 'page', 'post', 'listing', 'estate_property', 'product' ) );
		if ( ! is_array( $target_types ) ) {
			$target_types = array();
		}

		$is_match = false;
		if ( in_array( 'home', $target_types, true ) && ( is_front_page() || is_home() ) ) {
			$is_match = true;
		}
		if ( is_singular() ) {
			$post_type = get_post_type();
			if ( in_array( $post_type, $target_types, true ) || ( 'listing' === $post_type && in_array( 'estate_property', $target_types, true ) ) || ( 'estate_property' === $post_type && in_array( 'listing', $target_types, true ) ) ) {
				$is_match = true;
			}
		}
		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			if ( is_array( $post_type ) ) {
				$post_type = reset( $post_type );
			}
			if ( in_array( $post_type, $target_types, true ) ) {
				$is_match = true;
			}
		}

		if ( 'include' === $display_mode ) {
			return $is_match;
		}
		if ( 'exclude' === $display_mode ) {
			return ! $is_match;
		}

		return true;
	}

	/**
	 * Enqueue front-end assets.
	 *
	 * @return void
	 */
	public function enqueue_public_assets() {
		if ( ! $this->should_display_widget() ) {
			return;
		}

		// Enqueue styles.
		wp_enqueue_style( 'aiva-fonts', AIVA_URL . 'assets/fonts/fonts.css', array(), AIVA_VERSION );
		wp_enqueue_style( 'aiva-public-css', AIVA_URL . 'public/css/public-style.css', array( 'aiva-fonts' ), AIVA_VERSION );

		// Enqueue Dashicons so icons render properly on frontend.
		wp_enqueue_style( 'dashicons' );

		// Enqueue JS.
		wp_enqueue_script( 'aiva-public-js', AIVA_URL . 'public/js/public-script.js', array( 'jquery' ), AIVA_VERSION, true );

		// Localize variables to javascript.
		wp_localize_script(
			'aiva-public-js',
			'aivaPublic',
			array(
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'aiva_public_nonce' ),
				'bot_name'  => get_option( 'aiva_business_name', get_bloginfo( 'name' ) ) . ' Voice Agent',
				'debug'     => ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
				'languages' => array(
					'enabled'    => AIVA_Languages::enabled_codes(),
					'default'    => AIVA_Languages::default_code(),
					'autoDetect' => '0' !== (string) get_option( 'aiva_widget_auto_detect_language', '1' ),
					'rtl'        => array_keys( array_filter( wp_list_pluck( AIVA_Languages::all(), 'rtl' ) ) ),
				),
				'i18n'      => array(
					'openPage'     => __( 'Open page', 'cubixsol-ai-voice-chat-assistant' ),
					'viewProduct'  => __( 'View Product', 'cubixsol-ai-voice-chat-assistant' ),
					'connectError' => __( 'Sorry, I could not connect to the server.', 'cubixsol-ai-voice-chat-assistant' ),
					'genericError' => __( 'An unexpected error occurred.', 'cubixsol-ai-voice-chat-assistant' ),
					'noVoice'      => __( 'Spoken replies are not available for this language on this device, so the answer is shown as text.', 'cubixsol-ai-voice-chat-assistant' ),
					'noSpeechLang' => __( 'Voice input may not support this language in your browser. You can type your question instead.', 'cubixsol-ai-voice-chat-assistant' ),
				),
			)
		);
	}

	/**
	 * Default quick reply lines ("Label | message sent").
	 *
	 * @return string
	 */
	public static function default_quick_replies() {
		return implode(
			"\n",
			array(
				__( 'What do you sell? | What products do you sell?', 'cubixsol-ai-voice-chat-assistant' ),
				__( 'Show all categories | What product categories do you have?', 'cubixsol-ai-voice-chat-assistant' ),
				__( 'Shipping & delivery | What are your shipping and delivery options?', 'cubixsol-ai-voice-chat-assistant' ),
				__( 'Contact us | How can I contact you?', 'cubixsol-ai-voice-chat-assistant' ),
			)
		);
	}

	/**
	 * Whether quick reply buttons are enabled.
	 *
	 * @return bool
	 */
	public static function quick_replies_enabled() {
		return '0' !== (string) get_option( 'aiva_quick_replies_enable', '1' );
	}

	/**
	 * Parse the quick reply setting into buttons.
	 *
	 * Each line is "Label | Message sent to the agent"; without "|" the label is also the message.
	 *
	 * @return array List of array( 'label' => ..., 'message' => ... ).
	 */
	public static function get_quick_replies() {
		if ( ! self::quick_replies_enabled() ) {
			return array();
		}

		$raw = get_option( 'aiva_quick_replies', false );
		if ( false === $raw ) {
			$raw = self::default_quick_replies();
		}

		$replies = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts   = array_map( 'trim', explode( '|', $line, 2 ) );
			$label   = mb_substr( $parts[0], 0, 40 );
			$message = ( isset( $parts[1] ) && '' !== $parts[1] ) ? mb_substr( $parts[1], 0, 200 ) : $label;
			if ( '' !== $label ) {
				$replies[] = array(
					'label'   => $label,
					'message' => $message,
				);
			}
		}

		// Optional "Browse <category>" buttons from the store's biggest WooCommerce categories.
		if ( '0' !== (string) get_option( 'aiva_quick_replies_auto_categories', '1' ) && class_exists( 'AIVA_Catalog' ) && AIVA_Catalog::is_enabled() ) {
			foreach ( array_slice( AIVA_Catalog::get_categories(), 0, 3 ) as $term ) {
				$name      = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
				$replies[] = array(
					/* translators: %s: product category name. */
					'label'   => sprintf( __( 'Browse %s', 'cubixsol-ai-voice-chat-assistant' ), $name ),
					/* translators: %s: product category name. */
					'message' => sprintf( __( 'Show me products in %s', 'cubixsol-ai-voice-chat-assistant' ), $name ),
				);
			}
		}

		/**
		 * Filter the starter quick reply buttons.
		 *
		 * @param array $replies List of array( 'label' => ..., 'message' => ... ).
		 */
		$replies = (array) apply_filters( 'aiva_quick_replies', $replies );
		return array_slice( $replies, 0, 8 );
	}

	/**
	 * Output the widget markup in the footer.
	 *
	 * @return void
	 */
	public function render_voice_widget() {
		if ( ! $this->should_display_widget() ) {
			return;
		}

		$store_name    = get_option( 'aiva_business_name', get_bloginfo( 'name' ) );
		$trigger_style = get_option( 'aiva_widget_trigger_style', 'circle' );
		$position      = get_option( 'aiva_widget_position', 'bottom-right' );
		$cta_text      = get_option( 'aiva_widget_cta_text', __( 'Need Help? Speak with our AI Assistant', 'cubixsol-ai-voice-chat-assistant' ) );
		$title_text    = get_option( 'aiva_widget_title_text', __( 'AI Voice', 'cubixsol-ai-voice-chat-assistant' ) );
		$greeting_text = get_option( 'aiva_widget_greeting_text', '' );

		if ( empty( $greeting_text ) ) {
			/* translators: %s: store name. */
			$greeting_text = sprintf( __( 'Hello! I am your AI Voice Assistant for <strong>%s</strong>. Click the microphone button below and speak to ask me about products, pricing, or our services!', 'cubixsol-ai-voice-chat-assistant' ), esc_html( $store_name ) );
		}

		$pos_class     = ( 'bottom-left' === $position ) ? 'aiva-pos-bottom-left' : 'aiva-pos-bottom-right';
		$trigger_style = in_array( $trigger_style, array( 'circle', 'pill', 'badge' ), true ) ? $trigger_style : 'circle';
		$style_class   = 'aiva-trigger-' . $trigger_style;
		?>
		<!-- Cubixsol AI Assistant Launch Button -->
		<div id="aiva-launcher" class="aiva-launcher-btn <?php echo esc_attr( $pos_class . ' ' . $style_class ); ?>" title="<?php echo esc_attr( $cta_text ); ?>">
			<?php if ( 'badge' === $trigger_style ) : ?>
				<span class="aiva-launcher-text"><?php esc_html_e( 'Ask AI', 'cubixsol-ai-voice-chat-assistant' ); ?></span>
				<div class="aiva-launcher-icon">
					<svg class="aiva-launcher-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.49 6-3.31 6-6.72h-1.7z"/><path d="M19 2.5l.6 1.4 1.4.6-1.4.6-.6 1.4-.6-1.4-1.4-.6 1.4-.6z"/></svg>
				</div>
			<?php elseif ( 'pill' === $trigger_style ) : ?>
				<div class="aiva-launcher-icon">
					<svg class="aiva-launcher-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.49 6-3.31 6-6.72h-1.7z"/><path d="M19 2.5l.6 1.4 1.4.6-1.4.6-.6 1.4-.6-1.4-1.4-.6 1.4-.6z"/></svg>
				</div>
				<span class="aiva-launcher-text"><?php echo esc_html( $cta_text ); ?></span>
			<?php else : ?>
				<div class="aiva-launcher-icon">
					<svg class="aiva-launcher-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.49 6-3.31 6-6.72h-1.7z"/><path d="M19 2.5l.6 1.4 1.4.6-1.4.6-.6 1.4-.6-1.4-1.4-.6 1.4-.6z"/></svg>
				</div>
			<?php endif; ?>
			<div class="aiva-launcher-pulse"></div>
		</div>

		<!-- Cubixsol AI Assistant Widget Panel -->
		<div id="aiva-widget" class="aiva-widget-container <?php echo esc_attr( $pos_class ); ?>" style="display: none;">
			<div class="aiva-card">
				<!-- Header -->
				<div class="aiva-widget-header">
					<div class="aiva-bot-info">
						<span class="aiva-avatar"><svg class="aiva-avatar-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.49 6-3.31 6-6.72h-1.7z"/><path d="M19 2.5l.6 1.4 1.4.6-1.4.6-.6 1.4-.6-1.4-1.4-.6 1.4-.6z"/></svg></span>
						<div class="aiva-title-box">
							<h3><?php echo esc_html( $title_text ); ?></h3>
							<div class="aiva-status-row">
								<span class="aiva-status-dot online"></span>
								<span class="aiva-status-text"><?php esc_html_e( 'Active', 'cubixsol-ai-voice-chat-assistant' ); ?></span>
							</div>
						</div>
					</div>
					<div class="aiva-header-actions">
						<?php
						$aiva_languages     = AIVA_Languages::all();
						$aiva_enabled_langs = AIVA_Languages::enabled_codes();
						$aiva_default_lang  = AIVA_Languages::default_code();
						$aiva_show_selector = '0' !== (string) get_option( 'aiva_widget_show_language_selector', '1' ) && count( $aiva_enabled_langs ) > 1;
						?>
						<div class="aiva-lang-custom-dropdown"<?php echo $aiva_show_selector ? '' : ' style="display: none;"'; ?>>
							<button type="button" id="aiva-lang-custom-toggle" class="aiva-lang-toggle" title="<?php esc_attr_e( 'Change Language', 'cubixsol-ai-voice-chat-assistant' ); ?>">
								<span class="aiva-lang-current-label"><?php echo esc_html( $aiva_languages[ $aiva_default_lang ]['native'] ); ?></span>
								<span class="dashicons dashicons-arrow-down-alt2"></span>
							</button>
							<div id="aiva-lang-custom-menu" class="aiva-lang-dropdown-menu" style="display: none;">
								<?php foreach ( $aiva_enabled_langs as $aiva_code ) : ?>
									<div class="aiva-lang-option<?php echo ( $aiva_code === $aiva_default_lang ) ? ' active' : ''; ?>" data-value="<?php echo esc_attr( $aiva_code ); ?>" lang="<?php echo esc_attr( $aiva_code ); ?>" title="<?php echo esc_attr( $aiva_languages[ $aiva_code ]['english'] ); ?>"><?php echo esc_html( $aiva_languages[ $aiva_code ]['native'] ); ?>
									<?php
									if ( $aiva_code === $aiva_default_lang ) :
										?>
										<span class="dashicons dashicons-yes"></span><?php endif; ?></div>
								<?php endforeach; ?>
							</div>
							<select id="aiva-lang-select" style="display: none;" aria-hidden="true" tabindex="-1">
								<?php foreach ( $aiva_enabled_langs as $aiva_code ) : ?>
									<option value="<?php echo esc_attr( $aiva_code ); ?>" <?php selected( $aiva_code, $aiva_default_lang ); ?>><?php echo esc_html( $aiva_languages[ $aiva_code ]['native'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<button type="button" id="aiva-close-btn" class="aiva-icon-btn" title="<?php esc_attr_e( 'Minimize Panel', 'cubixsol-ai-voice-chat-assistant' ); ?>">
							<span class="dashicons dashicons-minus"></span>
						</button>
					</div>
				</div>

				<!-- Chat Log Transcripts -->
				<div class="aiva-chat-body" id="aiva-chat-messages">
					<div class="aiva-msg bot animate-pop">
						<div class="aiva-bubble" dir="auto">
							<?php echo wp_kses_post( $greeting_text ); ?>
						</div>
					</div>
					<?php $quick_replies = self::get_quick_replies(); ?>
					<?php if ( ! empty( $quick_replies ) ) : ?>
						<div class="aiva-quick-replies aiva-quick-starter" role="group" aria-label="<?php esc_attr_e( 'Suggested questions', 'cubixsol-ai-voice-chat-assistant' ); ?>">
							<?php foreach ( $quick_replies as $reply ) : ?>
								<button type="button" class="aiva-quick-chip" data-message="<?php echo esc_attr( $reply['message'] ); ?>"><?php echo esc_html( $reply['label'] ); ?></button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

				<!-- Footer Operations & Wave Animations -->
				<div class="aiva-widget-footer">
					<div class="aiva-status-indicator" id="aiva-agent-status">
						<?php esc_html_e( 'Click to speak...', 'cubixsol-ai-voice-chat-assistant' ); ?>
					</div>
					
					<!-- Microphone Wave Circle -->
					<div class="aiva-mic-outer-wrapper">
						<div class="aiva-mic-box">
							<button type="button" id="aiva-mic-trigger" class="aiva-mic-btn">
								<img src="<?php echo esc_url( AIVA_URL . 'public/images/ai-voice-agent-icon.png' ); ?>" alt="<?php esc_attr_e( 'Microphone', 'cubixsol-ai-voice-chat-assistant' ); ?>" class="aiva-mic-custom-icon" width="54" height="54" loading="lazy" decoding="async" />
							</button>
							<div class="aiva-mic-ring"></div>
							<div class="aiva-mic-ring2"></div>
						</div>
					</div>

					<!-- Stop Synthesis Button -->
					<div class="aiva-speech-controls">
						<button type="button" id="aiva-stop-speech" style="display: none;" class="aiva-stop-speech-pill">
							<span class="dashicons dashicons-controls-pause" style="font-size: 13px; width: 13px; height: 13px; vertical-align: middle; margin-right: 3px;"></span> <?php esc_html_e( 'Stop Audio', 'cubixsol-ai-voice-chat-assistant' ); ?>
						</button>
					</div>

					<!-- Keyboard Fallback Input -->
					<div class="aiva-input-box" id="aiva-text-input-wrapper">
						<input type="text" id="aiva-text-query" placeholder="<?php esc_attr_e( 'Type your query here...', 'cubixsol-ai-voice-chat-assistant' ); ?>" autocomplete="off" />
						<button type="button" id="aiva-send-text" class="aiva-send-btn">
							<span class="dashicons dashicons-arrow-right-alt2"></span>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
