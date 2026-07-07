<?php
/**
 * Admin UI: Tools page, post editor meta box, admin-post handlers, admin notices.
 *
 * Public functions defined (each guarded with function_exists):
 *   hge_klaviyo_register_meta_box
 *   hge_klaviyo_render_meta_box           — rule-aware since 3.0.0
 *   hge_klaviyo_handle_send_now
 *   hge_klaviyo_handle_reset
 *   hge_klaviyo_handle_reset_cooldown     — resets legacy v2.x global cooldown only
 *   hge_klaviyo_admin_notices
 *   hge_klaviyo_register_tools_page
 *   hge_klaviyo_render_tools_page         — Setări + Status (debug) tabs
 *   hge_klaviyo_handle_save_settings      — reads tag_rules[] array since 3.0.0
 *   hge_klaviyo_handle_refresh_api_cache
 *   hge_klaviyo_render_settings_tab       — cards system since 3.0.0
 *   hge_klaviyo_render_rule_card          — added in 3.0.0
 *   hge_klaviyo_format_list_count
 *   hge_klaviyo_friendly_api_error
 *
 * Schema (Free 3.0.0+): one rule per card. Each rule holds tag_slug + per-rule
 * lists + per-rule template + per-rule Web Feed config. The Settings tab renders
 * a tier-gated cards UI; the sanitiser in settings.php enforces the same caps
 * server-side (defence in depth).
 *
 * @package HgE\KlaviyoNewsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -----------------------------------------------------------------------------
// Meta box on the post edit screen — diagnostic + manual trigger
// -----------------------------------------------------------------------------

add_action( 'add_meta_boxes_post', 'hge_klaviyo_register_meta_box' );

if ( ! function_exists( 'hge_klaviyo_register_meta_box' ) ) {
    function hge_klaviyo_register_meta_box() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        add_meta_box(
            'hge_klaviyo_nl_status',
            __( 'Klaviyo Newsletter', 'hge-automated-post-campaigns-for-klaviyo' ),
            'hge_klaviyo_render_meta_box',
            'post',
            'side',
            'default'
        );
    }
}

if ( ! function_exists( 'hge_klaviyo_render_meta_box' ) ) {
    function hge_klaviyo_render_meta_box( $post ) {
        $sent     = get_post_meta( $post->ID, HGE_KLAVIYO_NL_META_SENT, true );
        $camp_id  = get_post_meta( $post->ID, HGE_KLAVIYO_NL_META_CAMP_ID, true );
        $sent_at  = get_post_meta( $post->ID, HGE_KLAVIYO_NL_META_SENT_AT, true );
        $error    = get_post_meta( $post->ID, HGE_KLAVIYO_NL_META_ERROR, true );
        $lock     = get_post_meta( $post->ID, HGE_KLAVIYO_NL_META_LOCK, true );
        $matching_rule = function_exists( 'hge_klaviyo_nl_get_matching_rule' )
            ? hge_klaviyo_nl_get_matching_rule( $post )
            : null;
        $has_tag  = (bool) $matching_rule;
        $matched_slug = $matching_rule ? (string) ( $matching_rule['_rule_tag_matched'] ?? $matching_rule['tag_slug'] ?? '' ) : '';
        $is_pub   = ( 'publish' === $post->post_status );

        $config_ok = function_exists( 'hge_klaviyo_nl_settings_complete' ) && hge_klaviyo_nl_settings_complete();

        $as_loaded = function_exists( 'as_enqueue_async_action' );

        $scheduled = false;
        if ( function_exists( 'as_has_scheduled_action' ) ) {
            $scheduled = as_has_scheduled_action( HGE_KLAVIYO_NL_HOOK, array( (int) $post->ID ), 'hge-klaviyo' );
        }

        echo '<p style="margin-top:0;"><strong>' . esc_html__( 'Status: ', 'hge-automated-post-campaigns-for-klaviyo' ) . '</strong>';
        if ( 'yes' === $sent ) {
            echo '<span style="color:#1e8e3e;">✓ ' . esc_html__( 'Sent', 'hge-automated-post-campaigns-for-klaviyo' ) . '</span></p>';
            if ( $camp_id ) {
                echo '<p style="font-size:12px;margin:4px 0;">' . esc_html__( 'Campaign ID:', 'hge-automated-post-campaigns-for-klaviyo' ) . ' <code>' . esc_html( $camp_id ) . '</code></p>';
            }
            if ( $sent_at ) {
                echo '<p style="font-size:12px;margin:4px 0;">' . esc_html__( 'At:', 'hge-automated-post-campaigns-for-klaviyo' ) . ' ' . esc_html( $sent_at ) . '</p>';
            }
        } elseif ( $scheduled ) {
            echo '<span style="color:#c45500;">' . esc_html__( 'Queued (Action Scheduler)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</span></p>';
        } else {
            echo '<span>' . esc_html__( 'Not sent', 'hge-automated-post-campaigns-for-klaviyo' ) . '</span></p>';
        }

        echo '<ul style="font-size:12px;margin:8px 0 0 0;list-style:none;padding:0;">';
        if ( $has_tag ) {
            echo '<li>✓ ' . esc_html__( 'Matched rule — tag', 'hge-automated-post-campaigns-for-klaviyo' ) . ' <code>' . esc_html( $matched_slug ) . '</code></li>';
        } else {
            echo '<li>✗ ' . esc_html__( 'No active rule tag is present on this post', 'hge-automated-post-campaigns-for-klaviyo' ) . '</li>';
        }
        echo '<li>' . ( $is_pub ? '✓' : '✗' ) . ' ' . esc_html__( 'Status:', 'hge-automated-post-campaigns-for-klaviyo' ) . ' <code>' . esc_html( $post->post_status ) . '</code></li>';
        echo '<li>' . ( $config_ok ? '✓' : '✗' ) . ' ' . esc_html__( 'Plugin configuration', 'hge-automated-post-campaigns-for-klaviyo' )
            . ( $config_ok ? '' : ' <em>(' . wp_kses_post(
                sprintf(
                    /* translators: %s is the Settings tab link */
                    __( 'incomplete — see %s', 'hge-automated-post-campaigns-for-klaviyo' ),
                    '<a href="' . esc_url( admin_url( 'tools.php?page=hge-klaviyo-newsletter&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'hge-automated-post-campaigns-for-klaviyo' ) . '</a>'
                )
            ) . ')</em>' ) . '</li>';
        echo '<li>' . ( $as_loaded ? '✓' : '✗' ) . ' Action Scheduler'
            . ( $as_loaded ? '' : ' <em>(' . esc_html__( 'not loaded', 'hge-automated-post-campaigns-for-klaviyo' ) . ')</em>' ) . '</li>';
        if ( $lock ) {
            echo '<li>⚠ ' . esc_html__( 'Active lock since:', 'hge-automated-post-campaigns-for-klaviyo' ) . ' ' . esc_html( gmdate( 'Y-m-d H:i:s', (int) $lock ) ) . ' UTC</li>';
        }
        echo '</ul>';

        if ( $error ) {
            echo '<div style="margin-top:10px;padding:8px;background:#fde7e7;border-left:3px solid #c00;font-size:11px;">'
                . '<strong>' . esc_html__( 'Last error:', 'hge-automated-post-campaigns-for-klaviyo' ) . '</strong><br><code style="word-break:break-all;">' . esc_html( $error ) . '</code></div>';
        }

        if ( $has_tag && $is_pub && $config_ok && 'yes' !== $sent ) {
            $url = wp_nonce_url(
                admin_url( 'admin-post.php?action=hge_klaviyo_send_now&post_id=' . (int) $post->ID ),
                'hge_klaviyo_send_now_' . $post->ID
            );
            echo '<p style="margin-top:12px;"><a href="' . esc_url( $url ) . '" class="button button-primary" onclick="return confirm(\'' . esc_js( __( 'Send the newsletter to the configured Klaviyo list now?', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '\');">' . esc_html__( 'Send now', 'hge-automated-post-campaigns-for-klaviyo' ) . '</a></p>';
        }

        if ( 'yes' === $sent || $error || $lock ) {
            $reset_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=hge_klaviyo_reset&post_id=' . (int) $post->ID ),
                'hge_klaviyo_reset_' . $post->ID
            );
            echo '<p style="margin-top:8px;"><a href="' . esc_url( $reset_url ) . '" class="button" onclick="return confirm(\'' . esc_js( __( 'Reset the Klaviyo status for this post? This allows re-sending.', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '\');">' . esc_html__( 'Reset status', 'hge-automated-post-campaigns-for-klaviyo' ) . '</a></p>';
        }

        // Per-post newsletter overrides (since 3.0.15 / FcRapid1923-dcr) — Core+ only.
        $dcr_plan = function_exists( 'hge_klaviyo_active_plan' ) ? hge_klaviyo_active_plan() : 'free';
        if ( in_array( $dcr_plan, array( 'core', 'pro' ), true ) ) {
            $ov_excerpt = (string) get_post_meta( $post->ID, '_klaviyo_newsletter_excerpt', true );
            $ov_image   = (string) get_post_meta( $post->ID, '_klaviyo_newsletter_image', true );
            wp_nonce_field( 'hge_klaviyo_meta_' . $post->ID, 'hge_klaviyo_meta_nonce' );
            echo '<hr style="margin:12px 0 8px;">';
            echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Newsletter overrides', 'hge-automated-post-campaigns-for-klaviyo' ) . '</strong></p>';
            echo '<p style="margin:0 0 8px;"><label for="hge_klaviyo_ov_excerpt" style="display:block;font-size:12px;margin-bottom:2px;">' . esc_html__( 'Excerpt override (max 200)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label>';
            echo '<textarea id="hge_klaviyo_ov_excerpt" name="hge_klaviyo_ov_excerpt" rows="3" maxlength="200" style="width:100%;box-sizing:border-box;">' . esc_textarea( $ov_excerpt ) . '</textarea></p>';
            echo '<p style="margin:0;"><label for="hge_klaviyo_ov_image" style="display:block;font-size:12px;margin-bottom:2px;">' . esc_html__( 'Image URL fallback', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label>';
            echo '<input type="url" id="hge_klaviyo_ov_image" name="hge_klaviyo_ov_image" value="' . esc_attr( $ov_image ) . '" style="width:100%;box-sizing:border-box;" placeholder="https://…" /></p>';
            echo '<p class="description" style="font-size:11px;">' . esc_html__( 'Used in the built-in newsletter instead of the post excerpt / featured image when set.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
            // Per-post Klaviyo template override (FcRapid1923-bn2).
            echo '<p style="margin:10px 0 0;"><label for="hge_klaviyo_ov_template" style="display:block;font-size:12px;margin-bottom:2px;">' . esc_html__( 'Template for this post', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label>';
            hge_klaviyo_nl_render_template_select( 'hge_klaviyo_ov_template', (string) get_post_meta( $post->ID, '_klaviyo_template_id', true ), __( 'Use default', 'hge-automated-post-campaigns-for-klaviyo' ), 'hge_klaviyo_ov_template' );
            echo '</p>';
        } elseif ( function_exists( 'hge_klaviyo_upgrade_cta_html' ) ) {
            echo '<hr style="margin:12px 0 8px;"><p style="font-size:12px;margin:0;">' . esc_html__( 'Per-post excerpt / image override', 'hge-automated-post-campaigns-for-klaviyo' ) . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'core' ) ) . '</p>';
        }
    }
}

// Save the per-post newsletter override fields (FcRapid1923-dcr).
add_action( 'save_post_post', 'hge_klaviyo_save_meta_box', 10, 2 );

if ( ! function_exists( 'hge_klaviyo_save_meta_box' ) ) {
    function hge_klaviyo_save_meta_box( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! isset( $_POST['hge_klaviyo_meta_nonce'] ) ) {
            return;
        }
        $nonce = sanitize_text_field( wp_unslash( $_POST['hge_klaviyo_meta_nonce'] ) );
        if ( ! wp_verify_nonce( $nonce, 'hge_klaviyo_meta_' . $post_id ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        // Core+ only — fields aren't rendered on Free, so ignore any crafted POST.
        $plan = function_exists( 'hge_klaviyo_active_plan' ) ? hge_klaviyo_active_plan() : 'free';
        if ( ! in_array( $plan, array( 'core', 'pro' ), true ) ) {
            return;
        }

        $excerpt = isset( $_POST['hge_klaviyo_ov_excerpt'] )
            ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['hge_klaviyo_ov_excerpt'] ) ), 0, 200 )
            : '';
        if ( '' !== trim( $excerpt ) ) {
            update_post_meta( $post_id, '_klaviyo_newsletter_excerpt', $excerpt );
        } else {
            delete_post_meta( $post_id, '_klaviyo_newsletter_excerpt' );
        }

        $image = isset( $_POST['hge_klaviyo_ov_image'] )
            ? esc_url_raw( wp_unslash( $_POST['hge_klaviyo_ov_image'] ) )
            : '';
        if ( '' !== $image ) {
            update_post_meta( $post_id, '_klaviyo_newsletter_image', $image );
        } else {
            delete_post_meta( $post_id, '_klaviyo_newsletter_image' );
        }

        // Per-post Klaviyo template override (FcRapid1923-bn2).
        $tpl = isset( $_POST['hge_klaviyo_ov_template'] )
            ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) wp_unslash( $_POST['hge_klaviyo_ov_template'] ) )
            : '';
        if ( '' !== $tpl ) {
            update_post_meta( $post_id, '_klaviyo_template_id', $tpl );
        } else {
            delete_post_meta( $post_id, '_klaviyo_template_id' );
        }
    }
}

// -----------------------------------------------------------------------------
// admin-post handlers — manual send, reset post state, reset global cooldown
// -----------------------------------------------------------------------------

add_action( 'admin_post_hge_klaviyo_send_now',        'hge_klaviyo_handle_send_now' );
add_action( 'admin_post_hge_klaviyo_reset',           'hge_klaviyo_handle_reset' );
add_action( 'admin_post_hge_klaviyo_reset_cooldown',  'hge_klaviyo_handle_reset_cooldown' );

if ( ! function_exists( 'hge_klaviyo_handle_send_now' ) ) {
    function hge_klaviyo_handle_send_now() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        $post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
        check_admin_referer( 'hge_klaviyo_send_now_' . $post_id );

        if ( $post_id ) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- intentional; dispatch can take several seconds via 3-5 Klaviyo API round-trips, and a 30s default could time out under cold cache.
            @set_time_limit( 60 );
            hge_klaviyo_dispatch_newsletter( $post_id );
        }

        $error = $post_id ? get_post_meta( $post_id, HGE_KLAVIYO_NL_META_ERROR, true ) : '';
        $sent  = $post_id ? get_post_meta( $post_id, HGE_KLAVIYO_NL_META_SENT, true ) : '';
        $msg   = $error ? 'klaviyo_error' : ( 'yes' === $sent ? 'klaviyo_sent' : 'klaviyo_unknown' );

        $return_to = ( isset( $_GET['return'] ) && 'tools' === $_GET['return'] )
            ? admin_url( 'tools.php?page=hge-klaviyo-newsletter' )
            : get_edit_post_link( $post_id, 'url' );

        wp_safe_redirect( add_query_arg( 'klaviyo_msg', $msg, $return_to ) );
        exit;
    }
}

if ( ! function_exists( 'hge_klaviyo_handle_reset' ) ) {
    function hge_klaviyo_handle_reset() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        $post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
        check_admin_referer( 'hge_klaviyo_reset_' . $post_id );

        if ( $post_id ) {
            delete_post_meta( $post_id, HGE_KLAVIYO_NL_META_SENT );
            delete_post_meta( $post_id, HGE_KLAVIYO_NL_META_CAMP_ID );
            delete_post_meta( $post_id, HGE_KLAVIYO_NL_META_SENT_AT );
            delete_post_meta( $post_id, HGE_KLAVIYO_NL_META_SCHED_FOR );
            delete_post_meta( $post_id, HGE_KLAVIYO_NL_META_ERROR );
            delete_post_meta( $post_id, HGE_KLAVIYO_NL_META_LOCK );
        }

        $return_to = ( isset( $_GET['return'] ) && 'tools' === $_GET['return'] )
            ? admin_url( 'tools.php?page=hge-klaviyo-newsletter' )
            : get_edit_post_link( $post_id, 'url' );

        wp_safe_redirect( add_query_arg( 'klaviyo_msg', 'klaviyo_reset', $return_to ) );
        exit;
    }
}

if ( ! function_exists( 'hge_klaviyo_handle_reset_cooldown' ) ) {
    function hge_klaviyo_handle_reset_cooldown() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'hge_klaviyo_reset_cooldown' );
        delete_option( HGE_KLAVIYO_NL_OPT_LAST_SEND );
        wp_safe_redirect( add_query_arg( 'klaviyo_msg', 'klaviyo_cooldown_reset', admin_url( 'tools.php?page=hge-klaviyo-newsletter' ) ) );
        exit;
    }
}

// -----------------------------------------------------------------------------
// Admin notices for action results
// -----------------------------------------------------------------------------

add_action( 'admin_notices', 'hge_klaviyo_admin_notices' );

// Tier-cap suppressed-dispatch notice (since 3.0.14 / FcRapid1923-omh).
// Shows a one-shot notice on the post-edit screen when the dispatch for
// that post was suppressed by the Free/Core tier cap. Deletes the
// `_hge_klaviyo_tier_suppressed` meta after the first render so the
// notice never repeats.
add_action( 'admin_notices', 'hge_klaviyo_tier_suppressed_notice' );

