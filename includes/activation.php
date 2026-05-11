<?php
/**
 * Plugin activation / dependency check.
 *
 * On activation:
 *   1. Ensures WooCommerce is active (Action Scheduler dependency).
 *   2. Flushes rewrite rules so /feed/klaviyo.json and /feed/klaviyo-current.json work without manual Settings → Permalinks → Save.
 *   3. Schedules an admin notice if any required wp-config constants are missing.
 *
 * @package HgE\KlaviyoNewsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'hge_klaviyo_nl_activate' ) ) {
    function hge_klaviyo_nl_activate() {
        // 1. Hard requirement: WooCommerce active (provides Action Scheduler)
        if ( ! class_exists( 'WooCommerce' ) ) {
            deactivate_plugins( plugin_basename( HGE_KLAVIYO_NL_PLUGIN_FILE ) );
            wp_die(
                'HgE Klaviyo Newsletter requires <strong>WooCommerce</strong> to be active (it provides Action Scheduler).<br>'
                . '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&larr; Back to Plugins</a>',
                'Plugin dependency missing',
                array( 'back_link' => true )
            );
        }

        // 2. Soft warning: Action Scheduler should be loadable. Most setups load it via WC.
        //    We don't deactivate on this — WC may load AS later than activation. The Tools
        //    page will display a clear status if AS is unreachable at runtime.

        // 3. Flush rewrite rules so feed URLs are routable immediately
        if ( function_exists( 'hge_klaviyo_register_feed_rewrites' ) ) {
            hge_klaviyo_register_feed_rewrites();
        }
        flush_rewrite_rules( false );

        // 4. One-time migration: copy v1.x wp-config constants into the new DB option
        if ( function_exists( 'hge_klaviyo_nl_migrate_from_wp_config' ) ) {
            hge_klaviyo_nl_migrate_from_wp_config();
        }

        // 5. Mark activation time + schedule a one-time admin notice if config is incomplete
        update_option( 'hge_klaviyo_nl_activated_at', time(), false );

        if ( function_exists( 'hge_klaviyo_nl_settings_complete' ) && ! hge_klaviyo_nl_settings_complete() ) {
            set_transient( 'hge_klaviyo_nl_activation_incomplete', 1, HOUR_IN_SECONDS );
        } else {
            delete_transient( 'hge_klaviyo_nl_activation_incomplete' );
        }
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_deactivate' ) ) {
    function hge_klaviyo_nl_deactivate() {
        // Flush rewrites so the feed URLs no longer resolve under this plugin's rules.
        flush_rewrite_rules( false );

        // Drop the "active campaign" transients so leftover post_ids can't leak to
        // Klaviyo if the plugin is re-activated later. Since 3.0.0 we enumerate the
        // per-rule keyed transients in addition to the legacy global one.
        delete_transient( HGE_KLAVIYO_NL_TRANSIENT_CURRENT );
        if ( function_exists( 'hge_klaviyo_nl_all_feed_names' ) && function_exists( 'hge_klaviyo_nl_transient_key_for_feed' ) ) {
            foreach ( hge_klaviyo_nl_all_feed_names() as $feed_name ) {
                delete_transient( hge_klaviyo_nl_transient_key_for_feed( $feed_name ) );
            }
        }

        // Best-effort cancel of any pending Action Scheduler jobs in the plugin's group.
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( HGE_KLAVIYO_NL_HOOK, array(), 'hge-klaviyo' );
        }
    }
}

// Show post-activation admin notice when wp-config constants are missing.
add_action( 'admin_notices', 'hge_klaviyo_nl_activation_notice' );

if ( ! function_exists( 'hge_klaviyo_nl_activation_notice' ) ) {
    function hge_klaviyo_nl_activation_notice() {
        if ( ! get_transient( 'hge_klaviyo_nl_activation_incomplete' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div class="notice notice-warning is-dismissible"><p><strong>HgE Klaviyo Newsletter:</strong> '
            . 'configurarea nu este completă (lipsește API key, feed token sau lista de trimitere). '
            . 'Mergi la <a href="' . esc_url( admin_url( 'tools.php?page=hge-klaviyo-newsletter&tab=settings' ) ) . '">Tools → Klaviyo Newsletter → Settings</a> pentru a completa.</p></div>';
    }
}
