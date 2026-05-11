<?php
/**
 * Secure JSON feed endpoints for Klaviyo.
 *
 * Endpoints:
 *   /feed/klaviyo.json          — top 8 articles from category "stiri" (cached 5 min)
 *   /feed/klaviyo-current.json  — single active article based on transient (Web Feed mode)
 *
 *   Optional query param on /feed/klaviyo-current.json:
 *     ?name=<web_feed_name>     — since 3.0.0, scopes the lookup to a specific
 *                                 rule's keyed transient. When omitted, falls
 *                                 back to the legacy global transient so old
 *                                 Klaviyo Web Feed URLs (pre-v3.0) keep working.
 *                                 (Param is `name=` not `feed=` because `feed`
 *                                 is a reserved WP core query var.)
 *
 * Authentication: token via `X-Feed-Token` header or `?key=` query parameter.
 * Token comparison via `hash_equals` (constant-time).
 *
 * @package HgE\KlaviyoNewsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Rewrite + query var registration
add_action( 'init', 'hge_klaviyo_register_feed_rewrites' );
if ( ! function_exists( 'hge_klaviyo_register_feed_rewrites' ) ) {
    function hge_klaviyo_register_feed_rewrites() {
        add_rewrite_rule(
            '^feed/klaviyo\.json$',
            'index.php?klaviyo_feed=1',
            'top'
        );
        add_rewrite_rule(
            '^feed/klaviyo-current\.json$',
            'index.php?klaviyo_current_feed=1',
            'top'
        );
    }
}

add_filter( 'query_vars', 'hge_klaviyo_register_feed_query_vars' );
if ( ! function_exists( 'hge_klaviyo_register_feed_query_vars' ) ) {
    function hge_klaviyo_register_feed_query_vars( $vars ) {
        $vars[] = 'klaviyo_feed';
        $vars[] = 'klaviyo_current_feed';
        return $vars;
    }
}

// /feed/klaviyo.json — token-protected, GET-only, transient cache 5 min
add_action( 'template_redirect', 'hge_klaviyo_feed_handler' );

if ( ! function_exists( 'hge_klaviyo_feed_handler' ) ) {
    function hge_klaviyo_feed_handler() {
        if ( ! get_query_var( 'klaviyo_feed' ) ) {
            return;
        }

        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
        if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
            status_header( 405 );
            header( 'Allow: GET, HEAD' );
            header( 'Content-Type: application/json; charset=UTF-8' );
            echo '{"error":"method_not_allowed"}';
            exit;
        }

        $expected_token = function_exists( 'hge_klaviyo_nl_resolve_feed_token' )
            ? hge_klaviyo_nl_resolve_feed_token()
            : ( defined( 'KLAVIYO_FEED_TOKEN' ) ? (string) KLAVIYO_FEED_TOKEN : '' );

        if ( '' === $expected_token ) {
            status_header( 503 );
            nocache_headers();
            header( 'Content-Type: application/json; charset=UTF-8' );
            echo '{"error":"feed_not_configured"}';
            exit;
        }

        $provided = '';
        if ( isset( $_SERVER['HTTP_X_FEED_TOKEN'] ) ) {
            $provided = (string) $_SERVER['HTTP_X_FEED_TOKEN'];
        } elseif ( isset( $_GET['key'] ) ) {
            $provided = (string) wp_unslash( $_GET['key'] );
        }

        if ( ! is_string( $provided ) || '' === $provided || ! hash_equals( $expected_token, $provided ) ) {
            status_header( 401 );
            nocache_headers();
            header( 'Content-Type: application/json; charset=UTF-8' );
            header( 'WWW-Authenticate: Bearer realm="klaviyo-feed"' );
            echo '{"error":"unauthorized"}';
            exit;
        }

        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Cache-Control: public, max-age=300, s-maxage=300' );
        header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'Vary: X-Feed-Token' );

        if ( 'HEAD' === $method ) {
            exit;
        }

        $cache_key = 'hge_klaviyo_feed_v1';
        $payload   = get_transient( $cache_key );

        if ( false === $payload ) {
            $query = new WP_Query( array(
                'post_type'              => 'post',
                'category_name'          => 'stiri',
                'posts_per_page'         => 8,
                'post_status'            => 'publish',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_term_cache' => true,
                'update_post_meta_cache' => false,
            ) );

            $items = array();

            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();

                $items[] = array(
                    'id'           => $post_id,
                    'title'        => wp_strip_all_tags( get_the_title() ),
                    'url'          => get_permalink(),
                    'excerpt'      => wp_strip_all_tags( get_the_excerpt() ),
                    'content'      => null,
                    'image'        => get_the_post_thumbnail_url( $post_id, 'full' ) ?: null,
                    'published_at' => get_the_date( DATE_ATOM ),
                    'updated_at'   => get_the_modified_date( DATE_ATOM ),
                    'author'       => get_the_author_meta( 'display_name' ),
                    'categories'   => wp_get_post_categories( $post_id, array( 'fields' => 'names' ) ),
                    'tags'         => wp_get_post_tags( $post_id, array( 'fields' => 'names' ) ),
                );
            }

            wp_reset_postdata();

            $payload = wp_json_encode(
                array(
                    'version'      => '1.0',
                    'source'       => parse_url( home_url(), PHP_URL_HOST ),
                    'generated_at' => gmdate( DATE_ATOM ),
                    'items'        => $items,
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ( is_string( $payload ) ) {
                set_transient( $cache_key, $payload, 5 * MINUTE_IN_SECONDS );
            }
        }

        echo $payload;
        exit;
    }
}

// /feed/klaviyo-current.json — single active article for Web Feed mode
add_action( 'template_redirect', 'hge_klaviyo_current_feed_handler' );

if ( ! function_exists( 'hge_klaviyo_current_feed_handler' ) ) {
    function hge_klaviyo_current_feed_handler() {
        if ( ! get_query_var( 'klaviyo_current_feed' ) ) {
            return;
        }

        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
        if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
            status_header( 405 );
            header( 'Allow: GET, HEAD' );
            header( 'Content-Type: application/json; charset=UTF-8' );
            echo '{"error":"method_not_allowed"}';
            exit;
        }

        $expected_token = function_exists( 'hge_klaviyo_nl_resolve_feed_token' )
            ? hge_klaviyo_nl_resolve_feed_token()
            : ( defined( 'KLAVIYO_FEED_TOKEN' ) ? (string) KLAVIYO_FEED_TOKEN : '' );

        if ( '' === $expected_token ) {
            status_header( 503 );
            nocache_headers();
            header( 'Content-Type: application/json; charset=UTF-8' );
            echo '{"error":"feed_not_configured"}';
            exit;
        }

        $provided = '';
        if ( isset( $_SERVER['HTTP_X_FEED_TOKEN'] ) ) {
            $provided = (string) $_SERVER['HTTP_X_FEED_TOKEN'];
        } elseif ( isset( $_GET['key'] ) ) {
            $provided = (string) wp_unslash( $_GET['key'] );
        }

        if ( ! is_string( $provided ) || '' === $provided || ! hash_equals( $expected_token, $provided ) ) {
            status_header( 401 );
            nocache_headers();
            header( 'Content-Type: application/json; charset=UTF-8' );
            header( 'WWW-Authenticate: Bearer realm="klaviyo-feed"' );
            echo '{"error":"unauthorized"}';
            exit;
        }

        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Referrer-Policy: no-referrer' );

        if ( 'HEAD' === $method ) {
            exit;
        }

        // Resolve which transient key to read (since 3.0.0).
        //   ?name=newsletter_feed_promo → hge_klaviyo_current_post_id_newsletter_feed_promo
        //   no ?name=                   → legacy global key (back-compat with pre-v3.0
        //                                 Klaviyo Web Feed URLs that omitted the param)
        $feed_name = isset( $_GET['name'] ) ? (string) wp_unslash( $_GET['name'] ) : '';
        $transient_key = function_exists( 'hge_klaviyo_nl_transient_key_for_feed' )
            ? hge_klaviyo_nl_transient_key_for_feed( $feed_name )
            : HGE_KLAVIYO_NL_TRANSIENT_CURRENT;

        $post_id = (int) get_transient( $transient_key );
        if ( ! $post_id ) {
            echo wp_json_encode( array( 'version' => '1.0', 'items' => array() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            exit;
        }

        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            echo wp_json_encode( array( 'version' => '1.0', 'items' => array() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            exit;
        }

        $excerpt_max  = (int) apply_filters( 'hge_klaviyo_excerpt_length', 120 );
        $excerpt_full = trim( wp_strip_all_tags( get_the_excerpt( $post ) ) );
        $excerpt      = mb_substr( $excerpt_full, 0, $excerpt_max );
        if ( mb_strlen( $excerpt_full ) > $excerpt_max ) {
            $excerpt = rtrim( $excerpt ) . '…';
        }

        $url_with_utm = add_query_arg(
            array(
                'utm_source'   => 'klaviyo',
                'utm_medium'   => 'email',
                'utm_campaign' => sanitize_title( $post->post_name ?: ( 'post-' . $post_id ) ),
                'utm_content'  => 'newsletter',
            ),
            get_permalink( $post )
        );

        $item = array(
            'id'           => $post_id,
            'title'        => wp_strip_all_tags( get_the_title( $post ) ),
            'url'          => $url_with_utm,
            'excerpt'      => $excerpt,
            'image'        => get_the_post_thumbnail_url( $post_id, 'full' ) ?: '',
            'published_at' => get_the_date( DATE_ATOM, $post ),
            'updated_at'   => get_the_modified_date( DATE_ATOM, $post ),
            'date'         => get_the_date( '', $post ),
            'author'       => get_the_author_meta( 'display_name', (int) $post->post_author ),
            'categories'   => wp_get_post_categories( $post_id, array( 'fields' => 'names' ) ),
            'tags'         => wp_get_post_tags( $post_id, array( 'fields' => 'names' ) ),
        );

        echo wp_json_encode(
            array(
                'version'      => '1.0',
                'source'       => parse_url( home_url(), PHP_URL_HOST ),
                'generated_at' => gmdate( DATE_ATOM ),
                'items'        => array( $item ),
            ),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}

// Invalidate the /feed/klaviyo.json transient when posts change
add_action( 'save_post_post', 'hge_klaviyo_feed_invalidate', 20, 3 );
add_action( 'deleted_post',   'hge_klaviyo_feed_invalidate' );
add_action( 'trashed_post',   'hge_klaviyo_feed_invalidate' );

if ( ! function_exists( 'hge_klaviyo_feed_invalidate' ) ) {
    function hge_klaviyo_feed_invalidate( $post_id = 0, $post = null, $update = null ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        delete_transient( 'hge_klaviyo_feed_v1' );
    }
}

// Marker pentru theme legacy: blocul feed din functions.php se dezactivează când e definit.
if ( ! defined( 'HGE_KLAVIYO_NL_FEEDS_LOADED' ) ) {
    define( 'HGE_KLAVIYO_NL_FEEDS_LOADED', true );
}
