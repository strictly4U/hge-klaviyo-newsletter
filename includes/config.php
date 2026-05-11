<?php
/**
 * Constants and small helpers shared across the plugin.
 *
 * @package HgE\KlaviyoNewsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'HGE_KLAVIYO_NL_TAG_SLUG' ) ) {
    define( 'HGE_KLAVIYO_NL_VERSION',           '3.0.1' );
    // Default tag slug for the first rule on fresh installs. Customers configure
    // their actual trigger tag in Setări → Reguli newsletter; this constant is
    // only the bootstrap seed value.
    define( 'HGE_KLAVIYO_NL_TAG_SLUG',          'newsletter' );
    define( 'HGE_KLAVIYO_NL_META_SENT',         '_klaviyo_campaign_sent' );
    define( 'HGE_KLAVIYO_NL_META_LOCK',         '_klaviyo_campaign_lock' );
    define( 'HGE_KLAVIYO_NL_META_CAMP_ID',      '_klaviyo_campaign_id' );
    define( 'HGE_KLAVIYO_NL_META_SENT_AT',      '_klaviyo_campaign_sent_at' );
    define( 'HGE_KLAVIYO_NL_META_SCHED_FOR',    '_klaviyo_campaign_scheduled_for' );
    define( 'HGE_KLAVIYO_NL_META_ERROR',        '_klaviyo_campaign_last_error' );
    define( 'HGE_KLAVIYO_NL_OPT_LAST_SEND',     'hge_klaviyo_last_send_at' );
    define( 'HGE_KLAVIYO_NL_TRANSIENT_CURRENT', 'hge_klaviyo_current_post_id' );
    define( 'HGE_KLAVIYO_NL_DEFAULT_INTERVAL_H', 12 );
    define( 'HGE_KLAVIYO_NL_API_REVISION',      '2024-10-15' );
    define( 'HGE_KLAVIYO_NL_HOOK',              'hge_klaviyo_dispatch_newsletter' );
}

if ( ! function_exists( 'hge_klaviyo_nl_transient_key_for_feed' ) ) {
    /**
     * Compute the per-feed transient key for the Web Feed "current article" cache.
     *
     * Why feed-name-keyed (not rule-index-keyed): the Klaviyo Web Feed URL is set
     * once in the Klaviyo dashboard and contains the feed name. Card reorders or
     * deletes in the WP admin must NOT break the existing Klaviyo config — keying
     * on the user-controlled `web_feed_name` survives any UI shuffling, while
     * indexes don't.
     *
     * Empty / 'fc_news' feed names map to the legacy unkeyed transient key so
     * Klaviyo Web Feed URLs configured against the v2.x deployment keep resolving
     * after upgrade.
     *
     * @since 3.0.0
     * @legacy The literal 'fc_news' check exists to preserve back-compat with the
     *         original Klaviyo deployment that shipped this plugin (FC Rapid 1923)
     *         and used 'fc_news' as the Web Feed name. New installs use
     *         'newsletter_feed' as the default and do not hit this branch.
     * @param string $feed_name Per-rule web_feed_name (e.g., "newsletter_feed_promo").
     * @return string Transient key (e.g., "hge_klaviyo_current_post_id_newsletter_feed_promo").
     */
    function hge_klaviyo_nl_transient_key_for_feed( $feed_name ) {
        $sanitized = sanitize_key( (string) $feed_name );
        if ( '' === $sanitized || 'fc_news' === $sanitized ) {
            return HGE_KLAVIYO_NL_TRANSIENT_CURRENT;
        }
        return HGE_KLAVIYO_NL_TRANSIENT_CURRENT . '_' . $sanitized;
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_all_feed_names' ) ) {
    /**
     * Return the distinct sanitised `web_feed_name` values across all configured
     * rules. Used by activation/uninstall cleanup and the Status tab to enumerate
     * which keyed transients are in play.
     *
     * @since 3.0.0
     * @return string[]
     */
    function hge_klaviyo_nl_all_feed_names() {
        if ( ! function_exists( 'hge_klaviyo_nl_get_settings' ) ) {
            return array();
        }
        $s     = hge_klaviyo_nl_get_settings();
        $rules = is_array( $s['tag_rules'] ?? null ) ? $s['tag_rules'] : array();
        $names = array();
        foreach ( $rules as $r ) {
            $n = sanitize_key( (string) ( $r['web_feed_name'] ?? '' ) );
            if ( '' !== $n ) {
                $names[] = $n;
            }
        }
        return array_values( array_unique( $names ) );
    }
}