if ( ! function_exists( 'hge_klaviyo_tier_suppressed_notice' ) ) {
    function hge_klaviyo_tier_suppressed_notice() {
        global $pagenow;
        if ( 'post.php' !== $pagenow ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check for the post being edited; non-mutating display flag.
        $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
        if ( $post_id <= 0 ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        $suppressed = get_post_meta( $post_id, '_hge_klaviyo_tier_suppressed', true );
        if ( ! is_array( $suppressed ) || empty( $suppressed ) ) {
            return;
        }
        delete_post_meta( $post_id, '_hge_klaviyo_tier_suppressed' );

        $plan_key   = isset( $suppressed['plan'] ) ? (string) $suppressed['plan'] : 'free';
        $plan_label = 'core' === $plan_key
            ? __( 'Core', 'hge-automated-post-campaigns-for-klaviyo' )
            : __( 'Free', 'hge-automated-post-campaigns-for-klaviyo' );
        $floor      = isset( $suppressed['tier_floor_hours'] ) ? (int) $suppressed['tier_floor_hours'] : 0;
        $floor_days = $floor > 0 ? max( 1, (int) round( $floor / 24 ) ) : 0;
        $last       = isset( $suppressed['last_send'] ) ? (int) $suppressed['last_send'] : 0;
        $next       = isset( $suppressed['next_allowed_at'] ) ? (int) $suppressed['next_allowed_at'] : 0;

        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo wp_kses_post( sprintf(
            /* translators: 1: plan label, 2: tier floor in days */
            __( '<strong>Klaviyo newsletter not sent for this post.</strong> The %1$s plan caps newsletters to <strong>1 per %2$d days</strong> per rule.', 'hge-automated-post-campaigns-for-klaviyo' ),
            esc_html( $plan_label ),
            $floor_days
        ) );
        if ( $last > 0 ) {
            echo ' ' . esc_html( sprintf(
                /* translators: %s: human-readable time (e.g. "5 days ago") */
                __( 'Last newsletter for this rule was sent %s.', 'hge-automated-post-campaigns-for-klaviyo' ),
                human_time_diff( $last, time() ) . ' ' . __( 'ago', 'hge-automated-post-campaigns-for-klaviyo' )
            ) );
        }
        if ( $next > time() ) {
            echo ' ' . esc_html( sprintf(
                /* translators: %s: human-readable time (e.g. "in 22 days") */
                __( 'Next dispatch allowed in %s.', 'hge-automated-post-campaigns-for-klaviyo' ),
                human_time_diff( time(), $next )
            ) );
        }
        echo ' ' . esc_html__( 'Upgrade for shorter cooldowns.', 'hge-automated-post-campaigns-for-klaviyo' );
        echo '</p></div>';
    }
}

if ( ! function_exists( 'hge_klaviyo_admin_notices' ) ) {
    function hge_klaviyo_admin_notices() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from a redirect after a nonced admin-post action; no DB write or auth side effect from reading this.
        if ( empty( $_GET['klaviyo_msg'] ) ) {
            return;
        }
        $messages = apply_filters( 'hge_klaviyo_admin_notice_messages', array(
            'klaviyo_sent'           => array( 'success', __( 'Newsletter sent successfully via Klaviyo.', 'hge-automated-post-campaigns-for-klaviyo' ) ),
            'klaviyo_error'          => array( 'error',   __( 'Error sending newsletter — see "Last error" in the meta box.', 'hge-automated-post-campaigns-for-klaviyo' ) ),
            'klaviyo_unknown'        => array( 'warning', __( 'Uncertain status — check Custom Fields manually.', 'hge-automated-post-campaigns-for-klaviyo' ) ),
            'klaviyo_reset'          => array( 'success', __( 'Klaviyo status reset. You can re-send.', 'hge-automated-post-campaigns-for-klaviyo' ) ),
            'klaviyo_cooldown_reset' => array( 'success', __( 'Global cooldown reset. The next publish sends immediately.', 'hge-automated-post-campaigns-for-klaviyo' ) ),
        ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same display-flag read; sanitize_key + array-key allowlist below prevent any injection.
        $msg = sanitize_key( wp_unslash( $_GET['klaviyo_msg'] ) );
        if ( ! isset( $messages[ $msg ] ) ) {
            return;
        }
        list( $class, $text ) = $messages[ $msg ];
        echo '<div class="notice notice-' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
    }
}

// -----------------------------------------------------------------------------
// Tools → Klaviyo Newsletter — dedicated admin page
// -----------------------------------------------------------------------------

add_action( 'admin_menu', 'hge_klaviyo_register_tools_page' );

if ( ! function_exists( 'hge_klaviyo_register_tools_page' ) ) {
    function hge_klaviyo_register_tools_page() {
        // Admin menu slug intentionally kept as the legacy 'hge-klaviyo-newsletter'
        // even though the WP plugin folder + Text Domain were renamed for
        // wp.org / trademark compliance. The admin URL is bookmarked by
        // existing customers, referenced by the Pro extension plugin, and
        // documented in support material. Changing the menu slug would
        // 404 every existing bookmark and break Pro -> Free deep links —
        // both customer-impacting. Keep the slug stable; the rename is
        // cosmetic at the directory level only.
        add_management_page(
            __( 'Klaviyo Newsletter', 'hge-automated-post-campaigns-for-klaviyo' ),
            __( 'Klaviyo Newsletter', 'hge-automated-post-campaigns-for-klaviyo' ),
            'manage_options',
            'hge-klaviyo-newsletter',
            'hge_klaviyo_render_tools_page'
        );
    }
}

if ( ! function_exists( 'hge_klaviyo_render_tools_page' ) ) {
    function hge_klaviyo_render_tools_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }

        $version       = defined( 'HGE_KLAVIYO_NL_VERSION' ) ? HGE_KLAVIYO_NL_VERSION : '?';
        $settings_now  = function_exists( 'hge_klaviyo_nl_get_settings' ) ? hge_klaviyo_nl_get_settings() : array();
        $debug_enabled = ! empty( $settings_now['debug_mode'] );

        // Tabs registry. Free emits "Setări" by default. Pro adds "Licență Pro" via filter.
        // "Status" (former Diagnostic) appears only when debug_mode is on (Settings → Debug mode).
        $tabs = apply_filters( 'hge_klaviyo_admin_tabs', array(
            'settings' => __( 'Settings', 'hge-automated-post-campaigns-for-klaviyo' ),
        ) );
        if ( $debug_enabled ) {
            $tabs['diagnostic'] = __( 'Status', 'hge-automated-post-campaigns-for-klaviyo' );
        }
        // Logs tab (Core+ — FcRapid1923-8ou): queryable dispatch history.
        if ( function_exists( 'hge_klaviyo_active_plan' ) && in_array( hge_klaviyo_active_plan(), array( 'core', 'pro' ), true ) ) {
            $tabs['logs'] = __( 'Logs', 'hge-automated-post-campaigns-for-klaviyo' );
        }

        // Enforce display order: Setări → Licență Pro → Logs → Status (orice tab terț apare la final).
        $ordered = array();
        foreach ( array( 'settings', 'license', 'logs', 'diagnostic' ) as $known ) {
            if ( isset( $tabs[ $known ] ) ) {
                $ordered[ $known ] = $tabs[ $known ];
            }
        }
        foreach ( $tabs as $k => $v ) {
            if ( ! isset( $ordered[ $k ] ) ) {
                $ordered[ $k ] = $v;
            }
        }
        $tabs = $ordered;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab routing for an admin-only page (manage_options enforced above); sanitize_key + array-key allowlist below prevent any injection.
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
        if ( ! array_key_exists( $active_tab, $tabs ) ) {
            $active_tab = 'settings';
        }

        echo '<div class="wrap">';
        echo '<h1>Klaviyo Newsletter <span style="font-size:13px;color:#666;font-weight:normal;">v' . esc_html( $version ) . '</span></h1>';

        echo '<h2 class="nav-tab-wrapper" style="margin-bottom:16px;">';
        foreach ( $tabs as $key => $label ) {
            $url   = admin_url( 'tools.php?page=hge-klaviyo-newsletter&tab=' . $key );
            $class = 'nav-tab' . ( $active_tab === $key ? ' nav-tab-active' : '' );
            echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</h2>';

        if ( 'settings' === $active_tab ) {
            hge_klaviyo_render_settings_tab();
            echo '</div>';
            return;
        }

        // Logs tab (Core+ — FcRapid1923-8ou).
        if ( 'logs' === $active_tab && function_exists( 'hge_klaviyo_nl_render_logs_tab' ) ) {
            hge_klaviyo_nl_render_logs_tab();
            echo '</div>';
            return;
        }

        // Externally registered tabs (Pro: license, Pro: logs, etc.)
        if ( ! in_array( $active_tab, array( 'diagnostic', 'settings' ), true ) ) {
            do_action( 'hge_klaviyo_render_tab_' . $active_tab );
            echo '</div>';
            return;
        }

        // ====== Diagnostic tab ======

        // Diagnostic "source" row: under normal install the plugin loads admin.php
        // and HGE_KLAVIYO_NL_PLUGIN_FILE is defined. The else branch is kept as a
        // safety net for legacy installs that may still have shadow copies of this
        // code in their theme — the fallback label is intentionally generic.
        $source_is_plugin = defined( 'HGE_KLAVIYO_NL_PLUGIN_FILE' );
        $source_file      = $source_is_plugin
            ? str_replace( WP_CONTENT_DIR, 'wp-content', HGE_KLAVIYO_NL_PLUGIN_DIR . 'includes/admin.php' )
            : '(theme legacy fallback)';

        $settings  = function_exists( 'hge_klaviyo_nl_get_settings' ) ? hge_klaviyo_nl_get_settings() : array();
        $config_ok = function_exists( 'hge_klaviyo_nl_settings_complete' ) && hge_klaviyo_nl_settings_complete();
        $as_loaded = function_exists( 'as_enqueue_async_action' );
        $rules     = is_array( $settings['tag_rules'] ?? null ) ? $settings['tag_rules'] : array();

        echo '<table class="widefat striped" style="max-width:720px;"><tbody>';
        printf( '<tr><td>%s</td><td><code>%s</code></td></tr>',
            esc_html__( 'Code version (constant)', 'hge-automated-post-campaigns-for-klaviyo' ),
            esc_html( $version )
        );
        printf( '<tr><td>%s</td><td>%s — <code style="font-size:11px;">%s</code></td></tr>',
            esc_html__( 'Active code source', 'hge-automated-post-campaigns-for-klaviyo' ),
            $source_is_plugin
                ? '<span style="color:#1e8e3e;">✓ plugin</span>'
                : '<span style="color:#c45500;">⚠ ' . esc_html__( 'theme legacy', 'hge-automated-post-campaigns-for-klaviyo' ) . '</span>',
            esc_html( $source_file )
        );
        printf( '<tr><td>%s</td><td>%s</td></tr>',
            esc_html__( 'Configuration', 'hge-automated-post-campaigns-for-klaviyo' ),
            $config_ok
                ? '<span style="color:#1e8e3e;">✓ ' . esc_html__( 'complete', 'hge-automated-post-campaigns-for-klaviyo' ) . '</span> (' . esc_html__( 'Settings tab', 'hge-automated-post-campaigns-for-klaviyo' ) . ')'
                : '<span style="color:#c00;">✗ ' . wp_kses_post(
                    sprintf(
                        /* translators: %s is the Settings tab link */
                        __( 'incomplete — see %s', 'hge-automated-post-campaigns-for-klaviyo' ),
                        '<a href="' . esc_url( admin_url( 'tools.php?page=hge-klaviyo-newsletter&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'hge-automated-post-campaigns-for-klaviyo' ) . '</a>'
                    )
                ) . '</span>'
        );
        printf( '<tr><td>Action Scheduler</td><td>%s</td></tr>',
            $as_loaded
                ? '<span style="color:#1e8e3e;">✓ ' . esc_html__( 'loaded', 'hge-automated-post-campaigns-for-klaviyo' ) . '</span>'
                : '<span style="color:#c00;">✗ ' . esc_html__( 'not loaded (check WooCommerce)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</span>'
        );

        printf( '<tr><td>%s</td><td>%d / %d (' . esc_html__( 'plan', 'hge-automated-post-campaigns-for-klaviyo' ) . ': <code>%s</code>)</td></tr>',
            esc_html__( 'Configured rules', 'hge-automated-post-campaigns-for-klaviyo' ),
            count( $rules ),
            (int) hge_klaviyo_nl_max_rules(),
            esc_html( function_exists( 'hge_klaviyo_active_plan' ) ? hge_klaviyo_active_plan() : 'free' )
        );

        $feed_token_resolved = function_exists( 'hge_klaviyo_nl_resolve_feed_token' ) ? hge_klaviyo_nl_resolve_feed_token() : '';
        $any_web_feed        = hge_klaviyo_use_web_feed();

        // Per-rule active-post lookup. Replaces the legacy single-transient diagnostic
        // in 2.x — each rule with Web Feed enabled has its own keyed transient.
        if ( $any_web_feed ) {
            printf( '<tr><td>%s</td><td>%s</td></tr>',
                esc_html__( 'Feed token', 'hge-automated-post-campaigns-for-klaviyo' ),
                '' !== $feed_token_resolved
                    ? '<span style="color:#1e8e3e;">✓ ' . esc_html__( 'configured', 'hge-automated-post-campaigns-for-klaviyo' ) . '</span> (' . esc_html( strlen( $feed_token_resolved ) ) . ' ' . esc_html__( 'characters', 'hge-automated-post-campaigns-for-klaviyo' ) . ')'
                    : '<span style="color:#c00;">✗ ' . esc_html__( 'not defined — Klaviyo cannot authenticate to the feed', 'hge-automated-post-campaigns-for-klaviyo' ) . '</span>' );
        }
        printf( '<tr><td>%s</td><td>%d ' . esc_html__( 'characters', 'hge-automated-post-campaigns-for-klaviyo' ) . '</td></tr>',
            esc_html__( 'Excerpt length', 'hge-automated-post-campaigns-for-klaviyo' ),
            (int) apply_filters( 'hge_klaviyo_excerpt_length', 120 )
        );
        printf( '<tr><td>%s</td><td>%d %s</td></tr>',
            esc_html__( 'Subject length (ASCII only)', 'hge-automated-post-campaigns-for-klaviyo' ),
            (int) apply_filters( 'hge_klaviyo_subject_length', 60 ),
            esc_html__( 'characters, no diacritics', 'hge-automated-post-campaigns-for-klaviyo' )
        );

        printf( '<tr><td>Smart Sending</td><td><span style="color:#c00;">%s</span> — %s</td></tr>',
            esc_html__( 'OFF', 'hge-automated-post-campaigns-for-klaviyo' ),
            esc_html__( 'all list recipients receive the campaign', 'hge-automated-post-campaigns-for-klaviyo' )
        );

        $min_int_h = (int) ( hge_klaviyo_min_interval_seconds() / HOUR_IN_SECONDS );
        printf(
            '<tr><td>%s</td><td>%d %s <em>(%s)</em></td></tr>',
            esc_html__( 'Minimum interval between sends', 'hge-automated-post-campaigns-for-klaviyo' ),
            (int) $min_int_h, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %d format specifier + (int) cast guarantee a safe integer.
            esc_html__( 'hours', 'hge-automated-post-campaigns-for-klaviyo' ),
            esc_html__( 'per rule', 'hge-automated-post-campaigns-for-klaviyo' )
        );
        echo '</tbody></table>';

        // Per-rule diagnostic — replaces the legacy single-tag/template summary.
        // Per-rule "Active post" column reads the keyed transient (since 3.0.0)
        // so a leftover post-id from any specific rule's Web Feed is surfaced.
        if ( ! empty( $rules ) ) {
            echo '<h3 style="margin-top:18px;">' . esc_html__( 'Active rules', 'hge-automated-post-campaigns-for-klaviyo' ) . '</h3>';
            echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr>';
            echo '<th>#</th>'
                . '<th>' . esc_html__( 'Tag(s)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th>'
                . '<th>' . esc_html__( 'Included', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th>'
                . '<th>' . esc_html__( 'Excluded', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th>'
                . '<th>' . esc_html__( 'Template', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th>'
                . '<th>' . esc_html__( 'Web Feed (name)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th>'
                . '<th>' . esc_html__( 'Active post', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th>'
                . '<th>' . esc_html__( 'Last send (UTC)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $rules as $i => $r ) {
                $slug  = (string) ( $r['tag_slug'] ?? '' );
                $inc   = (array)  ( $r['included_list_ids'] ?? array() );
                $exc   = (array)  ( $r['excluded_list_ids'] ?? array() );
                $tpl   = (string) ( $r['template_id'] ?? '' );
                $wf    = ! empty( $r['use_web_feed'] );
                $wf_name = (string) ( $r['web_feed_name'] ?? 'newsletter_feed' );
                $last  = function_exists( 'hge_klaviyo_nl_get_last_send_for_slug' )
                    ? (int) hge_klaviyo_nl_get_last_send_for_slug( $slug )
                    : 0;

                $active_post_cell = '<em>—</em>';
                if ( $wf && function_exists( 'hge_klaviyo_nl_transient_key_for_feed' ) ) {
                    $key = hge_klaviyo_nl_transient_key_for_feed( $wf_name );
                    $pid = (int) get_transient( $key );
                    if ( $pid ) {
                        $cp = get_post( $pid );
                        $active_post_cell = $cp
                            ? '<a href="' . esc_url( get_edit_post_link( $pid ) ) . '">' . esc_html( get_the_title( $cp ) ) . '</a> <small>(' . (int) $pid . ')</small>'
                            : '<em>(' . esc_html__( 'post not found, id=', 'hge-automated-post-campaigns-for-klaviyo' ) . (int) $pid . ')</em>';
                    }
                }

                echo '<tr>';
                printf( '<td>%d</td>', (int) ( $i + 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %d + (int) cast guarantee safe integer.
                printf( '<td><code>%s</code></td>', esc_html( $slug !== '' ? $slug : '—' ) );
                printf( '<td>%s</td>', $inc ? esc_html( implode( ', ', $inc ) ) : '<em>—</em>' );
                printf( '<td>%s</td>', $exc ? esc_html( implode( ', ', $exc ) ) : '<em>—</em>' );
                printf( '<td>%s</td>', $tpl ? '<code>' . esc_html( $tpl ) . '</code>' : '<em>' . esc_html__( 'built-in', 'hge-automated-post-campaigns-for-klaviyo' ) . '</em>' );
                printf( '<td>%s</td>', $wf ? '<span style="color:#1e8e3e;">' . esc_html__( 'ACTIVE', 'hge-automated-post-campaigns-for-klaviyo' ) . '</span> <code>' . esc_html( $wf_name ) . '</code>' : '—' );
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $active_post_cell is composed of esc_url() + esc_html() + esc_html__(); pre-escaped HTML.
                echo '<td>' . $active_post_cell . '</td>';
                printf( '<td>%s</td>', $last ? esc_html( gmdate( 'Y-m-d H:i:s', $last ) ) : '<em>—</em>' );
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Legacy global cooldown reset — still useful for testing
        $legacy_last_send = (int) get_option( HGE_KLAVIYO_NL_OPT_LAST_SEND, 0 );
        if ( $legacy_last_send ) {
            $reset_cd_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=hge_klaviyo_reset_cooldown' ),
                'hge_klaviyo_reset_cooldown'
            );
            echo '<p style="margin-top:8px;"><a href="' . esc_url( $reset_cd_url ) . '" class="button" onclick="return confirm(\'' . esc_js( __( 'Reset the legacy global cooldown? Per-rule cooldowns remain untouched.', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '\');">' . esc_html__( 'Reset legacy global cooldown', 'hge-automated-post-campaigns-for-klaviyo' ) . '</a> <em style="font-size:12px;">— ' . esc_html__( 'resets the v2.x legacy option. Per-rule cooldowns remain in', 'hge-automated-post-campaigns-for-klaviyo' ) . ' <code>hge_klaviyo_last_send_at_by_slug</code>.</em></p>';
        }

        echo '<h3 style="margin-top:18px;">' . esc_html__( 'Placeholders available in the Klaviyo template', 'hge-automated-post-campaigns-for-klaviyo' ) . '</h3>';
        echo '<p style="font-size:13px;">' . esc_html__( 'Drop any of these into your Klaviyo template HTML (selected in Settings); they are replaced per post before the campaign is dispatched.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        echo '<table class="widefat striped" style="max-width:720px;"><tbody>';
        echo '<tr><td><code>{{title}}</code></td><td>' . esc_html__( 'Post title (HTML escaped)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</td></tr>';
        echo '<tr><td><code>{{excerpt}}</code></td><td>' . esc_html__( 'Short description (max 120 chars, HTML escaped)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</td></tr>';
        echo '<tr><td><code>{{image}}</code></td><td>' . wp_kses_post( __( 'Featured image URL (use inside <code>src=""</code>)', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '</td></tr>';
        echo '<tr><td><code>{{url}}</code></td><td>' . wp_kses_post( __( 'Post URL with UTM (use inside <code>href=""</code>)', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '</td></tr>';
        echo '<tr><td><code>{{date}}</code></td><td>' . esc_html__( 'Publication date (WP-formatted)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</td></tr>';
        echo '<tr><td><code>{{site}}</code></td><td>' . esc_html__( 'Site name', 'hge-automated-post-campaigns-for-klaviyo' ) . '</td></tr>';
        echo '</tbody></table>';

        // Collect all tag slugs from all rules (split comma-separated for Pro)
        $all_slugs = array();
        foreach ( $rules as $r ) {
            $raw = (string) ( $r['tag_slug'] ?? '' );
            foreach ( explode( ',', $raw ) as $part ) {
                $part = trim( $part );
                if ( '' !== $part ) {
                    $all_slugs[] = $part;
                }
            }
        }
        $all_slugs = array_values( array_unique( $all_slugs ) );

        if ( empty( $all_slugs ) ) {
            echo '<div class="notice notice-warning inline" style="margin-top:12px;"><p>' . wp_kses_post(
                sprintf(
                    /* translators: %s is the Settings tab link */
                    __( 'No rule with a <code>tag_slug</code> configured. Set at least one rule in %s.', 'hge-automated-post-campaigns-for-klaviyo' ),
                    '<a href="' . esc_url( admin_url( 'tools.php?page=hge-klaviyo-newsletter&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'hge-automated-post-campaigns-for-klaviyo' ) . '</a>'
                )
            ) . '</p></div>';
            echo '</div>';
            return;
        }

        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- admin-only diagnostic view (manage_options gated by caller); LIMIT 20 caps the cost; tax_query is essential for filtering posts by configured tag slugs.
        $posts = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'any',
            'posts_per_page' => 20,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'post_tag',
                    'field'    => 'slug',
                    'terms'    => $all_slugs,
                ),
            ),
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ) );
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_tax_query

        $slugs_html = implode( ', ', array_map( static function ( $s ) {
            return '<code>' . esc_html( $s ) . '</code>';
        }, $all_slugs ) );
        echo '<h2 style="margin-top:24px;">' . wp_kses_post(
            sprintf(
                /* translators: %s is a comma-separated list of <code>-wrapped tag slugs */
                __( 'Posts with configured tags (%s) — last 20', 'hge-automated-post-campaigns-for-klaviyo' ),
                $slugs_html
            )
        ) . '</h2>';

        if ( empty( $posts ) ) {
            echo '<p><em>' . esc_html__( 'No posts found with any of the configured tags.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</em></p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__( 'Title', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th>'
            . '<th>' . esc_html__( 'WP status', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th>'
            . '<th>' . esc_html__( 'Sent?', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th>'
            . '<th>' . esc_html__( 'Campaign ID', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th>'
            . '<th>' . esc_html__( 'Scheduled / Sent at (UTC)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th>'
            . '<th>' . esc_html__( 'Error', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th>'
            . '<th>' . esc_html__( 'Actions', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $posts as $p ) {
            $sent    = get_post_meta( $p->ID, HGE_KLAVIYO_NL_META_SENT, true );
            $camp_id = get_post_meta( $p->ID, HGE_KLAVIYO_NL_META_CAMP_ID, true );
            $sent_at = get_post_meta( $p->ID, HGE_KLAVIYO_NL_META_SENT_AT, true );
            $sched   = get_post_meta( $p->ID, HGE_KLAVIYO_NL_META_SCHED_FOR, true );
            $error   = get_post_meta( $p->ID, HGE_KLAVIYO_NL_META_ERROR, true );

            $send_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=hge_klaviyo_send_now&post_id=' . $p->ID . '&return=tools' ),
                'hge_klaviyo_send_now_' . $p->ID
            );
            $reset_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=hge_klaviyo_reset&post_id=' . $p->ID . '&return=tools' ),
                'hge_klaviyo_reset_' . $p->ID
            );

            echo '<tr>';
            echo '<td><a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></td>';
            echo '<td><code>' . esc_html( $p->post_status ) . '</code></td>';
            echo '<td>' . ( 'yes' === $sent ? '<span style="color:#1e8e3e;">✓</span>' : '—' ) . '</td>';
            echo '<td>' . ( $camp_id ? '<code>' . esc_html( $camp_id ) . '</code>' : '—' ) . '</td>';
            if ( $sched ) {
                echo '<td><strong>📅 ' . esc_html( $sched ) . '</strong><br><small>(' . esc_html__( 'dispatch:', 'hge-automated-post-campaigns-for-klaviyo' ) . ' ' . esc_html( $sent_at ) . ')</small></td>';
            } else {
                echo '<td>' . ( $sent_at ? esc_html( $sent_at ) : '—' ) . '</td>';
            }
            echo '<td>' . ( $error ? '<code style="color:#c00;font-size:11px;">' . esc_html( substr( $error, 0, 120 ) ) . '</code>' : '—' ) . '</td>';
            echo '<td>';
            if ( 'publish' === $p->post_status && 'yes' !== $sent && $config_ok ) {
                echo '<a href="' . esc_url( $send_url ) . '" class="button button-small button-primary" onclick="return confirm(\'' . esc_js( __( 'Send newsletter to the Klaviyo list?', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '\');">' . esc_html__( 'Send', 'hge-automated-post-campaigns-for-klaviyo' ) . '</a> ';
            }
            if ( 'yes' === $sent || $error ) {
                echo '<a href="' . esc_url( $reset_url ) . '" class="button button-small" onclick="return confirm(\'' . esc_js( __( 'Reset Klaviyo status?', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '\');">' . esc_html__( 'Reset', 'hge-automated-post-campaigns-for-klaviyo' ) . '</a>';
            }
            echo '</td></tr>';
        }

        echo '</tbody></table>';

        /**
         * Action — let Pro feature modules render extra debug sections at the
         * bottom of the Status tab (e.g., webhook activity log, server response).
         * Only fired when the Status tab is rendered (i.e., debug_mode is on).
         *
         * @since 2.3.0
         */
        do_action( 'hge_klaviyo_render_status_extra' );

        echo '</div>';
    }
}

// -----------------------------------------------------------------------------
// Settings tab — UI for the database-backed configuration
// -----------------------------------------------------------------------------

/**
 * Format a Klaviyo list profile count for display in <option> labels.
 * Returns "" (empty) when count is null/missing — graceful degradation when the
 * Klaviyo API revision doesn't include `profile_count` or the field was filtered.
 *
 * Locale-aware: uses `number_format_i18n` so the thousands separator matches the
 * site language (e.g., Romanian: "5.432" with dot as thousands separator).
 *
 * @since 2.4.0
 * @param int|null $count Subscriber count or null when unknown.
 * @return string Formatted suffix like " — 5.432 abonați" or "" when count is null.
 */
if ( ! function_exists( 'hge_klaviyo_format_list_count' ) ) {
    function hge_klaviyo_format_list_count( $count ) {
        if ( null === $count || ! is_numeric( $count ) ) {
            return '';
        }
        $count = (int) $count;
        $word  = _n( 'subscriber', 'subscribers', $count, 'hge-automated-post-campaigns-for-klaviyo' );
        return ' — ' . number_format_i18n( $count ) . ' ' . $word;
    }
}

/**
 * Translate raw Klaviyo API error messages into short Romanian admin notices.
 * The full raw message is logged via the dispatcher's `error_log` for debugging;
 * this helper exists so the Settings tab UI doesn't dump JSON-API error blobs at
 * the user.
 *
 * @since 2.3.1
 * @param string $raw Original error message (typically the WP_Error message
 *                    returned by `hge_klaviyo_api_request`).
 * @return string HTML-safe (HTML allowed) friendly message.
 */
if ( ! function_exists( 'hge_klaviyo_friendly_api_error' ) ) {
    function hge_klaviyo_friendly_api_error( $raw ) {
        $raw = (string) $raw;

        // No API key configured locally
        if ( false !== strpos( $raw, 'API key not configured' )
             || false !== stripos( $raw, 'klaviyo_api_no_key' ) ) {
            return __( 'No Klaviyo API key configured. Fill in the <strong>Klaviyo API Key</strong> field above and click <strong>Save settings</strong>.', 'hge-automated-post-campaigns-for-klaviyo' );
        }

        // 401 — invalid / revoked / wrong key
        if ( false !== strpos( $raw, 'HTTP 401' )
             || false !== stripos( $raw, 'authentication_failed' )
             || false !== stripos( $raw, 'Incorrect authentication credentials' ) ) {
            return __( 'The Klaviyo API key is invalid or has been revoked. Generate a new key in Klaviyo &rarr; Settings &rarr; API Keys, replace it in the <strong>Klaviyo API Key</strong> field above and click <strong>Save settings</strong>.', 'hge-automated-post-campaigns-for-klaviyo' );
        }

        // 403 — insufficient scopes
        if ( false !== strpos( $raw, 'HTTP 403' ) ) {
            return __( 'The Klaviyo API key lacks the required scopes. Required: <code>campaigns:write</code>, <code>templates:write</code>, <code>lists:read</code>, <code>segments:read</code>. Generate a new key with all scopes checked and save.', 'hge-automated-post-campaigns-for-klaviyo' );
        }

        // 429 — rate limited
        if ( false !== strpos( $raw, 'HTTP 429' ) ) {
            return __( 'Klaviyo applied rate-limiting (too many requests in a short window). Wait a few minutes and try again.', 'hge-automated-post-campaigns-for-klaviyo' );
        }

        // 5xx — Klaviyo down
        if ( preg_match( '/HTTP 5\d\d/', $raw ) ) {
            return __( 'The Klaviyo server is not responding correctly (5xx). Try again in a few minutes. If the issue persists, check <a href="https://status.klaviyo.com/" target="_blank" rel="noopener">status.klaviyo.com</a>.', 'hge-automated-post-campaigns-for-klaviyo' );
        }

        // Network / timeout
        if ( false !== stripos( $raw, 'cURL error' )
             || false !== stripos( $raw, 'timed out' )
             || false !== stripos( $raw, 'could not resolve host' ) ) {
            return __( 'Network error. The WordPress server cannot reach <code>a.klaviyo.com</code>. Check DNS, the firewall, or whether an outbound proxy is in place on this install.', 'hge-automated-post-campaigns-for-klaviyo' );
        }

        // Default — strip JSON-API noise but keep something readable
        // Reduce verbose JSON to first 160 chars of the human-relevant prefix.
        $short = substr( $raw, 0, 160 );
        return esc_html( $short );
    }
}

add_action( 'admin_post_hge_klaviyo_save_settings',  'hge_klaviyo_handle_save_settings' );
add_action( 'admin_post_hge_klaviyo_refresh_api',    'hge_klaviyo_handle_refresh_api_cache' );

/**
 * Persist the Settings form. Capability + nonce checked, POST data unslashed.
 *
 * @since 2.2.0
 * @since 3.0.0 Reads `tag_rules[]` array (cards system) instead of the legacy
 *              top-level included/excluded/template/web_feed keys (those were
 *              removed in v3.0). `array_values()` re-keys the rules so removed
 *              cards leave no gaps.
 */
if ( ! function_exists( 'hge_klaviyo_handle_save_settings' ) ) {
    function hge_klaviyo_handle_save_settings() {
        // Capability + nonce checks first — Plugin Check expects ANY $_POST
        // read (even for diagnostic logging) to happen AFTER the referer
        // verification. Logging the audit entry moves below the
        // unslash+sanitize step.
        if ( ! current_user_can( 'manage_options' ) ) {
            if ( class_exists( 'HgE_Klaviyo_Logger' ) ) {
                HgE_Klaviyo_Logger::error( 'Settings save denied — user lacks manage_options' );
            }
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'hge_klaviyo_save_settings' );

        $input = isset( $_POST['hge_klaviyo'] ) && is_array( $_POST['hge_klaviyo'] )
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nested array, each leaf is sanitized later in $partial + hge_klaviyo_nl_sanitize_settings filter chain.
            ? wp_unslash( $_POST['hge_klaviyo'] )
            : array();

        // Always-on audit log — settings save is a critical operation; admins
        // need a record of WHO saved, WHEN, and WHAT keys came through.
        // Reads the already-unslashed + nonce-verified $input, not $_POST
        // directly. Sensitive values are length-only, never logged in
        // cleartext.
        if ( class_exists( 'HgE_Klaviyo_Logger' ) ) {
            HgE_Klaviyo_Logger::warning( 'Settings save handler invoked', array(
                'user_id'     => get_current_user_id(),
                'has_payload' => ! empty( $input ),
                'post_keys'   => array_keys( $input ),
            ) );
        }

        $partial = array(
            'api_key'            => isset( $input['api_key'] )            ? (string) $input['api_key']            : '',
            'feed_token'         => isset( $input['feed_token'] )         ? (string) $input['feed_token']         : '',
            'reply_to_email'     => isset( $input['reply_to_email'] )     ? (string) $input['reply_to_email']     : '',
            'min_interval_hours' => isset( $input['min_interval_hours'] ) ? (int) $input['min_interval_hours']    : 12,
            'debug_mode'         => ! empty( $input['debug_mode'] ),
            // tag_rules: wholesale-replaced by hge_klaviyo_nl_update_settings()
            // when present (see settings.php). Reindexed so removed cards leave
            // no gaps; sanitiser enforces tier caps via hge_klaviyo_nl_max_rules().
            'tag_rules'          => isset( $input['tag_rules'] ) && is_array( $input['tag_rules'] )
                ? array_values( $input['tag_rules'] )
                : array(),
        );

        // Diagnostic: log lengths (not values) of incoming sensitive fields so
        // we can tell whether the input arrived intact, was stripped by a
        // sanitiser, or never reached the handler. Keys map 1:1 to the form.
        if ( class_exists( 'HgE_Klaviyo_Logger' ) ) {
            HgE_Klaviyo_Logger::warning( 'Settings save — partial built', array(
                'api_key_len'       => strlen( $partial['api_key'] ),
                'feed_token_len'    => strlen( $partial['feed_token'] ),
                'reply_to_email'    => $partial['reply_to_email'],
                'min_interval_hours'=> $partial['min_interval_hours'],
                'debug_mode'        => $partial['debug_mode'],
                'tag_rules_count'   => count( $partial['tag_rules'] ),
            ) );
        }

        /**
         * Filter — let Pro feature modules pull their own POST keys into the partial
         * before update_settings sanitises and persists.
         *
         * @since 2.2.0
         * @param array $partial Settings keys/values about to be persisted.
         * @param array $input   Raw $_POST['hge_klaviyo'] array (already wp_unslashed).
         */
        $partial = apply_filters( 'hge_klaviyo_settings_save_partial', $partial, $input );

        $clean = hge_klaviyo_nl_update_settings( $partial );

        // Diagnostic: verify what actually landed in the DB after sanitise +
        // update_option. Length-only — never log the cleartext key.
        if ( class_exists( 'HgE_Klaviyo_Logger' ) ) {
            HgE_Klaviyo_Logger::warning( 'Settings save — persisted', array(
                'api_key_len_db'    => strlen( $clean['api_key'] ?? '' ),
                'feed_token_len_db' => strlen( $clean['feed_token'] ?? '' ),
                'rules_count_db'    => count( $clean['tag_rules'] ?? array() ),
            ) );
        }

        wp_safe_redirect( add_query_arg( 'klaviyo_msg', 'klaviyo_settings_saved', admin_url( 'tools.php?page=hge-klaviyo-newsletter&tab=settings' ) ) );
        exit;
    }
}

if ( ! function_exists( 'hge_klaviyo_handle_refresh_api_cache' ) ) {
    function hge_klaviyo_handle_refresh_api_cache() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'hge_klaviyo_refresh_api' );
        if ( function_exists( 'hge_klaviyo_nl_clear_api_cache' ) ) {
            hge_klaviyo_nl_clear_api_cache();
        }
        wp_safe_redirect( add_query_arg( 'klaviyo_msg', 'klaviyo_api_refreshed', admin_url( 'tools.php?page=hge-klaviyo-newsletter&tab=settings' ) ) );
        exit;
    }
}

/**
 * Render the Setări tab — global settings table + tier-gated cards system.
 *
 * Layout:
 *   1. <h2>Setări generale</h2> — API key, Feed token, Reply-to, Min interval, Debug mode
 *   2. <h2>Reguli newsletter</h2> — one card per tag_rule (rendered via hge_klaviyo_render_rule_card)
 *   3. "Adaugă regulă" button — disabled when count >= hge_klaviyo_nl_max_rules()
 *   4. <script type="text/template"> with blank-card HTML — cloned by inline JS
 *   5. Inline vanilla JS for add/remove/reindex (no jQuery)
 *
 * The cards' per-rule field gating mirrors hge_klaviyo_nl_rule_caps() so the
 * sanitiser silently caps client-side over-submission. Free always shows 1
 * card; Core up to 2; Pro up to 5 (see hge_klaviyo_nl_max_rules() in settings.php).
 *
 * @since 2.0.0
 * @since 3.0.0 Rewritten — cards system replaces single top-level list/template config.
 */
if ( ! function_exists( 'hge_klaviyo_nl_render_template_select' ) ) {
    /**
     * Render a <select> of the customer's Klaviyo email templates (cached) plus a
     * leading "built-in HTML" / "use default" option (value ""). Used by the
     * Settings default-template field and the per-post override (FcRapid1923-bn2).
     */
    function hge_klaviyo_nl_render_template_select( $name, $selected, $builtin_label = '', $id = '' ) {
        $templates = function_exists( 'hge_klaviyo_api_list_templates' ) ? hge_klaviyo_api_list_templates() : array();
        if ( is_wp_error( $templates ) ) {
            echo '<em>' . esc_html(
                sprintf(
                    /* translators: %s: API error message */
                    __( 'Could not load Klaviyo templates: %s', 'hge-automated-post-campaigns-for-klaviyo' ),
                    $templates->get_error_message()
                )
            ) . '</em>';
            return;
        }
        echo '<select name="' . esc_attr( $name ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
        echo '<option value="">' . esc_html( '' !== $builtin_label ? $builtin_label : __( 'Built-in HTML (default)', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '</option>';
        foreach ( (array) $templates as $t ) {
            $tid = isset( $t['id'] ) ? (string) $t['id'] : '';
            if ( '' === $tid ) {
                continue;
            }
            $label = isset( $t['name'] ) && '' !== $t['name'] ? (string) $t['name'] : $tid;
            echo '<option value="' . esc_attr( $tid ) . '" ' . selected( (string) $selected, $tid, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }
}

if ( ! function_exists( 'hge_klaviyo_render_settings_tab' ) ) {
    function hge_klaviyo_render_settings_tab() {
        $s              = hge_klaviyo_nl_get_settings();
        $plan           = function_exists( 'hge_klaviyo_active_plan' ) ? hge_klaviyo_active_plan() : 'free';
        $max_rules      = function_exists( 'hge_klaviyo_nl_max_rules' ) ? hge_klaviyo_nl_max_rules() : 1;
        $caps           = function_exists( 'hge_klaviyo_nl_rule_caps' ) ? hge_klaviyo_nl_rule_caps() : array( 'max_included' => 1, 'max_excluded' => 0, 'allow_template' => false, 'allow_web_feed' => false );
        $supports_multi = function_exists( 'hge_klaviyo_nl_supports_multi_tag_rule' ) && hge_klaviyo_nl_supports_multi_tag_rule();

        $action_url  = admin_url( 'admin-post.php' );
        $refresh_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=hge_klaviyo_refresh_api' ),
            'hge_klaviyo_refresh_api'
        );

        $can_query_api = '' !== $s['api_key'];

        // Try fetching lists + segments + templates only if API key is present.
        // Segments share the lists' dropdown (since 3.0.3) — Klaviyo's Campaigns
        // API accepts segment IDs alongside list IDs in audiences.included.
        $lists_data       = array();
        $segments_data    = array();
        $templates_data   = array();
        $api_error        = '';
        $templates_error  = '';
        $segments_error   = '';
        if ( $can_query_api && function_exists( 'hge_klaviyo_api_list_lists' ) ) {
            $lists = hge_klaviyo_api_list_lists();
            if ( is_wp_error( $lists ) ) {
                $api_error = $lists->get_error_message();
            } else {
                $lists_data = $lists;
            }
            if ( function_exists( 'hge_klaviyo_api_list_segments' ) ) {
                $segments = hge_klaviyo_api_list_segments();
                if ( is_wp_error( $segments ) ) {
                    $segments_error = $segments->get_error_message();
                } else {
                    $segments_data = $segments;
                }
            }
            $templates = hge_klaviyo_api_list_templates();
            if ( is_wp_error( $templates ) ) {
                $templates_error = $templates->get_error_message();
            } else {
                $templates_data = $templates;
            }
        }

        echo '<form method="post" action="' . esc_url( $action_url ) . '">';
        wp_nonce_field( 'hge_klaviyo_save_settings' );
        echo '<input type="hidden" name="action" value="hge_klaviyo_save_settings">';

        // ====== Section 1 — global settings (API key, feed token, etc.) ======

        echo '<h2>' . esc_html__( 'General settings', 'hge-automated-post-campaigns-for-klaviyo' ) . '</h2>';
        echo '<table class="form-table" role="presentation">';

        // API Key
        echo '<tr><th scope="row"><label for="hge_klaviyo_api_key">' . esc_html__( 'Klaviyo API Key', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label></th><td>';
        echo '<input type="password" id="hge_klaviyo_api_key" name="hge_klaviyo[api_key]" value="' . esc_attr( $s['api_key'] ) . '" class="regular-text" autocomplete="new-password" />';
        echo '<p class="description">' . wp_kses_post( __( 'Private API key (Klaviyo → Settings → API Keys). Required scopes: <code>campaigns:write</code>, <code>templates:write</code>, <code>lists:read</code>, <code>segments:read</code>.', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '</p>';
        echo '</td></tr>';

        // Feed Token — visible only in debug mode (since 3.0.8).
        //
        // The token is auto-generated on first save when empty (see
        // hge_klaviyo_nl_sanitize_settings) and used internally by
        // /feed/klaviyo*.json endpoints. Production admins never need to
        // touch it manually — hide it from the default Setări view to
        // reduce clutter; show it only when debug_mode is on (same toggle
        // that gates the Status tab).
        if ( ! empty( $s['debug_mode'] ) ) {
            echo '<tr><th scope="row"><label for="hge_klaviyo_feed_token">' . esc_html__( 'Feed token', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label></th><td>';
            echo '<input type="text" id="hge_klaviyo_feed_token" name="hge_klaviyo[feed_token]" value="' . esc_attr( $s['feed_token'] ) . '" class="regular-text" />';
            echo '<p class="description">' . wp_kses_post( __( 'Random string (32+ chars) used to authenticate requests to <code>/feed/klaviyo*.json</code>. Auto-generated on first save when empty; rotate manually with <code>openssl rand -hex 32</code>.', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '</p>';
            echo '</td></tr>';
        } else {
            // Hidden field preserves the saved value through the form post
            // even when the visible input is suppressed.
            echo '<input type="hidden" name="hge_klaviyo[feed_token]" value="' . esc_attr( $s['feed_token'] ) . '">';
        }

        // Refresh API cache
        if ( $can_query_api ) {
            echo '<tr><th scope="row">' . esc_html__( 'Klaviyo data', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th><td>';
            echo '<a href="' . esc_url( $refresh_url ) . '" class="button">' . esc_html__( 'Reload from Klaviyo', 'hge-automated-post-campaigns-for-klaviyo' ) . '</a>';
            if ( $api_error ) {
                $friendly = hge_klaviyo_friendly_api_error( $api_error );
                echo ' <span style="color:#c00;">⚠ ' . wp_kses_post( $friendly ) . '</span>';
            } else {
                $list_count    = count( $lists_data );
                $segment_count = count( $segments_data );
                $tpl_count     = count( $templates_data );
                echo ' <span style="color:#666;">' . esc_html(
                    sprintf(
                        /* translators: 1: number of lists, 2: number of segments, 3: number of templates */
                        __( '%1$d lists, %2$d segments, %3$d templates (5 min cache)', 'hge-automated-post-campaigns-for-klaviyo' ),
                        $list_count,
                        $segment_count,
                        $tpl_count
                    )
                ) . '</span>';

                if ( $segments_error && 0 === $segment_count ) {
                    $seg_friendly = hge_klaviyo_friendly_api_error( $segments_error );
                    echo '<br><span style="color:#c00;font-size:12px;">⚠ ' . esc_html__( 'Segments:', 'hge-automated-post-campaigns-for-klaviyo' ) . ' ' . wp_kses_post( $seg_friendly ) . '</span>';
                }

                if ( $templates_error && 0 === $tpl_count ) {
                    $tpl_friendly = hge_klaviyo_friendly_api_error( $templates_error );
                    echo '<br><span style="color:#c00;font-size:12px;">⚠ ' . esc_html__( 'Templates:', 'hge-automated-post-campaigns-for-klaviyo' ) . ' ' . wp_kses_post( $tpl_friendly ) . '</span>';
                }

                if ( ! $templates_error && 0 === $tpl_count && $list_count > 0 ) {
                    echo '<p class="description" style="margin-top:6px;">' . wp_kses_post( __( 'No template saved in your Klaviyo account. Create one in <a href="https://www.klaviyo.com/email-templates" target="_blank" rel="noopener">Klaviyo &rarr; Email Templates</a> (any name + Code/HTML or Drag & Drop editor), then click <strong>Reload from Klaviyo</strong>.', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '</p>';
                }
            }
            echo '</td></tr>';
        }

        // Reply-to
        echo '<tr><th scope="row"><label for="hge_klaviyo_reply_to">' . esc_html__( 'Reply-to address (optional)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label></th><td>';
        echo '<input type="email" id="hge_klaviyo_reply_to" name="hge_klaviyo[reply_to_email]" value="' . esc_attr( $s['reply_to_email'] ) . '" class="regular-text" placeholder="contact@example.com" />';
        echo '<p class="description">' . esc_html__( 'When set, overrides the reply-to configured in Klaviyo. Leave empty to use the Klaviyo account default.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        echo '</td></tr>';

        // Min interval — tier-aware helper text added since 3.0.14 (FcRapid1923-omh).
        // The customer's value is fully editable on every tier, but the dispatcher
        // applies a hard floor on Free + Core (the field stays editable so the
        // value survives an upgrade — Pro will then honour whatever the customer
        // saved here).
        $active_plan_for_help = function_exists( 'hge_klaviyo_active_plan' ) ? hge_klaviyo_active_plan() : 'free';
        $tier_floor_hours     = function_exists( 'hge_klaviyo_nl_tier_min_interval_hours' )
            ? (int) hge_klaviyo_nl_tier_min_interval_hours( $active_plan_for_help )
            : 0;
        echo '<tr><th scope="row"><label for="hge_klaviyo_interval">' . esc_html__( 'Minimum interval between sends (hours)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label></th><td>';
        echo '<input type="number" id="hge_klaviyo_interval" name="hge_klaviyo[min_interval_hours]" value="' . esc_attr( (int) $s['min_interval_hours'] ) . '" min="0" max="168" step="1" class="small-text" />';
        echo '<p class="description"><strong>' . esc_html__( 'Default 12.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</strong> ' . esc_html__( 'Cooldown is applied per rule (per tag). Set 0 to disable.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        if ( $tier_floor_hours > 0 ) {
            $floor_days   = (int) round( $tier_floor_hours / 24 );
            $plan_label   = 'free' === $active_plan_for_help ? __( 'Free', 'hge-automated-post-campaigns-for-klaviyo' ) : __( 'Core', 'hge-automated-post-campaigns-for-klaviyo' );
            echo '<p class="description" style="color:#996800;background:#fcf9e8;border-left:4px solid #dba617;padding:6px 10px;">' . wp_kses_post( sprintf(
                /* translators: 1: plan label (Free|Core), 2: tier floor in hours, 3: tier floor in days */
                __( '<strong>Tier limit (%1$s plan):</strong> the dispatcher enforces a minimum of <strong>%2$d hours (%3$d days)</strong> between newsletters, per rule. Values below this floor are accepted by the form (they survive an upgrade) but the dispatcher will hard-suppress dispatches that would violate the floor — the post is not deferred, it is dropped, with a one-shot notice on the post edit screen. Upgrade to Pro for unrestricted cooldown control.', 'hge-automated-post-campaigns-for-klaviyo' ),
                $plan_label,
                $tier_floor_hours,
                $floor_days
            ) ) . '</p>';
        }
        echo '</td></tr>';

        // Dynamic UTM (since 3.0.15 / FcRapid1923-5a3) — available on every plan.
        $utm_source      = isset( $s['utm_source'] ) ? (string) $s['utm_source'] : 'klaviyo';
        $utm_medium      = isset( $s['utm_medium'] ) ? (string) $s['utm_medium'] : 'email';
        $utm_camp_slug   = array_key_exists( 'utm_campaign_use_slug', $s ) ? (bool) $s['utm_campaign_use_slug'] : true;
        $utm_cont_postid = ! empty( $s['utm_content_use_post_id'] );
        echo '<tr><th scope="row">' . esc_html__( 'Link tracking (UTM)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th><td>';
        echo '<p style="margin:0 0 6px;"><label style="display:inline-block;min-width:120px;">' . esc_html__( 'utm_source', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label> '
            . '<input type="text" name="hge_klaviyo[utm_source]" value="' . esc_attr( $utm_source ) . '" class="regular-text" placeholder="klaviyo" /></p>';
        echo '<p style="margin:0 0 6px;"><label style="display:inline-block;min-width:120px;">' . esc_html__( 'utm_medium', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label> '
            . '<input type="text" name="hge_klaviyo[utm_medium]" value="' . esc_attr( $utm_medium ) . '" class="regular-text" placeholder="email" /></p>';
        echo '<p style="margin:0 0 6px;"><label><input type="checkbox" name="hge_klaviyo[utm_campaign_use_slug]" value="1" ' . checked( $utm_camp_slug, true, false ) . '> '
            . esc_html__( 'utm_campaign = post slug (uncheck to use a stable post-<id> token)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label></p>';
        echo '<p style="margin:0 0 6px;"><label><input type="checkbox" name="hge_klaviyo[utm_content_use_post_id]" value="1" ' . checked( $utm_cont_postid, true, false ) . '> '
            . esc_html__( 'utm_content = post id (uncheck to use "newsletter")', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label></p>';
        echo '<p class="description">' . esc_html__( 'Applied to the article link in the built-in HTML newsletter. Available on all plans. Klaviyo campaign id is not yet known when the link is built, so the non-slug campaign option uses a stable per-post token.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        echo '</td></tr>';

        // Auto-retry on transient API failure (since 3.0.15 / FcRapid1923-mrb) — Core+.
        $retry_plan = function_exists( 'hge_klaviyo_active_plan' ) ? hge_klaviyo_active_plan() : 'free';
        $retry_max  = isset( $s['retry_max_attempts'] ) ? (int) $s['retry_max_attempts'] : 3;
        echo '<tr><th scope="row">' . esc_html__( 'Auto-retry on API failure', 'hge-automated-post-campaigns-for-klaviyo' );
        if ( ! in_array( $retry_plan, array( 'core', 'pro' ), true ) && function_exists( 'hge_klaviyo_upgrade_cta_html' ) ) {
            echo wp_kses_post( hge_klaviyo_upgrade_cta_html( 'core' ) );
        }
        echo '</th><td>';
        if ( in_array( $retry_plan, array( 'core', 'pro' ), true ) ) {
            echo '<input type="number" name="hge_klaviyo[retry_max_attempts]" value="' . esc_attr( (string) max( 1, min( 5, $retry_max ) ) ) . '" min="1" max="5" step="1" class="small-text" /> ';
            echo esc_html__( 'attempts (1–5)', 'hge-automated-post-campaigns-for-klaviyo' );
            echo '<p class="description">' . esc_html__( 'On a transient Klaviyo API failure (HTTP 5xx / 429 rate limit / timeout) that happens before the campaign is created, the dispatch is retried with exponential backoff (+1, +5, +30 min). After the last attempt the post is marked failed. A campaign that was already created is never re-sent.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__( 'Automatically retries failed sends with exponential backoff. Available on Core and Pro.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        }
        echo '</td></tr>';

        // Auto-exclude unsubscribed (since 3.0.15 / FcRapid1923-8cx) — Core+.
        $unsub_plan = function_exists( 'hge_klaviyo_active_plan' ) ? hge_klaviyo_active_plan() : 'free';
        echo '<tr><th scope="row">' . esc_html__( 'Exclude unsubscribed', 'hge-automated-post-campaigns-for-klaviyo' );
        if ( ! in_array( $unsub_plan, array( 'core', 'pro' ), true ) && function_exists( 'hge_klaviyo_upgrade_cta_html' ) ) {
            echo wp_kses_post( hge_klaviyo_upgrade_cta_html( 'core' ) );
        }
        echo '</th><td>';
        if ( in_array( $unsub_plan, array( 'core', 'pro' ), true ) ) {
            $unsub_on = ! empty( $s['auto_exclude_unsubscribed'] );
            $unsub_id = isset( $s['unsubscribed_list_id'] ) ? (string) $s['unsubscribed_list_id'] : '';
            echo '<label><input type="checkbox" name="hge_klaviyo[auto_exclude_unsubscribed]" value="1" ' . checked( $unsub_on, true, false ) . '> ' . esc_html__( 'Add a suppression list/segment to every campaign\'s excluded audiences', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label>';
            echo '<p style="margin:6px 0 0;"><label style="display:inline-block;min-width:160px;">' . esc_html__( 'Suppression list/segment ID', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label> <input type="text" name="hge_klaviyo[unsubscribed_list_id]" value="' . esc_attr( $unsub_id ) . '" class="regular-text" placeholder="' . esc_attr__( 'Klaviyo list or segment ID', 'hge-automated-post-campaigns-for-klaviyo' ) . '" /></p>';
            echo '<p class="description">' . esc_html__( 'Klaviyo already auto-suppresses unsubscribed profiles at send time. Set a suppression list/segment ID here to also exclude it explicitly from every newsletter campaign.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__( 'Explicitly exclude a suppression list/segment from every campaign. Available on Core and Pro.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        }
        echo '</td></tr>';

        // Reusable Klaviyo template (since 3.0.15 / FcRapid1923-bn2) — Core/Pro.
        $tpl_plan = function_exists( 'hge_klaviyo_active_plan' ) ? hge_klaviyo_active_plan() : 'free';
        echo '<tr><th scope="row">' . esc_html__( 'Default email template', 'hge-automated-post-campaigns-for-klaviyo' );
        if ( ! in_array( $tpl_plan, array( 'core', 'pro' ), true ) && function_exists( 'hge_klaviyo_upgrade_cta_html' ) ) {
            echo wp_kses_post( hge_klaviyo_upgrade_cta_html( 'core' ) );
        }
        echo '</th><td>';
        if ( in_array( $tpl_plan, array( 'core', 'pro' ), true ) ) {
            hge_klaviyo_nl_render_template_select( 'hge_klaviyo[default_template_id]', isset( $s['default_template_id'] ) ? (string) $s['default_template_id'] : '' );
            echo '<p class="description">' . esc_html__( 'Reuse an email template you already built in Klaviyo — it is used on every send, so the plugin never creates a new template per campaign. Leave on "Built-in HTML" to keep the plugin-generated template. Each post can override this in the post editor.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__( 'Free uses the built-in HTML template. Core and Pro can reuse their own Klaviyo templates instead of generating one per send.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        }
        echo '</td></tr>';

        // Debug mode
        echo '<tr><th scope="row">' . esc_html__( 'Debug mode', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th><td>';
        echo '<label><input type="checkbox" name="hge_klaviyo[debug_mode]" value="1" ' . checked( ! empty( $s['debug_mode'] ), true, false ) . '> ' . wp_kses_post( __( 'Enable the <strong>Status</strong> tab, show internal credentials (Feed token, Pro webhook secret) in the admin UI, and write detailed entries to <strong>WooCommerce → Status → Logs</strong> (source <code>hge-klaviyo</code>).', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '</label>';
        echo '<p class="description">' . esc_html__( 'Leave off in production. Turn on when you need to inspect the webhook / dispatch / API response flow, copy the auto-generated Feed token / webhook secret into an external system, or trace a delivery issue end-to-end. ERROR and WARNING entries are always written regardless of this setting.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        echo '</td></tr>';

        // Kill switch — background jobs (since 3.0.16 / FcRapid1923-lxe, every plan)
        echo '<tr><th scope="row">' . esc_html__( 'Background jobs', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th><td>';
        if ( defined( 'HGE_KLAVIYO_NL_DISABLE_BACKGROUND' ) && HGE_KLAVIYO_NL_DISABLE_BACKGROUND ) {
            echo '<p class="description"><strong>' . esc_html__( 'Disabled by the HGE_KLAVIYO_NL_DISABLE_BACKGROUND constant in wp-config.php (host-level override).', 'hge-automated-post-campaigns-for-klaviyo' ) . '</strong></p>';
        } else {
            echo '<label><input type="checkbox" name="hge_klaviyo[disable_background_jobs]" value="1" ' . checked( ! empty( $s['disable_background_jobs'] ), true, false ) . '> ' . wp_kses_post( __( '<strong>Disable background jobs</strong> (low-resource mode). Stops the Klaviyo API cache warmup entirely.', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '</label>';
            echo '<p class="description">' . esc_html__( 'Campaign dispatch and its retries keep working — only the non-essential cache refresh stops. Turn on during high-traffic events (campaign launches, sales) or on constrained hosting. Trade-off: the Settings page loads list/segment/template data directly from Klaviyo when its cache is cold, which can take a few seconds. Hosts can force this mode with the HGE_KLAVIYO_NL_DISABLE_BACKGROUND constant.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        }
        echo '</td></tr>';

        echo '</table>';

        // ====== Section 2 — Newsletter rules (cards) ======

        $rules     = is_array( $s['tag_rules'] ?? null ) ? $s['tag_rules'] : array();
        $rule_count = count( $rules );

        $plan_label = ( 'pro' === $plan )
            ? __( 'PRO', 'hge-automated-post-campaigns-for-klaviyo' )
            : ( ( 'core' === $plan ) ? __( 'CORE', 'hge-automated-post-campaigns-for-klaviyo' ) : __( 'FREE', 'hge-automated-post-campaigns-for-klaviyo' ) );

        echo '<h2 style="margin-top:24px;">' . esc_html__( 'Newsletter rules', 'hge-automated-post-campaigns-for-klaviyo' ) . '</h2>';
        echo '<p class="description" style="max-width:780px;">' . wp_kses_post( __( 'Each rule maps a post <strong>tag</strong> to a configuration: <em>recipient list(s)</em>, <em>excluded list(s)</em> (Core+), <em>Klaviyo template</em> (Pro) and <em>Web Feed mode</em> (Pro). When a post is published, the plugin matches the first rule whose tag is present on the post (card order = priority) and dispatches using that rule. Cooldown is applied separately per rule (per tag).', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '</p>';
        echo '<p class="description" style="max-width:780px;"><strong>' . esc_html__( 'Current plan:', 'hge-automated-post-campaigns-for-klaviyo' ) . '</strong> ' . esc_html( $plan_label ) . ' — ' . esc_html(
            sprintf(
                /* translators: %d is the maximum number of rules */
                _n( 'max %d rule', 'max %d rules', $max_rules, 'hge-automated-post-campaigns-for-klaviyo' ),
                $max_rules
            )
        ) . '.';
        if ( 'pro' !== $plan ) {
            echo ' ' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'free' === $plan ? 'core' : 'pro' ) );
        }
        echo '</p>';

        if ( ! $can_query_api ) {
            echo '<div class="notice notice-warning inline" style="margin:8px 0;"><p>' . wp_kses_post( __( 'Save the <strong>Klaviyo API Key</strong> above first so that lists and templates can be loaded into the rule cards.', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '</p></div>';
        } elseif ( $api_error ) {
            echo '<div class="notice notice-error inline" style="margin:8px 0;"><p>' . esc_html__( 'Could not load lists from Klaviyo:', 'hge-automated-post-campaigns-for-klaviyo' ) . ' ' . wp_kses_post( hge_klaviyo_friendly_api_error( $api_error ) ) . '</p></div>';
        }

        echo '<div id="hge-klaviyo-rules" data-max="' . esc_attr( $max_rules ) . '">';
        if ( empty( $rules ) ) {
            // Always show at least one editable card
            $rules = array( hge_klaviyo_nl_default_rule() );
        }
        foreach ( $rules as $idx => $rule ) {
            hge_klaviyo_render_rule_card( (int) $idx, $rule, $lists_data, $segments_data, $templates_data, $caps, $supports_multi, $plan );
        }
        echo '</div>';

        $can_add = $rule_count < $max_rules;
        echo '<p style="margin:8px 0 0 0;">';
        echo '<button type="button" id="hge-klaviyo-add-rule" class="button"' . ( $can_add ? '' : ' disabled' ) . '>' . esc_html__( 'Add rule', 'hge-automated-post-campaigns-for-klaviyo' ) . '</button>';
        if ( ! $can_add ) {
            echo ' <span class="description">' . wp_kses_post(
                sprintf(
                    /* translators: 1: plan label (FREE / CORE / PRO), 2: rule count */
                    _n(
                        'You have reached the plan limit for <strong>%1$s</strong> (%2$d rule).',
                        'You have reached the plan limit for <strong>%1$s</strong> (%2$d rules).',
                        $max_rules,
                        'hge-automated-post-campaigns-for-klaviyo'
                    ),
                    esc_html( $plan_label ),
                    (int) $max_rules
                )
            );
            if ( 'pro' !== $plan ) {
                echo ' ' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'free' === $plan ? 'core' : 'pro' ) );
            }
            echo '</span>';
        }
        echo '</p>';

        // Expose a blank rule card to the client. <script type="text/template">
        // is inert (browsers don't execute or render it) and the captured HTML
        // contains only server-escaped attribute values — no <script>-terminating
        // sequences can appear, so embedding is safe.
        $blank_rule = hge_klaviyo_nl_default_rule();
        ob_start();
        hge_klaviyo_render_rule_card( 0, $blank_rule, $lists_data, $segments_data, $templates_data, $caps, $supports_multi, $plan, true );
        $blank_html = ob_get_clean();

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $blank_html is the output of hge_klaviyo_render_rule_card() which builds pre-escaped HTML internally (esc_attr + esc_html on all dynamic values).
        echo '<script type="text/template" id="hge-klaviyo-rule-template">' . $blank_html . '</script>';

        // Inline JS — vanilla, no jQuery dependency.
        //
        // Naming contract (must match the PHP renderer):
        //   - `name="hge_klaviyo[tag_rules][N][...]"` — reindex regex rewrites N
        //   - `id="hge-rule-N-<field>"`              — reindex regex rewrites N
        //   - `<label for="hge-rule-N-<field>">`     — reindex regex rewrites N
        // Cards are wholly removed from the DOM on delete; reindex() then renumbers
        // remaining cards 0..k so PHP receives a gapless `tag_rules` array.
        //
        // i18n strings are echoed below from PHP through esc_js() so translations
        // flow through `__()` like the rest of the UI.
        ?>
        <script>
        (function() {
            var container = document.getElementById('hge-klaviyo-rules');
            var addBtn    = document.getElementById('hge-klaviyo-add-rule');
            var tmpl      = document.getElementById('hge-klaviyo-rule-template');
            if ( ! container || ! addBtn || ! tmpl ) { return; }

            var maxRules = parseInt( container.getAttribute('data-max'), 10 ) || 1;

            function reindex() {
                var cards = container.querySelectorAll('.hge-klaviyo-rule-card');
                cards.forEach(function(card, newIdx) {
                    card.setAttribute('data-idx', newIdx);
                    var labelNum = card.querySelector('.hge-rule-num');
                    if ( labelNum ) { labelNum.textContent = '#' + (newIdx + 1); }
                    card.querySelectorAll('[name]').forEach(function(el) {
                        var n = el.getAttribute('name');
                        if ( n ) {
                            el.setAttribute('name', n.replace(/hge_klaviyo\[tag_rules\]\[\d+\]/, 'hge_klaviyo[tag_rules][' + newIdx + ']'));
                        }
                    });
                    card.querySelectorAll('[id]').forEach(function(el) {
                        var id = el.getAttribute('id');
                        if ( id && id.indexOf('hge-rule-') === 0 ) {
                            el.setAttribute('id', id.replace(/^hge-rule-\d+-/, 'hge-rule-' + newIdx + '-'));
                        }
                    });
                    card.querySelectorAll('label[for]').forEach(function(el) {
                        var f = el.getAttribute('for');
                        if ( f && f.indexOf('hge-rule-') === 0 ) {
                            el.setAttribute('for', f.replace(/^hge-rule-\d+-/, 'hge-rule-' + newIdx + '-'));
                        }
                    });
                    // Since 3.0.10 — the template combobox carries cross-element
                    // references via aria-controls + data-list + data-count.
                    // These must follow the renumbering or the combo wires its
                    // input to a stale list element after card add/remove.
                    card.querySelectorAll('[aria-controls], [data-list], [data-count]').forEach(function(el) {
                        ['aria-controls', 'data-list', 'data-count'].forEach(function(attr){
                            var v = el.getAttribute(attr);
                            if ( v && v.indexOf('hge-rule-') === 0 ) {
                                el.setAttribute(attr, v.replace(/^hge-rule-\d+-/, 'hge-rule-' + newIdx + '-'));
                            }
                        });
                    });
                });
                updateAddButton();
            }

            function updateAddButton() {
                var count = container.querySelectorAll('.hge-klaviyo-rule-card').length;
                addBtn.disabled = ( count >= maxRules );
            }

            container.addEventListener('click', function(ev) {
                var t = ev.target;
                if ( t && t.classList && t.classList.contains('hge-rule-remove') ) {
                    ev.preventDefault();
                    var cards = container.querySelectorAll('.hge-klaviyo-rule-card');
                    if ( cards.length <= 1 ) {
                        if ( ! confirm('<?php echo esc_js( __( 'This is the only rule. Deleting it stops all automatic sends. Continue?', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?>') ) {
                            return;
                        }
                    } else if ( ! confirm('<?php echo esc_js( __( 'Delete this rule? The change takes effect after Save.', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?>') ) {
                        return;
                    }
                    var card = t.closest('.hge-klaviyo-rule-card');
                    if ( card ) {
                        card.remove();
                        reindex();
                    }
                }
            });

            addBtn.addEventListener('click', function(ev) {
                ev.preventDefault();
                var count = container.querySelectorAll('.hge-klaviyo-rule-card').length;
                if ( count >= maxRules ) { return; }
                var div = document.createElement('div');
                div.innerHTML = tmpl.innerHTML.trim();
                var newCard = div.firstChild;
                container.appendChild(newCard);
                reindex();
                applyCrossExcludeAll();
            });

            // -------------------------------------------------------------
            // Cross-exclude: an ID selected as Included must be disabled in
            // the same card's Excluded select (and vice versa). Klaviyo would
            // reject the campaign anyway, so we hide the contradictory choice
            // at the source.
            //
            // Implementation: scan both selects in each card, collect the set
            // of selected values from each, then mark conflicting <option>s as
            // disabled in the opposite select. Selected options stay enabled.
            // -------------------------------------------------------------
            function selectedValues(select) {
                if ( ! select ) { return []; }
                var out = [];
                for ( var i = 0; i < select.options.length; i++ ) {
                    if ( select.options[i].selected && select.options[i].value !== '' ) {
                        out.push(select.options[i].value);
                    }
                }
                return out;
            }

            function applyCrossExclude(card) {
                var inc = card.querySelector('[data-audience-role="included"]');
                var exc = card.querySelector('[data-audience-role="excluded"]');
                if ( ! inc || ! exc ) { return; }
                var incSel = selectedValues(inc);
                var excSel = selectedValues(exc);

                function disableMatching(targetSelect, otherSelected) {
                    for ( var i = 0; i < targetSelect.options.length; i++ ) {
                        var opt = targetSelect.options[i];
                        if ( opt.value === '' ) { continue; }
                        if ( opt.selected ) {
                            // never disable an already-selected option in this
                            // select — user must be able to deselect it.
                            opt.disabled = false;
                            continue;
                        }
                        opt.disabled = otherSelected.indexOf(opt.value) !== -1;
                    }
                }

                disableMatching(inc, excSel);
                disableMatching(exc, incSel);
            }

            function applyCrossExcludeAll() {
                container.querySelectorAll('.hge-klaviyo-rule-card').forEach(applyCrossExclude);
            }

            container.addEventListener('change', function(ev) {
                var t = ev.target;
                if ( t && t.classList && t.classList.contains('hge-audience-select') ) {
                    var card = t.closest('.hge-klaviyo-rule-card');
                    if ( card ) { applyCrossExclude(card); }
                }
            });

            // -------------------------------------------------------------
            // Template combobox (since 3.0.10) — supersedes the v3.0.7
            // search-input + <select> pair.
            //
            // Each rule card renders a `.hge-tpl-combo` wrapper with:
            //   - visible <input class="hge-tpl-combo-input" role="combobox">
            //   - <button class="hge-tpl-clear">×</button>
            //   - hidden <input> (the form-submit carrier; same name as the
            //     v3.0.0 <select>, so sanitizer + DB shape unchanged)
            //   - <ul role="listbox"> with <li role="option" data-value …>
            //   - count badge to the right of the wrapper
            //
            // Behaviour: focus opens listbox; typing filters by substring
            // (data-name, lowercased); click / Enter on a highlighted option
            // selects it (writes name → visible input, id → hidden input);
            // click-outside / Esc / Tab closes; × clears selection.
            // -------------------------------------------------------------
            var I18N = {
                tpl_one:        <?php echo wp_json_encode( __( 'template', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?>,
                tpl_many:       <?php echo wp_json_encode( __( 'templates', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?>,
                showing:        <?php echo wp_json_encode( __( 'Showing', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?>,
                none_match:     <?php echo wp_json_encode( __( 'No template matches that search.', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?>
            };

            function tplComboParts( anchorEl ) {
                // Resolve the combobox parts from any element inside the wrapper.
                var wrapper = anchorEl.closest ? anchorEl.closest('.hge-tpl-combo') : null;
                if ( ! wrapper ) { return null; }
                return {
                    wrapper: wrapper,
                    input:   wrapper.querySelector('.hge-tpl-combo-input'),
                    clear:   wrapper.querySelector('.hge-tpl-clear'),
                    hidden:  wrapper.querySelector('input[type="hidden"]'),
                    list:    wrapper.querySelector('.hge-tpl-options'),
                    items:   wrapper.querySelectorAll('.hge-tpl-options li'),
                    count:   document.getElementById( wrapper.querySelector('.hge-tpl-combo-input').getAttribute('data-count') )
                };
            }

            function tplFilter( parts ) {
                var term = (parts.input.value || '').toLowerCase().trim();
                // Treat the visible name as a non-search if it equals the
                // currently-selected option's name (i.e., user hasn't started
                // typing fresh after open) — show full list.
                var selectedName = '';
                parts.items.forEach(function(li){
                    if ( li.getAttribute('aria-selected') === 'true' ) {
                        selectedName = (li.getAttribute('data-name') || '').toLowerCase();
                    }
                });
                if ( term === selectedName ) { term = ''; }

                var shown = 0, total = 0;
                parts.items.forEach(function(li){
                    var name = li.getAttribute('data-name') || '';
                    var isPlaceholder = ( '' === li.getAttribute('data-value') );
                    if ( isPlaceholder ) {
                        li.hidden = false; // sentinel always visible
                        return;
                    }
                    total++;
                    var match = ( '' === term ) || name.indexOf(term) !== -1;
                    li.hidden = ! match;
                    if ( match ) { shown++; }
                });
                // No-results row
                var noRes = parts.list.querySelector('.hge-tpl-no-results');
                if ( '' !== term && 0 === shown ) {
                    if ( ! noRes ) {
                        noRes = document.createElement('li');
                        noRes.className = 'hge-tpl-no-results';
                        noRes.setAttribute('aria-hidden', 'true');
                        noRes.style.cssText = 'padding:8px 10px;color:#888;font-style:italic;';
                        noRes.textContent = I18N.none_match;
                        parts.list.appendChild(noRes);
                    }
                } else if ( noRes ) {
                    noRes.remove();
                }
                // Count badge
                if ( parts.count ) {
                    if ( '' === term ) {
                        parts.count.textContent = total + ' ' + (total === 1 ? I18N.tpl_one : I18N.tpl_many);
                    } else {
                        parts.count.textContent = I18N.showing + ' ' + shown + ' / ' + total;
                    }
                }
            }

            function tplOpenList( parts ) {
                parts.list.hidden = false;
                parts.input.setAttribute('aria-expanded', 'true');
                tplFilter( parts );
            }

            function tplCloseList( parts ) {
                parts.list.hidden = true;
                parts.input.setAttribute('aria-expanded', 'false');
                // Restore selected option label into the visible input so the
                // user always sees what is currently selected — including the
                // sentinel "use built-in" choice. The hidden input drives the
                // form submit (empty string for the sentinel).
                var selectedLi = parts.list.querySelector('li[aria-selected="true"]');
                if ( selectedLi ) {
                    parts.input.value = selectedLi.firstChild ? selectedLi.firstChild.textContent.trim() : '';
                }
            }

            function tplSelectItem( parts, li ) {
                if ( ! li ) { return; }
                parts.items.forEach(function(x){ x.setAttribute('aria-selected', 'false'); });
                li.setAttribute('aria-selected', 'true');
                parts.hidden.value = li.getAttribute('data-value') || '';
                // Visible input always shows the option label (sentinel
                // included) so the user sees confirmation of their choice.
                // First child node is the text label (before any <small>).
                parts.input.value = li.firstChild ? li.firstChild.textContent.trim() : '';
                tplCloseList( parts );
            }

            function tplHighlightedIndex( parts ) {
                var visible = Array.prototype.filter.call(parts.items, function(li){ return ! li.hidden; });
                for ( var i = 0; i < visible.length; i++ ) {
                    if ( visible[i].classList.contains('hge-tpl-active') ) { return { idx: i, list: visible }; }
                }
                return { idx: -1, list: visible };
            }

            function tplMoveHighlight( parts, delta ) {
                var state = tplHighlightedIndex( parts );
                if ( state.list.length === 0 ) { return; }
                var next = state.idx + delta;
                if ( next < 0 ) { next = state.list.length - 1; }
                if ( next >= state.list.length ) { next = 0; }
                state.list.forEach(function(li){ li.classList.remove('hge-tpl-active'); li.style.background = ''; });
                state.list[ next ].classList.add('hge-tpl-active');
                state.list[ next ].style.background = '#e7f2fb';
                state.list[ next ].scrollIntoView({ block: 'nearest' });
            }

            container.addEventListener('focusin', function(ev){
                var t = ev.target;
                if ( t && t.classList && t.classList.contains('hge-tpl-combo-input') ) {
                    var parts = tplComboParts( t );
                    if ( parts ) {
                        tplOpenList( parts );
                        // Pre-select the visible text so the user can type
                        // straight away to filter, without having to clear
                        // the displayed selection by hand.
                        try { t.select(); } catch ( e ) {}
                    }
                }
            });

            container.addEventListener('input', function(ev){
                var t = ev.target;
                if ( t && t.classList && t.classList.contains('hge-tpl-combo-input') ) {
                    var parts = tplComboParts( t );
                    if ( parts ) { tplOpenList( parts ); }
                }
            });

            container.addEventListener('keydown', function(ev){
                var t = ev.target;
                if ( ! t || ! t.classList || ! t.classList.contains('hge-tpl-combo-input') ) { return; }
                var parts = tplComboParts( t );
                if ( ! parts ) { return; }
                if ( ev.key === 'ArrowDown' ) { ev.preventDefault(); tplOpenList(parts); tplMoveHighlight(parts, 1); }
                else if ( ev.key === 'ArrowUp' ) { ev.preventDefault(); tplMoveHighlight(parts, -1); }
                else if ( ev.key === 'Enter' ) {
                    ev.preventDefault();
                    var active = parts.list.querySelector('.hge-tpl-active') || parts.list.querySelector('li:not([hidden])');
                    if ( active && active.classList.contains('hge-tpl-no-results') ) { active = null; }
                    if ( active ) { tplSelectItem( parts, active ); }
                }
                else if ( ev.key === 'Escape' ) { tplCloseList(parts); }
                else if ( ev.key === 'Home' ) {
                    var visible = parts.list.querySelectorAll('li:not([hidden])');
                    if ( visible.length ) { visible.forEach(function(li){li.classList.remove('hge-tpl-active');li.style.background='';}); visible[0].classList.add('hge-tpl-active'); visible[0].style.background='#e7f2fb'; }
                }
                else if ( ev.key === 'End' ) {
                    var visible2 = parts.list.querySelectorAll('li:not([hidden])');
                    if ( visible2.length ) { visible2.forEach(function(li){li.classList.remove('hge-tpl-active');li.style.background='';}); visible2[ visible2.length - 1 ].classList.add('hge-tpl-active'); visible2[ visible2.length - 1 ].style.background='#e7f2fb'; }
                }
            });

            container.addEventListener('click', function(ev){
                var t = ev.target;
                // Clear (×) button
                if ( t && t.classList && t.classList.contains('hge-tpl-clear') ) {
                    ev.preventDefault();
                    var parts = tplComboParts( t );
                    if ( parts ) {
                        var sentinel = parts.list.querySelector('li[data-value=""]');
                        tplSelectItem( parts, sentinel );
                        parts.input.focus();
                    }
                    return;
                }
                // Option click
                var li = t && t.closest ? t.closest('.hge-tpl-options li[role="option"]') : null;
                if ( li ) {
                    var parts2 = tplComboParts( li );
                    if ( parts2 ) { tplSelectItem( parts2, li ); }
                }
            });

            // Click outside any combobox closes all open lists.
            document.addEventListener('mousedown', function(ev){
                container.querySelectorAll('.hge-tpl-combo').forEach(function(wrapper){
                    if ( ! wrapper.contains(ev.target) ) {
                        var input = wrapper.querySelector('.hge-tpl-combo-input');
                        var list  = wrapper.querySelector('.hge-tpl-options');
                        if ( list && ! list.hidden ) {
                            list.hidden = true;
                            if ( input ) { input.setAttribute('aria-expanded', 'false'); }
                        }
                    }
                });
            });

            // Initial state — ensure add button reflects current count
            updateAddButton();
            applyCrossExcludeAll();
            // Prime each combobox's count badge using the same filter path that
            // runs on input — guarantees the idle "N templates" message uses
            // the same formatter as the active "Showing X / Y" message.
            container.querySelectorAll('.hge-tpl-combo').forEach(function(wrapper){
                var parts = tplComboParts( wrapper );
                if ( parts ) { tplFilter( parts ); }
            });
        })();
        </script>
        <?php

        // ============================================================
        // Web Feed quick-start modal (since 3.0.9)
        //
        // Single hidden modal in the DOM, populated per-card on open via
        // data-* attributes on the trigger button. Vanilla JS — Esc /
        // outside-click / X to close, copy-to-clipboard on each snippet.
        // ============================================================
        hge_klaviyo_render_wf_quickstart_modal();

        /**
         * Action — let Pro feature modules render extra settings sections inside
         * the same form, just before the submit button.
         *
         * @since 2.2.0
         * @param array $s Current settings array.
         */
        do_action( 'hge_klaviyo_render_settings_extra', $s );

        submit_button( __( 'Save settings', 'hge-automated-post-campaigns-for-klaviyo' ) );
        echo '</form>';
    }
}

/**
 * Render the shared Web Feed quick-start modal.
 *
 * Markup is rendered once per Settings page render (single instance in the
 * DOM); the per-card "Quick start" buttons populate the {{NAME}} and
 * {{URL}} placeholders on click via JS. Keeps DOM weight constant
 * regardless of rule count.
 *
 * @since 3.0.9
 */
if ( ! function_exists( 'hge_klaviyo_render_wf_quickstart_modal' ) ) {
    function hge_klaviyo_render_wf_quickstart_modal() {

        // Starter HTML offered for copy-paste into Klaviyo's HTML editor.
        // Single-article variant — covers the most common "publish post →
        // newsletter goes out" workflow. Digest variant shown inline in
        // step 3 as a Jinja for-loop snippet.
        $starter_html = <<<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="background:#fff;max-width:600px;width:100%;border-radius:8px;overflow:hidden;">
{% if web_feeds.NAME.items.0.image %}
<tr><td><img src="{{ web_feeds.NAME.items.0.image }}" width="600" alt="" style="display:block;width:100%;height:auto;"></td></tr>
{% endif %}
<tr><td style="padding:28px 28px 8px;">
  <h1 style="margin:0;font-size:24px;line-height:1.3;color:#111;">{{ web_feeds.NAME.items.0.title }}</h1>
</td></tr>
<tr><td style="padding:8px 28px 24px;font-size:16px;line-height:1.5;color:#333;">
  {{ web_feeds.NAME.items.0.excerpt }}
</td></tr>
<tr><td align="center" style="padding:8px 28px 32px;">
  <a href="{{ web_feeds.NAME.items.0.url }}" style="background:#2271b1;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;display:inline-block;">Read the full article →</a>
</td></tr>
<tr><td style="padding:16px 28px;background:#fafafa;font-size:12px;color:#666;text-align:center;">{{ organization.name }} — {% unsubscribe %}</td></tr>
</table></td></tr></table>
</body></html>
HTML;

        $digest_html = <<<'HTML'
{% for item in web_feeds.NAME.items[:3] %}
<tr><td style="padding:20px 28px;border-top:1px solid #eee;">
  <h2 style="margin:0 0 8px;font-size:18px;color:#111;">{{ item.title }}</h2>
  {% if item.excerpt %}<p style="margin:0 0 12px;color:#444;">{{ item.excerpt }}</p>{% endif %}
  <a href="{{ item.url }}" style="color:#2271b1;">Read more →</a>
</td></tr>
{% endfor %}
HTML;
        ?>

        <div id="hge-wf-modal" class="hge-wf-modal" hidden role="dialog" aria-modal="true" aria-labelledby="hge-wf-modal-title">
            <div class="hge-wf-modal-backdrop"></div>
            <div class="hge-wf-modal-dialog" role="document">
                <div class="hge-wf-modal-header">
                    <h2 id="hge-wf-modal-title" style="margin:0;font-size:18px;">
                        <?php esc_html_e( 'Quick start: Klaviyo digest template', 'hge-automated-post-campaigns-for-klaviyo' ); ?>
                    </h2>
                    <button type="button" class="hge-wf-modal-close button-link" aria-label="<?php echo esc_attr__( 'Close', 'hge-automated-post-campaigns-for-klaviyo' ); ?>" style="font-size:24px;line-height:1;background:none;border:0;cursor:pointer;color:#666;">✕</button>
                </div>

                <div class="hge-wf-modal-body">

                    <p class="description" style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 12px;margin:0 0 16px;font-size:13px;">
                        <strong><?php esc_html_e( 'Alternative path:', 'hge-automated-post-campaigns-for-klaviyo' ); ?></strong>
                        <?php
                        echo wp_kses_post(
                            __( 'if you already have a Klaviyo template built with <strong>Global Blocks</strong> (drag-and-drop), you can use it directly — just pick it from the <em>Klaviyo template</em> dropdown in step 4 and skip the manual HTML in steps 2 and 3. See Klaviyo\'s reference:', 'hge-automated-post-campaigns-for-klaviyo' )
                        );
                        ?>
                        <a href="https://help.klaviyo.com/hc/en-us/articles/115005258768" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e( 'Template editor options', 'hge-automated-post-campaigns-for-klaviyo' ); ?>
                            <span aria-hidden="true">↗</span>
                        </a>.
                    </p>

                    <ol style="padding-left:1.4em;">

                        <li style="margin-bottom:18px;">
                            <strong><?php esc_html_e( 'Create the Web Feed in Klaviyo', 'hge-automated-post-campaigns-for-klaviyo' ); ?></strong>
                            <p>
                                <?php echo wp_kses_post( __( 'Klaviyo → <strong>Settings → Web Feeds → Add web feed</strong>. Fill in:', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?>
                            </p>
                            <ul style="list-style:disc;padding-left:1.4em;">
                                <li><strong><?php esc_html_e( 'Name:', 'hge-automated-post-campaigns-for-klaviyo' ); ?></strong> <code class="hge-wf-name">newsletter_feed</code></li>
                                <li><strong><?php esc_html_e( 'URL:', 'hge-automated-post-campaigns-for-klaviyo' ); ?></strong>
                                    <code class="hge-wf-url" style="word-break:break-all;font-size:11px;">—</code>
                                    <button type="button" class="button button-small hge-wf-copy" data-target=".hge-wf-url" style="margin-left:6px;"><?php esc_html_e( 'Copy URL', 'hge-automated-post-campaigns-for-klaviyo' ); ?></button>
                                </li>
                                <li><strong><?php esc_html_e( 'Refresh interval:', 'hge-automated-post-campaigns-for-klaviyo' ); ?></strong> 5 <?php esc_html_e( 'minutes (Klaviyo default)', 'hge-automated-post-campaigns-for-klaviyo' ); ?></li>
                                <li><strong><?php esc_html_e( 'Content type:', 'hge-automated-post-campaigns-for-klaviyo' ); ?></strong> JSON</li>
                            </ul>
                            <p class="description"><?php esc_html_e( 'Save it; Klaviyo will fetch the feed and verify access.', 'hge-automated-post-campaigns-for-klaviyo' ); ?></p>
                        </li>

                        <li style="margin-bottom:18px;">
                            <strong><?php esc_html_e( 'Create a Code template in Klaviyo', 'hge-automated-post-campaigns-for-klaviyo' ); ?></strong>
                            <p>
                                <?php echo wp_kses_post( __( 'Klaviyo → <strong>Email Templates → Create template → HTML editor</strong>. Paste this starter, then customise:', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?>
                            </p>
                            <pre class="hge-wf-snippet" id="hge-wf-starter-html" style="background:#f6f7f7;padding:10px;font-size:11px;max-height:240px;overflow:auto;border:1px solid #ddd;border-radius:3px;"><?php echo esc_html( $starter_html ); ?></pre>
                            <p>
                                <button type="button" class="button hge-wf-copy" data-target="#hge-wf-starter-html"><?php esc_html_e( 'Copy starter HTML', 'hge-automated-post-campaigns-for-klaviyo' ); ?></button>
                                <span class="description" style="margin-left:8px;"><?php echo wp_kses_post( __( 'Save the template with a memorable name — it appears in the plugin\'s <em>Klaviyo template</em> dropdown.', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?></span>
                            </p>
                            <p class="description"><strong><?php esc_html_e( 'Note:', 'hge-automated-post-campaigns-for-klaviyo' ); ?></strong> <?php echo wp_kses_post( __( 'every <code>NAME</code> placeholder in the snippet is the Web Feed name from step 1. The Copy button substitutes it automatically.', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?></p>
                        </li>

                        <li style="margin-bottom:18px;">
                            <strong><?php esc_html_e( 'Render multiple articles (digest layout)', 'hge-automated-post-campaigns-for-klaviyo' ); ?></strong>
                            <p><?php esc_html_e( 'Replace the single-article block in the starter with a Jinja for-loop to render N articles:', 'hge-automated-post-campaigns-for-klaviyo' ); ?></p>
                            <pre class="hge-wf-snippet" id="hge-wf-digest-html" style="background:#f6f7f7;padding:10px;font-size:11px;max-height:200px;overflow:auto;border:1px solid #ddd;border-radius:3px;"><?php echo esc_html( $digest_html ); ?></pre>
                            <p>
                                <button type="button" class="button hge-wf-copy" data-target="#hge-wf-digest-html"><?php esc_html_e( 'Copy digest loop', 'hge-automated-post-campaigns-for-klaviyo' ); ?></button>
                            </p>
                            <p class="description"><?php echo wp_kses_post( __( '<code>items[:3]</code> renders the top 3 articles; change the number to taste. Available fields per item: <code>id</code>, <code>title</code>, <code>url</code>, <code>excerpt</code>, <code>image</code>, <code>published_at</code>, <code>updated_at</code>, <code>date</code>, <code>author</code>, <code>categories[]</code>, <code>tags[]</code>.', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?></p>
                        </li>

                        <li style="margin-bottom:18px;">
                            <strong><?php esc_html_e( 'Wire the template to this rule', 'hge-automated-post-campaigns-for-klaviyo' ); ?></strong>
                            <p>
                                <?php echo wp_kses_post( __( 'Back here in WordPress → this rule card:', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?>
                            </p>
                            <ol style="list-style:decimal;padding-left:1.4em;">
                                <li><?php echo wp_kses_post( __( 'Pick the new template from the <em>Klaviyo template</em> dropdown.', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?></li>
                                <li><?php echo wp_kses_post( __( 'Check <em>Use Web Feed</em>.', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?></li>
                                <li><?php echo wp_kses_post( __( 'Confirm the <em>Web Feed name in Klaviyo</em> matches step 1 (<code class="hge-wf-name">newsletter_feed</code>).', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?></li>
                                <li><?php esc_html_e( 'Save settings.', 'hge-automated-post-campaigns-for-klaviyo' ); ?></li>
                            </ol>
                        </li>

                        <li>
                            <strong><?php esc_html_e( 'Test', 'hge-automated-post-campaigns-for-klaviyo' ); ?></strong>
                            <p>
                                <?php echo wp_kses_post( __( 'Publish a post with this rule\'s trigger tag. Within ~30 seconds you should see a draft campaign in Klaviyo with the template assigned + a send-job launched. Use <em>Send test</em> from Klaviyo first if you want to preview without dispatching to the audience.', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?>
                            </p>
                        </li>

                    </ol>

                </div>

                <div class="hge-wf-modal-footer" style="text-align:right;padding:10px 16px;border-top:1px solid #ddd;">
                    <button type="button" class="button button-primary hge-wf-modal-close"><?php esc_html_e( 'Got it', 'hge-automated-post-campaigns-for-klaviyo' ); ?></button>
                </div>
            </div>
        </div>

        <style>
        .hge-wf-modal { position: fixed; inset: 0; z-index: 100000; display: flex; align-items: center; justify-content: center; }
        .hge-wf-modal[hidden] { display: none; }
        .hge-wf-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.5); }
        .hge-wf-modal-dialog { position: relative; background: #fff; max-width: 760px; width: 92%; max-height: 86vh; display: flex; flex-direction: column; border-radius: 6px; box-shadow: 0 8px 32px rgba(0,0,0,0.25); }
        .hge-wf-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; border-bottom: 1px solid #ddd; }
        .hge-wf-modal-body { padding: 14px 18px; overflow: auto; }
        .hge-wf-modal-body pre { white-space: pre-wrap; word-break: break-word; }
        </style>

        <script>
        (function(){
            var modal = document.getElementById('hge-wf-modal');
            if ( ! modal ) { return; }
            var currentName = 'newsletter_feed';
            var currentUrl  = '';

            function interpolate( str ) {
                // Substitute the Web Feed NAME placeholder in copied snippets.
                return str.replace(/NAME/g, currentName);
            }

            function openModal( btn ) {
                currentName = btn.getAttribute('data-feed-name') || 'newsletter_feed';
                currentUrl  = btn.getAttribute('data-feed-url') || '';
                modal.querySelectorAll('.hge-wf-name').forEach(function(el){ el.textContent = currentName; });
                modal.querySelectorAll('.hge-wf-url').forEach(function(el){
                    el.textContent = currentUrl !== '' ? currentUrl : '—';
                });
                modal.hidden = false;
                // Focus the close button so Esc / Enter behave predictably
                var closeBtn = modal.querySelector('.hge-wf-modal-close');
                if ( closeBtn ) { closeBtn.focus(); }
            }

            function closeModal() { modal.hidden = true; }

            document.addEventListener('click', function(ev){
                var t = ev.target;
                if ( t && t.classList && t.classList.contains('hge-wf-quickstart') ) {
                    ev.preventDefault();
                    openModal(t);
                }
            });

            modal.addEventListener('click', function(ev){
                var t = ev.target;
                if ( t && t.classList && (t.classList.contains('hge-wf-modal-close') || t.classList.contains('hge-wf-modal-backdrop')) ) {
                    closeModal();
                    return;
                }
                if ( t && t.classList && t.classList.contains('hge-wf-copy') ) {
                    var sel = t.getAttribute('data-target');
                    var src = sel ? modal.querySelector(sel) : null;
                    if ( ! src ) { return; }
                    var text = interpolate( src.textContent || '' );
                    if ( navigator.clipboard && navigator.clipboard.writeText ) {
                        navigator.clipboard.writeText(text).then(function(){
                            var prev = t.textContent;
                            t.textContent = '<?php echo esc_js( __( '✓ Copied', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?>';
                            setTimeout(function(){ t.textContent = prev; }, 1500);
                        });
                    } else {
                        // Fallback for older browsers / non-HTTPS dev sites
                        var ta = document.createElement('textarea');
                        ta.value = text;
                        document.body.appendChild(ta);
                        ta.select();
                        try { document.execCommand('copy'); } catch (e) {}
                        document.body.removeChild(ta);
                        var prev2 = t.textContent;
                        t.textContent = '<?php echo esc_js( __( '✓ Copied', 'hge-automated-post-campaigns-for-klaviyo' ) ); ?>';
                        setTimeout(function(){ t.textContent = prev2; }, 1500);
                    }
                }
            });

            document.addEventListener('keydown', function(ev){
                if ( ! modal.hidden && ev.key === 'Escape' ) {
                    closeModal();
                }
            });
        })();
        </script>
        <?php
    }
}

/**
 * Render one rule card (used both server-side for initial render and
 * captured into a <script type="text/template"> for client-side add).
 *
 * Inputs:
 *   $idx            — initial card index (re-keyed on submit by sanitiser)
 *   $rule           — the rule dict (or default skeleton for blank template)
 *   $lists_data     — Klaviyo lists from API
 *   $segments_data  — Klaviyo segments from API (since 3.0.3)
 *   $templates_data — Klaviyo templates from API
 *   $caps           — per-rule caps (max_included, max_excluded, allow_template, allow_web_feed)
 *   $supports_multi — true on Pro plan (comma-separated tag_slug)
 *   $plan           — 'free' | 'core' | 'pro'
 *   $is_template    — when true, render as blank-template stub (no selected values)
 *
 * Lists and segments share the same <select> dropdowns via <optgroup> so the
 * sanitiser doesn't need to distinguish them (Klaviyo's Campaigns API accepts
 * both ID kinds in audiences.included / audiences.excluded interchangeably).
 *
 * @since 3.0.0
 * @since 3.0.3 Accepts $segments_data and emits an optgroup-grouped select.
 */
if ( ! function_exists( 'hge_klaviyo_render_rule_card' ) ) {
    function hge_klaviyo_render_rule_card( $idx, $rule, $lists_data, $segments_data, $templates_data, $caps, $supports_multi, $plan, $is_template = false ) {
        $name_prefix = 'hge_klaviyo[tag_rules][' . (int) $idx . ']';
        $id_prefix   = 'hge-rule-' . (int) $idx . '-';

        $included_disabled = empty( $lists_data );
        $excluded_allowed  = $caps['max_excluded'] > 0;
        $template_allowed  = (bool) $caps['allow_template'];
        $web_feed_allowed  = (bool) $caps['allow_web_feed'];

        $rule = array_merge( hge_klaviyo_nl_default_rule(), is_array( $rule ) ? $rule : array() );
        if ( $is_template ) {
            // Stub-out values for the JS-clonable template — user starts fresh
            $rule = hge_klaviyo_nl_default_rule();
        }

        echo '<div class="hge-klaviyo-rule-card" data-idx="' . esc_attr( $idx ) . '" style="border:1px solid #c3c4c7;border-left:4px solid #2271b1;background:#fff;padding:14px 18px;margin:10px 0;border-radius:3px;">';

        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">';
        echo '<h3 style="margin:0;font-size:14px;">' . esc_html__( 'Rule', 'hge-automated-post-campaigns-for-klaviyo' ) . ' <span class="hge-rule-num">#' . esc_html( $idx + 1 ) . '</span></h3>';
        echo '<button type="button" class="button-link hge-rule-remove" style="color:#b32d2e;text-decoration:none;">✕ ' . esc_html__( 'Delete rule', 'hge-automated-post-campaigns-for-klaviyo' ) . '</button>';
        echo '</div>';

        echo '<table class="form-table" role="presentation" style="margin-top:0;">';

        // tag_slug
        $slug_id    = $id_prefix . 'slug';
        $slug_label = $supports_multi
            ? __( 'Trigger tag(s)', 'hge-automated-post-campaigns-for-klaviyo' )
            : __( 'Trigger tag', 'hge-automated-post-campaigns-for-klaviyo' );
        echo '<tr><th scope="row" style="width:200px;"><label for="' . esc_attr( $slug_id ) . '">' . esc_html( $slug_label ) . '</label></th><td>';
        echo '<input type="text" id="' . esc_attr( $slug_id ) . '" name="' . esc_attr( $name_prefix ) . '[tag_slug]" value="' . esc_attr( $rule['tag_slug'] ) . '" class="regular-text" placeholder="newsletter" />';
        if ( $supports_multi ) {
            echo '<p class="description">' . wp_kses_post( __( 'WordPress tag slug that triggers this rule. <strong>Pro:</strong> multiple comma-separated tags, e.g. <code>news,promo,events</code> (any present tag fires the rule — OR semantics).', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '</p>';
        } else {
            echo '<p class="description">' . wp_kses_post( __( 'WordPress tag slug that triggers this rule. Ex: <code>newsletter</code>.', 'hge-automated-post-campaigns-for-klaviyo' ) );
            if ( 'free' === $plan ) {
                echo ' ' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'pro' ) ) . ' ' . esc_html__( 'for multi-tag (comma-separated).', 'hge-automated-post-campaigns-for-klaviyo' );
            }
            echo '</p>';
        }
        echo '</td></tr>';

        // Helper closure: render <optgroup>-grouped <option> list for audiences.
        // Lists + segments share the same select; selected values come from the
        // single $rule key (included_list_ids / excluded_list_ids — name kept
        // for backward-compat, value space now includes segment IDs too).
        $render_audience_options = static function ( $selected_ids ) use ( $lists_data, $segments_data, $plan ) {
            $selected_ids = array_map( 'strval', (array) $selected_ids );
            $out          = '';
            if ( ! empty( $lists_data ) ) {
                $out .= '<optgroup label="' . esc_attr__( 'Lists', 'hge-automated-post-campaigns-for-klaviyo' ) . '">';
                foreach ( $lists_data as $list ) {
                    $sel   = in_array( (string) $list['id'], $selected_ids, true ) ? ' selected' : '';
                    $count = isset( $list['profile_count'] ) ? $list['profile_count'] : null;
                    $out  .= '<option value="' . esc_attr( $list['id'] ) . '"' . $sel . ' data-kind="list">'
                        . esc_html( $list['name'] )
                        . esc_html( hge_klaviyo_format_list_count( $count ) )
                        . ' <small>(' . esc_html( $list['id'] ) . ')</small></option>';
                }
                $out .= '</optgroup>';
            }
            // Dynamic segments are a Pro feature (`dynamic_segments` in the
            // Pro tier-manager registry). Hide the Segments optgroup entirely
            // on Free + Core so the dropdown can't be used to pick segments
            // that the Pro module wouldn't accept anyway. Selected segment
            // IDs from a license-downgrade scenario are surfaced as a warning
            // line elsewhere — they're not silently kept in the dropdown.
            if ( 'pro' === $plan && ! empty( $segments_data ) ) {
                $out .= '<optgroup label="' . esc_attr__( 'Segments', 'hge-automated-post-campaigns-for-klaviyo' ) . '">';
                foreach ( $segments_data as $seg ) {
                    $sel   = in_array( (string) $seg['id'], $selected_ids, true ) ? ' selected' : '';
                    $count = isset( $seg['profile_count'] ) ? $seg['profile_count'] : null;
                    $out  .= '<option value="' . esc_attr( $seg['id'] ) . '"' . $sel . ' data-kind="segment">'
                        . esc_html( $seg['name'] )
                        . esc_html( hge_klaviyo_format_list_count( $count ) )
                        . ' <small>(' . esc_html( $seg['id'] ) . ')</small></option>';
                }
                $out .= '</optgroup>';
            }
            return $out;
        };

        // included_list_ids
        $inc_id   = $id_prefix . 'included';
        $inc_mult = $caps['max_included'] > 1;
        echo '<tr><th scope="row"><label for="' . esc_attr( $inc_id ) . '">' . esc_html__( 'Recipient list(s)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label></th><td>';
        if ( $included_disabled ) {
            echo '<em>' . esc_html__( 'Save the API Key to load the lists.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</em>';
        } else {
            echo '<select id="' . esc_attr( $inc_id ) . '" name="' . esc_attr( $name_prefix ) . '[included_list_ids][]"'
                . ( $inc_mult ? ' multiple size="5"' : '' )
                . ' class="hge-audience-select" data-audience-role="included" data-card-idx="' . esc_attr( $idx ) . '"'
                . ' style="min-width:340px;">';
            if ( ! $inc_mult ) {
                echo '<option value="">— ' . esc_html__( 'choose a list or segment', 'hge-automated-post-campaigns-for-klaviyo' ) . ' —</option>';
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- closure builds pre-escaped <option> markup (esc_attr + esc_html on every dynamic value).
            echo $render_audience_options( $rule['included_list_ids'] );
            echo '</select>';
        }
        echo '<p class="description">' . wp_kses_post(
            sprintf(
                /* translators: %d is the maximum number of lists per rule */
                _n( 'Max <strong>%d</strong> list per rule.', 'Max <strong>%d</strong> lists or segments per rule.', $caps['max_included'], 'hge-automated-post-campaigns-for-klaviyo' ),
                (int) $caps['max_included']
            )
        );
        if ( $inc_mult && ! $included_disabled ) {
            echo ' ' . esc_html__( 'Hold Ctrl (Windows) / Cmd (Mac) and click to add or remove items in the multi-select.', 'hge-automated-post-campaigns-for-klaviyo' );
        }
        if ( 'pro' !== $plan ) {
            echo ' ' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'pro' ) ) . ' ' . esc_html__( 'for up to 15 lists/segments per rule.', 'hge-automated-post-campaigns-for-klaviyo' );
        }
        echo '</p>';
        echo '</td></tr>';

        // excluded_list_ids
        $exc_id = $id_prefix . 'excluded';
        echo '<tr><th scope="row"><label for="' . esc_attr( $exc_id ) . '">' . esc_html__( 'Excluded list(s)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label></th><td>';
        if ( ! $excluded_allowed ) {
            echo '<em>—</em> <span class="description">' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'core' ) ) . ' ' . esc_html__( 'to be able to exclude lists from the audience.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</span>';
        } elseif ( $included_disabled ) {
            echo '<em>' . esc_html__( 'Save the API Key to load the lists.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</em>';
        } else {
            echo '<select id="' . esc_attr( $exc_id ) . '" name="' . esc_attr( $name_prefix ) . '[excluded_list_ids][]"'
                . ' multiple size="4"'
                . ' class="hge-audience-select" data-audience-role="excluded" data-card-idx="' . esc_attr( $idx ) . '"'
                . ' style="min-width:340px;">';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- closure builds pre-escaped <option> markup (esc_attr + esc_html on every dynamic value).
            echo $render_audience_options( $rule['excluded_list_ids'] );
            echo '</select>';
            echo '<p class="description">' . wp_kses_post(
                sprintf(
                    /* translators: %d is the maximum number of excluded lists per rule */
                    _n( 'Max <strong>%d</strong> excluded list.', 'Max <strong>%d</strong> excluded lists or segments.', $caps['max_excluded'], 'hge-automated-post-campaigns-for-klaviyo' ),
                    (int) $caps['max_excluded']
                )
            ) . ' ' . esc_html__( 'Klaviyo limit: included + excluded ≤ 15.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        }
        echo '</td></tr>';

        // template_id (Pro only — Core / Free hidden + locked to '')
        $tpl_id = $id_prefix . 'template';
        echo '<tr><th scope="row"><label for="' . esc_attr( $tpl_id ) . '">' . esc_html__( 'Klaviyo template', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label></th><td>';
        if ( ! $template_allowed ) {
            echo '<em>' . esc_html__( 'Built-in HTML template', 'hge-automated-post-campaigns-for-klaviyo' ) . '</em> <span class="description">' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'pro' ) ) . ' ' . esc_html__( 'to pick a template from your Klaviyo account.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</span>';
            // Keep an existing saved value (backward-compat for tier downgrades)
            if ( ! empty( $rule['template_id'] ) ) {
                echo '<input type="hidden" name="' . esc_attr( $name_prefix ) . '[template_id]" value="' . esc_attr( $rule['template_id'] ) . '">';
            }
        } else {
            // Combobox component (since 3.0.10) — supersedes the v3.0.7 search-
            // input + <select> pair. Single visible <input> doubles as search +
            // selection display; a hidden <input> carries the actual template_id
            // through form submit (same name as the prior <select>, so sanitizer
            // + DB shape are unchanged).
            //
            // Markup contract:
            //   <div class="hge-tpl-combo" data-card-idx="N">
            //     <input type="text" id="{tpl_id}" role="combobox" aria-controls="{tpl_list_id}" …>
            //     <button class="hge-tpl-clear" …>×</button>
            //     <input type="hidden" name="hge_klaviyo[tag_rules][N][template_id]" value="{id}">
            //     <ul id="{tpl_list_id}" role="listbox" hidden>
            //       <li role="option" data-value="{id}" data-name="{lowercased}">{name}</li>
            //     </ul>
            //     <span class="hge-tpl-count">…</span>
            //   </div>
            //
            // Keyboard contract: focus opens list; ↓ ↑ navigate (Home/End jump
            // to extremes); Enter selects highlighted; Esc closes; Tab closes
            // and submits naturally; click-outside closes.
            $tpl_list_id   = $id_prefix . 'template-list';
            $tpl_count_id  = $id_prefix . 'template-count';
            $tpl_total     = count( $templates_data );
            $selected_id   = (string) $rule['template_id'];
            $builtin_label = '— ' . __( 'use the built-in HTML template', 'hge-automated-post-campaigns-for-klaviyo' ) . ' —';
            // The visible input always displays the current selection's label
            // (template name OR the built-in sentinel) so the user sees what
            // they picked. Empty template_id → show the sentinel label.
            $selected_name = $builtin_label;
            foreach ( $templates_data as $tpl ) {
                if ( '' !== $selected_id && $selected_id === $tpl['id'] ) {
                    $selected_name = (string) $tpl['name'];
                    break;
                }
            }

            echo '<div class="hge-tpl-combo" style="position:relative;display:inline-block;min-width:340px;">';

            // Visible input — search + selected display
            echo '<input type="text" id="' . esc_attr( $tpl_id ) . '"'
                . ' class="hge-tpl-combo-input regular-text" autocomplete="off"'
                . ' role="combobox" aria-autocomplete="list" aria-expanded="false"'
                . ' aria-controls="' . esc_attr( $tpl_list_id ) . '"'
                . ' data-list="' . esc_attr( $tpl_list_id ) . '"'
                . ' data-count="' . esc_attr( $tpl_count_id ) . '"'
                . ' placeholder="' . esc_attr__( 'Choose or search a Klaviyo template…', 'hge-automated-post-campaigns-for-klaviyo' ) . '"'
                . ' value="' . esc_attr( $selected_name ) . '"'
                . ' style="min-width:340px;padding-right:28px;" />';

            // Clear (×) button — visible only when something is selected; CSS
            // handles the empty-input case via :placeholder-shown / fallback.
            echo '<button type="button" class="hge-tpl-clear" aria-label="' . esc_attr__( 'Clear template selection', 'hge-automated-post-campaigns-for-klaviyo' ) . '"'
                . ' style="position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:0;font-size:18px;color:#888;cursor:pointer;padding:0 4px;">✕</button>';

            // Hidden field — what actually submits. Same name as the v3.0.0 <select>.
            echo '<input type="hidden" name="' . esc_attr( $name_prefix ) . '[template_id]" value="' . esc_attr( $selected_id ) . '" data-default-label="' . esc_attr( $builtin_label ) . '" />';

            // Dropdown options list
            echo '<ul id="' . esc_attr( $tpl_list_id ) . '" class="hge-tpl-options" role="listbox" hidden'
                . ' style="position:absolute;top:100%;left:0;right:0;margin:2px 0 0;padding:0;list-style:none;background:#fff;border:1px solid #c3c4c7;border-radius:3px;max-height:260px;overflow:auto;z-index:5;box-shadow:0 4px 12px rgba(0,0,0,0.08);">';
            // First "use built-in" sentinel option — value="" so clearing selection still submits a valid empty template_id.
            // data-name carries the lowercased sentinel label so the filter's
            // "selectedName matches current term" branch in tplFilter() works
            // when the sentinel is the active selection.
            $is_default = ( '' === $selected_id );
            echo '<li role="option" data-value="" data-name="' . esc_attr( strtolower( $builtin_label ) ) . '" aria-selected="' . ( $is_default ? 'true' : 'false' ) . '"'
                . ' style="padding:6px 10px;cursor:pointer;color:#666;font-style:italic;">'
                . esc_html( $builtin_label )
                . '</li>';
            foreach ( $templates_data as $tpl ) {
                $is_sel  = ( $selected_id === $tpl['id'] );
                $editor  = isset( $tpl['editor_type'] ) ? $tpl['editor_type'] : '';
                echo '<li role="option" data-value="' . esc_attr( $tpl['id'] ) . '"'
                    . ' data-name="' . esc_attr( strtolower( $tpl['name'] ) ) . '"'
                    . ' aria-selected="' . ( $is_sel ? 'true' : 'false' ) . '"'
                    . ' style="padding:6px 10px;cursor:pointer;">'
                    . esc_html( $tpl['name'] )
                    . ( $editor ? ' <small style="color:#888;">(' . esc_html( $editor ) . ')</small>' : '' )
                    . '</li>';
            }
            echo '</ul>';

            echo '</div>'; // .hge-tpl-combo

            echo ' <span id="' . esc_attr( $tpl_count_id ) . '" class="hge-tpl-count description" style="margin-left:8px;color:#666;">' . esc_html(
                sprintf(
                    /* translators: %d is the number of Klaviyo templates */
                    _n( '%d template', '%d templates', $tpl_total, 'hge-automated-post-campaigns-for-klaviyo' ),
                    $tpl_total
                )
            ) . '</span>';
            // The "Quick start" trigger (since 3.0.9) replaces the prior single
            // help line about {{ web_feeds.NAME.items.0.* }}. The full Jinja
            // reference, starter HTML and step-by-step Web Feed setup all live
            // in the modal opened from the Web Feed row below — much friendlier
            // for first-time digest builders.
            echo '<p class="description">' . esc_html__( 'For Web Feed mode (digest emails), see the “Quick start” button under Web Feed mode below.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        }
        echo '</td></tr>';

        // Web Feed mode — re-scoped in 3.0.13:
        //   - The plugin ALWAYS exposes the `/feed/klaviyo-current.json` endpoint,
        //     on every tier (Free included). Customers can paste the per-rule
        //     URL into Klaviyo → Settings → Web Feeds and use it inside any
        //     Klaviyo template they build, regardless of plan.
        //   - The "Use Web Feed" CHECKBOX (which makes THIS plugin's auto-dispatch
        //     send a Klaviyo master-template campaign that pulls from the feed
        //     instead of the built-in inline HTML template) stays Pro-gated.
        //     That's the actual Pro value-add: server-side selection of the
        //     Klaviyo template + dispatch as a feed-driven campaign.
        //   - The "Web Feed name" text input is editable on every tier so the
        //     name (which becomes the `?name=` query parameter) can be set to
        //     match Klaviyo's Web Feed name. The URL preview below uses this
        //     value verbatim.
        $wn_id = $id_prefix . 'web_feed_name';
        echo '<tr><th scope="row">' . esc_html__( 'Klaviyo Web Feed (dynamic content for your Klaviyo templates)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</th><td>';

        // 1. The auto-dispatch toggle stays Pro-gated on Free/Core.
        if ( ! $web_feed_allowed ) {
            echo '<p><em>' . wp_kses_post( __( '<strong>Auto-dispatch via Web Feed mode</strong> (this plugin sends a Klaviyo master-template campaign that pulls dynamic content from your feed instead of the built-in inline HTML template) — <strong>Pro plan</strong>.', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '</em> ';
            echo wp_kses_post( hge_klaviyo_upgrade_cta_html( 'pro' ) );
            echo '</p>';
        } else {
            $wf_id = $id_prefix . 'use_web_feed';
            echo '<p><label><input type="checkbox" id="' . esc_attr( $wf_id ) . '" name="' . esc_attr( $name_prefix ) . '[use_web_feed]" value="1"' . checked( ! empty( $rule['use_web_feed'] ), true, false ) . ' /> ' . esc_html__( 'Use Web Feed mode (1 master template + dynamic data)', 'hge-automated-post-campaigns-for-klaviyo' ) . '</label></p>';
        }

        // 2. The feed URL itself — shown on every tier (since 3.0.13).
        //    Customers on Free can paste this into Klaviyo and use it inside
        //    custom campaigns / templates they build themselves, even without
        //    upgrading to the auto-dispatch Pro feature.
        $feed_token = function_exists( 'hge_klaviyo_nl_resolve_feed_token' ) ? hge_klaviyo_nl_resolve_feed_token() : '';
        $feed_name_sanitized = sanitize_key( (string) ( $rule['web_feed_name'] ?? '' ) );
        $feed_url = '';
        if ( '' !== $feed_token && '' !== $feed_name_sanitized && ! $is_template ) {
            $feed_url = add_query_arg(
                array( 'key' => $feed_token, 'name' => $feed_name_sanitized ),
                home_url( '/feed/klaviyo-current.json' )
            );
        }

        // 3. The name input — used to scope the feed transient lookup, also
        //    becomes the `?name=` query parameter in the URL. Editable on
        //    every tier so customers can match the name they configured in
        //    Klaviyo → Settings → Web Feeds.
        echo '<p><label for="' . esc_attr( $wn_id ) . '"><strong>' . esc_html__( 'Web Feed name (used in the URL below and in Klaviyo → Settings → Web Feeds):', 'hge-automated-post-campaigns-for-klaviyo' ) . '</strong></label><br>';
        echo '<input type="text" id="' . esc_attr( $wn_id ) . '" name="' . esc_attr( $name_prefix ) . '[web_feed_name]" value="' . esc_attr( $rule['web_feed_name'] ) . '" class="regular-text" style="max-width:240px;" placeholder="newsletter_feed" /></p>';

        // 4. Feed URL preview + setup steps — always shown.
        if ( '' !== $feed_url ) {
            echo '<div style="background:#f6f7f7;border-left:4px solid #2271b1;padding:10px 12px;margin-top:10px;">';
            echo '<p style="margin-top:0;"><strong>' . esc_html__( 'Your feed URL — paste this into Klaviyo:', 'hge-automated-post-campaigns-for-klaviyo' ) . '</strong></p>';
            echo '<p><code style="font-size:11px;word-break:break-all;">' . esc_html( $feed_url ) . '</code></p>';
            echo '<p style="margin-bottom:0;"><strong>' . esc_html__( 'How to use it (Free works the same as Pro for the feed itself):', 'hge-automated-post-campaigns-for-klaviyo' ) . '</strong></p>';
            echo '<ol style="margin:6px 0 0 24px;">';
            echo '<li>' . wp_kses_post( __( 'In Klaviyo, open <em>Settings → Other → Web Feeds</em> and click <em>Add Web Feed</em>.', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '</li>';
            echo '<li>' . wp_kses_post( sprintf(
                /* translators: %s is the Web Feed name configured in the field above */
                __( 'Set <em>Feed name</em> to exactly <code>%s</code> (match the field above).', 'hge-automated-post-campaigns-for-klaviyo' ),
                $feed_name_sanitized
            ) ) . '</li>';
            echo '<li>' . esc_html__( 'Paste the URL above into the Feed URL field. Save.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</li>';
            echo '<li>' . wp_kses_post( sprintf(
                /* translators: %s is the Web Feed name (becomes the Klaviyo template variable) */
                __( 'In any Klaviyo template / email block, reference the feed via the dynamic-data syntax. The published WordPress post that triggered the campaign appears as <code>{{ feeds.%s.items[0] }}</code> (title, url, excerpt, image, published_at, author, categories, tags).', 'hge-automated-post-campaigns-for-klaviyo' ),
                esc_html( $feed_name_sanitized )
            ) ) . '</li>';
            echo '</ol>';
            if ( ! $web_feed_allowed ) {
                echo '<p style="margin-bottom:0;color:#1d2327;">' . wp_kses_post( __( '✔ <strong>On the Free plan</strong> the feed is fully functional — build any Klaviyo template or flow against it. The feed content is the latest published post that matched a rule (single article). <br><br><strong>The Pro plan adds:</strong><ul style="margin:6px 0 0 24px;list-style:disc;"><li><em>Auto-dispatch via Web Feed mode</em> — this plugin sends a Klaviyo master-template campaign automatically every time a tagged post is published, instead of the built-in inline HTML template.</li><li><em>Per-feed content filters</em> — checkboxes for which post categories, tags, or other taxonomies should be included in (or excluded from) the feed, so the same site can power multiple Klaviyo Web Feeds for distinct audiences (e.g. one feed for <code>stiri</code>, another for <code>promotii</code>, another for a specific author or custom taxonomy term).</li></ul>', 'hge-automated-post-campaigns-for-klaviyo' ) ) . '</p>';
            }
            echo '</div>';
        } elseif ( '' === $feed_token ) {
            echo '<p class="description" style="color:#b32d2e;">' . esc_html__( 'Feed token not configured yet. Save the settings once — a 64-character token is auto-generated on first save and the URL will appear here.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
        }

        // 5. Quick start modal — always available.
        echo '<p style="margin-top:10px;">'
            . '<button type="button" class="button hge-wf-quickstart"'
            . ' data-feed-name="' . esc_attr( $feed_name_sanitized ) . '"'
            . ' data-feed-url="' . esc_attr( $feed_url ) . '">'
            . esc_html__( '📖 Quick start: build a Klaviyo digest template', 'hge-automated-post-campaigns-for-klaviyo' )
            . '</button>'
            . '</p>';

        echo '</td></tr>';

        // Per-feed content filters UI removed per user request 2026-05-27.
        // The backing infrastructure (schema in default_rule, sanitiser in
        // sanitize_rules, dispatch check in get_matching_rule, tax_query in
        // feed-endpoints.php) is kept intact so empty filters are a clean
        // no-op — and so a future UI iteration can re-expose the editor
        // without touching backend code. See FcRapid1923-bqn for the planned
        // future UX. For now, the rule is fully defined by:
        //   - Triggered tag(s)
        //   - Recipient list(s) (+ excluded lists, Pro)
        //   - (Pro) Klaviyo template
        //   - (Pro) Web Feed mode toggle
        //   - Web Feed name in Klaviyo

        echo '</table>';
        echo '</div>';
    }
}

// Add the new admin notice messages for Settings actions
add_filter( 'hge_klaviyo_admin_notice_messages', static function ( $messages ) {
    $messages['klaviyo_settings_saved'] = array( 'success', __( 'Settings saved.', 'hge-automated-post-campaigns-for-klaviyo' ) );
    $messages['klaviyo_api_refreshed']  = array( 'success', __( 'Klaviyo API cache cleared. The next render will fetch fresh data.', 'hge-automated-post-campaigns-for-klaviyo' ) );
    return $messages;
} );

// Marker pentru theme legacy: când e definit, blocul admin din functions.php se dezactivează.
if ( ! defined( 'HGE_KLAVIYO_NL_ADMIN_LOADED' ) ) {
    define( 'HGE_KLAVIYO_NL_ADMIN_LOADED', true );
}
