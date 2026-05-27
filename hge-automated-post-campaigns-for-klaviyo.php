<?php
/**
 * Plugin Name:       HgE Automated Post Campaigns for Klaviyo
 * Plugin URI:        https://github.com/strictly4U/hge-automated-post-campaigns-for-klaviyo
 * Description:       Automatically send a Klaviyo email campaign from a WordPress post — when a post is published with a configured tag, the plugin renders a built-in HTML template populated with the post (title, excerpt, featured image, link with UTM) and dispatches the campaign to your Klaviyo list. Free: single list, basic UTM, built-in template. Pro extension adds Klaviyo templates with WooCommerce product feed, multi-list (up to 15), exclusions, delay window, retry and A/B testing.
 * Version:           3.0.13
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            HgE
 * Author URI:        https://github.com/strictly4U
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hge-automated-post-campaigns-for-klaviyo
 * Domain Path:       /languages
 *
 * Requires Plugins:  woocommerce
 *
 * @package HgE\KlaviyoNewsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'HGE_KLAVIYO_NL_PLUGIN_FILE' ) ) {
    define( 'HGE_KLAVIYO_NL_PLUGIN_FILE', __FILE__ );
    define( 'HGE_KLAVIYO_NL_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
    define( 'HGE_KLAVIYO_NL_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
    define( 'HGE_KLAVIYO_NL_PLUGIN_BASE', plugin_basename( __FILE__ ) );
}

/**
 * Translations are auto-loaded by WordPress core (≥ 4.6) for any plugin whose
 * Text Domain header matches its folder slug. We omit `load_plugin_textdomain`
 * to satisfy the wp.org Plugin Check `DiscouragedFunctions` rule. The bundled
 * `.mo` files in `/languages/` continue to load via core's discovery.
 */

/**
 * Declare WooCommerce HPOS compatibility.
 */
add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// WooCommerce-backed logger (writes to WC → Status → Logs, source `hge-klaviyo`).
// Loaded first so subsequent includes can call HgE_Klaviyo_Logger::* freely.
require_once HGE_KLAVIYO_NL_PLUGIN_DIR . 'includes/class-hge-klaviyo-logger.php';

// Core: constants + helpers
require_once HGE_KLAVIYO_NL_PLUGIN_DIR . 'includes/config.php';

// Tier helper (Free knows about Pro presence)
require_once HGE_KLAVIYO_NL_PLUGIN_DIR . 'includes/tier.php';

// Settings DB schema + getters/setters + migration shim from wp-config
require_once HGE_KLAVIYO_NL_PLUGIN_DIR . 'includes/settings.php';

// Klaviyo API client (api_request + list_lists/list_templates with cache)
require_once HGE_KLAVIYO_NL_PLUGIN_DIR . 'includes/api-client.php';

// Dispatcher: transition_post_status + Action Scheduler + Klaviyo Campaigns API
require_once HGE_KLAVIYO_NL_PLUGIN_DIR . 'includes/dispatcher.php';

// Feed endpoints (klaviyo.json + klaviyo-current.json)
require_once HGE_KLAVIYO_NL_PLUGIN_DIR . 'includes/feed-endpoints.php';

// Admin UI: Tools page (Diagnostic + Settings tabs), post meta box, admin-post handlers
require_once HGE_KLAVIYO_NL_PLUGIN_DIR . 'includes/admin.php';

// Activation / deactivation hooks (WC dependency, flush rewrites, migrate wp-config → DB)
require_once HGE_KLAVIYO_NL_PLUGIN_DIR . 'includes/activation.php';
register_activation_hook(   __FILE__, 'hge_klaviyo_nl_activate' );
register_deactivation_hook( __FILE__, 'hge_klaviyo_nl_deactivate' );
