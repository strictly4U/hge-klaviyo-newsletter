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
    define( 'HGE_KLAVIYO_NL_API_CACHE_TTL', 5 * MINUTE_IN_SECONDS );
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

        set_transient( $cache_key, $items, HGE_KLAVIYO_NL_API_CACHE_TTL );
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
    }
}

// One-shot cache invalidation when the plugin code is updated to a new version.
// Without this, the previous (cached for 5 min) "0 templates" empty array
// keeps getting served even after the page_size fix is deployed.
add_action( 'admin_init', static function () {
    $marker = 'hge_klaviyo_nl_api_cache_codever';
    $stored = (string) get_option( $marker, '' );
    $current = defined( 'HGE_KLAVIYO_NL_VERSION' ) ? HGE_KLAVIYO_NL_VERSION : '';
    if ( $current && $current !== $stored ) {
        if ( function_exists( 'hge_klaviyo_nl_clear_api_cache' ) ) {
            hge_klaviyo_nl_clear_api_cache();
        }
        update_option( $marker, $current, false );
    }
} );

// Invalidate API cache when settings are saved (e.g. user updated API key)
add_action( 'update_option_' . HGE_KLAVIYO_NL_OPT_SETTINGS, 'hge_klaviyo_nl_clear_api_cache', 10, 0 );
