<?php
/**
 * Fired during plugin deactivation.
 *
 * @package           Cubixsol_AI_Voice_Chat_Assistant
 * @subpackage        Cubixsol_AI_Voice_Chat_Assistant/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Fired during plugin deactivation.
 */
class AIVA_Deactivator {

	/**
	 * Clean up temporary state and scheduled events on plugin deactivation.
	 *
	 * Runs without a capability check so it also works for WP-CLI deactivation;
	 * WordPress itself only fires this hook for users allowed to deactivate plugins.
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_transient( 'aiva_temp_audio_cache' );
		delete_transient( 'aiva_scanner_queue_lock' );

		wp_unschedule_hook( 'aiva_daily_sync_cron' );
		wp_unschedule_hook( 'aiva_index_post_event' ); // Clears every scheduled post, whatever its arguments.
	}
}