if ( ! function_exists( 'hge_klaviyo_use_web_feed' ) ) {
    /**
     * @deprecated 3.0.0 use Web Feed mode is now per-rule. This helper kept for
     *             backward compatibility; returns true if ANY rule has Web Feed
     *             enabled with a template_id (used only by feed-endpoints.php
     *             diagnostic UI). Dispatcher reads the field from the matched rule.
     */
    function hge_klaviyo_use_web_feed() {
        if ( ! function_exists( 'hge_klaviyo_nl_get_settings' ) ) {
            return false;
        }
        $s = hge_klaviyo_nl_get_settings();
        foreach ( (array) ( $s['tag_rules'] ?? array() ) as $rule ) {
            if ( ! empty( $rule['use_web_feed'] ) && ! empty( $rule['template_id'] ) ) {
                return true;
            }
        }
        return false;
    }
}

if ( ! function_exists( 'hge_klaviyo_excluded_list_ids' ) ) {
    /**
     * @deprecated 3.0.0 excluded lists are per-rule now. Returns an empty array
     *             — direct callers should query the matched rule from
     *             hge_klaviyo_nl_get_matching_rule( $post ) instead.
     */
    function hge_klaviyo_excluded_list_ids() {
        return array();
    }
}

if ( ! function_exists( 'hge_klaviyo_safe_subject' ) ) {
    function hge_klaviyo_safe_subject( $title ) {
        $max = (int) apply_filters( 'hge_klaviyo_subject_length', 60 );

        $clean = wp_strip_all_tags( (string) $title );
        if ( function_exists( 'remove_accents' ) ) {
            $clean = remove_accents( $clean );
        }
        $clean = preg_replace( '/[\x00-\x1F\x7F]/u', '', $clean );  // control chars
        $clean = preg_replace( '/[^\x20-\x7E]/u', '',  $clean );    // strict ASCII printable
        $clean = preg_replace( '/\s+/', ' ', $clean );
        $clean = trim( $clean );

        if ( '' === $clean ) {
            /**
             * Filter the fallback subject used when the post title is empty / unprintable.
             * Default uses the site name from `get_bloginfo( 'name' )` so the email still
             * carries a meaningful sender identifier without baking any brand into the plugin.
             *
             * @since 3.0.1
             * @param string $fallback Default fallback subject.
             */
            $site_name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
            $site_name = function_exists( 'remove_accents' ) ? remove_accents( $site_name ) : $site_name;
            $site_name = preg_replace( '/[^\x20-\x7E]/u', '', $site_name );
            $site_name = trim( $site_name );
            $fallback  = '' !== $site_name ? ( 'Newsletter ' . $site_name ) : 'Newsletter';
            return (string) apply_filters( 'hge_klaviyo_safe_subject_fallback', $fallback );
        }

        if ( mb_strlen( $clean ) > $max ) {
            $cut   = mb_substr( $clean, 0, $max );
            $space = mb_strrpos( $cut, ' ' );
            if ( false !== $space && $space > $max - 15 ) {
                $cut = mb_substr( $cut, 0, $space );
            }
            $clean = rtrim( $cut, " .,;:-_" ) . '...';
        }

        return $clean;
    }
}

if ( ! function_exists( 'hge_klaviyo_min_interval_seconds' ) ) {
    function hge_klaviyo_min_interval_seconds() {
        if ( function_exists( 'hge_klaviyo_nl_get_settings' ) ) {
            $s = hge_klaviyo_nl_get_settings();
            $hours = max( 0, (int) ( $s['min_interval_hours'] ?? HGE_KLAVIYO_NL_DEFAULT_INTERVAL_H ) );
            return $hours * HOUR_IN_SECONDS;
        }
        $hours = defined( 'KLAVIYO_NEWSLETTER_MIN_INTERVAL_HOURS' )
            ? max( 0, (int) KLAVIYO_NEWSLETTER_MIN_INTERVAL_HOURS )
            : HGE_KLAVIYO_NL_DEFAULT_INTERVAL_H;
        return $hours * HOUR_IN_SECONDS;
    }
}

if ( ! function_exists( 'hge_klaviyo_compute_send_time' ) ) {
    /**
     * @deprecated 3.0.0 Replaced by hge_klaviyo_nl_compute_send_time_for_slug( $tag_slug ).
     *             This wrapper preserves the old API by reading the legacy global
     *             option key (which Pro feature modules from v2.x may still write to).
     *             New code should call the per-slug helper.
     */
    function hge_klaviyo_compute_send_time() {
        $last     = (int) get_option( HGE_KLAVIYO_NL_OPT_LAST_SEND, 0 );
        $now      = time();
        $earliest = $last + hge_klaviyo_min_interval_seconds();

        if ( $earliest <= $now ) {
            return array( 'mode' => 'immediate', 'time' => $now );
        }
        $time = max( $earliest, $now + 15 * MINUTE_IN_SECONDS );
        return array( 'mode' => 'static_time', 'time' => $time );
    }
}
