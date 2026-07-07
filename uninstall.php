<?php
/**
 * Plugin uninstall handler.
 *
 * Runs ONLY when the user clicks "Delete" on the plugin in WP Admin → Plugins
 * (NOT on simple deactivation — see includes/activation.php for that).
 *
 * By default this file does NOTHING destructive: it preserves all post meta,
 * options, and transients so historical campaign data stays intact and the
 * plugin can be reinstalled without losing state.
 *
 * To wipe everything on uninstall, define this in wp-config.php BEFORE clicking Delete:
 *
 *     define( 'HGE_KLAVIYO_NL_FULL_UNINSTALL', true );
 *
 * @package HgE\KlaviyoNewsletter
 */

// Fired only by WP_UNINSTALL_PLUGIN context. Reject any direct access.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Default: do nothing. Persist all data so the plugin can be reinstalled cleanly.
if ( ! defined( 'HGE_KLAVIYO_NL_FULL_UNINSTALL' ) || true !== HGE_KLAVIYO_NL_FULL_UNINSTALL ) {
    return;
}

// FULL UNINSTALL — wipe options, transients, scheduled actions, and post meta.

// 1. Options
delete_option( 'hge_klaviyo_last_send_at' );
delete_option( 'hge_klaviyo_last_send_at_by_slug' ); // since 3.0.0 — per-slug cooldown timers
delete_option( 'hge_klaviyo_nl_activated_at' );

// 2. Transients
//    Enumerate the per-rule Web Feed transients before deleting the settings
//    option (otherwise we'd lose the list of feed names). Helpers from
//    config.php are not loaded during uninstall, so we read the option directly.
$hge_klaviyo_nl_settings = get_option( 'hge_klaviyo_nl_settings', array() );
if ( is_array( $hge_klaviyo_nl_settings ) && isset( $hge_klaviyo_nl_settings['tag_rules'] ) && is_array( $hge_klaviyo_nl_settings['tag_rules'] ) ) {
    foreach ( $hge_klaviyo_nl_settings['tag_rules'] as $hge_klaviyo_nl_rule ) {
        $hge_klaviyo_nl_feed_name = isset( $hge_klaviyo_nl_rule['web_feed_name'] ) ? sanitize_key( (string) $hge_klaviyo_nl_rule['web_feed_name'] ) : '';
        // Skip 'fc_news' — it shares the legacy unkeyed transient key (back-compat
        // with the original FC Rapid 1923 Klaviyo deployment), which we delete via
        // the legacy line further below.
        if ( '' !== $hge_klaviyo_nl_feed_name && 'fc_news' !== $hge_klaviyo_nl_feed_name ) {
            delete_transient( 'hge_klaviyo_current_post_id_' . $hge_klaviyo_nl_feed_name );
        }
    }
}
delete_option( 'hge_klaviyo_nl_settings' );
delete_transient( 'hge_klaviyo_current_post_id' );
delete_transient( 'hge_klaviyo_feed_v1' );
delete_transient( 'hge_klaviyo_nl_activation_missing' );

// Since 3.0.16 — warmup v2 chain state.
delete_option( 'hge_klaviyo_nl_warmup_v2' );
delete_option( 'hge_klaviyo_nl_warmup_fails' );
delete_option( 'hge_klaviyo_nl_warmup_status' );
delete_option( 'hge_klaviyo_nl_warmup_selfoff' );
delete_transient( 'hge_klaviyo_nl_warmup_lock' );
delete_transient( 'hge_klaviyo_nl_warmup_acc' );
delete_transient( 'hge_klaviyo_nl_warmup_pause' );
delete_transient( 'hge_klaviyo_nl_admin_active' );

// 3. Action Scheduler queue (best-effort — AS may not be loaded during uninstall)
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( 'hge_klaviyo_dispatch_newsletter', array(), 'hge-klaviyo' );
    as_unschedule_all_actions( 'hge_klaviyo_nl_api_cache_warmup', array(), 'hge-klaviyo' ); // legacy 3.0.6 recurring
    as_unschedule_all_actions( 'hge_klaviyo_nl_warmup_step', array(), 'hge-klaviyo' );      // 3.0.16 chained steps
}

// 4. Post meta — direct DB delete to bypass per-post overhead
global $wpdb;
$hge_klaviyo_nl_meta_keys = array(
    '_klaviyo_campaign_sent',
    '_klaviyo_campaign_lock',
    '_klaviyo_campaign_id',
    '_klaviyo_campaign_sent_at',
    '_klaviyo_campaign_scheduled_for',
    '_klaviyo_campaign_last_error',
);
foreach ( $hge_klaviyo_nl_meta_keys as $hge_klaviyo_nl_meta_key ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- uninstall.php: bulk meta delete is the documented WP pattern; caching is irrelevant because the site is being permanently torn down.
    $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $hge_klaviyo_nl_meta_key ), array( '%s' ) );
}
