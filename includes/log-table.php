<?php
/**
 * Dispatch log table (Core+ / Tier 2 — FcRapid1923-8ou).
 *
 * A queryable custom table `{prefix}hge_klaviyo_nl_log` that records the
 * lifecycle of each newsletter dispatch (scheduled → pending → sent / failed),
 * so the Tools → Logs tab can show a sortable/filterable history beyond what the
 * WooCommerce log files offer. Complements (does not replace) HgE_Klaviyo_Logger,
 * which still writes the verbose, secret-scrubbed entries to WC → Status → Logs.
 *
 * The table is created on plugin activation regardless of tier (cheap, and the
 * schema must exist before a later upgrade), but rows are only written/displayed
 * for Core+ — Free never accrues log rows.
 *
 * @package HgE\KlaviyoNewsletter
 * @since 3.0.15 (FcRapid1923-8ou)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'HGE_KLAVIYO_NL_LOG_DB_VERSION' ) ) {
    define( 'HGE_KLAVIYO_NL_LOG_DB_VERSION', '1' );
    define( 'HGE_KLAVIYO_NL_LOG_DB_OPTION',  'hge_klaviyo_nl_log_db_version' );
}

if ( ! function_exists( 'hge_klaviyo_nl_log_table_name' ) ) {
    /**
     * Fully-qualified, prefixed table name.
     */
    function hge_klaviyo_nl_log_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'hge_klaviyo_nl_log';
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_log_install_table' ) ) {
    /**
     * Create / migrate the log table via dbDelta(). Idempotent — safe to call on
     * every activation and cheap enough to call on an admin_init version check.
     */
    function hge_klaviyo_nl_log_install_table() {
        global $wpdb;
        $table           = hge_klaviyo_nl_log_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // status: scheduled | pending | sent | failed
        $sql = "CREATE TABLE `{$table}` (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            campaign_id VARCHAR(64) NOT NULL DEFAULT '',
            rule_tag_slug VARCHAR(191) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempt SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
            error TEXT NULL,
            scheduled_for DATETIME NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( HGE_KLAVIYO_NL_LOG_DB_OPTION, HGE_KLAVIYO_NL_LOG_DB_VERSION, false );
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_log_maybe_upgrade' ) ) {
    /**
     * Lightweight schema-version check on admin load so the table appears even
     * when the plugin was updated by file copy (no re-activation).
     */
    function hge_klaviyo_nl_log_maybe_upgrade() {
        if ( (string) get_option( HGE_KLAVIYO_NL_LOG_DB_OPTION, '' ) !== HGE_KLAVIYO_NL_LOG_DB_VERSION ) {
            hge_klaviyo_nl_log_install_table();
        }
    }
}
add_action( 'admin_init', 'hge_klaviyo_nl_log_maybe_upgrade' );

