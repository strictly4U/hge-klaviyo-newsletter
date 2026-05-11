<?php
/**
 * Settings DB schema, sanitization, tag-rule helpers.
 *
 * Single option key: `hge_klaviyo_nl_settings` (autoload=false).
 *
 * Top-level shape:
 *   array(
 *     'api_key'             => string  (Klaviyo Private API key)
 *     'feed_token'          => string  (token for /feed/klaviyo*.json endpoints)
 *     'reply_to_email'      => string|''
 *     'min_interval_hours'  => int     (default: 12) — cooldown duration applied PER RULE
 *     'debug_mode'          => bool    (gates the Status tab visibility)
 *     'tag_rules'           => array<int, array{
 *         tag_slug:          string,   // single slug for Free/Core; comma-separated allowed for Pro (OR semantics)
 *         included_list_ids: array,
 *         excluded_list_ids: array,
 *         template_id:       string,
 *         use_web_feed:      bool,
 *         web_feed_name:     string,
 *     }>
 *   )
 *
 * Per-rule cooldown timestamps are stored in a separate option `hge_klaviyo_last_send_at_by_slug`
 * keyed by the rule's tag_slug — independent timers per rule survive reorder.
 *
 * Tier caps (enforced by the sanitiser):
 *   - Free  → 1 rule  | 1 included list, 0 excluded, no template (built-in HTML only)
 *   - Core  → 2 rules | 1+1 lists per rule, template_id allowed
 *   - Pro   → 5 rules | up to 15 combined lists per rule (Klaviyo limit), comma-separated tag_slug allowed
 *
 * @package HgE\KlaviyoNewsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'HGE_KLAVIYO_NL_OPT_SETTINGS' ) ) {
    define( 'HGE_KLAVIYO_NL_OPT_SETTINGS',          'hge_klaviyo_nl_settings' );
    define( 'HGE_KLAVIYO_NL_OPT_LAST_SEND_BY_SLUG', 'hge_klaviyo_last_send_at_by_slug' );
}

if ( ! function_exists( 'hge_klaviyo_nl_default_rule' ) ) {
    /**
     * Skeleton for a fresh empty rule.
     */
    function hge_klaviyo_nl_default_rule() {
        return array(
            'tag_slug'          => '',
            'included_list_ids' => array(),
            'excluded_list_ids' => array(),
            'template_id'       => '',
            'use_web_feed'      => false,
            // Default Web Feed name on fresh installs. Customers usually rename
            // this to match what's configured in Klaviyo → Settings → Web Feeds.
            'web_feed_name'     => 'newsletter_feed',
        );
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_settings_defaults' ) ) {
    /**
     * @since 2.2.0  Filterable.
     * @since 3.0.0  Top-level list / template / web-feed keys removed (moved into tag_rules).
     */
    function hge_klaviyo_nl_settings_defaults() {
        $default_rule = hge_klaviyo_nl_default_rule();
        $default_rule['tag_slug'] = defined( 'HGE_KLAVIYO_NL_TAG_SLUG' ) ? HGE_KLAVIYO_NL_TAG_SLUG : 'newsletter';

        return apply_filters( 'hge_klaviyo_nl_settings_defaults', array(
            'api_key'            => '',
            'feed_token'         => '',
            'reply_to_email'     => '',
            'min_interval_hours' => 12,
            'debug_mode'         => false,
            'tag_rules'          => array( $default_rule ),
        ) );
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_get_settings' ) ) {
    /**
     * @since 3.0.0  Hard-filters the stored option to the current schema.
     *               Legacy keys from v2.x (`included_list_ids`, `excluded_list_ids`,
     *               `template_id`, `use_web_feed`, `web_feed_name`) are silently dropped
     *               at read time — no migration shim. We're at T0 so this is safe.
     */
    function hge_klaviyo_nl_get_settings() {
        $stored = get_option( HGE_KLAVIYO_NL_OPT_SETTINGS, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        $defaults = hge_klaviyo_nl_settings_defaults();
        $merged   = wp_parse_args( $stored, $defaults );

        // Drop any keys not in defaults (kills legacy v2.x keys at runtime view)
        $merged = array_intersect_key( $merged, $defaults );

        // Ensure `tag_rules` is always an array of fully-shaped rules
        if ( ! is_array( $merged['tag_rules'] ) || empty( $merged['tag_rules'] ) ) {
            $merged['tag_rules'] = $defaults['tag_rules'];
        } else {
            $skeleton = hge_klaviyo_nl_default_rule();
            $merged['tag_rules'] = array_map( static function ( $rule ) use ( $skeleton ) {
                if ( ! is_array( $rule ) ) {
                    return $skeleton;
                }
                $rule = array_merge( $skeleton, $rule );
                return array_intersect_key( $rule, $skeleton );
            }, array_values( $merged['tag_rules'] ) );
        }

        return $merged;
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_get_setting' ) ) {
    function hge_klaviyo_nl_get_setting( $key, $fallback = null ) {
        $s = hge_klaviyo_nl_get_settings();
        return array_key_exists( $key, $s ) ? $s[ $key ] : $fallback;
    }
}

// =============================================================================
// Tier-aware helpers for the rules system
// =============================================================================

if ( ! function_exists( 'hge_klaviyo_nl_max_rules' ) ) {
    /**
     * Maximum number of tag rules allowed under the active plan.
     *
     * @since 3.0.0
     * @return int 1 (Free) | 2 (Core) | 5 (Pro)
     */
    function hge_klaviyo_nl_max_rules() {
        $plan = function_exists( 'hge_klaviyo_active_plan' ) ? hge_klaviyo_active_plan() : 'free';
        switch ( $plan ) {
            case 'pro':
                return 5;
            case 'core':
                return 2;
            default:
                return 1;
        }
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_supports_multi_tag_rule' ) ) {
    /**
     * Whether the active plan accepts comma-separated tag slugs in a single rule
     * (OR semantics — post matches if ANY of the slugs is present).
     *
     * @since 3.0.0
     */
    function hge_klaviyo_nl_supports_multi_tag_rule() {
        $plan = function_exists( 'hge_klaviyo_active_plan' ) ? hge_klaviyo_active_plan() : 'free';
        return 'pro' === $plan;
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_rule_caps' ) ) {
    /**
     * Per-rule list/template caps based on plan.
     *
     * @return array{ max_included:int, max_excluded:int, allow_template:bool, allow_web_feed:bool }
     */
    function hge_klaviyo_nl_rule_caps() {
        $plan = function_exists( 'hge_klaviyo_active_plan' ) ? hge_klaviyo_active_plan() : 'free';
        switch ( $plan ) {
            case 'pro':
                return array( 'max_included' => 15, 'max_excluded' => 15, 'allow_template' => true, 'allow_web_feed' => true );
            case 'core':
                return array( 'max_included' => 1,  'max_excluded' => 1,  'allow_template' => true, 'allow_web_feed' => true );
            default:
                return array( 'max_included' => 1,  'max_excluded' => 0,  'allow_template' => false, 'allow_web_feed' => false );
        }
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_get_matching_rule' ) ) {
    /**
     * Find the first rule that matches a post, by priority (array order).
     * On Pro, a rule's tag_slug may be a comma-separated list — we match if the
     * post has ANY of the listed tags (OR semantics).
     *
     * The resolved match passes through the `hge_klaviyo_nl_matching_rule` filter
     * before returning, so Pro / theme code can override the default
     * first-tag-wins behaviour (e.g., AND semantics, custom scoring, taxonomy
     * other than `post_tag`).
     *
     * @since 3.0.0
     * @param WP_Post $post
     * @return array|null  The matched rule (with `_rule_idx` and `_rule_tag_matched` keys appended) or null.
     */
    function hge_klaviyo_nl_get_matching_rule( $post ) {
        if ( ! ( $post instanceof WP_Post ) ) {
            return null;
        }
        $settings = hge_klaviyo_nl_get_settings();
        $rules    = is_array( $settings['tag_rules'] ?? null ) ? $settings['tag_rules'] : array();

        $matched = null;
        foreach ( $rules as $idx => $rule ) {
            $slug = (string) ( $rule['tag_slug'] ?? '' );
            if ( '' === $slug ) {
                continue;
            }
            // Comma-separated → multi-tag (Pro). Single slug for Free/Core works the same way.
            $tags = array_filter( array_map( 'trim', explode( ',', $slug ) ), 'strlen' );
            foreach ( $tags as $t ) {
                if ( has_tag( $t, $post ) ) {
                    $rule['_rule_idx']         = $idx;
                    $rule['_rule_tag_matched'] = $t;
                    $matched                   = $rule;
                    break 2;
                }
            }
        }

        /**
         * Filter the resolved rule for a given post.
         *
         * @since 3.0.0
         * @param array|null $matched The matched rule array (with `_rule_idx`,
         *                            `_rule_tag_matched`) or null when no rule fires.
         * @param WP_Post    $post    The post being evaluated.
         * @param array      $rules   All configured rules (in priority order).
         */
        return apply_filters( 'hge_klaviyo_nl_matching_rule', $matched, $post, $rules );
    }
}

// =============================================================================
// Per-rule cooldown helpers
// =============================================================================

if ( ! function_exists( 'hge_klaviyo_nl_get_last_send_for_slug' ) ) {
    /**
     * Get the last-send timestamp for a given rule's tag_slug.
     * Returns 0 when no send was ever recorded for this slug.
     */
    function hge_klaviyo_nl_get_last_send_for_slug( $tag_slug ) {
        $by_slug = get_option( HGE_KLAVIYO_NL_OPT_LAST_SEND_BY_SLUG, array() );
        if ( ! is_array( $by_slug ) ) {
            return 0;
        }
        return isset( $by_slug[ $tag_slug ] ) ? (int) $by_slug[ $tag_slug ] : 0;
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_set_last_send_for_slug' ) ) {
    /**
     * Persist the last-send timestamp for a given rule's tag_slug.
     */
    function hge_klaviyo_nl_set_last_send_for_slug( $tag_slug, $ts ) {
        $by_slug = get_option( HGE_KLAVIYO_NL_OPT_LAST_SEND_BY_SLUG, array() );
        if ( ! is_array( $by_slug ) ) {
            $by_slug = array();
        }
        $by_slug[ $tag_slug ] = (int) $ts;
        update_option( HGE_KLAVIYO_NL_OPT_LAST_SEND_BY_SLUG, $by_slug, false );
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_compute_send_time_for_slug' ) ) {
    /**
     * Compute the next allowed send time for a given tag slug (per-rule cooldown).
     *
     * @return array{ mode: 'immediate'|'static_time', time: int }
     */
    function hge_klaviyo_nl_compute_send_time_for_slug( $tag_slug ) {
        $last     = hge_klaviyo_nl_get_last_send_for_slug( $tag_slug );
        $now      = time();
        $earliest = $last + hge_klaviyo_min_interval_seconds();

        if ( $earliest <= $now ) {
            return array( 'mode' => 'immediate', 'time' => $now );
        }
        // Klaviyo requires static_time at least a few minutes in the future
        $time = max( $earliest, $now + 15 * MINUTE_IN_SECONDS );
        return array( 'mode' => 'static_time', 'time' => $time );
    }
}

// =============================================================================
// Sanitiser
// =============================================================================

if ( ! function_exists( 'hge_klaviyo_nl_sanitize_settings' ) ) {
    function hge_klaviyo_nl_sanitize_settings( $input ) {
        $out = hge_klaviyo_nl_settings_defaults();

        if ( isset( $input['api_key'] ) ) {
            $out['api_key'] = trim( sanitize_text_field( (string) $input['api_key'] ) );
        }
        if ( isset( $input['feed_token'] ) ) {
            $out['feed_token'] = trim( sanitize_text_field( (string) $input['feed_token'] ) );
        }
        if ( isset( $input['reply_to_email'] ) ) {
            $email = sanitize_email( (string) $input['reply_to_email'] );
            $out['reply_to_email'] = is_email( $email ) ? $email : '';
        }
        if ( isset( $input['min_interval_hours'] ) ) {
            $h = (int) $input['min_interval_hours'];
            $out['min_interval_hours'] = max( 0, min( 168, $h ) );
        }
        if ( isset( $input['debug_mode'] ) ) {
            $out['debug_mode'] = (bool) $input['debug_mode'];
        }

        if ( isset( $input['tag_rules'] ) && is_array( $input['tag_rules'] ) ) {
            $out['tag_rules'] = hge_klaviyo_nl_sanitize_rules( $input['tag_rules'] );
        }

        // If after sanitising we have zero rules, seed one with the default tag slug
        // so the UI never shows an empty rules list.
        if ( empty( $out['tag_rules'] ) ) {
            $out['tag_rules'] = hge_klaviyo_nl_settings_defaults()['tag_rules'];
        }

        /**
         * Filter sanitised settings — Pro feature modules use this to add their fields.
         *
         * @since 2.2.0
         */
        return apply_filters( 'hge_klaviyo_nl_sanitize_settings', $out, $input );
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_sanitize_rules' ) ) {
    /**
     * Sanitise an array of rule dicts. Enforces tier caps:
     *   - rule count ≤ hge_klaviyo_nl_max_rules()
     *   - tag_slug: single slug (Free/Core) or comma-separated (Pro)
     *   - per-rule list/template caps via hge_klaviyo_nl_rule_caps()
     *   - Klaviyo per-campaign limit: included + excluded ≤ 15
     *
     * @since 3.0.0
     */
    function hge_klaviyo_nl_sanitize_rules( $raw_rules ) {
        $max_rules     = hge_klaviyo_nl_max_rules();
        $caps          = hge_klaviyo_nl_rule_caps();
        $supports_multi = hge_klaviyo_nl_supports_multi_tag_rule();
        $clean         = array();

        foreach ( $raw_rules as $raw ) {
            if ( count( $clean ) >= $max_rules ) {
                break;
            }
            if ( ! is_array( $raw ) ) {
                continue;
            }

            $tag_slug = isset( $raw['tag_slug'] ) ? (string) $raw['tag_slug'] : '';
            $tag_slug = hge_klaviyo_nl_sanitize_tag_slug( $tag_slug, $supports_multi );
            if ( '' === $tag_slug ) {
                continue;
            }

            // Lists — alphanumeric/dash whitelist on each ID
            $included = array();
            if ( isset( $raw['included_list_ids'] ) && is_array( $raw['included_list_ids'] ) ) {
                $included = array_values( array_filter( array_map( static function ( $id ) {
                    return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $id );
                }, $raw['included_list_ids'] ), 'strlen' ) );
            }
            $excluded = array();
            if ( isset( $raw['excluded_list_ids'] ) && is_array( $raw['excluded_list_ids'] ) ) {
                $excluded = array_values( array_filter( array_map( static function ( $id ) {
                    return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $id );
                }, $raw['excluded_list_ids'] ), 'strlen' ) );
            }

            // Tier caps
            $included = array_slice( $included, 0, $caps['max_included'] );
            $excluded = array_slice( $excluded, 0, $caps['max_excluded'] );

            // Klaviyo per-campaign hard limit (applies on top of tier caps)
            $combined = count( $included ) + count( $excluded );
            if ( $combined > 15 ) {
                $allowed_inc = max( 0, 15 - count( $excluded ) );
                $included    = array_slice( $included, 0, $allowed_inc );
            }

            $template_id = '';
            if ( $caps['allow_template'] && isset( $raw['template_id'] ) ) {
                $template_id = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $raw['template_id'] );
            }

            $use_web_feed = false;
            $web_feed_name = 'newsletter_feed';
            if ( $caps['allow_web_feed'] ) {
                $use_web_feed = ! empty( $raw['use_web_feed'] );
                if ( isset( $raw['web_feed_name'] ) ) {
                    $name = sanitize_key( (string) $raw['web_feed_name'] );
                    if ( '' !== $name ) {
                        $web_feed_name = $name;
                    }
                }
            }

            $clean[] = array(
                'tag_slug'          => $tag_slug,
                'included_list_ids' => $included,
                'excluded_list_ids' => $excluded,
                'template_id'       => $template_id,
                'use_web_feed'      => $use_web_feed,
                'web_feed_name'     => $web_feed_name,
            );
        }
        return $clean;
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_sanitize_tag_slug' ) ) {
    /**
     * Sanitise a tag_slug field. Single slug for Free/Core; comma-separated for Pro.
     */
    function hge_klaviyo_nl_sanitize_tag_slug( $raw, $allow_multi ) {
        $raw = (string) $raw;
        if ( $allow_multi ) {
            $parts = array_filter( array_map( static function ( $p ) {
                return sanitize_title( trim( $p ) );
            }, explode( ',', $raw ) ), 'strlen' );
            $parts = array_values( array_unique( $parts ) );
            return implode( ',', $parts );
        }
        // Free/Core: a single slug. If the user typed a comma-separated value
        // we silently keep only the first non-empty token (UX safer than
        // collapsing the comma into a dash and producing a non-existent slug).
        if ( false !== strpos( $raw, ',' ) ) {
            $first = '';
            foreach ( explode( ',', $raw ) as $p ) {
                $first = sanitize_title( trim( $p ) );
                if ( '' !== $first ) {
                    break;
                }
            }
            return $first;
        }
        return sanitize_title( $raw );
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_update_settings' ) ) {
    /**
     * Merge + sanitize + persist. Pass full array or partial.
     *
     * `tag_rules` is wholesale-replaced when present in the partial (not merged
     * by key), so removing a rule card in the UI actually drops it from the DB.
     * Array_replace_recursive would otherwise keep orphan rules at higher indices.
     */
    function hge_klaviyo_nl_update_settings( array $partial ) {
        $current = hge_klaviyo_nl_get_settings();
        $merged  = array_replace_recursive( $current, $partial );

        if ( array_key_exists( 'tag_rules', $partial ) ) {
            $merged['tag_rules'] = is_array( $partial['tag_rules'] )
                ? array_values( $partial['tag_rules'] )
                : array();
        }

        $clean   = hge_klaviyo_nl_sanitize_settings( $merged );
        update_option( HGE_KLAVIYO_NL_OPT_SETTINGS, $clean, false );
        return $clean;
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_settings_complete' ) ) {
    /**
     * True when the bare-minimum config is present so dispatch can run.
     * Requires API key, feed token, and at least one rule with a tag_slug + included list.
     */
    function hge_klaviyo_nl_settings_complete() {
        $s = hge_klaviyo_nl_get_settings();
        if ( '' === $s['api_key'] || '' === $s['feed_token'] ) {
            return false;
        }
        foreach ( (array) $s['tag_rules'] as $rule ) {
            if ( ! empty( $rule['tag_slug'] ) && ! empty( $rule['included_list_ids'] ) ) {
                return true;
            }
        }
        return false;
    }
}

// =============================================================================
// Resolver helpers (read-only convenience for parts of the codebase that don't
// need the full settings array)
// =============================================================================

if ( ! function_exists( 'hge_klaviyo_nl_resolve_api_key' ) ) {
    function hge_klaviyo_nl_resolve_api_key() {
        $s = hge_klaviyo_nl_get_setting( 'api_key', '' );
        if ( '' !== $s ) {
            return $s;
        }
        if ( defined( 'KLAVIYO_API_PRIVATE_KEY' ) ) {
            return (string) KLAVIYO_API_PRIVATE_KEY;
        }
        return '';
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_resolve_feed_token' ) ) {
    function hge_klaviyo_nl_resolve_feed_token() {
        $s = hge_klaviyo_nl_get_setting( 'feed_token', '' );
        if ( '' !== $s ) {
            return $s;
        }
        if ( defined( 'KLAVIYO_FEED_TOKEN' ) ) {
            return (string) KLAVIYO_FEED_TOKEN;
        }
        return '';
    }
}

if ( ! function_exists( 'hge_klaviyo_nl_resolve_reply_to' ) ) {
    function hge_klaviyo_nl_resolve_reply_to() {
        $s = hge_klaviyo_nl_get_setting( 'reply_to_email', '' );
        if ( '' !== $s ) {
            return $s;
        }
        if ( defined( 'KLAVIYO_NEWSLETTER_REPLY_TO' ) && KLAVIYO_NEWSLETTER_REPLY_TO ) {
            return (string) KLAVIYO_NEWSLETTER_REPLY_TO;
        }
        return '';
    }
}
