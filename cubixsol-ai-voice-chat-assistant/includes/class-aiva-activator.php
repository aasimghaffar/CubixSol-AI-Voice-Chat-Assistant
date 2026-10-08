<?php
/**
 * Activation and upgrade routines.
 *
 * @package Cubixsol_AI_Voice_Chat_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles table creation, cron scheduling and version upgrades.
 */
class AIVA_Activator {

	/**
	 * Retired model IDs mapped to their current replacements.
	 *
	 * @var array
	 */
	private static $retired_models = array(
		'aiva_gemini_model'    => array(
			'gemini-2.0-flash'      => 'gemini-3.5-flash',
			'gemini-2.0-flash-lite' => 'gemini-3.1-flash-lite',
			'gemini-1.5-flash'      => 'gemini-3.5-flash',
			'gemini-1.5-pro'        => 'gemini-3.5-flash',
			'gemini-2.5-flash'      => 'gemini-3.5-flash',
			'gemini-2.5-flash-lite' => 'gemini-3.1-flash-lite',
		),
		'aiva_anthropic_model' => array(
			'claude-3-5-haiku-20241022'  => 'claude-haiku-4-5-20251001',
			'claude-3-haiku-20240307'    => 'claude-haiku-4-5-20251001',
			'claude-3-7-sonnet'          => 'claude-sonnet-4-6',
			'claude-3-5-sonnet-20241022' => 'claude-sonnet-4-6',
			'claude-3-opus-20240229'     => 'claude-sonnet-5',
		),
	);

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_table();
		self::schedule_cron();
		self::migrate_retired_models();

		if ( ! get_option( 'aiva_first_activation_time' ) ) {
			update_option( 'aiva_first_activation_time', time(), false );
		}
		update_option( 'aiva_db_version', AIVA_DB_VERSION, false );
	}

	/**
	 * Run upgrade steps when the plugin code is newer than the stored DB version.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'aiva_db_version' ) === AIVA_DB_VERSION ) {
			return;
		}
		self::activate();
	}

	/**
	 * Create or update the knowledge base table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'aiva_knowledge_base';
		$charset_collate = $wpdb->get_charset_collate();

		// SQL schema for RAG knowledge chunks and vector embeddings (stored as JSON arrays).
		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned DEFAULT NULL,
			content_type varchar(50) NOT NULL,
			source_url varchar(255) DEFAULT NULL,
			chunk_text longtext NOT NULL,
			chunk_hash varchar(32) NOT NULL,
			embedding longtext DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY content_type (content_type)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Schedule the daily background sync event.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( 'aiva_daily_sync_cron' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'aiva_daily_sync_cron' );
		}
	}

	/**
	 * Move saved model settings off models that the providers have shut down.
	 *
	 * @return void
	 */
	public static function migrate_retired_models() {
		foreach ( self::$retired_models as $option => $map ) {
			$current = get_option( $option, '' );
			if ( is_string( $current ) && isset( $map[ $current ] ) ) {
				update_option( $option, $map[ $current ] );
			}
		}
	}
}
