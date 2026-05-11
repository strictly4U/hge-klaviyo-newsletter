<?php
/**
 * Plugin Name:       HgE Klaviyo Newsletter
 * Plugin URI:        https://github.com/strictly4U/hge-klaviyo-newsletter
 * Description:       Auto-trigger Klaviyo email campaigns when a tagged WordPress post is published. Tier 1 (Free): single list, basic UTM, built-in HTML template. Pro extension available for delay/window control, multi-template, multi-list (up to 15), retry, A/B testing, analytics and more.
 * Version:           3.0.4
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            HgE
 * Author URI:        https://github.com/strictly4U
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hge-klaviyo-newsletter
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
 * Load translations.
 *
 * `init` (not `plugins_loaded`) per WordPress 6.7+ guidance — wp.org translations
 * are auto-loaded by core, so calling `load_plugin_textdomain` only matters for
 * sideloaded `.mo` files in `wp-content/languages/plugins/` or in this plugin's
 * `/languages/` directory.
 */
add_action( 'init', static function () {
    load_plugin_textdomain(
        'hge-klaviyo-newsletter',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages/'
    );
} );

/**
 * Declare WooCommerce HPOS compatibility.
 */
add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

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
