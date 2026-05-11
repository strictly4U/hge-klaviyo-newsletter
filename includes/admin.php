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
            'Klaviyo Newsletter',
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

        echo '<p style="margin-top:0;"><strong>Status: </strong>';
        if ( 'yes' === $sent ) {
            echo '<span style="color:#1e8e3e;">✓ Trimis</span></p>';
            if ( $camp_id ) {
                echo '<p style="font-size:12px;margin:4px 0;">Campaign ID: <code>' . esc_html( $camp_id ) . '</code></p>';
            }
            if ( $sent_at ) {
                echo '<p style="font-size:12px;margin:4px 0;">La: ' . esc_html( $sent_at ) . '</p>';
            }
        } elseif ( $scheduled ) {
            echo '<span style="color:#c45500;">În coadă (Action Scheduler)</span></p>';
        } else {
            echo '<span>Netrimis</span></p>';
        }

        echo '<ul style="font-size:12px;margin:8px 0 0 0;list-style:none;padding:0;">';
        if ( $has_tag ) {
            echo '<li>✓ Regulă potrivită — tag <code>' . esc_html( $matched_slug ) . '</code></li>';
        } else {
            echo '<li>✗ Niciun tag al regulilor active prezent pe articol</li>';
        }
        echo '<li>' . ( $is_pub ? '✓' : '✗' ) . ' Status: <code>' . esc_html( $post->post_status ) . '</code></li>';
        echo '<li>' . ( $config_ok ? '✓' : '✗' ) . ' Configurare plugin'
            . ( $config_ok ? '' : ' <em>(incompletă — vezi <a href="' . esc_url( admin_url( 'tools.php?page=hge-klaviyo-newsletter&tab=settings' ) ) . '">Setări</a>)</em>' ) . '</li>';
        echo '<li>' . ( $as_loaded ? '✓' : '✗' ) . ' Action Scheduler'
            . ( $as_loaded ? '' : ' <em>(neîncărcat)</em>' ) . '</li>';
        if ( $lock ) {
            echo '<li>⚠ Lock activ din: ' . esc_html( gmdate( 'Y-m-d H:i:s', (int) $lock ) ) . ' UTC</li>';
        }
        echo '</ul>';

        if ( $error ) {
            echo '<div style="margin-top:10px;padding:8px;background:#fde7e7;border-left:3px solid #c00;font-size:11px;">'
                . '<strong>Ultima eroare:</strong><br><code style="word-break:break-all;">' . esc_html( $error ) . '</code></div>';
        }

        if ( $has_tag && $is_pub && $config_ok && 'yes' !== $sent ) {
            $url = wp_nonce_url(
                admin_url( 'admin-post.php?action=hge_klaviyo_send_now&post_id=' . (int) $post->ID ),
                'hge_klaviyo_send_now_' . $post->ID
            );
            echo '<p style="margin-top:12px;"><a href="' . esc_url( $url ) . '" class="button button-primary" onclick="return confirm(\'Trimit newsletter-ul către lista Klaviyo configurată acum?\');">Trimite acum</a></p>';
        }

        if ( 'yes' === $sent || $error || $lock ) {
            $reset_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=hge_klaviyo_reset&post_id=' . (int) $post->ID ),
                'hge_klaviyo_reset_' . $post->ID
            );
            echo '<p style="margin-top:8px;"><a href="' . esc_url( $reset_url ) . '" class="button" onclick="return confirm(\'Resetez statusul Klaviyo pentru articol? Permite re-trimitere.\');">Reset status</a></p>';
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
            'klaviyo_sent'           => array( 'success', 'Newsletter trimis cu succes prin Klaviyo.' ),
            'klaviyo_error'          => array( 'error',   'Eroare la trimiterea newsletter — vezi „Ultima eroare" în meta box.' ),
            'klaviyo_unknown'        => array( 'warning', 'Status incert — verifică Custom Fields manual.' ),
            'klaviyo_reset'          => array( 'success', 'Status Klaviyo resetat. Poți retrimite.' ),
            'klaviyo_cooldown_reset' => array( 'success', 'Cooldown global resetat. Următoarea publicare se trimite imediat.' ),
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
            'Klaviyo Newsletter',
            'Klaviyo Newsletter',
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
        // "Status" (former Diagnostic) appears only when debug_mode is on (Settings → Mod debug).
        $tabs = apply_filters( 'hge_klaviyo_admin_tabs', array(
            'settings' => 'Setări',
        ) );
        if ( $debug_enabled ) {
            $tabs['diagnostic'] = 'Status';
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
        printf( '<tr><td>Versiune cod (constant)</td><td><code>%s</code></td></tr>', esc_html( $version ) );
        printf( '<tr><td>Sursă cod activă</td><td>%s — <code style="font-size:11px;">%s</code></td></tr>',
            $source_is_plugin
                ? '<span style="color:#1e8e3e;">✓ plugin</span>'
                : '<span style="color:#c45500;">⚠ theme legacy</span>',
            esc_html( $source_file )
        );
        printf( '<tr><td>Configurare</td><td>%s</td></tr>',
            $config_ok
                ? '<span style="color:#1e8e3e;">✓ completă</span> (tab-ul Setări)'
                : '<span style="color:#c00;">✗ incompletă — vezi <a href="' . esc_url( admin_url( 'tools.php?page=hge-klaviyo-newsletter&tab=settings' ) ) . '">Setări</a></span>'
        );
        printf( '<tr><td>Action Scheduler</td><td>%s</td></tr>',
            $as_loaded ? '<span style="color:#1e8e3e;">✓ încărcat</span>' : '<span style="color:#c00;">✗ neîncărcat (verifică WooCommerce)</span>' );

        printf( '<tr><td>Reguli configurate</td><td>%d / %d (plan: <code>%s</code>)</td></tr>',
            count( $rules ),
            (int) hge_klaviyo_nl_max_rules(),
            esc_html( function_exists( 'hge_klaviyo_active_plan' ) ? hge_klaviyo_active_plan() : 'free' )
        );

        $feed_token_resolved = function_exists( 'hge_klaviyo_nl_resolve_feed_token' ) ? hge_klaviyo_nl_resolve_feed_token() : '';
        $any_web_feed        = hge_klaviyo_use_web_feed();

        // Per-rule active-post lookup. Replaces the legacy single-transient diagnostic
        // in 2.x — each rule with Web Feed enabled has its own keyed transient.
        if ( $any_web_feed ) {
            printf( '<tr><td>Token Feed</td><td>%s</td></tr>',
                '' !== $feed_token_resolved
                    ? '<span style="color:#1e8e3e;">✓ configurat</span> (' . esc_html( strlen( $feed_token_resolved ) ) . ' caractere)'
                    : '<span style="color:#c00;">✗ nedefinit — Klaviyo nu se poate autentifica la feed</span>' );
        }
        printf( '<tr><td>Lungime descriere scurtă</td><td>%d caractere</td></tr>', (int) apply_filters( 'hge_klaviyo_excerpt_length', 120 ) );
        printf( '<tr><td>Lungime subiect (doar ASCII)</td><td>%d caractere, fără diacritice</td></tr>', (int) apply_filters( 'hge_klaviyo_subject_length', 60 ) );

        printf( '<tr><td>Smart Sending</td><td><span style="color:#c00;">OPRIT</span> — toți destinatarii din listă primesc</td></tr>' );

        $min_int_h = (int) ( hge_klaviyo_min_interval_seconds() / HOUR_IN_SECONDS );
        printf( '<tr><td>Pauză minimă între trimiteri</td><td>%d ore <em>(per regulă)</em></td></tr>', $min_int_h );
        echo '</tbody></table>';

        // Per-rule diagnostic — replaces the legacy single-tag/template summary.
        // Per-rule "Articol activ" column reads the keyed transient (since 3.0.0)
        // so a leftover post-id from any specific rule's Web Feed is surfaced.
        if ( ! empty( $rules ) ) {
            echo '<h3 style="margin-top:18px;">Reguli active</h3>';
            echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr>';
            echo '<th>#</th><th>Tag(uri)</th><th>Incluse</th><th>Excluse</th><th>Template</th><th>Web Feed (nume)</th><th>Articol activ</th><th>Ultima trimitere (UTC)</th>';
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
                            : '<em>(post inexistent, id=' . (int) $pid . ')</em>';
                    }
                }

                echo '<tr>';
                printf( '<td>%d</td>', $i + 1 );
                printf( '<td><code>%s</code></td>', esc_html( $slug !== '' ? $slug : '—' ) );
                printf( '<td>%s</td>', $inc ? esc_html( implode( ', ', $inc ) ) : '<em>—</em>' );
                printf( '<td>%s</td>', $exc ? esc_html( implode( ', ', $exc ) ) : '<em>—</em>' );
                printf( '<td>%s</td>', $tpl ? '<code>' . esc_html( $tpl ) . '</code>' : '<em>built-in</em>' );
                printf( '<td>%s</td>', $wf ? '<span style="color:#1e8e3e;">ACTIV</span> <code>' . esc_html( $wf_name ) . '</code>' : '—' );
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
            echo '<p style="margin-top:8px;"><a href="' . esc_url( $reset_cd_url ) . '" class="button" onclick="return confirm(\'Resetez cooldown-ul global legacy? Per-slug cooldown rămâne neatins.\');">Reset cooldown global legacy</a> <em style="font-size:12px;">— resetează opțiunea v2.x legacy. Cooldown-urile per-regulă rămân în <code>hge_klaviyo_last_send_at_by_slug</code>.</em></p>';
        }

        echo '<h3 style="margin-top:18px;">Placeholder-e disponibile în template-ul Klaviyo</h3>';
        echo '<p style="font-size:13px;">Pune oricare dintre acestea în HTML-ul template-ului tău Klaviyo (selectat în Settings); le înlocuim per articol înainte să trimitem campania.</p>';
        echo '<table class="widefat striped" style="max-width:720px;"><tbody>';
        echo '<tr><td><code>{{title}}</code></td><td>Titlul articolului (HTML escaped)</td></tr>';
        echo '<tr><td><code>{{excerpt}}</code></td><td>Descrierea scurtă (max 120 caractere, HTML escaped)</td></tr>';
        echo '<tr><td><code>{{image}}</code></td><td>URL-ul imaginii featured (folosește în <code>src=""</code>)</td></tr>';
        echo '<tr><td><code>{{url}}</code></td><td>URL-ul articolului cu UTM (folosește în <code>href=""</code>)</td></tr>';
        echo '<tr><td><code>{{date}}</code></td><td>Data publicării (formatat WP)</td></tr>';
        echo '<tr><td><code>{{site}}</code></td><td>Numele site-ului</td></tr>';
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
            echo '<div class="notice notice-warning inline" style="margin-top:12px;"><p>Nicio regulă cu <code>tag_slug</code> configurat. Setează cel puțin o regulă în <a href="' . esc_url( admin_url( 'tools.php?page=hge-klaviyo-newsletter&tab=settings' ) ) . '">Setări</a>.</p></div>';
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
        echo '<h2 style="margin-top:24px;">Articole cu tag-uri configurate (' . $slugs_html . ') — ultimele 20</h2>';

        if ( empty( $posts ) ) {
            echo '<p><em>Niciun articol găsit cu vreunul dintre tag-urile configurate.</em></p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Titlu</th><th>Status WP</th><th>Trimis?</th><th>Campaign ID</th><th>Programat / Trimis la (UTC)</th><th>Eroare</th><th>Acțiuni</th>';
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
                echo '<td><strong>📅 ' . esc_html( $sched ) . '</strong><br><small>(dispatch: ' . esc_html( $sent_at ) . ')</small></td>';
            } else {
                echo '<td>' . ( $sent_at ? esc_html( $sent_at ) : '—' ) . '</td>';
            }
            echo '<td>' . ( $error ? '<code style="color:#c00;font-size:11px;">' . esc_html( substr( $error, 0, 120 ) ) . '</code>' : '—' ) . '</td>';
            echo '<td>';
            if ( 'publish' === $p->post_status && 'yes' !== $sent && $config_ok ) {
                echo '<a href="' . esc_url( $send_url ) . '" class="button button-small button-primary" onclick="return confirm(\'Trimit newsletter către lista Klaviyo?\');">Trimite</a> ';
            }
            if ( 'yes' === $sent || $error ) {
                echo '<a href="' . esc_url( $reset_url ) . '" class="button button-small" onclick="return confirm(\'Reset status Klaviyo?\');">Reset</a>';
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
        $word  = ( 1 === $count ) ? 'abonat' : 'abonați';
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
            return 'Nicio cheie API Klaviyo configurată. Completează câmpul <strong>Cheie API Klaviyo</strong> de mai sus și apasă <strong>Salvează setările</strong>.';
        }

        // 401 — invalid / revoked / wrong key
        if ( false !== strpos( $raw, 'HTTP 401' )
             || false !== stripos( $raw, 'authentication_failed' )
             || false !== stripos( $raw, 'Incorrect authentication credentials' ) ) {
            return 'Cheia API Klaviyo este invalidă sau a fost revocată. Generează o cheie nouă din Klaviyo &rarr; Settings &rarr; API Keys, înlocuiește-o în câmpul <strong>Cheie API Klaviyo</strong> de mai sus și apasă <strong>Salvează setările</strong>.';
        }

        // 403 — insufficient scopes
        if ( false !== strpos( $raw, 'HTTP 403' ) ) {
            return 'Cheia API Klaviyo nu are scope-urile necesare. Trebuie: <code>campaigns:write</code>, <code>templates:write</code>, <code>lists:read</code>. Generează o cheie nouă cu toate scope-urile bifate și salvează.';
        }

        // 429 — rate limited
        if ( false !== strpos( $raw, 'HTTP 429' ) ) {
            return 'Klaviyo a aplicat rate-limiting (prea multe cereri într-un interval scurt). Așteaptă câteva minute și încearcă din nou.';
        }

        // 5xx — Klaviyo down
        if ( preg_match( '/HTTP 5\d\d/', $raw ) ) {
            return 'Server-ul Klaviyo nu răspunde corect (5xx). Încearcă din nou peste câteva minute. Dacă persistă, verifică <a href="https://status.klaviyo.com/" target="_blank" rel="noopener">status.klaviyo.com</a>.';
        }

        // Network / timeout
        if ( false !== stripos( $raw, 'cURL error' )
             || false !== stripos( $raw, 'timed out' )
             || false !== stripos( $raw, 'could not resolve host' ) ) {
            return 'Eroare de rețea. Server-ul WordPress nu poate ajunge la <code>a.klaviyo.com</code>. Verifică DNS-ul, firewall-ul sau dacă există un proxy de ieșire pe această instalare.';
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

        // Try fetching lists + templates only if API key is present
        $lists_data       = array();
        $templates_data   = array();
        $api_error        = '';
        $templates_error  = '';
        if ( $can_query_api && function_exists( 'hge_klaviyo_api_list_lists' ) ) {
            $lists = hge_klaviyo_api_list_lists();
            if ( is_wp_error( $lists ) ) {
                $api_error = $lists->get_error_message();
            } else {
                $lists_data = $lists;
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

        echo '<h2>Setări generale</h2>';
        echo '<table class="form-table" role="presentation">';

        // API Key
        echo '<tr><th scope="row"><label for="hge_klaviyo_api_key">Cheie API Klaviyo</label></th><td>';
        echo '<input type="password" id="hge_klaviyo_api_key" name="hge_klaviyo[api_key]" value="' . esc_attr( $s['api_key'] ) . '" class="regular-text" autocomplete="new-password" />';
        echo '<p class="description">Cheie API privată (Klaviyo → Settings → API Keys). Scopes necesare: <code>campaigns:write</code>, <code>templates:write</code>, <code>lists:read</code>.</p>';
        echo '</td></tr>';

        // Feed Token
        echo '<tr><th scope="row"><label for="hge_klaviyo_feed_token">Token Feed</label></th><td>';
        echo '<input type="text" id="hge_klaviyo_feed_token" name="hge_klaviyo[feed_token]" value="' . esc_attr( $s['feed_token'] ) . '" class="regular-text" />';
        echo '<p class="description">String aleator (32+ caractere) folosit pentru autentificarea cererilor către <code>/feed/klaviyo*.json</code>. Generează cu <code>openssl rand -hex 32</code>.</p>';
        echo '</td></tr>';

        // Refresh API cache
        if ( $can_query_api ) {
            echo '<tr><th scope="row">Date Klaviyo</th><td>';
            echo '<a href="' . esc_url( $refresh_url ) . '" class="button">Reîncarcă din Klaviyo</a>';
            if ( $api_error ) {
                $friendly = hge_klaviyo_friendly_api_error( $api_error );
                echo ' <span style="color:#c00;">⚠ ' . wp_kses_post( $friendly ) . '</span>';
            } else {
                $list_count = count( $lists_data );
                $tpl_count  = count( $templates_data );
                echo ' <span style="color:#666;">' . esc_html( $list_count ) . ' liste, ' . esc_html( $tpl_count ) . ' template-uri (cache 5 min)</span>';

                if ( $templates_error && 0 === $tpl_count ) {
                    $tpl_friendly = hge_klaviyo_friendly_api_error( $templates_error );
                    echo '<br><span style="color:#c00;font-size:12px;">⚠ Template-uri: ' . wp_kses_post( $tpl_friendly ) . '</span>';
                }

                if ( ! $templates_error && 0 === $tpl_count && $list_count > 0 ) {
                    echo '<p class="description" style="margin-top:6px;">Niciun template salvat în contul Klaviyo. Creează unul în <a href="https://www.klaviyo.com/email-templates" target="_blank" rel="noopener">Klaviyo &rarr; Email Templates</a> (orice nume + editor Code/HTML sau Drag & Drop), apoi apasă <strong>Reîncarcă din Klaviyo</strong>.</p>';
                }
            }
            echo '</td></tr>';
        }

        // Reply-to
        echo '<tr><th scope="row"><label for="hge_klaviyo_reply_to">Adresă răspuns (opțional)</label></th><td>';
        echo '<input type="email" id="hge_klaviyo_reply_to" name="hge_klaviyo[reply_to_email]" value="' . esc_attr( $s['reply_to_email'] ) . '" class="regular-text" placeholder="contact@example.com" />';
        echo '<p class="description">Dacă e completat, suprascrie adresa de răspuns setată în Klaviyo. Lasă gol pentru a folosi cea implicită din contul Klaviyo.</p>';
        echo '</td></tr>';

        // Min interval
        echo '<tr><th scope="row"><label for="hge_klaviyo_interval">Pauză minimă între trimiteri (ore)</label></th><td>';
        echo '<input type="number" id="hge_klaviyo_interval" name="hge_klaviyo[min_interval_hours]" value="' . esc_attr( (int) $s['min_interval_hours'] ) . '" min="0" max="168" step="1" class="small-text" />';
        echo '<p class="description">Implicit 12. Cooldown-ul se aplică <strong>per regulă</strong> (per tag). Setează 0 pentru a dezactiva.</p>';
        echo '</td></tr>';

        // Debug mode
        echo '<tr><th scope="row">Mod debug</th><td>';
        echo '<label><input type="checkbox" name="hge_klaviyo[debug_mode]" value="1" ' . checked( ! empty( $s['debug_mode'] ), true, false ) . '> Activează tab-ul <strong>Status</strong> (diagnostic + activity logs + raw server responses)</label>';
        echo '<p class="description">Lasă oprit în producție. Pornește când ai nevoie să verifici fluxul webhook / dispatch / API responses.</p>';
        echo '</td></tr>';

        echo '</table>';

        // ====== Section 2 — Newsletter rules (cards) ======

        $rules     = is_array( $s['tag_rules'] ?? null ) ? $s['tag_rules'] : array();
        $rule_count = count( $rules );

        $plan_label = ( 'pro' === $plan ) ? 'PRO' : ( ( 'core' === $plan ) ? 'CORE' : 'GRATUIT' );

        echo '<h2 style="margin-top:24px;">Reguli newsletter</h2>';
        echo '<p class="description" style="max-width:780px;">Fiecare regulă mapează un <strong>tag</strong> de pe articol la o configurație: <em>liste destinatari</em>, <em>liste excluse</em> (Core+), <em>template Klaviyo</em> (Pro) și <em>Mod Web Feed</em> (Pro). '
            . 'La publicarea unui articol, plugin-ul caută prima regulă a cărei tag este prezent pe articol (ordinea din pagină = prioritate) și trimite folosind acea regulă. '
            . 'Cooldown-ul se aplică separat per regulă (per tag).</p>';
        echo '<p class="description" style="max-width:780px;"><strong>Plan curent:</strong> ' . esc_html( $plan_label ) . ' — maxim <strong>' . esc_html( $max_rules ) . '</strong> regul' . ( $max_rules === 1 ? 'ă' : 'i' ) . '.';
        if ( 'pro' !== $plan ) {
            echo ' ' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'free' === $plan ? 'core' : 'pro' ) );
        }
        echo '</p>';

        if ( ! $can_query_api ) {
            echo '<div class="notice notice-warning inline" style="margin:8px 0;"><p>Salvează mai întâi <strong>Cheie API Klaviyo</strong> mai sus, pentru ca listele și template-urile să poată fi încărcate în card-urile regulilor.</p></div>';
        } elseif ( $api_error ) {
            echo '<div class="notice notice-error inline" style="margin:8px 0;"><p>Nu s-au putut încărca listele din Klaviyo: ' . wp_kses_post( hge_klaviyo_friendly_api_error( $api_error ) ) . '</p></div>';
        }

        echo '<div id="hge-klaviyo-rules" data-max="' . esc_attr( $max_rules ) . '">';
        if ( empty( $rules ) ) {
            // Always show at least one editable card
            $rules = array( hge_klaviyo_nl_default_rule() );
        }
        foreach ( $rules as $idx => $rule ) {
            hge_klaviyo_render_rule_card( (int) $idx, $rule, $lists_data, $templates_data, $caps, $supports_multi, $plan );
        }
        echo '</div>';

        $can_add = $rule_count < $max_rules;
        echo '<p style="margin:8px 0 0 0;">';
        echo '<button type="button" id="hge-klaviyo-add-rule" class="button"' . ( $can_add ? '' : ' disabled' ) . '>Adaugă regulă</button>';
        if ( ! $can_add ) {
            echo ' <span class="description">Ai atins limita planului <strong>' . esc_html( $plan_label ) . '</strong> (' . esc_html( $max_rules ) . ' regul' . ( $max_rules === 1 ? 'ă' : 'i' ) . ').';
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
        hge_klaviyo_render_rule_card( 0, $blank_rule, $lists_data, $templates_data, $caps, $supports_multi, $plan, true );
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
                        if ( ! confirm('Aceasta este singura regulă. Ștergerea va opri toate trimiterile automate. Continui?') ) {
                            return;
                        }
                    } else if ( ! confirm('Ștergi această regulă? Modificarea devine efectivă după Salvează.') ) {
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
            });

            // Initial state — ensure add button reflects current count
            updateAddButton();
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

        submit_button( 'Salvează setările' );
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
 *   $templates_data — Klaviyo templates from API
 *   $caps           — per-rule caps (max_included, max_excluded, allow_template, allow_web_feed)
 *   $supports_multi — true on Pro plan (comma-separated tag_slug)
 *   $plan           — 'free' | 'core' | 'pro'
 *   $is_template    — when true, render as blank-template stub (no selected values)
 *
 * @since 3.0.0
 */
