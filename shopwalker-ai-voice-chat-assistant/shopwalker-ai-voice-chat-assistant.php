<?php
/**
 * Shopwalker – AI Voice & Chat Assistant
 *
 * @package           Shopwalker
 * @author            Aasim Ghaffar
 * @copyright         2026 Cubixsol
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Shopwalker – AI Voice & Chat Assistant
 * Plugin URI:        https://cubixsol.com/plugins/shopwalker-ai-voice-chat-assistant/
 * Description:       An AI voice & chat sales and support assistant for your website and store. Visitors speak or type; answers come from your own site content, using your own AI provider account.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Cubixsol
 * Author URI:        https://cubixsol.com/
 * Text Domain:       shopwalker-ai-voice-chat-assistant
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants.
 */
define( 'AIVA_VERSION', '1.0.0' );
define( 'AIVA_DB_VERSION', '1.1' );
define( 'AIVA_PLUGIN_FILE', __FILE__ );
define( 'AIVA_PATH', plugin_dir_path( __FILE__ ) );
define( 'AIVA_URL', plugin_dir_url( __FILE__ ) );
define( 'AIVA_BASENAME', plugin_basename( __FILE__ ) );

// Load plugin classes.
require_once AIVA_PATH . 'includes/providers/class-provider-registry.php';
require_once AIVA_PATH . 'includes/class-aiva-activator.php';
require_once AIVA_PATH . 'includes/class-aiva-api.php';
require_once AIVA_PATH . 'includes/class-aiva-scanner.php';
require_once AIVA_PATH . 'includes/class-aiva-catalog.php';
require_once AIVA_PATH . 'includes/class-aiva-languages.php';
require_once AIVA_PATH . 'includes/class-aiva-admin.php';
require_once AIVA_PATH . 'includes/class-aiva-ajax.php';
require_once AIVA_PATH . 'includes/class-aiva-widget.php';
require_once AIVA_PATH . 'includes/class-aiva-listener.php';

/**
 * Plugin bootstrap (kept in a class so no un-prefixed global functions are declared).
 */
class AIVA_Plugin {

	/**
	 * Plugin activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		AIVA_Activator::activate();
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		require_once AIVA_PATH . 'includes/class-aiva-deactivator.php';
		AIVA_Deactivator::deactivate();
	}

	/**
	 * Begin plugin execution.
	 *
	 * @return void
	 */
	public static function run() {
		// Run database/option upgrades when the stored DB version is older than the code.
		AIVA_Activator::maybe_upgrade();

		new AIVA_Admin();
		new AIVA_Ajax();
		new AIVA_Widget();
		new AIVA_Listener();
	}

	/**
	 * Add Settings link to plugin action links on plugins page.
	 *
	 * @param array $links Plugin action links.
	 * @return array Modified links.
	 */
	public static function action_links( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=aiva-settings' ) ) . '">' . esc_html__( 'Settings', 'shopwalker-ai-voice-chat-assistant' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
}

register_activation_hook( __FILE__, array( 'AIVA_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AIVA_Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'AIVA_Plugin', 'run' ) );
add_filter( 'plugin_action_links_' . AIVA_BASENAME, array( 'AIVA_Plugin', 'action_links' ) );
