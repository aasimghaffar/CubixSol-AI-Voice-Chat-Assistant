<?php
/**
 * Fired when the plugin is uninstalled via WordPress.
 *
 * Removes every option, post meta value, scheduled event, cached audio file and the
 * knowledge base table created by the plugin (on every site of a multisite network).
 *
 * @package Cubixsol_AI_Voice_Chat_Assistant
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Uninstall routine (kept in a class so no un-prefixed global functions or variables are declared).
 */
class AIVA_Uninstaller {

	/**
	 * Run on every site of a network, or on the single site.
	 *
	 * @return void
	 */
	public static function run() {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::uninstall_site();
				restore_current_blog();
			}
		} else {
			self::uninstall_site();
		}
	}

	/**
	 * Remove all plugin data for the current site.
	 *
	 * @return void
	 */
	public static function uninstall_site() {
		global $wpdb;

		$options = array(
			// Settings.
			'aiva_active_llm_engine',
			'aiva_gemini_api_key',
			'aiva_gemini_model',
			'aiva_gemini_custom_model',
			'aiva_openai_api_key',
			'aiva_openai_model',
			'aiva_openai_custom_model',
			'aiva_anthropic_api_key',
			'aiva_anthropic_model',
			'aiva_anthropic_custom_model',
			'aiva_tts_engine',
			'aiva_elevenlabs_api_key',
			'aiva_elevenlabs_voice_id',
			'aiva_elevenlabs_model',
			'aiva_elevenlabs_custom_model',
			'aiva_included_types',
			'aiva_excluded_urls',
			'aiva_business_name',
			'aiva_business_desc',
			'aiva_business_hours',
			'aiva_business_contact',
			'aiva_business_phone',
			'aiva_business_email',
			'aiva_business_address',
			'aiva_business_instructions',
			'aiva_business_services',
			'aiva_business_faqs',
			'aiva_privacy_include_faqs',
			'aiva_privacy_include_contact',
			'aiva_privacy_include_policies',
			'aiva_privacy_include_menus',
			'aiva_widget_enable',
			'aiva_widget_trigger_style',
			'aiva_widget_position',
			'aiva_widget_cta_text',
			'aiva_widget_title_text',
			'aiva_widget_greeting_text',
			'aiva_quick_replies_enable',
			'aiva_widget_languages',
			'aiva_widget_default_language',
			'aiva_widget_show_language_selector',
			'aiva_widget_auto_detect_language',
			'aiva_quick_replies',
			'aiva_quick_replies_auto_categories',
			'aiva_widget_display_mode',
			'aiva_widget_target_post_types',
			'aiva_widget_excluded_slugs',
			'aiva_active_tab',
			// Internal state.
			'aiva_db_version',
			'aiva_first_activation_time',
			'aiva_review_notice_dismissed',
			'aiva_review_notice_temp_dismiss',
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		// Rate-limit and other plugin transients.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup on uninstall.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_aiva_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_aiva_' ) . '%'
			)
		);

		// Per-post "auto index" toggle.
		delete_post_meta_by_key( '_aiva_auto_index' );

		// Scheduled events.
		wp_unschedule_hook( 'aiva_daily_sync_cron' );
		wp_unschedule_hook( 'aiva_index_post_event' ); // Clears every scheduled post, whatever its arguments.

		// Knowledge base table.
		$table_name = esc_sql( $wpdb->prefix . 'aiva_knowledge_base' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping the plugin's own table on uninstall; table name is not user input.
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

		// Cached ElevenLabs audio files.
		$upload_dir = wp_upload_dir();
		$cache_dir  = trailingslashit( $upload_dir['basedir'] ) . 'aiva-cache/';
		if ( is_dir( $cache_dir ) ) {
			$files = glob( $cache_dir . '*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						wp_delete_file( $file );
					}
				}
			}
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( WP_Filesystem() && $wp_filesystem ) {
				$wp_filesystem->rmdir( $cache_dir );
			}
		}
	}
}

AIVA_Uninstaller::run();
