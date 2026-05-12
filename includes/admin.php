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
            __( 'Klaviyo Newsletter', 'hge-klaviyo-newsletter' ),
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

        echo '<p style="margin-top:0;"><strong>' . esc_html__( 'Status: ', 'hge-klaviyo-newsletter' ) . '</strong>';
        if ( 'yes' === $sent ) {
            echo '<span style="color:#1e8e3e;">✓ ' . esc_html__( 'Sent', 'hge-klaviyo-newsletter' ) . '</span></p>';
            if ( $camp_id ) {
                echo '<p style="font-size:12px;margin:4px 0;">' . esc_html__( 'Campaign ID:', 'hge-klaviyo-newsletter' ) . ' <code>' . esc_html( $camp_id ) . '</code></p>';
            }
            if ( $sent_at ) {
                echo '<p style="font-size:12px;margin:4px 0;">' . esc_html__( 'At:', 'hge-klaviyo-newsletter' ) . ' ' . esc_html( $sent_at ) . '</p>';
            }
        } elseif ( $scheduled ) {
            echo '<span style="color:#c45500;">' . esc_html__( 'Queued (Action Scheduler)', 'hge-klaviyo-newsletter' ) . '</span></p>';
        } else {
            echo '<span>' . esc_html__( 'Not sent', 'hge-klaviyo-newsletter' ) . '</span></p>';
        }

        echo '<ul style="font-size:12px;margin:8px 0 0 0;list-style:none;padding:0;">';
        if ( $has_tag ) {
            echo '<li>✓ ' . esc_html__( 'Matched rule — tag', 'hge-klaviyo-newsletter' ) . ' <code>' . esc_html( $matched_slug ) . '</code></li>';
        } else {
            echo '<li>✗ ' . esc_html__( 'No active rule tag is present on this post', 'hge-klaviyo-newsletter' ) . '</li>';
        }
        echo '<li>' . ( $is_pub ? '✓' : '✗' ) . ' ' . esc_html__( 'Status:', 'hge-klaviyo-newsletter' ) . ' <code>' . esc_html( $post->post_status ) . '</code></li>';
        echo '<li>' . ( $config_ok ? '✓' : '✗' ) . ' ' . esc_html__( 'Plugin configuration', 'hge-klaviyo-newsletter' )
            . ( $config_ok ? '' : ' <em>(' . wp_kses_post(
                sprintf(
                    /* translators: %s is the Settings tab link */
                    __( 'incomplete — see %s', 'hge-klaviyo-newsletter' ),
                    '<a href="' . esc_url( admin_url( 'tools.php?page=hge-klaviyo-newsletter&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'hge-klaviyo-newsletter' ) . '</a>'
                )
            ) . ')</em>' ) . '</li>';
        echo '<li>' . ( $as_loaded ? '✓' : '✗' ) . ' Action Scheduler'
            . ( $as_loaded ? '' : ' <em>(' . esc_html__( 'not loaded', 'hge-klaviyo-newsletter' ) . ')</em>' ) . '</li>';
        if ( $lock ) {
            echo '<li>⚠ ' . esc_html__( 'Active lock since:', 'hge-klaviyo-newsletter' ) . ' ' . esc_html( gmdate( 'Y-m-d H:i:s', (int) $lock ) ) . ' UTC</li>';
        }
        echo '</ul>';

        if ( $error ) {
            echo '<div style="margin-top:10px;padding:8px;background:#fde7e7;border-left:3px solid #c00;font-size:11px;">'
                . '<strong>' . esc_html__( 'Last error:', 'hge-klaviyo-newsletter' ) . '</strong><br><code style="word-break:break-all;">' . esc_html( $error ) . '</code></div>';
        }

        if ( $has_tag && $is_pub && $config_ok && 'yes' !== $sent ) {
            $url = wp_nonce_url(
                admin_url( 'admin-post.php?action=hge_klaviyo_send_now&post_id=' . (int) $post->ID ),
                'hge_klaviyo_send_now_' . $post->ID
            );
            $confirm_msg = esc_js( __( 'Send the newsletter to the configured Klaviyo list now?', 'hge-klaviyo-newsletter' ) );
            echo '<p style="margin-top:12px;"><a href="' . esc_url( $url ) . '" class="button button-primary" onclick="return confirm(\'' . $confirm_msg . '\');">' . esc_html__( 'Send now', 'hge-klaviyo-newsletter' ) . '</a></p>';
        }

        if ( 'yes' === $sent || $error || $lock ) {
            $reset_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=hge_klaviyo_reset&post_id=' . (int) $post->ID ),
                'hge_klaviyo_reset_' . $post->ID
            );
            $reset_confirm = esc_js( __( 'Reset the Klaviyo status for this post? This allows re-sending.', 'hge-klaviyo-newsletter' ) );
            echo '<p style="margin-top:8px;"><a href="' . esc_url( $reset_url ) . '" class="button" onclick="return confirm(\'' . $reset_confirm . '\');">' . esc_html__( 'Reset status', 'hge-klaviyo-newsletter' ) . '</a></p>';
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

if ( ! function_exists( 'hge_klaviyo_admin_notices' ) ) {
    function hge_klaviyo_admin_notices() {
        if ( empty( $_GET['klaviyo_msg'] ) ) {
            return;
        }
        $messages = apply_filters( 'hge_klaviyo_admin_notice_messages', array(
            'klaviyo_sent'           => array( 'success', __( 'Newsletter sent successfully via Klaviyo.', 'hge-klaviyo-newsletter' ) ),
            'klaviyo_error'          => array( 'error',   __( 'Error sending newsletter — see "Last error" in the meta box.', 'hge-klaviyo-newsletter' ) ),
            'klaviyo_unknown'        => array( 'warning', __( 'Uncertain status — check Custom Fields manually.', 'hge-klaviyo-newsletter' ) ),
            'klaviyo_reset'          => array( 'success', __( 'Klaviyo status reset. You can re-send.', 'hge-klaviyo-newsletter' ) ),
            'klaviyo_cooldown_reset' => array( 'success', __( 'Global cooldown reset. The next publish sends immediately.', 'hge-klaviyo-newsletter' ) ),
        ) );
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
        add_management_page(
            __( 'Klaviyo Newsletter', 'hge-klaviyo-newsletter' ),
            __( 'Klaviyo Newsletter', 'hge-klaviyo-newsletter' ),
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
            'settings' => __( 'Settings', 'hge-klaviyo-newsletter' ),
        ) );
        if ( $debug_enabled ) {
            $tabs['diagnostic'] = __( 'Status', 'hge-klaviyo-newsletter' );
        }

        // Enforce display order: Setări → Licență Pro → Status (orice tab terț apare la final).
        $ordered = array();
        foreach ( array( 'settings', 'license', 'diagnostic' ) as $known ) {
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
            esc_html__( 'Code version (constant)', 'hge-klaviyo-newsletter' ),
            esc_html( $version )
        );
        printf( '<tr><td>%s</td><td>%s — <code style="font-size:11px;">%s</code></td></tr>',
            esc_html__( 'Active code source', 'hge-klaviyo-newsletter' ),
            $source_is_plugin
                ? '<span style="color:#1e8e3e;">✓ plugin</span>'
                : '<span style="color:#c45500;">⚠ ' . esc_html__( 'theme legacy', 'hge-klaviyo-newsletter' ) . '</span>',
            esc_html( $source_file )
        );
        printf( '<tr><td>%s</td><td>%s</td></tr>',
            esc_html__( 'Configuration', 'hge-klaviyo-newsletter' ),
            $config_ok
                ? '<span style="color:#1e8e3e;">✓ ' . esc_html__( 'complete', 'hge-klaviyo-newsletter' ) . '</span> (' . esc_html__( 'Settings tab', 'hge-klaviyo-newsletter' ) . ')'
                : '<span style="color:#c00;">✗ ' . wp_kses_post(
                    sprintf(
                        /* translators: %s is the Settings tab link */
                        __( 'incomplete — see %s', 'hge-klaviyo-newsletter' ),
                        '<a href="' . esc_url( admin_url( 'tools.php?page=hge-klaviyo-newsletter&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'hge-klaviyo-newsletter' ) . '</a>'
                    )
                ) . '</span>'
        );
        printf( '<tr><td>Action Scheduler</td><td>%s</td></tr>',
            $as_loaded
                ? '<span style="color:#1e8e3e;">✓ ' . esc_html__( 'loaded', 'hge-klaviyo-newsletter' ) . '</span>'
                : '<span style="color:#c00;">✗ ' . esc_html__( 'not loaded (check WooCommerce)', 'hge-klaviyo-newsletter' ) . '</span>'
        );

        printf( '<tr><td>%s</td><td>%d / %d (' . esc_html__( 'plan', 'hge-klaviyo-newsletter' ) . ': <code>%s</code>)</td></tr>',
            esc_html__( 'Configured rules', 'hge-klaviyo-newsletter' ),
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
                esc_html__( 'Feed token', 'hge-klaviyo-newsletter' ),
                '' !== $feed_token_resolved
                    ? '<span style="color:#1e8e3e;">✓ ' . esc_html__( 'configured', 'hge-klaviyo-newsletter' ) . '</span> (' . esc_html( strlen( $feed_token_resolved ) ) . ' ' . esc_html__( 'characters', 'hge-klaviyo-newsletter' ) . ')'
                    : '<span style="color:#c00;">✗ ' . esc_html__( 'not defined — Klaviyo cannot authenticate to the feed', 'hge-klaviyo-newsletter' ) . '</span>' );
        }
        printf( '<tr><td>%s</td><td>%d ' . esc_html__( 'characters', 'hge-klaviyo-newsletter' ) . '</td></tr>',
            esc_html__( 'Excerpt length', 'hge-klaviyo-newsletter' ),
            (int) apply_filters( 'hge_klaviyo_excerpt_length', 120 )
        );
        printf( '<tr><td>%s</td><td>%d %s</td></tr>',
            esc_html__( 'Subject length (ASCII only)', 'hge-klaviyo-newsletter' ),
            (int) apply_filters( 'hge_klaviyo_subject_length', 60 ),
            esc_html__( 'characters, no diacritics', 'hge-klaviyo-newsletter' )
        );

        printf( '<tr><td>Smart Sending</td><td><span style="color:#c00;">%s</span> — %s</td></tr>',
            esc_html__( 'OFF', 'hge-klaviyo-newsletter' ),
            esc_html__( 'all list recipients receive the campaign', 'hge-klaviyo-newsletter' )
        );

        $min_int_h = (int) ( hge_klaviyo_min_interval_seconds() / HOUR_IN_SECONDS );
        printf( '<tr><td>%s</td><td>%d %s <em>(%s)</em></td></tr>',
            esc_html__( 'Minimum interval between sends', 'hge-klaviyo-newsletter' ),
            $min_int_h,
            esc_html__( 'hours', 'hge-klaviyo-newsletter' ),
            esc_html__( 'per rule', 'hge-klaviyo-newsletter' )
        );
        echo '</tbody></table>';

        // Per-rule diagnostic — replaces the legacy single-tag/template summary.
        // Per-rule "Active post" column reads the keyed transient (since 3.0.0)
        // so a leftover post-id from any specific rule's Web Feed is surfaced.
        if ( ! empty( $rules ) ) {
            echo '<h3 style="margin-top:18px;">' . esc_html__( 'Active rules', 'hge-klaviyo-newsletter' ) . '</h3>';
            echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr>';
            echo '<th>#</th>'
                . '<th>' . esc_html__( 'Tag(s)', 'hge-klaviyo-newsletter' ) . '</th>'
                . '<th>' . esc_html__( 'Included', 'hge-klaviyo-newsletter' ) . '</th>'
                . '<th>' . esc_html__( 'Excluded', 'hge-klaviyo-newsletter' ) . '</th>'
                . '<th>' . esc_html__( 'Template', 'hge-klaviyo-newsletter' ) . '</th>'
                . '<th>' . esc_html__( 'Web Feed (name)', 'hge-klaviyo-newsletter' ) . '</th>'
                . '<th>' . esc_html__( 'Active post', 'hge-klaviyo-newsletter' ) . '</th>'
                . '<th>' . esc_html__( 'Last send (UTC)', 'hge-klaviyo-newsletter' ) . '</th>';
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
                            : '<em>(' . esc_html__( 'post not found, id=', 'hge-klaviyo-newsletter' ) . (int) $pid . ')</em>';
                    }
                }

                echo '<tr>';
                printf( '<td>%d</td>', $i + 1 );
                printf( '<td><code>%s</code></td>', esc_html( $slug !== '' ? $slug : '—' ) );
                printf( '<td>%s</td>', $inc ? esc_html( implode( ', ', $inc ) ) : '<em>—</em>' );
                printf( '<td>%s</td>', $exc ? esc_html( implode( ', ', $exc ) ) : '<em>—</em>' );
                printf( '<td>%s</td>', $tpl ? '<code>' . esc_html( $tpl ) . '</code>' : '<em>' . esc_html__( 'built-in', 'hge-klaviyo-newsletter' ) . '</em>' );
                printf( '<td>%s</td>', $wf ? '<span style="color:#1e8e3e;">' . esc_html__( 'ACTIVE', 'hge-klaviyo-newsletter' ) . '</span> <code>' . esc_html( $wf_name ) . '</code>' : '—' );
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
            $confirm_legacy = esc_js( __( 'Reset the legacy global cooldown? Per-rule cooldowns remain untouched.', 'hge-klaviyo-newsletter' ) );
            echo '<p style="margin-top:8px;"><a href="' . esc_url( $reset_cd_url ) . '" class="button" onclick="return confirm(\'' . $confirm_legacy . '\');">' . esc_html__( 'Reset legacy global cooldown', 'hge-klaviyo-newsletter' ) . '</a> <em style="font-size:12px;">— ' . esc_html__( 'resets the v2.x legacy option. Per-rule cooldowns remain in', 'hge-klaviyo-newsletter' ) . ' <code>hge_klaviyo_last_send_at_by_slug</code>.</em></p>';
        }

        echo '<h3 style="margin-top:18px;">' . esc_html__( 'Placeholders available in the Klaviyo template', 'hge-klaviyo-newsletter' ) . '</h3>';
        echo '<p style="font-size:13px;">' . esc_html__( 'Drop any of these into your Klaviyo template HTML (selected in Settings); they are replaced per post before the campaign is dispatched.', 'hge-klaviyo-newsletter' ) . '</p>';
        echo '<table class="widefat striped" style="max-width:720px;"><tbody>';
        echo '<tr><td><code>{{title}}</code></td><td>' . esc_html__( 'Post title (HTML escaped)', 'hge-klaviyo-newsletter' ) . '</td></tr>';
        echo '<tr><td><code>{{excerpt}}</code></td><td>' . esc_html__( 'Short description (max 120 chars, HTML escaped)', 'hge-klaviyo-newsletter' ) . '</td></tr>';
        echo '<tr><td><code>{{image}}</code></td><td>' . wp_kses_post( __( 'Featured image URL (use inside <code>src=""</code>)', 'hge-klaviyo-newsletter' ) ) . '</td></tr>';
        echo '<tr><td><code>{{url}}</code></td><td>' . wp_kses_post( __( 'Post URL with UTM (use inside <code>href=""</code>)', 'hge-klaviyo-newsletter' ) ) . '</td></tr>';
        echo '<tr><td><code>{{date}}</code></td><td>' . esc_html__( 'Publication date (WP-formatted)', 'hge-klaviyo-newsletter' ) . '</td></tr>';
        echo '<tr><td><code>{{site}}</code></td><td>' . esc_html__( 'Site name', 'hge-klaviyo-newsletter' ) . '</td></tr>';
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
                    __( 'No rule with a <code>tag_slug</code> configured. Set at least one rule in %s.', 'hge-klaviyo-newsletter' ),
                    '<a href="' . esc_url( admin_url( 'tools.php?page=hge-klaviyo-newsletter&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'hge-klaviyo-newsletter' ) . '</a>'
                )
            ) . '</p></div>';
            echo '</div>';
            return;
        }

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

        $slugs_html = implode( ', ', array_map( static function ( $s ) {
            return '<code>' . esc_html( $s ) . '</code>';
        }, $all_slugs ) );
        echo '<h2 style="margin-top:24px;">' . wp_kses_post(
            sprintf(
                /* translators: %s is a comma-separated list of <code>-wrapped tag slugs */
                __( 'Posts with configured tags (%s) — last 20', 'hge-klaviyo-newsletter' ),
                $slugs_html
            )
        ) . '</h2>';

        if ( empty( $posts ) ) {
            echo '<p><em>' . esc_html__( 'No posts found with any of the configured tags.', 'hge-klaviyo-newsletter' ) . '</em></p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__( 'Title', 'hge-klaviyo-newsletter' ) . '</th>'
            . '<th>' . esc_html__( 'WP status', 'hge-klaviyo-newsletter' ) . '</th>'
            . '<th>' . esc_html__( 'Sent?', 'hge-klaviyo-newsletter' ) . '</th>'
            . '<th>' . esc_html__( 'Campaign ID', 'hge-klaviyo-newsletter' ) . '</th>'
            . '<th>' . esc_html__( 'Scheduled / Sent at (UTC)', 'hge-klaviyo-newsletter' ) . '</th>'
            . '<th>' . esc_html__( 'Error', 'hge-klaviyo-newsletter' ) . '</th>'
            . '<th>' . esc_html__( 'Actions', 'hge-klaviyo-newsletter' ) . '</th>';
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
                echo '<td><strong>📅 ' . esc_html( $sched ) . '</strong><br><small>(' . esc_html__( 'dispatch:', 'hge-klaviyo-newsletter' ) . ' ' . esc_html( $sent_at ) . ')</small></td>';
            } else {
                echo '<td>' . ( $sent_at ? esc_html( $sent_at ) : '—' ) . '</td>';
            }
            echo '<td>' . ( $error ? '<code style="color:#c00;font-size:11px;">' . esc_html( substr( $error, 0, 120 ) ) . '</code>' : '—' ) . '</td>';
            echo '<td>';
            $send_confirm  = esc_js( __( 'Send newsletter to the Klaviyo list?', 'hge-klaviyo-newsletter' ) );
            $reset_confirm = esc_js( __( 'Reset Klaviyo status?', 'hge-klaviyo-newsletter' ) );
            if ( 'publish' === $p->post_status && 'yes' !== $sent && $config_ok ) {
                echo '<a href="' . esc_url( $send_url ) . '" class="button button-small button-primary" onclick="return confirm(\'' . $send_confirm . '\');">' . esc_html__( 'Send', 'hge-klaviyo-newsletter' ) . '</a> ';
            }
            if ( 'yes' === $sent || $error ) {
                echo '<a href="' . esc_url( $reset_url ) . '" class="button button-small" onclick="return confirm(\'' . $reset_confirm . '\');">' . esc_html__( 'Reset', 'hge-klaviyo-newsletter' ) . '</a>';
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
        $word  = _n( 'subscriber', 'subscribers', $count, 'hge-klaviyo-newsletter' );
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
            return __( 'No Klaviyo API key configured. Fill in the <strong>Klaviyo API Key</strong> field above and click <strong>Save settings</strong>.', 'hge-klaviyo-newsletter' );
        }

        // 401 — invalid / revoked / wrong key
        if ( false !== strpos( $raw, 'HTTP 401' )
             || false !== stripos( $raw, 'authentication_failed' )
             || false !== stripos( $raw, 'Incorrect authentication credentials' ) ) {
            return __( 'The Klaviyo API key is invalid or has been revoked. Generate a new key in Klaviyo &rarr; Settings &rarr; API Keys, replace it in the <strong>Klaviyo API Key</strong> field above and click <strong>Save settings</strong>.', 'hge-klaviyo-newsletter' );
        }

        // 403 — insufficient scopes
        if ( false !== strpos( $raw, 'HTTP 403' ) ) {
            return __( 'The Klaviyo API key lacks the required scopes. Required: <code>campaigns:write</code>, <code>templates:write</code>, <code>lists:read</code>, <code>segments:read</code>. Generate a new key with all scopes checked and save.', 'hge-klaviyo-newsletter' );
        }

        // 429 — rate limited
        if ( false !== strpos( $raw, 'HTTP 429' ) ) {
            return __( 'Klaviyo applied rate-limiting (too many requests in a short window). Wait a few minutes and try again.', 'hge-klaviyo-newsletter' );
        }

        // 5xx — Klaviyo down
        if ( preg_match( '/HTTP 5\d\d/', $raw ) ) {
            return __( 'The Klaviyo server is not responding correctly (5xx). Try again in a few minutes. If the issue persists, check <a href="https://status.klaviyo.com/" target="_blank" rel="noopener">status.klaviyo.com</a>.', 'hge-klaviyo-newsletter' );
        }

        // Network / timeout
        if ( false !== stripos( $raw, 'cURL error' )
             || false !== stripos( $raw, 'timed out' )
             || false !== stripos( $raw, 'could not resolve host' ) ) {
            return __( 'Network error. The WordPress server cannot reach <code>a.klaviyo.com</code>. Check DNS, the firewall, or whether an outbound proxy is in place on this install.', 'hge-klaviyo-newsletter' );
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'hge_klaviyo_save_settings' );

        $input = isset( $_POST['hge_klaviyo'] ) && is_array( $_POST['hge_klaviyo'] )
            ? wp_unslash( $_POST['hge_klaviyo'] )
            : array();

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

        /**
         * Filter — let Pro feature modules pull their own POST keys into the partial
         * before update_settings sanitises and persists.
         *
         * @since 2.2.0
         * @param array $partial Settings keys/values about to be persisted.
         * @param array $input   Raw $_POST['hge_klaviyo'] array (already wp_unslashed).
         */
        $partial = apply_filters( 'hge_klaviyo_settings_save_partial', $partial, $input );

        hge_klaviyo_nl_update_settings( $partial );

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

        echo '<h2>' . esc_html__( 'General settings', 'hge-klaviyo-newsletter' ) . '</h2>';
        echo '<table class="form-table" role="presentation">';

        // API Key
        echo '<tr><th scope="row"><label for="hge_klaviyo_api_key">' . esc_html__( 'Klaviyo API Key', 'hge-klaviyo-newsletter' ) . '</label></th><td>';
        echo '<input type="password" id="hge_klaviyo_api_key" name="hge_klaviyo[api_key]" value="' . esc_attr( $s['api_key'] ) . '" class="regular-text" autocomplete="new-password" />';
        echo '<p class="description">' . wp_kses_post( __( 'Private API key (Klaviyo → Settings → API Keys). Required scopes: <code>campaigns:write</code>, <code>templates:write</code>, <code>lists:read</code>, <code>segments:read</code>.', 'hge-klaviyo-newsletter' ) ) . '</p>';
        echo '</td></tr>';

        // Feed Token
        echo '<tr><th scope="row"><label for="hge_klaviyo_feed_token">' . esc_html__( 'Feed token', 'hge-klaviyo-newsletter' ) . '</label></th><td>';
        echo '<input type="text" id="hge_klaviyo_feed_token" name="hge_klaviyo[feed_token]" value="' . esc_attr( $s['feed_token'] ) . '" class="regular-text" />';
        echo '<p class="description">' . wp_kses_post( __( 'Random string (32+ chars) used to authenticate requests to <code>/feed/klaviyo*.json</code>. Generate with <code>openssl rand -hex 32</code>.', 'hge-klaviyo-newsletter' ) ) . '</p>';
        echo '</td></tr>';

        // Refresh API cache
        if ( $can_query_api ) {
            echo '<tr><th scope="row">' . esc_html__( 'Klaviyo data', 'hge-klaviyo-newsletter' ) . '</th><td>';
            echo '<a href="' . esc_url( $refresh_url ) . '" class="button">' . esc_html__( 'Reload from Klaviyo', 'hge-klaviyo-newsletter' ) . '</a>';
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
                        __( '%1$d lists, %2$d segments, %3$d templates (5 min cache)', 'hge-klaviyo-newsletter' ),
                        $list_count,
                        $segment_count,
                        $tpl_count
                    )
                ) . '</span>';

                if ( $segments_error && 0 === $segment_count ) {
                    $seg_friendly = hge_klaviyo_friendly_api_error( $segments_error );
                    echo '<br><span style="color:#c00;font-size:12px;">⚠ ' . esc_html__( 'Segments:', 'hge-klaviyo-newsletter' ) . ' ' . wp_kses_post( $seg_friendly ) . '</span>';
                }

                if ( $templates_error && 0 === $tpl_count ) {
                    $tpl_friendly = hge_klaviyo_friendly_api_error( $templates_error );
                    echo '<br><span style="color:#c00;font-size:12px;">⚠ ' . esc_html__( 'Templates:', 'hge-klaviyo-newsletter' ) . ' ' . wp_kses_post( $tpl_friendly ) . '</span>';
                }

                if ( ! $templates_error && 0 === $tpl_count && $list_count > 0 ) {
                    echo '<p class="description" style="margin-top:6px;">' . wp_kses_post( __( 'No template saved in your Klaviyo account. Create one in <a href="https://www.klaviyo.com/email-templates" target="_blank" rel="noopener">Klaviyo &rarr; Email Templates</a> (any name + Code/HTML or Drag & Drop editor), then click <strong>Reload from Klaviyo</strong>.', 'hge-klaviyo-newsletter' ) ) . '</p>';
                }
            }
            echo '</td></tr>';
        }

        // Reply-to
        echo '<tr><th scope="row"><label for="hge_klaviyo_reply_to">' . esc_html__( 'Reply-to address (optional)', 'hge-klaviyo-newsletter' ) . '</label></th><td>';
        echo '<input type="email" id="hge_klaviyo_reply_to" name="hge_klaviyo[reply_to_email]" value="' . esc_attr( $s['reply_to_email'] ) . '" class="regular-text" placeholder="contact@example.com" />';
        echo '<p class="description">' . esc_html__( 'When set, overrides the reply-to configured in Klaviyo. Leave empty to use the Klaviyo account default.', 'hge-klaviyo-newsletter' ) . '</p>';
        echo '</td></tr>';

        // Min interval
        echo '<tr><th scope="row"><label for="hge_klaviyo_interval">' . esc_html__( 'Minimum interval between sends (hours)', 'hge-klaviyo-newsletter' ) . '</label></th><td>';
        echo '<input type="number" id="hge_klaviyo_interval" name="hge_klaviyo[min_interval_hours]" value="' . esc_attr( (int) $s['min_interval_hours'] ) . '" min="0" max="168" step="1" class="small-text" />';
        echo '<p class="description">' . wp_kses_post( __( 'Default 12. Cooldown is applied <strong>per rule</strong> (per tag). Set 0 to disable.', 'hge-klaviyo-newsletter' ) ) . '</p>';
        echo '</td></tr>';

        // Debug mode
        echo '<tr><th scope="row">' . esc_html__( 'Debug mode', 'hge-klaviyo-newsletter' ) . '</th><td>';
        echo '<label><input type="checkbox" name="hge_klaviyo[debug_mode]" value="1" ' . checked( ! empty( $s['debug_mode'] ), true, false ) . '> ' . wp_kses_post( __( 'Enable the <strong>Status</strong> tab (diagnostic + activity logs + raw server responses)', 'hge-klaviyo-newsletter' ) ) . '</label>';
        echo '<p class="description">' . esc_html__( 'Leave off in production. Turn on when you need to inspect the webhook / dispatch / API response flow.', 'hge-klaviyo-newsletter' ) . '</p>';
        echo '</td></tr>';

        echo '</table>';

        // ====== Section 2 — Newsletter rules (cards) ======

        $rules     = is_array( $s['tag_rules'] ?? null ) ? $s['tag_rules'] : array();
        $rule_count = count( $rules );

        $plan_label = ( 'pro' === $plan )
            ? __( 'PRO', 'hge-klaviyo-newsletter' )
            : ( ( 'core' === $plan ) ? __( 'CORE', 'hge-klaviyo-newsletter' ) : __( 'FREE', 'hge-klaviyo-newsletter' ) );

        echo '<h2 style="margin-top:24px;">' . esc_html__( 'Newsletter rules', 'hge-klaviyo-newsletter' ) . '</h2>';
        echo '<p class="description" style="max-width:780px;">' . wp_kses_post( __( 'Each rule maps a post <strong>tag</strong> to a configuration: <em>recipient list(s)</em>, <em>excluded list(s)</em> (Core+), <em>Klaviyo template</em> (Pro) and <em>Web Feed mode</em> (Pro). When a post is published, the plugin matches the first rule whose tag is present on the post (card order = priority) and dispatches using that rule. Cooldown is applied separately per rule (per tag).', 'hge-klaviyo-newsletter' ) ) . '</p>';
        echo '<p class="description" style="max-width:780px;"><strong>' . esc_html__( 'Current plan:', 'hge-klaviyo-newsletter' ) . '</strong> ' . esc_html( $plan_label ) . ' — ' . esc_html(
            sprintf(
                /* translators: %d is the maximum number of rules */
                _n( 'max %d rule', 'max %d rules', $max_rules, 'hge-klaviyo-newsletter' ),
                $max_rules
            )
        ) . '.';
        if ( 'pro' !== $plan ) {
            echo ' ' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'free' === $plan ? 'core' : 'pro' ) );
        }
        echo '</p>';

        if ( ! $can_query_api ) {
            echo '<div class="notice notice-warning inline" style="margin:8px 0;"><p>' . wp_kses_post( __( 'Save the <strong>Klaviyo API Key</strong> above first so that lists and templates can be loaded into the rule cards.', 'hge-klaviyo-newsletter' ) ) . '</p></div>';
        } elseif ( $api_error ) {
            echo '<div class="notice notice-error inline" style="margin:8px 0;"><p>' . esc_html__( 'Could not load lists from Klaviyo:', 'hge-klaviyo-newsletter' ) . ' ' . wp_kses_post( hge_klaviyo_friendly_api_error( $api_error ) ) . '</p></div>';
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
        echo '<button type="button" id="hge-klaviyo-add-rule" class="button"' . ( $can_add ? '' : ' disabled' ) . '>' . esc_html__( 'Add rule', 'hge-klaviyo-newsletter' ) . '</button>';
        if ( ! $can_add ) {
            echo ' <span class="description">' . wp_kses_post(
                sprintf(
                    /* translators: 1: plan label (FREE / CORE / PRO), 2: rule count */
                    _n(
                        'You have reached the plan limit for <strong>%1$s</strong> (%2$d rule).',
                        'You have reached the plan limit for <strong>%1$s</strong> (%2$d rules).',
                        $max_rules,
                        'hge-klaviyo-newsletter'
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
        $js_confirm_last     = esc_js( __( 'This is the only rule. Deleting it stops all automatic sends. Continue?', 'hge-klaviyo-newsletter' ) );
        $js_confirm_delete   = esc_js( __( 'Delete this rule? The change takes effect after Save.', 'hge-klaviyo-newsletter' ) );
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
                        if ( ! confirm('<?php echo $js_confirm_last; ?>') ) {
                            return;
                        }
                    } else if ( ! confirm('<?php echo $js_confirm_delete; ?>') ) {
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
            // Template typeahead filter (since 3.0.7)
            //
            // Each rule card may render `<input class="hge-tpl-search">`
            // above its template `<select>`. As the user types, we hide
            // options whose `data-name` doesn't contain the search term
            // (substring, case-insensitive). The currently-selected option
            // and the empty placeholder ("use the built-in HTML template")
            // are never hidden, so submit always carries a valid value.
            //
            // Count badge updates with "Showing X of Y" while filtering.
            // -------------------------------------------------------------
            function applyTemplateSearch( input ) {
                var targetId = input.getAttribute('data-target');
                var countId  = input.getAttribute('data-count');
                var select   = targetId ? document.getElementById(targetId) : null;
                var countEl  = countId  ? document.getElementById(countId)  : null;
                if ( ! select ) { return; }
                var term = (input.value || '').toLowerCase().trim();
                var shown = 0, total = 0;
                for ( var i = 0; i < select.options.length; i++ ) {
                    var opt = select.options[i];
                    var name = opt.getAttribute('data-name');
                    if ( null === name ) {
                        // Placeholder (empty value) — always visible.
                        continue;
                    }
                    total++;
                    var match = ( '' === term ) || name.indexOf(term) !== -1;
                    // Never hide the currently-selected option, even if it
                    // doesn't match — the user would lose the indication of
                    // their current setting.
                    if ( opt.selected ) { match = true; }
                    opt.hidden = ! match;
                    if ( match ) { shown++; }
                }
                if ( countEl ) {
                    if ( '' === term ) {
                        countEl.textContent = total + ' ' + (total === 1 ? <?php echo wp_json_encode( __( 'template', 'hge-klaviyo-newsletter' ) ); ?> : <?php echo wp_json_encode( __( 'templates', 'hge-klaviyo-newsletter' ) ); ?>);
                    } else {
                        countEl.textContent = <?php echo wp_json_encode( __( 'Showing', 'hge-klaviyo-newsletter' ) ); ?> + ' ' + shown + ' / ' + total;
                    }
                }
            }
            container.addEventListener('input', function(ev) {
                var t = ev.target;
                if ( t && t.classList && t.classList.contains('hge-tpl-search') ) {
                    applyTemplateSearch(t);
                }
            });

            // Initial state — ensure add button reflects current count
            updateAddButton();
            applyCrossExcludeAll();
            container.querySelectorAll('.hge-tpl-search').forEach(applyTemplateSearch);
        })();
        </script>
        <?php

        /**
         * Action — let Pro feature modules render extra settings sections inside
         * the same form, just before the submit button.
         *
         * @since 2.2.0
         * @param array $s Current settings array.
         */
        do_action( 'hge_klaviyo_render_settings_extra', $s );

        submit_button( __( 'Save settings', 'hge-klaviyo-newsletter' ) );
        echo '</form>';
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
        echo '<h3 style="margin:0;font-size:14px;">' . esc_html__( 'Rule', 'hge-klaviyo-newsletter' ) . ' <span class="hge-rule-num">#' . esc_html( $idx + 1 ) . '</span></h3>';
        echo '<button type="button" class="button-link hge-rule-remove" style="color:#b32d2e;text-decoration:none;">✕ ' . esc_html__( 'Delete rule', 'hge-klaviyo-newsletter' ) . '</button>';
        echo '</div>';

        echo '<table class="form-table" role="presentation" style="margin-top:0;">';

        // tag_slug
        $slug_id    = $id_prefix . 'slug';
        $slug_label = $supports_multi
            ? __( 'Trigger tag(s)', 'hge-klaviyo-newsletter' )
            : __( 'Trigger tag', 'hge-klaviyo-newsletter' );
        echo '<tr><th scope="row" style="width:200px;"><label for="' . esc_attr( $slug_id ) . '">' . esc_html( $slug_label ) . '</label></th><td>';
        echo '<input type="text" id="' . esc_attr( $slug_id ) . '" name="' . esc_attr( $name_prefix ) . '[tag_slug]" value="' . esc_attr( $rule['tag_slug'] ) . '" class="regular-text" placeholder="newsletter" />';
        if ( $supports_multi ) {
            echo '<p class="description">' . wp_kses_post( __( 'WordPress tag slug that triggers this rule. <strong>Pro:</strong> multiple comma-separated tags, e.g. <code>news,promo,events</code> (any present tag fires the rule — OR semantics).', 'hge-klaviyo-newsletter' ) ) . '</p>';
        } else {
            echo '<p class="description">' . wp_kses_post( __( 'WordPress tag slug that triggers this rule. Ex: <code>newsletter</code>.', 'hge-klaviyo-newsletter' ) );
            if ( 'free' === $plan ) {
                echo ' ' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'pro' ) ) . ' ' . esc_html__( 'for multi-tag (comma-separated).', 'hge-klaviyo-newsletter' );
            }
            echo '</p>';
        }
        echo '</td></tr>';

        // Helper closure: render <optgroup>-grouped <option> list for audiences.
        // Lists + segments share the same select; selected values come from the
        // single $rule key (included_list_ids / excluded_list_ids — name kept
        // for backward-compat, value space now includes segment IDs too).
        $render_audience_options = static function ( $selected_ids ) use ( $lists_data, $segments_data ) {
            $selected_ids = array_map( 'strval', (array) $selected_ids );
            $out          = '';
            if ( ! empty( $lists_data ) ) {
                $out .= '<optgroup label="' . esc_attr__( 'Lists', 'hge-klaviyo-newsletter' ) . '">';
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
            if ( ! empty( $segments_data ) ) {
                $out .= '<optgroup label="' . esc_attr__( 'Segments', 'hge-klaviyo-newsletter' ) . '">';
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
        echo '<tr><th scope="row"><label for="' . esc_attr( $inc_id ) . '">' . esc_html__( 'Recipient list(s)', 'hge-klaviyo-newsletter' ) . '</label></th><td>';
        if ( $included_disabled ) {
            echo '<em>' . esc_html__( 'Save the API Key to load the lists.', 'hge-klaviyo-newsletter' ) . '</em>';
        } else {
            echo '<select id="' . esc_attr( $inc_id ) . '" name="' . esc_attr( $name_prefix ) . '[included_list_ids][]"'
                . ( $inc_mult ? ' multiple size="5"' : '' )
                . ' class="hge-audience-select" data-audience-role="included" data-card-idx="' . esc_attr( $idx ) . '"'
                . ' style="min-width:340px;">';
            if ( ! $inc_mult ) {
                echo '<option value="">— ' . esc_html__( 'choose a list or segment', 'hge-klaviyo-newsletter' ) . ' —</option>';
            }
            echo $render_audience_options( $rule['included_list_ids'] );
            echo '</select>';
        }
        echo '<p class="description">' . wp_kses_post(
            sprintf(
                /* translators: %d is the maximum number of lists per rule */
                _n( 'Max <strong>%d</strong> list per rule.', 'Max <strong>%d</strong> lists or segments per rule.', $caps['max_included'], 'hge-klaviyo-newsletter' ),
                (int) $caps['max_included']
            )
        );
        if ( 'pro' !== $plan ) {
            echo ' ' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'pro' ) ) . ' ' . esc_html__( 'for up to 15 lists/segments per rule.', 'hge-klaviyo-newsletter' );
        }
        echo '</p>';
        echo '</td></tr>';

        // excluded_list_ids
        $exc_id = $id_prefix . 'excluded';
        echo '<tr><th scope="row"><label for="' . esc_attr( $exc_id ) . '">' . esc_html__( 'Excluded list(s)', 'hge-klaviyo-newsletter' ) . '</label></th><td>';
        if ( ! $excluded_allowed ) {
            echo '<em>—</em> <span class="description">' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'core' ) ) . ' ' . esc_html__( 'to be able to exclude lists from the audience.', 'hge-klaviyo-newsletter' ) . '</span>';
        } elseif ( $included_disabled ) {
            echo '<em>' . esc_html__( 'Save the API Key to load the lists.', 'hge-klaviyo-newsletter' ) . '</em>';
        } else {
            echo '<select id="' . esc_attr( $exc_id ) . '" name="' . esc_attr( $name_prefix ) . '[excluded_list_ids][]"'
                . ' multiple size="4"'
                . ' class="hge-audience-select" data-audience-role="excluded" data-card-idx="' . esc_attr( $idx ) . '"'
                . ' style="min-width:340px;">';
            echo $render_audience_options( $rule['excluded_list_ids'] );
            echo '</select>';
            echo '<p class="description">' . wp_kses_post(
                sprintf(
                    /* translators: %d is the maximum number of excluded lists per rule */
                    _n( 'Max <strong>%d</strong> excluded list.', 'Max <strong>%d</strong> excluded lists or segments.', $caps['max_excluded'], 'hge-klaviyo-newsletter' ),
                    (int) $caps['max_excluded']
                )
            ) . ' ' . esc_html__( 'Klaviyo limit: included + excluded ≤ 15.', 'hge-klaviyo-newsletter' ) . '</p>';
        }
        echo '</td></tr>';

        // template_id (Pro only — Core / Free hidden + locked to '')
        $tpl_id = $id_prefix . 'template';
        echo '<tr><th scope="row"><label for="' . esc_attr( $tpl_id ) . '">' . esc_html__( 'Klaviyo template', 'hge-klaviyo-newsletter' ) . '</label></th><td>';
        if ( ! $template_allowed ) {
            echo '<em>' . esc_html__( 'Built-in HTML template', 'hge-klaviyo-newsletter' ) . '</em> <span class="description">' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'pro' ) ) . ' ' . esc_html__( 'to pick a template from your Klaviyo account.', 'hge-klaviyo-newsletter' ) . '</span>';
            // Keep an existing saved value (backward-compat for tier downgrades)
            if ( ! empty( $rule['template_id'] ) ) {
                echo '<input type="hidden" name="' . esc_attr( $name_prefix ) . '[template_id]" value="' . esc_attr( $rule['template_id'] ) . '">';
            }
        } else {
            // Vanilla typeahead filter (since 3.0.7) — keeps the DOM responsive
            // when the Klaviyo account ships hundreds of templates. The full
            // list is still rendered into the <select> (so form submit serialises
            // correctly without JS), but a search <input> above filters option
            // visibility client-side. No external dependency, no asset pipeline.
            $tpl_search_id = $id_prefix . 'template-search';
            $tpl_count_id  = $id_prefix . 'template-count';
            $tpl_total     = count( $templates_data );
            echo '<input type="search" id="' . esc_attr( $tpl_search_id ) . '"'
                . ' class="hge-tpl-search" data-target="' . esc_attr( $tpl_id ) . '"'
                . ' data-count="' . esc_attr( $tpl_count_id ) . '"'
                . ' placeholder="' . esc_attr__( 'Search templates by name…', 'hge-klaviyo-newsletter' ) . '"'
                . ' style="min-width:340px;margin-bottom:6px;display:block;" />';
            echo '<select id="' . esc_attr( $tpl_id ) . '" name="' . esc_attr( $name_prefix ) . '[template_id]" style="min-width:340px;">';
            echo '<option value=""' . ( '' === $rule['template_id'] ? ' selected' : '' ) . '>— ' . esc_html__( 'use the built-in HTML template', 'hge-klaviyo-newsletter' ) . ' —</option>';
            foreach ( $templates_data as $tpl ) {
                $sel = ( $rule['template_id'] === $tpl['id'] ) ? ' selected' : '';
                $editor = isset( $tpl['editor_type'] ) ? $tpl['editor_type'] : '';
                // data-name carries the lowercase name for case-insensitive
                // matching without recomputing on every keystroke.
                echo '<option value="' . esc_attr( $tpl['id'] ) . '"' . $sel
                    . ' data-name="' . esc_attr( strtolower( $tpl['name'] ) ) . '">'
                    . esc_html( $tpl['name'] )
                    . ( $editor ? ' <small>(' . esc_html( $editor ) . ')</small>' : '' )
                    . '</option>';
            }
            echo '</select>';
            echo ' <span id="' . esc_attr( $tpl_count_id ) . '" class="hge-tpl-count description" style="margin-left:8px;color:#666;">' . esc_html(
                sprintf(
                    /* translators: %d is the number of Klaviyo templates */
                    _n( '%d template', '%d templates', $tpl_total, 'hge-klaviyo-newsletter' ),
                    $tpl_total
                )
            ) . '</span>';
            echo '<p class="description">' . wp_kses_post( __( 'In Web Feed mode, your template must use <code>{{ web_feeds.NAME.items.0.* }}</code>.', 'hge-klaviyo-newsletter' ) ) . '</p>';
        }
        echo '</td></tr>';

        // use_web_feed + web_feed_name (Pro only)
        echo '<tr><th scope="row">' . esc_html__( 'Web Feed mode', 'hge-klaviyo-newsletter' ) . '</th><td>';
        if ( ! $web_feed_allowed ) {
            echo '<em>' . esc_html__( 'Unavailable', 'hge-klaviyo-newsletter' ) . '</em> <span class="description">' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'pro' ) ) . ' ' . esc_html__( 'for Web Feed mode (1 template + dynamic data).', 'hge-klaviyo-newsletter' ) . '</span>';
            if ( ! empty( $rule['web_feed_name'] ) ) {
                echo '<input type="hidden" name="' . esc_attr( $name_prefix ) . '[web_feed_name]" value="' . esc_attr( $rule['web_feed_name'] ) . '">';
            }
        } else {
            $wf_id = $id_prefix . 'use_web_feed';
            $wn_id = $id_prefix . 'web_feed_name';
            echo '<label><input type="checkbox" id="' . esc_attr( $wf_id ) . '" name="' . esc_attr( $name_prefix ) . '[use_web_feed]" value="1"' . checked( ! empty( $rule['use_web_feed'] ), true, false ) . ' /> ' . esc_html__( 'Use Web Feed (1 master template + dynamic data)', 'hge-klaviyo-newsletter' ) . '</label>';
            echo '<br><label for="' . esc_attr( $wn_id ) . '" style="display:inline-block;margin-top:8px;">' . esc_html__( 'Web Feed name in Klaviyo:', 'hge-klaviyo-newsletter' ) . '</label> ';
            echo '<input type="text" id="' . esc_attr( $wn_id ) . '" name="' . esc_attr( $name_prefix ) . '[web_feed_name]" value="' . esc_attr( $rule['web_feed_name'] ) . '" class="regular-text" style="max-width:200px;" placeholder="newsletter_feed" />';
            echo '<p class="description">' . esc_html__( 'Exact name configured in Klaviyo → Settings → Web Feeds.', 'hge-klaviyo-newsletter' ) . '</p>';

            // Per-rule feed URL preview (since 3.0.0). Keyed on web_feed_name so
            // each rule gets a distinct URL that Klaviyo can pull from.
            $feed_token = function_exists( 'hge_klaviyo_nl_resolve_feed_token' ) ? hge_klaviyo_nl_resolve_feed_token() : '';
            $feed_name_sanitized = sanitize_key( (string) $rule['web_feed_name'] );
            if ( '' !== $feed_token && '' !== $feed_name_sanitized && ! $is_template ) {
                $feed_url = add_query_arg(
                    array( 'key' => $feed_token, 'name' => $feed_name_sanitized ),
                    home_url( '/feed/klaviyo-current.json' )
                );
                echo '<p class="description" style="margin-top:6px;"><strong>' . esc_html__( 'URL for Klaviyo Web Feed (this rule):', 'hge-klaviyo-newsletter' ) . '</strong><br>'
                    . '<code style="font-size:11px;word-break:break-all;">' . esc_html( $feed_url ) . '</code></p>';
            }
        }
        echo '</td></tr>';

        echo '</table>';
        echo '</div>';
    }
}

// Add the new admin notice messages for Settings actions
add_filter( 'hge_klaviyo_admin_notice_messages', static function ( $messages ) {
    $messages['klaviyo_settings_saved'] = array( 'success', __( 'Settings saved.', 'hge-klaviyo-newsletter' ) );
    $messages['klaviyo_api_refreshed']  = array( 'success', __( 'Klaviyo API cache cleared. The next render will fetch fresh data.', 'hge-klaviyo-newsletter' ) );
    return $messages;
} );

// Marker pentru theme legacy: când e definit, blocul admin din functions.php se dezactivează.
if ( ! defined( 'HGE_KLAVIYO_NL_ADMIN_LOADED' ) ) {
    define( 'HGE_KLAVIYO_NL_ADMIN_LOADED', true );
}
