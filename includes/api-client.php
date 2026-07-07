<?php
/**
 * Klaviyo API client extensions used by the Settings UI.
 *
 * The low-level `hge_klaviyo_api_request()` already lives in dispatcher.php (Stage 1).
 * This file adds list/template helpers with transient caching so the Settings page
 * doesn't hammer the Klaviyo API on every render.
 *
 * @package HgE\KlaviyoNewsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'HGE_KLAVIYO_NL_API_CACHE_TTL' ) ) {
    // Default TTL for lists + segments (subscriber counts change more often
    // than templates). Templates use the longer TTL below.
    // Manual refresh is always available via the `Reload from Klaviyo`
    // button (admin-post handler invalidates all three caches).
    define( 'HGE_KLAVIYO_NL_API_CACHE_TTL', 30 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'HGE_KLAVIYO_NL_API_TEMPLATES_CACHE_TTL' ) ) {
    // Templates rarely change once configured — keep them cached for an hour
    // so the Settings tab renders fast even after the lists/segments cache
    // expires. Up to ~3 seconds saved on each cold render (templates pagination
    // is the heaviest fetch — 56 templates / 10-per-page = 6 round-trips).
    define( 'HGE_KLAVIYO_NL_API_TEMPLATES_CACHE_TTL', HOUR_IN_SECONDS );
}

if ( ! function_exists( 'hge_klaviyo_api_list_lists' ) ) {
    /**
     * Fetch all lists from Klaviyo. The Lists API caps `page[size]` at 10 (per Klaviyo
     * docs / API revision 2024-10-15), so we paginate up to 50 pages × 10 = 500 lists.
     *
     * Subscriber counts are OPT-IN via the `hge_klaviyo_lists_extra_query` filter.
     * Klaviyo API revision 2024-10-15 returns HTTP 400 when `additional-fields[list]
     * =profile_count` is supplied, so we don't request it by default. Sites on a
     * Klaviyo account / API revision that supports it can enable counts with:
     *
     *     add_filter( 'hge_klaviyo_lists_extra_query', function ( $extra ) {
     *         $extra['additional-fields[list]'] = 'profile_count';
     *         return $extra;
     *     } );
     *
     * When enabled, `attributes.profile_count` lands in each item's `profile_count`
     * key as `int|null` and is rendered next to the list name in the Settings UI.
     *
     * @param bool $force_refresh Bypass the transient cache.
     * @return array<int, array{id:string, name:string, profile_count: int|null}>|WP_Error
     */
    function hge_klaviyo_api_list_lists( $force_refresh = false ) {
        $cache_key = 'hge_klaviyo_nl_api_lists';

        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $items = array();
        $extra = (array) apply_filters( 'hge_klaviyo_lists_extra_query', array() );
        $query = array_merge( array( 'page[size]' => '10' ), $extra );
        $next  = '/api/lists/?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        $guard = 50;

        while ( $next && $guard-- > 0 ) {
            $resp = hge_klaviyo_api_request( 'GET', $next );
            if ( is_wp_error( $resp ) ) {
                return $resp;
            }
            foreach ( (array) ( $resp['data'] ?? array() ) as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $count = null;
                if ( isset( $row['attributes']['profile_count'] ) && is_numeric( $row['attributes']['profile_count'] ) ) {
                    $count = (int) $row['attributes']['profile_count'];
                }
                $items[] = array(
                    'id'            => isset( $row['id'] ) ? (string) $row['id'] : '',
                    'name'          => isset( $row['attributes']['name'] ) ? (string) $row['attributes']['name'] : '(unnamed)',
                    'profile_count' => $count,
                );
            }
            $next_url = isset( $resp['links']['next'] ) ? (string) $resp['links']['next'] : '';
            if ( '' === $next_url ) {
                break;
            }
            $parsed = wp_parse_url( $next_url );
            $next   = ( isset( $parsed['path'] ) ? $parsed['path'] : '' )
                . ( isset( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
        }

        // Sort alphabetically for predictable UI
        usort( $items, static function ( $a, $b ) {
            return strcasecmp( $a['name'], $b['name'] );
        } );

        set_transient( $cache_key, $items, HGE_KLAVIYO_NL_API_CACHE_TTL );
        return $items;
    }
}

if ( ! function_exists( 'hge_klaviyo_api_list_segments' ) ) {
    /**
     * Fetch all segments from Klaviyo. The Segments API uses the same JSON:API
     * shape as Lists/Templates (revision 2024-10-15), with the same 10-item
     * page[size] cap. We paginate up to 50 pages × 10 = 500 segments.
     *
     * Klaviyo's Campaigns API accepts segment IDs in `audiences.included` /
     * `audiences.excluded` arrays interchangeably with list IDs — no extra
     * `type` annotation is needed on send. The Settings UI surfaces them
     * separately so users know which is which.
     *
     * @since 3.0.3
     * @param bool $force_refresh Bypass the transient cache.
     * @return array<int, array{id:string, name:string, profile_count: int|null}>|WP_Error
     */
    function hge_klaviyo_api_list_segments( $force_refresh = false ) {
        $cache_key = 'hge_klaviyo_nl_api_segments';

        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $items = array();
        // profile_count on segments is opt-in via the same filter as lists,
        // because the same Klaviyo API revision rejects the field by default.
        $extra = (array) apply_filters( 'hge_klaviyo_segments_extra_query', array() );
        $query = array_merge( array( 'page[size]' => '10' ), $extra );
        $next  = '/api/segments/?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        $guard = 50;

        while ( $next && $guard-- > 0 ) {
            $resp = hge_klaviyo_api_request( 'GET', $next );
            if ( is_wp_error( $resp ) ) {
                return $resp;
            }
            foreach ( (array) ( $resp['data'] ?? array() ) as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $count = null;
                if ( isset( $row['attributes']['profile_count'] ) && is_numeric( $row['attributes']['profile_count'] ) ) {
                    $count = (int) $row['attributes']['profile_count'];
                }
                $items[] = array(
                    'id'            => isset( $row['id'] ) ? (string) $row['id'] : '',
                    'name'          => isset( $row['attributes']['name'] ) ? (string) $row['attributes']['name'] : '(unnamed)',
                    'profile_count' => $count,
                );
            }
            $next_url = isset( $resp['links']['next'] ) ? (string) $resp['links']['next'] : '';
            if ( '' === $next_url ) {
                break;
            }
            $parsed = wp_parse_url( $next_url );
            $next   = ( isset( $parsed['path'] ) ? $parsed['path'] : '' )
                . ( isset( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
        }

        usort( $items, static function ( $a, $b ) {
            return strcasecmp( $a['name'], $b['name'] );
        } );

        set_transient( $cache_key, $items, HGE_KLAVIYO_NL_API_CACHE_TTL );
        return $items;
    }
}

if ( ! function_exists( 'hge_klaviyo_api_list_templates' ) ) {
    /**
     * Fetch email templates from Klaviyo. The Templates API caps `page[size]` at 10
     * (same hard limit as Lists API, per Klaviyo API revision 2024-10-15 — values
     * larger than 10 return HTTP 400 "Page size must be an integer between 1 and 10").
     * We paginate up to 50 pages × 10 = 500 templates.
     *
     * @param bool $force_refresh Bypass the transient cache.
     * @return array<int, array{id:string, name:string, editor_type:string}>|WP_Error
     */
    function hge_klaviyo_api_list_templates( $force_refresh = false ) {
        $cache_key = 'hge_klaviyo_nl_api_templates';

        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $items = array();
        $next  = '/api/templates/?page%5Bsize%5D=10';
        $guard = 50;

        while ( $next && $guard-- > 0 ) {
            $resp = hge_klaviyo_api_request( 'GET', $next );
            if ( is_wp_error( $resp ) ) {
                return $resp;
            }
            foreach ( (array) ( $resp['data'] ?? array() ) as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $items[] = array(
                    'id'          => isset( $row['id'] ) ? (string) $row['id'] : '',
                    'name'        => isset( $row['attributes']['name'] ) ? (string) $row['attributes']['name'] : '(unnamed)',
                    'editor_type' => isset( $row['attributes']['editor_type'] ) ? (string) $row['attributes']['editor_type'] : '',
                );
            }
            $next_url = isset( $resp['links']['next'] ) ? (string) $resp['links']['next'] : '';
            if ( '' === $next_url ) {
                break;
            }
            $parsed = wp_parse_url( $next_url );
            $next   = ( isset( $parsed['path'] ) ? $parsed['path'] : '' )
                . ( isset( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
        }

        usort( $items, static function ( $a, $b ) {
            return strcasecmp( $a['name'], $b['name'] );
        } );

        set_transient( $cache_key, $items, HGE_KLAVIYO_NL_API_TEMPLATES_CACHE_TTL );
        return $items;
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_clear_api_cache' ) ) {
    /**
     * Drop all API result caches. Used by Settings page "Refresh from Klaviyo" button.
     */
    function hge_klaviyo_nl_clear_api_cache() {
        delete_transient( 'hge_klaviyo_nl_api_lists' );
        delete_transient( 'hge_klaviyo_nl_api_templates' );
        delete_transient( 'hge_klaviyo_nl_api_segments' );
    }
}

// One-shot cache invalidation when the plugin code is updated to a NEW MAJOR
// version. Patch / minor bumps don't change the API client behaviour, so
// keeping the cache across them avoids the cold-fetch storm (3-15 seconds)
// users hit on every Settings page after an upgrade.
//
// History: pre-3.0.5 this fired on every version change (any segment of the
// version string). Day-of-upgrade UX was unusable when multiple patches
// shipped in one day. Now scoped to the major segment only.
add_action( 'admin_init', static function () {
    $marker  = 'hge_klaviyo_nl_api_cache_codever';
    $stored  = (string) get_option( $marker, '' );
    $current = defined( 'HGE_KLAVIYO_NL_VERSION' ) ? HGE_KLAVIYO_NL_VERSION : '';
    if ( '' === $current ) {
        return;
    }
    $stored_major  = (int) strtok( $stored, '.' );
    $current_major = (int) strtok( $current, '.' );
    if ( $current_major !== $stored_major ) {
        if ( function_exists( 'hge_klaviyo_nl_clear_api_cache' ) ) {
            hge_klaviyo_nl_clear_api_cache();
        }
    }
    if ( $current !== $stored ) {
        update_option( $marker, $current, false );
    }
} );

// Invalidate API cache only when the API key actually changes.
// (Pre-3.0.5 every Settings save cleared all caches, causing the slow cold
// fetch that drove the 10-15s Settings load time.)
add_action(
    'update_option_' . HGE_KLAVIYO_NL_OPT_SETTINGS,
    static function ( $old_value, $new_value ) {
        $old_key = is_array( $old_value ) ? (string) ( $old_value['api_key'] ?? '' ) : '';
        $new_key = is_array( $new_value ) ? (string) ( $new_value['api_key'] ?? '' ) : '';
        if ( $old_key !== $new_key && function_exists( 'hge_klaviyo_nl_clear_api_cache' ) ) {
            hge_klaviyo_nl_clear_api_cache();
        }
    },
    10,
    2
);

// =============================================================================
// Background cache warmup v2 (since 3.0.16 — on-demand, chained short steps)
//
// History: 3.0.6 introduced a RECURRING Action Scheduler job every 25 minutes
// that refreshed lists + segments + templates in ONE action: up to 3 × 50
// pages × 25s HTTP timeout in a single queue worker. Under load (or with
// Klaviyo slow/unreachable) that action could hold a PHP worker for many
// minutes, show up as a stuck/past-due action, and degrade the whole store —
// observed live during a campaign-launch load test (Beads FcRapid1923-qee).
//
// v2 invariants — a background job must never hurt the host store:
//   * NOTHING runs 24/7. Warmup is enqueued only when an admin actually
//     visits the plugin's Tools page, and self-renews (single actions, +22
//     min) only while the "admin active" marker (24h) is fresh.
//   * Each queue action fetches AT MOST 5 pages with an 8s HTTP timeout
//     (worst case ~40s, typical <5s), then re-enqueues its own continuation
//     with the pagination cursor — long syncs become many short actions.
//   * Endpoints run as separate chained actions (lists → segments →
//     templates), never stacked in one worker.
//   * Overlap lock (10 min transient) — two chains can't run concurrently.
//   * Failure backoff — after 3 consecutive failed steps the warmup pauses
//     for 6h. Any WP_Error aborts the current chain immediately.
//   * Kill switch — the "disable background jobs" setting or the
//     HGE_KLAVIYO_NL_DISABLE_BACKGROUND constant turns all of this off.
// =============================================================================

if ( ! defined( 'HGE_KLAVIYO_NL_WARMUP_HOOK' ) ) {
    define( 'HGE_KLAVIYO_NL_WARMUP_HOOK', 'hge_klaviyo_nl_warmup_step' );
}
if ( ! defined( 'HGE_KLAVIYO_NL_WARMUP_MAX_PAGES' ) ) {
    define( 'HGE_KLAVIYO_NL_WARMUP_MAX_PAGES', 5 );  // pages per queue action
}
if ( ! defined( 'HGE_KLAVIYO_NL_WARMUP_TIMEOUT' ) ) {
    define( 'HGE_KLAVIYO_NL_WARMUP_TIMEOUT', 8 );    // seconds per HTTP call (send path keeps 25)
}

add_action( 'admin_init', 'hge_klaviyo_nl_maybe_enqueue_warmup' );
add_action( HGE_KLAVIYO_NL_WARMUP_HOOK, 'hge_klaviyo_nl_warmup_step', 10, 1 );
// Legacy 3.0.6 recurring hook: mapped to a no-op so an already-queued
// occurrence fails gracefully instead of erroring, until migration unschedules it.
add_action( 'hge_klaviyo_nl_api_cache_warmup', '__return_null' );

if ( ! function_exists( 'hge_klaviyo_nl_background_disabled' ) ) {
    /**
     * Kill switch for ALL non-dispatch background activity. Two knobs:
     * the wp-config constant (host-level, wins) and the Settings checkbox.
     *
     * @since 3.0.16 (FcRapid1923-lxe)
     */
    function hge_klaviyo_nl_background_disabled() {
        if ( defined( 'HGE_KLAVIYO_NL_DISABLE_BACKGROUND' ) && HGE_KLAVIYO_NL_DISABLE_BACKGROUND ) {
            return true;
        }
        if ( function_exists( 'hge_klaviyo_nl_get_settings' ) ) {
            $s = hge_klaviyo_nl_get_settings();
            return ! empty( $s['disable_background_jobs'] );
        }
        return false;
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_warmup_spec' ) ) {
    /**
     * Endpoint spec for one warmup step. Shapes mirror the synchronous
     * hge_klaviyo_api_list_* functions and write the SAME transients.
     *
     * @param string $what lists|segments|templates
     * @return array{path:string, cache_key:string, ttl:int, kind:string, next:string}|null
     */
    function hge_klaviyo_nl_warmup_spec( $what ) {
        switch ( $what ) {
            case 'lists':
                $extra = (array) apply_filters( 'hge_klaviyo_lists_extra_query', array() );
                $query = array_merge( array( 'page[size]' => '10' ), $extra );
                return array(
                    'path'      => '/api/lists/?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ),
                    'cache_key' => 'hge_klaviyo_nl_api_lists',
                    'ttl'       => HGE_KLAVIYO_NL_API_CACHE_TTL,
                    'kind'      => 'audience',
                    'next'      => 'segments',
                );
            case 'segments':
                $extra = (array) apply_filters( 'hge_klaviyo_segments_extra_query', array() );
                $query = array_merge( array( 'page[size]' => '10' ), $extra );
                return array(
                    'path'      => '/api/segments/?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ),
                    'cache_key' => 'hge_klaviyo_nl_api_segments',
                    'ttl'       => HGE_KLAVIYO_NL_API_CACHE_TTL,
                    'kind'      => 'audience',
                    'next'      => 'templates',
                );
            case 'templates':
                return array(
                    'path'      => '/api/templates/?page%5Bsize%5D=10',
                    'cache_key' => 'hge_klaviyo_nl_api_templates',
                    'ttl'       => HGE_KLAVIYO_NL_API_TEMPLATES_CACHE_TTL,
                    'kind'      => 'template',
                    'next'      => '',
                );
        }
        return null;
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_maybe_enqueue_warmup' ) ) {
    /**
     * On-demand trigger (FcRapid1923-yrm): runs ONLY on the plugin's own
     * Tools page. Marks admin activity (24h) and starts a warmup chain if
     * none is queued/running. No HTTP here — just queue bookkeeping.
     *
     * Also migrates away from the 3.0.6 recurring job (one-time unschedule).
     *
     * @since 3.0.16
     */
    function hge_klaviyo_nl_maybe_enqueue_warmup() {
        // One-time migration on ANY admin pageview (not just ours): drop the
        // legacy 25-min recurring action, otherwise it would keep re-scheduling
        // itself (as a no-op) until someone opened the plugin page. The marker
        // is autoloaded, so after migration this branch costs nothing.
        if ( ! get_option( 'hge_klaviyo_nl_warmup_v2' ) && function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'hge_klaviyo_nl_api_cache_warmup', array(), 'hge-klaviyo' );
            update_option( 'hge_klaviyo_nl_warmup_v2', 1, true );
        }

        if ( ! isset( $_GET['page'] ) || 'hge-klaviyo-newsletter' !== $_GET['page'] || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
            return; // Action Scheduler not loaded (WC missing — unusual)
        }

        if ( hge_klaviyo_nl_background_disabled() ) {
            return;
        }
        if ( ! function_exists( 'hge_klaviyo_nl_resolve_api_key' ) || '' === hge_klaviyo_nl_resolve_api_key() ) {
            return;
        }

        // Admin is around — keep caches warm for the next 24h (self-renewing chain).
        set_transient( 'hge_klaviyo_nl_admin_active', time(), DAY_IN_SECONDS );

        if ( get_transient( 'hge_klaviyo_nl_warmup_pause' ) ) {
            return; // backing off after repeated failures
        }
        if ( get_transient( 'hge_klaviyo_nl_warmup_lock' ) ) {
            return; // a chain is already running
        }
        if ( as_has_scheduled_action( HGE_KLAVIYO_NL_WARMUP_HOOK, null, 'hge-klaviyo' ) ) {
            return; // a chain is already queued
        }

        as_enqueue_async_action(
            HGE_KLAVIYO_NL_WARMUP_HOOK,
            array( array( 'what' => 'lists', 'cursor' => '', 'pages' => 0 ) ),
            'hge-klaviyo'
        );
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_warmup_fail' ) ) {
    /**
     * Failure bookkeeping (FcRapid1923-86d): count consecutive failed steps;
     * at 3, pause the whole warmup for 6h. Always cleans lock + accumulator
     * so the next chain starts fresh.
     */
    function hge_klaviyo_nl_warmup_fail( $what, $message ) {
        $fails = (int) get_option( 'hge_klaviyo_nl_warmup_fails', 0 ) + 1;
        update_option( 'hge_klaviyo_nl_warmup_fails', $fails, false );
        if ( $fails >= 3 ) {
            set_transient( 'hge_klaviyo_nl_warmup_pause', time(), 6 * HOUR_IN_SECONDS );
            update_option( 'hge_klaviyo_nl_warmup_fails', 0, false );
        }
        delete_transient( 'hge_klaviyo_nl_warmup_lock' );
        delete_transient( 'hge_klaviyo_nl_warmup_acc' );
        if ( class_exists( 'HgE_Klaviyo_Logger' ) ) {
            HgE_Klaviyo_Logger::warning( 'Cache warmup step failed', array(
                'what'    => $what,
                'fails'   => $fails,
                'paused'  => $fails >= 3,
                'message' => is_string( $message ) ? substr( $message, 0, 300 ) : '',
            ) );
        }
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_warmup_step' ) ) {
    /**
     * One short queue action (FcRapid1923-82z): fetches at most
     * HGE_KLAVIYO_NL_WARMUP_MAX_PAGES pages of ONE endpoint with a short
     * timeout, then either re-enqueues its continuation (cursor) or chains
     * to the next endpoint. Whole body is wrapped so a Throwable can never
     * leave a stale lock or kill the queue worker.
     *
     * @since 3.0.16
     * @param array $job {what: lists|segments|templates, cursor: string, pages: int}
     */
    function hge_klaviyo_nl_warmup_step( $job = array() ) {
        try {
            if ( hge_klaviyo_nl_background_disabled() || get_transient( 'hge_klaviyo_nl_warmup_pause' ) ) {
                delete_transient( 'hge_klaviyo_nl_warmup_lock' );
                delete_transient( 'hge_klaviyo_nl_warmup_acc' );
                return;
            }
            $what = is_array( $job ) && isset( $job['what'] ) ? (string) $job['what'] : 'lists';
            $spec = hge_klaviyo_nl_warmup_spec( $what );
            if ( null === $spec ) {
                return;
            }
            if ( ! function_exists( 'hge_klaviyo_nl_resolve_api_key' ) || '' === hge_klaviyo_nl_resolve_api_key() ) {
                delete_transient( 'hge_klaviyo_nl_warmup_lock' );
                return;
            }

            // (Re)take the overlap lock — refreshed on every step so it only
            // expires if a worker dies mid-chain (10 min, self-healing).
            set_transient( 'hge_klaviyo_nl_warmup_lock', time(), 10 * MINUTE_IN_SECONDS );

            // Resume accumulated items for THIS endpoint (continuation steps).
            $acc   = get_transient( 'hge_klaviyo_nl_warmup_acc' );
            $items = ( is_array( $acc ) && ( $acc['what'] ?? '' ) === $what && is_array( $acc['items'] ?? null ) )
                ? $acc['items']
                : array();

            $cursor = is_array( $job ) && isset( $job['cursor'] ) ? (string) $job['cursor'] : '';
            $next   = ( '' !== $cursor ) ? $cursor : $spec['path'];
            $pages  = 0;

            while ( $next && $pages < HGE_KLAVIYO_NL_WARMUP_MAX_PAGES ) {
                $pages++;
                $resp = hge_klaviyo_api_request( 'GET', $next, null, array( 'timeout' => HGE_KLAVIYO_NL_WARMUP_TIMEOUT ) );
                if ( is_wp_error( $resp ) ) {
                    hge_klaviyo_nl_warmup_fail( $what, $resp->get_error_message() );
                    return;
                }
                foreach ( (array) ( $resp['data'] ?? array() ) as $row ) {
                    if ( ! is_array( $row ) ) {
                        continue;
                    }
                    if ( 'template' === $spec['kind'] ) {
                        $items[] = array(
                            'id'          => isset( $row['id'] ) ? (string) $row['id'] : '',
                            'name'        => isset( $row['attributes']['name'] ) ? (string) $row['attributes']['name'] : '(unnamed)',
                            'editor_type' => isset( $row['attributes']['editor_type'] ) ? (string) $row['attributes']['editor_type'] : '',
                        );
                    } else {
                        $count = null;
                        if ( isset( $row['attributes']['profile_count'] ) && is_numeric( $row['attributes']['profile_count'] ) ) {
                            $count = (int) $row['attributes']['profile_count'];
                        }
                        $items[] = array(
                            'id'            => isset( $row['id'] ) ? (string) $row['id'] : '',
                            'name'          => isset( $row['attributes']['name'] ) ? (string) $row['attributes']['name'] : '(unnamed)',
                            'profile_count' => $count,
                        );
                    }
                }
                $next_url = isset( $resp['links']['next'] ) ? (string) $resp['links']['next'] : '';
                if ( '' === $next_url ) {
                    $next = '';
                    break;
                }
                $parsed = wp_parse_url( $next_url );
                $next   = ( isset( $parsed['path'] ) ? $parsed['path'] : '' )
                    . ( isset( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
            }

            if ( '' !== (string) $next ) {
                // Page budget spent, endpoint unfinished — persist progress and
                // hand the cursor to a fresh queue action.
                set_transient( 'hge_klaviyo_nl_warmup_acc', array( 'what' => $what, 'items' => $items ), 30 * MINUTE_IN_SECONDS );
                if ( function_exists( 'as_enqueue_async_action' ) ) {
                    as_enqueue_async_action(
                        HGE_KLAVIYO_NL_WARMUP_HOOK,
                        array( array( 'what' => $what, 'cursor' => (string) $next, 'pages' => 0 ) ),
                        'hge-klaviyo'
                    );
                }
                return;
            }

            // Endpoint complete — publish the cache (same shape/keys as the
            // synchronous fetchers) and move to the next endpoint or finish.
            usort( $items, static function ( $a, $b ) {
                return strcasecmp( $a['name'], $b['name'] );
            } );
            set_transient( $spec['cache_key'], $items, $spec['ttl'] );
            delete_transient( 'hge_klaviyo_nl_warmup_acc' );

            if ( '' !== $spec['next'] ) {
                if ( function_exists( 'as_enqueue_async_action' ) ) {
                    as_enqueue_async_action(
                        HGE_KLAVIYO_NL_WARMUP_HOOK,
                        array( array( 'what' => $spec['next'], 'cursor' => '', 'pages' => 0 ) ),
                        'hge-klaviyo'
                    );
                }
                return;
            }

            // Full chain done — reset failure counter, release the lock and,
            // while an admin was active in the last 24h, keep the cache hot
            // with ONE future action (22 min < the 30-min lists TTL). No
            // admin around → nothing is scheduled → zero background activity.
            // NOTE: no as_has_scheduled_action() guard here — it also counts
            // the CURRENTLY RUNNING action (status running), so inside this
            // handler it is always true and would suppress every renewal.
            // Duplicate risk is already covered by the lock + the guard in
            // hge_klaviyo_nl_maybe_enqueue_warmup().
            update_option( 'hge_klaviyo_nl_warmup_fails', 0, false );
            delete_transient( 'hge_klaviyo_nl_warmup_lock' );
            if ( get_transient( 'hge_klaviyo_nl_admin_active' ) && function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action(
                    time() + 22 * MINUTE_IN_SECONDS,
                    HGE_KLAVIYO_NL_WARMUP_HOOK,
                    array( array( 'what' => 'lists', 'cursor' => '', 'pages' => 0 ) ),
                    'hge-klaviyo'
                );
            }
        } catch ( \Throwable $e ) {
            hge_klaviyo_nl_warmup_fail(
                is_array( $job ) && isset( $job['what'] ) ? (string) $job['what'] : '?',
                get_class( $e ) . ': ' . $e->getMessage()
            );
        }
    }
}

// Kill switch flipped ON in Settings → immediately drain the warmup queue.
add_action(
    'update_option_' . HGE_KLAVIYO_NL_OPT_SETTINGS,
    static function ( $old_value, $new_value ) {
        $was = is_array( $old_value ) ? ! empty( $old_value['disable_background_jobs'] ) : false;
        $now = is_array( $new_value ) ? ! empty( $new_value['disable_background_jobs'] ) : false;
        if ( $now && ! $was && function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( HGE_KLAVIYO_NL_WARMUP_HOOK, array(), 'hge-klaviyo' );
            as_unschedule_all_actions( 'hge_klaviyo_nl_api_cache_warmup', array(), 'hge-klaviyo' );
            delete_transient( 'hge_klaviyo_nl_warmup_lock' );
            delete_transient( 'hge_klaviyo_nl_warmup_acc' );
        }
    },
    10,
    2
);