if ( ! function_exists( 'hge_klaviyo_render_rule_card' ) ) {
    function hge_klaviyo_render_rule_card( $idx, $rule, $lists_data, $templates_data, $caps, $supports_multi, $plan, $is_template = false ) {
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
        echo '<h3 style="margin:0;font-size:14px;">Regulă <span class="hge-rule-num">#' . esc_html( $idx + 1 ) . '</span></h3>';
        echo '<button type="button" class="button-link hge-rule-remove" style="color:#b32d2e;text-decoration:none;">✕ Șterge regula</button>';
        echo '</div>';

        echo '<table class="form-table" role="presentation" style="margin-top:0;">';

        // tag_slug
        $slug_id    = $id_prefix . 'slug';
        $slug_label = $supports_multi ? 'Tag(uri) declanșator(i)' : 'Tag declanșator';
        echo '<tr><th scope="row" style="width:200px;"><label for="' . esc_attr( $slug_id ) . '">' . esc_html( $slug_label ) . '</label></th><td>';
        echo '<input type="text" id="' . esc_attr( $slug_id ) . '" name="' . esc_attr( $name_prefix ) . '[tag_slug]" value="' . esc_attr( $rule['tag_slug'] ) . '" class="regular-text" placeholder="newsletter" />';
        if ( $supports_multi ) {
            echo '<p class="description">Slug-ul tag-ului WordPress care declanșează această regulă. <strong>Pro:</strong> mai multe tag-uri separate prin virgulă, ex: <code>news,promo,events</code> (orice tag prezent declanșează regula — semantică OR).</p>';
        } else {
            echo '<p class="description">Slug-ul tag-ului WordPress care declanșează această regulă. Ex: <code>newsletter</code>.';
            if ( 'free' === $plan ) {
                echo ' ' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'pro' ) ) . ' pentru multi-tag (comma-separated).';
            }
            echo '</p>';
        }
        echo '</td></tr>';

        // included_list_ids
        $inc_id   = $id_prefix . 'included';
        $inc_mult = $caps['max_included'] > 1;
        echo '<tr><th scope="row"><label for="' . esc_attr( $inc_id ) . '">Listă(e) destinatari</label></th><td>';
        if ( $included_disabled ) {
            echo '<em>Salvează API Key pentru a încărca listele.</em>';
        } else {
            echo '<select id="' . esc_attr( $inc_id ) . '" name="' . esc_attr( $name_prefix ) . '[included_list_ids][]"' . ( $inc_mult ? ' multiple size="5"' : '' ) . ' style="min-width:340px;">';
            if ( ! $inc_mult ) {
                echo '<option value="">— alege listă —</option>';
            }
            foreach ( $lists_data as $list ) {
                $sel   = in_array( $list['id'], (array) $rule['included_list_ids'], true ) ? ' selected' : '';
                $count = isset( $list['profile_count'] ) ? $list['profile_count'] : null;
                echo '<option value="' . esc_attr( $list['id'] ) . '"' . $sel . '>'
                    . esc_html( $list['name'] )
                    . esc_html( hge_klaviyo_format_list_count( $count ) )
                    . ' <small>(' . esc_html( $list['id'] ) . ')</small></option>';
            }
            echo '</select>';
        }
        echo '<p class="description">Maxim <strong>' . esc_html( $caps['max_included'] ) . '</strong> ' . ( $caps['max_included'] === 1 ? 'listă' : 'liste' ) . ' pe regulă.';
        if ( 'pro' !== $plan ) {
            echo ' ' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'pro' ) ) . ' pentru până la 15 liste/regulă.';
        }
        echo '</p>';
        echo '</td></tr>';

        // excluded_list_ids
        $exc_id = $id_prefix . 'excluded';
        echo '<tr><th scope="row"><label for="' . esc_attr( $exc_id ) . '">Listă(e) excluse</label></th><td>';
        if ( ! $excluded_allowed ) {
            echo '<em>—</em> <span class="description">' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'core' ) ) . ' pentru a putea exclude liste din audiență.</span>';
        } elseif ( $included_disabled ) {
            echo '<em>Salvează API Key pentru a încărca listele.</em>';
        } else {
            echo '<select id="' . esc_attr( $exc_id ) . '" name="' . esc_attr( $name_prefix ) . '[excluded_list_ids][]" multiple size="4" style="min-width:340px;">';
            foreach ( $lists_data as $list ) {
                $sel   = in_array( $list['id'], (array) $rule['excluded_list_ids'], true ) ? ' selected' : '';
                $count = isset( $list['profile_count'] ) ? $list['profile_count'] : null;
                echo '<option value="' . esc_attr( $list['id'] ) . '"' . $sel . '>'
                    . esc_html( $list['name'] )
                    . esc_html( hge_klaviyo_format_list_count( $count ) )
                    . ' <small>(' . esc_html( $list['id'] ) . ')</small></option>';
            }
            echo '</select>';
            echo '<p class="description">Maxim <strong>' . esc_html( $caps['max_excluded'] ) . '</strong> exclus' . ( $caps['max_excluded'] === 1 ? 'ă' : 'e' ) . '. Limită Klaviyo: incluse + excluse ≤ 15.</p>';
        }
        echo '</td></tr>';

        // template_id (Pro only — Core / Free hidden + locked to '')
        $tpl_id = $id_prefix . 'template';
        echo '<tr><th scope="row"><label for="' . esc_attr( $tpl_id ) . '">Template Klaviyo</label></th><td>';
        if ( ! $template_allowed ) {
            echo '<em>Template HTML încorporat</em> <span class="description">' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'pro' ) ) . ' pentru a alege un template din contul Klaviyo.</span>';
            // Keep an existing saved value (backward-compat for tier downgrades)
            if ( ! empty( $rule['template_id'] ) ) {
                echo '<input type="hidden" name="' . esc_attr( $name_prefix ) . '[template_id]" value="' . esc_attr( $rule['template_id'] ) . '">';
            }
        } else {
            echo '<select id="' . esc_attr( $tpl_id ) . '" name="' . esc_attr( $name_prefix ) . '[template_id]" style="min-width:340px;">';
            echo '<option value=""' . ( '' === $rule['template_id'] ? ' selected' : '' ) . '>— folosește template-ul HTML încorporat —</option>';
            foreach ( $templates_data as $tpl ) {
                $sel = ( $rule['template_id'] === $tpl['id'] ) ? ' selected' : '';
                $editor = isset( $tpl['editor_type'] ) ? $tpl['editor_type'] : '';
                echo '<option value="' . esc_attr( $tpl['id'] ) . '"' . $sel . '>' . esc_html( $tpl['name'] ) . ( $editor ? ' <small>(' . esc_html( $editor ) . ')</small>' : '' ) . '</option>';
            }
            echo '</select>';
            echo '<p class="description">Pentru modul Web Feed, template-ul trebuie să folosească <code>{{ web_feeds.NAME.items.0.* }}</code>.</p>';
        }
        echo '</td></tr>';

        // use_web_feed + web_feed_name (Pro only)
        echo '<tr><th scope="row">Mod Web Feed</th><td>';
        if ( ! $web_feed_allowed ) {
            echo '<em>Indisponibil</em> <span class="description">' . wp_kses_post( hge_klaviyo_upgrade_cta_html( 'pro' ) ) . ' pentru Mod Web Feed (1 template + date dinamice).</span>';
            if ( ! empty( $rule['web_feed_name'] ) ) {
                echo '<input type="hidden" name="' . esc_attr( $name_prefix ) . '[web_feed_name]" value="' . esc_attr( $rule['web_feed_name'] ) . '">';
            }
        } else {
            $wf_id = $id_prefix . 'use_web_feed';
            $wn_id = $id_prefix . 'web_feed_name';
            echo '<label><input type="checkbox" id="' . esc_attr( $wf_id ) . '" name="' . esc_attr( $name_prefix ) . '[use_web_feed]" value="1"' . checked( ! empty( $rule['use_web_feed'] ), true, false ) . ' /> Folosește Web Feed (1 template master + date dinamice)</label>';
            echo '<br><label for="' . esc_attr( $wn_id ) . '" style="display:inline-block;margin-top:8px;">Numele Web Feed-ului în Klaviyo:</label> ';
            echo '<input type="text" id="' . esc_attr( $wn_id ) . '" name="' . esc_attr( $name_prefix ) . '[web_feed_name]" value="' . esc_attr( $rule['web_feed_name'] ) . '" class="regular-text" style="max-width:200px;" placeholder="newsletter_feed" />';
            echo '<p class="description">Numele exact configurat în Klaviyo → Settings → Web Feeds.</p>';

            // Per-rule feed URL preview (since 3.0.0). Keyed on web_feed_name so
            // each rule gets a distinct URL that Klaviyo can pull from.
            $feed_token = function_exists( 'hge_klaviyo_nl_resolve_feed_token' ) ? hge_klaviyo_nl_resolve_feed_token() : '';
            $feed_name_sanitized = sanitize_key( (string) $rule['web_feed_name'] );
            if ( '' !== $feed_token && '' !== $feed_name_sanitized && ! $is_template ) {
                $feed_url = add_query_arg(
                    array( 'key' => $feed_token, 'name' => $feed_name_sanitized ),
                    home_url( '/feed/klaviyo-current.json' )
                );
                echo '<p class="description" style="margin-top:6px;"><strong>URL pentru Klaviyo Web Feed (această regulă):</strong><br>'
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
    $messages['klaviyo_settings_saved'] = array( 'success', 'Setările au fost salvate.' );
    $messages['klaviyo_api_refreshed']  = array( 'success', 'Cache-ul API Klaviyo a fost golit. Următorul render va fetch-ui date proaspete.' );
    return $messages;
} );

// Marker pentru theme legacy: când e definit, blocul admin din functions.php se dezactivează.
if ( ! defined( 'HGE_KLAVIYO_NL_ADMIN_LOADED' ) ) {
    define( 'HGE_KLAVIYO_NL_ADMIN_LOADED', true );
}
