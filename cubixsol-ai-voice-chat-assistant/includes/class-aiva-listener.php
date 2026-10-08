<?php
/**
 * Keeps the knowledge base in sync with content changes.
 *
 * @package Cubixsol_AI_Voice_Chat_Assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Content change listener and background sync.
 */
class AIVA_Listener {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// Runs after AIVA_Admin saves the meta box toggle (priority 20).
		add_action( 'save_post', array( $this, 'on_save_post' ), 99, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ), 99 );

		// Background indexing of a single item (scheduled on save so the editor never waits on AI APIs).
		add_action( 'aiva_index_post_event', array( $this, 'run_single_index' ) );

		// Daily background sync.
		add_action( 'aiva_daily_sync_cron', array( $this, 'run_daily_background_sync' ) );

		// Self-heal the daily event if it went missing (checked in admin only, not on every front-end request).
		add_action( 'admin_init', array( 'AIVA_Activator', 'schedule_cron' ) );
	}

	/**
	 * Triggered when any post, page, or product is saved or updated.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public function on_save_post( $post_id, $post, $update ) {
		unset( $update );

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$included_types = get_option( 'aiva_included_types', array( 'page', 'product' ) );
		if ( ! is_array( $included_types ) || ! in_array( $post->post_type, $included_types, true ) ) {
			return;
		}

		// Anything that is not public any more (draft, private, trash, password protected) leaves the index right away.
		if ( ! AIVA_Scanner::is_indexable_post( $post ) ) {
			AIVA_Scanner::delete_post_chunks( $post_id );
			return;
		}

		// Respect the per-item "Auto-index on Save / Update" toggle (enabled by default).
		$auto_index = get_post_meta( $post_id, '_aiva_auto_index', true );
		if ( '0' === (string) $auto_index ) {
			return;
		}

		// Embeddings always use the Gemini key.
		if ( '' === (string) get_option( 'aiva_gemini_api_key', '' ) ) {
			return;
		}

		// Index in the background so saving never waits on remote API calls.
		// WordPress ignores an identical event scheduled within 10 minutes, so double save_post calls are merged.
		if ( ! wp_next_scheduled( 'aiva_index_post_event', array( (int) $post_id ) ) ) {
			wp_schedule_single_event( time() + 5, 'aiva_index_post_event', array( (int) $post_id ) );
		}
	}

	/**
	 * Background handler that indexes one post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function run_single_index( $post_id ) {
		AIVA_Scanner::scan_item( (int) $post_id );
	}

	/**
	 * Triggered when any post, page, or product is deleted permanently.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_delete_post( $post_id ) {
		AIVA_Scanner::delete_post_chunks( $post_id );
	}

	/**
	 * Daily handler: purge disabled chunks, auto-sync unindexed items and prune the audio cache.
	 *
	 * @return void
	 */
	public function run_daily_background_sync() {
		AIVA_API::prune_audio_cache();

		if ( '' === (string) get_option( 'aiva_gemini_api_key', '' ) ) {
			AIVA_Scanner::purge_disabled_chunks();
			return;
		}

		// get_queue() also purges chunks of disabled content types.
		$queue = AIVA_Scanner::get_queue();
		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return;
		}

		$indexed = AIVA_Scanner::get_indexed_post_ids();

		// Cap work per run so a large catalogue is synced gradually instead of timing out.
		$processed = 0;
		$max_items = (int) apply_filters( 'aiva_daily_sync_batch_size', 50 );

		foreach ( $queue as $post_id ) {
			if ( isset( $indexed[ (int) $post_id ] ) ) {
				continue;
			}
			AIVA_Scanner::scan_item( $post_id );
			++$processed;
			if ( $processed >= $max_items ) {
				break;
			}
		}
	}
}