if ( ! function_exists( 'hge_klaviyo_nl_log_event' ) ) {
    /**
     * Record / update a dispatch lifecycle event. Core+ only — on Free this is a
     * no-op so the table never accrues rows.
     *
     * Upsert semantics: when $post_id already has an open (non-sent) row for the
     * same rule slug, that row is updated in place (status/attempt/error/campaign
     * advance through the lifecycle) rather than inserting a duplicate per stage.
     *
     * @param int    $post_id
     * @param string $status  scheduled | pending | sent | failed
     * @param array  $args    { rule_tag_slug, campaign_id, attempt, error, scheduled_for(int ts) }
     * @return void
     */
    function hge_klaviyo_nl_log_event( $post_id, $status, array $args = array() ) {
        if ( ! function_exists( 'hge_klaviyo_active_plan' )
            || ! in_array( hge_klaviyo_active_plan(), array( 'core', 'pro' ), true ) ) {
            return;
        }
        global $wpdb;
        $table   = hge_klaviyo_nl_log_table_name();
        $post_id = (int) $post_id;
        $status  = in_array( $status, array( 'scheduled', 'pending', 'sent', 'failed' ), true ) ? $status : 'pending';
        $slug    = isset( $args['rule_tag_slug'] ) ? substr( (string) $args['rule_tag_slug'], 0, 191 ) : '';
        $now     = current_time( 'mysql', true );

        $data = array(
            'post_id'       => $post_id,
            'campaign_id'   => isset( $args['campaign_id'] ) ? substr( (string) $args['campaign_id'], 0, 64 ) : '',
            'rule_tag_slug' => $slug,
            'status'        => $status,
            'attempt'       => isset( $args['attempt'] ) ? max( 0, (int) $args['attempt'] ) : 0,
            'error'         => isset( $args['error'] ) ? (string) $args['error'] : null,
            'scheduled_for' => ! empty( $args['scheduled_for'] ) ? gmdate( 'Y-m-d H:i:s', (int) $args['scheduled_for'] ) : null,
            'sent_at'       => ( 'sent' === $status ) ? $now : null,
            'updated_at'    => $now,
        );

        // Find an open row for this post + slug to update in place.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom plugin table, no WP API; this is the canonical store.
        $open_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE post_id = %d AND rule_tag_slug = %s AND status != 'sent' ORDER BY id DESC LIMIT 1",
            $post_id,
            $slug
        ) );

        if ( $open_id > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
            $wpdb->update( $table, $data, array( 'id' => $open_id ) );
        } else {
            $data['created_at'] = $now;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
            $wpdb->insert( $table, $data );
        }
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_log_query' ) ) {
    /**
     * Fetch recent log rows for the admin Logs tab.
     *
     * @param array $args { status(string|''), per_page(int), paged(int) }
     * @return array{ rows: array<int,object>, total: int }
     */
    function hge_klaviyo_nl_log_query( array $args = array() ) {
        global $wpdb;
        $table    = hge_klaviyo_nl_log_table_name();
        $per_page = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 25;
        $paged    = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
        $offset   = ( $paged - 1 ) * $per_page;
        $status   = isset( $args['status'] ) ? (string) $args['status'] : '';

        $where  = '1=1';
        $params = array();
        if ( in_array( $status, array( 'scheduled', 'pending', 'sent', 'failed' ), true ) ) {
            $where   .= ' AND status = %s';
            $params[] = $status;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; $where built from a fixed whitelist, values bound via prepare().
        $total = (int) $wpdb->get_var(
            empty( $params )
                ? "SELECT COUNT(*) FROM `{$table}` WHERE {$where}"
                : $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", $params )
        );

        $sql_params   = $params;
        $sql_params[] = $per_page;
        $sql_params[] = $offset;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above; LIMIT/OFFSET bound via prepare().
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            $sql_params
        ) );

        return array( 'rows' => is_array( $rows ) ? $rows : array(), 'total' => $total );
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_render_logs_tab' ) ) {
    /**
     * Render the Tools → Logs tab (Core+). A native sortable/filterable
     * widefat table with status filter buttons + simple pagination. (We use a
     * plain WP table rather than bundling a DataTables JS asset — same UX for
     * sort/filter/paginate without shipping a third-party library, which keeps
     * the WordPress.org Plugin Check happy.)
     */
    function hge_klaviyo_nl_render_logs_tab() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter/pagination on an admin page (manage_options enforced); values sanitised below.
        $status = isset( $_GET['log_status'] ) ? sanitize_key( wp_unslash( $_GET['log_status'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        $paged    = isset( $_GET['log_paged'] ) ? max( 1, (int) $_GET['log_paged'] ) : 1;
        $per_page = 25;

        $result = hge_klaviyo_nl_log_query( array( 'status' => $status, 'per_page' => $per_page, 'paged' => $paged ) );
        $rows   = $result['rows'];
        $total  = (int) $result['total'];
        $pages  = max( 1, (int) ceil( $total / $per_page ) );
        $base   = admin_url( 'tools.php?page=hge-klaviyo-newsletter&tab=logs' );

        echo '<p style="margin:0 0 10px;">';
        $filters = array(
            ''          => __( 'All', 'hge-automated-post-campaigns-for-klaviyo' ),
            'sent'      => __( 'Sent', 'hge-automated-post-campaigns-for-klaviyo' ),
            'failed'    => __( 'Failed', 'hge-automated-post-campaigns-for-klaviyo' ),
            'scheduled' => __( 'Scheduled', 'hge-automated-post-campaigns-for-klaviyo' ),
            'pending'   => __( 'Pending', 'hge-automated-post-campaigns-for-klaviyo' ),
        );
        foreach ( $filters as $key => $label ) {
            $url = '' === $key ? $base : add_query_arg( 'log_status', $key, $base );
            $is  = ( $status === $key );
            echo '<a href="' . esc_url( $url ) . '" class="button' . ( $is ? ' button-primary' : '' ) . '" style="margin-right:4px;">' . esc_html( $label ) . '</a>';
        }
        echo '</p>';

        echo '<table class="widefat striped"><thead><tr>';
        foreach ( array(
            __( 'Post', 'hge-automated-post-campaigns-for-klaviyo' ),
            __( 'Status', 'hge-automated-post-campaigns-for-klaviyo' ),
            __( 'Rule tag', 'hge-automated-post-campaigns-for-klaviyo' ),
            __( 'Campaign', 'hge-automated-post-campaigns-for-klaviyo' ),
            __( 'Attempt', 'hge-automated-post-campaigns-for-klaviyo' ),
            __( 'Scheduled for', 'hge-automated-post-campaigns-for-klaviyo' ),
            __( 'Updated (UTC)', 'hge-automated-post-campaigns-for-klaviyo' ),
            __( 'Error', 'hge-automated-post-campaigns-for-klaviyo' ),
        ) as $h ) {
            echo '<th>' . esc_html( $h ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ( empty( $rows ) ) {
            echo '<tr><td colspan="8">' . esc_html__( 'No log entries yet.', 'hge-automated-post-campaigns-for-klaviyo' ) . '</td></tr>';
        } else {
            $colors = array( 'sent' => '#1e8e3e', 'failed' => '#c00', 'scheduled' => '#c45500', 'pending' => '#666' );
            foreach ( $rows as $r ) {
                $pid   = (int) $r->post_id;
                $title = $pid ? get_the_title( $pid ) : '';
                $edit  = $pid ? get_edit_post_link( $pid, 'url' ) : '';
                $color = isset( $colors[ $r->status ] ) ? $colors[ $r->status ] : '#666';
                echo '<tr>';
                echo '<td>' . ( $edit
                    ? '<a href="' . esc_url( $edit ) . '">' . esc_html( '' !== $title ? $title : ( '#' . $pid ) ) . '</a>'
                    : esc_html( '#' . $pid ) ) . '</td>';
                echo '<td><strong style="color:' . esc_attr( $color ) . ';">' . esc_html( (string) $r->status ) . '</strong></td>';
                echo '<td><code>' . esc_html( (string) $r->rule_tag_slug ) . '</code></td>';
                echo '<td>' . ( '' !== (string) $r->campaign_id ? '<code style="font-size:11px;">' . esc_html( (string) $r->campaign_id ) . '</code>' : '—' ) . '</td>';
                echo '<td>' . esc_html( (string) (int) $r->attempt ) . '</td>';
                echo '<td>' . esc_html( $r->scheduled_for ? (string) $r->scheduled_for : '—' ) . '</td>';
                echo '<td>' . esc_html( (string) $r->updated_at ) . '</td>';
                echo '<td style="font-size:11px;color:#a00;max-width:280px;word-break:break-word;">' . esc_html( (string) ( $r->error ? $r->error : '' ) ) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        if ( $pages > 1 ) {
            echo '<p style="margin-top:10px;">';
            for ( $i = 1; $i <= $pages; $i++ ) {
                if ( $i === $paged ) {
                    echo '<strong style="margin-right:6px;">' . esc_html( (string) $i ) . '</strong>';
                } else {
                    $url = add_query_arg( array( 'log_status' => $status, 'log_paged' => $i ), $base );
                    echo '<a href="' . esc_url( $url ) . '" style="margin-right:6px;">' . esc_html( (string) $i ) . '</a>';
                }
            }
            echo '</p>';
        }
        echo '<p class="description">' . esc_html__( 'Dispatch history recorded by this plugin (Core+). Verbose technical logs live in WooCommerce → Status → Logs (source hge-klaviyo).', 'hge-automated-post-campaigns-for-klaviyo' ) . '</p>';
    }
}
