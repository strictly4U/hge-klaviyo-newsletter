<?php
/**
 * Dispatcher: WordPress hooks → Action Scheduler queue → Klaviyo Campaigns API.
 *
 * Public functions defined (each wrapped in function_exists guard so the legacy
 * in-theme implementation can coexist temporarily during the parity test):
 *
 *   hge_klaviyo_maybe_queue_newsletter
 *   hge_klaviyo_maybe_queue_newsletter_save
 *   hge_klaviyo_maybe_enqueue
 *   hge_klaviyo_dispatch_newsletter
 *   hge_klaviyo_api_request
 *   hge_klaviyo_build_email_body
 *   hge_klaviyo_render_newsletter_html
 *
 * @package HgE\KlaviyoNewsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'transition_post_status', 'hge_klaviyo_maybe_queue_newsletter', 20, 3 );
add_action( 'save_post_post',         'hge_klaviyo_maybe_queue_newsletter_save', 20, 3 );

if ( ! function_exists( 'hge_klaviyo_maybe_queue_newsletter' ) ) {
    function hge_klaviyo_maybe_queue_newsletter( $new_status, $old_status, $post ) {
        if ( 'publish' !== $new_status ) {
            return;
        }
        if ( ! ( $post instanceof WP_Post ) || 'post' !== $post->post_type ) {
            return;
        }
        hge_klaviyo_maybe_enqueue( $post );
    }
}

if ( ! function_exists( 'hge_klaviyo_maybe_queue_newsletter_save' ) ) {
    function hge_klaviyo_maybe_queue_newsletter_save( $post_id, $post, $update ) {
        if ( ! ( $post instanceof WP_Post ) || 'post' !== $post->post_type ) {
            return;
        }
        if ( 'publish' !== $post->post_status ) {
            return;
        }
        hge_klaviyo_maybe_enqueue( $post );
    }
}

if ( ! function_exists( 'hge_klaviyo_maybe_enqueue' ) ) {
    /**
     * Find the first matching rule for the post (by priority order in tag_rules)
     * and schedule a dispatch action for it. Each rule has its own per-slug cooldown.
     *
     * @since 3.0.0  Rule-aware (was tag_slug global constant before).
     */
    function hge_klaviyo_maybe_enqueue( WP_Post $post ) {
        if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
            return;
        }
        if ( 'yes' === get_post_meta( $post->ID, HGE_KLAVIYO_NL_META_SENT, true ) ) {
            return;
        }

        // Find the matching rule (by priority order, OR semantics for Pro multi-tag)
        if ( ! function_exists( 'hge_klaviyo_nl_get_matching_rule' ) ) {
            return;
        }
        $rule = hge_klaviyo_nl_get_matching_rule( $post );
        if ( null === $rule ) {
            return;
        }

        // Per-rule scheduling: cooldown is keyed on the rule's tag_slug field.
        // We pass tag_slug as the second AS arg so dispatch_newsletter knows
        // which rule fired (in case rules change between enqueue and execution).
        $hook = HGE_KLAVIYO_NL_HOOK;
        $args = array( (int) $post->ID, (string) $rule['tag_slug'] );

        // In Web Feed mode the dispatch must run "now" (transient with post_id has 1h TTL),
        // so we honour cooldown by scheduling AS at the future time. Other modes can use
        // Klaviyo's `static` send strategy and dispatch immediately.
        $is_web_feed = ! empty( $rule['use_web_feed'] ) && ! empty( $rule['template_id'] );

        if ( $is_web_feed ) {
            $plan = hge_klaviyo_nl_compute_send_time_for_slug( $rule['tag_slug'] );
            $when = $plan['time'];

            if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, 'hge-klaviyo' ) ) {
                return;
            }
            if ( function_exists( 'as_schedule_single_action' ) ) {
                as_schedule_single_action( $when, $hook, $args, 'hge-klaviyo' );
                hge_klaviyo_nl_set_last_send_for_slug( $rule['tag_slug'], (int) $when );
                if ( $when > time() + 60 ) {
                    update_post_meta( $post->ID, HGE_KLAVIYO_NL_META_SCHED_FOR, gmdate( DATE_ATOM, $when ) );
                }
                return;
            }
            if ( ! wp_next_scheduled( $hook, $args ) ) {
                wp_schedule_single_event( $when, $hook, $args );
                hge_klaviyo_nl_set_last_send_for_slug( $rule['tag_slug'], (int) $when );
            }
            return;
        }

        // Legacy mode (per-campaign template). Cooldown is enforced by passing
        // a `static` send strategy to Klaviyo at dispatch time; AS just runs immediately.
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, 'hge-klaviyo' ) ) {
                return;
            }
            as_enqueue_async_action( $hook, $args, 'hge-klaviyo' );
            return;
        }
        if ( ! wp_next_scheduled( $hook, $args ) ) {
            wp_schedule_single_event( time() + 30, $hook, $args );
        }
    }
}

add_action( HGE_KLAVIYO_NL_HOOK, 'hge_klaviyo_dispatch_newsletter', 10, 2 );

if ( ! function_exists( 'hge_klaviyo_dispatch_newsletter' ) ) {
    /**
     * @since 3.0.0  Accepts a `$tag_slug` second argument identifying which rule
     *               fired the dispatch. Falls back to re-finding the matching rule
     *               when the slug is missing (manual "Trimite acum" / legacy AS rows).
     */
    function hge_klaviyo_dispatch_newsletter( $post_id, $tag_slug = '' ) {
        $post_id  = (int) $post_id;
        $tag_slug = (string) $tag_slug;
        if ( ! $post_id ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post || 'publish' !== $post->post_status || 'post' !== $post->post_type ) {
            return;
        }
        if ( 'yes' === get_post_meta( $post_id, HGE_KLAVIYO_NL_META_SENT, true ) ) {
            return;
        }

        // Resolve the rule that fired this dispatch. Prefer the slug passed via AS args
        // (set at enqueue time); fall back to re-finding the matching rule by post tags.
        $rule = null;
        if ( '' !== $tag_slug && function_exists( 'hge_klaviyo_nl_get_settings' ) ) {
            $settings_for_rule = hge_klaviyo_nl_get_settings();
            foreach ( (array) $settings_for_rule['tag_rules'] as $idx => $r ) {
                if ( ( $r['tag_slug'] ?? '' ) === $tag_slug ) {
                    $rule = array_merge( $r, array( '_rule_idx' => $idx, '_rule_tag_matched' => $tag_slug ) );
                    break;
                }
            }
        }
        if ( null === $rule && function_exists( 'hge_klaviyo_nl_get_matching_rule' ) ) {
            $rule = hge_klaviyo_nl_get_matching_rule( $post );
        }
        if ( null === $rule ) {
            error_log( '[HgE Klaviyo NL] No matching rule for post ' . $post_id . ' (slug arg: ' . $tag_slug . ')' );
            update_post_meta( $post_id, HGE_KLAVIYO_NL_META_ERROR, 'no_matching_rule' );
            return;
        }

        // Anti-duplicate: dacă o campanie a fost deja creată (din rulare anterioară parțial reușită),
        // marcăm postul ca trimis și NU mai apelăm API-ul. Tu poți face Reset dacă chiar vrei retrimitere.
        $existing_camp = (string) get_post_meta( $post_id, HGE_KLAVIYO_NL_META_CAMP_ID, true );
        if ( '' !== $existing_camp ) {
            update_post_meta( $post_id, HGE_KLAVIYO_NL_META_SENT, 'yes' );
            update_post_meta( $post_id, HGE_KLAVIYO_NL_META_ERROR, 'duplicate_prevented: campaign already created (' . $existing_camp . ')' );
            error_log( '[HgE Klaviyo NL] Post ' . $post_id . ' already has campaign ' . $existing_camp . ' — duplicate prevented' );
            return;
        }

        $settings = hge_klaviyo_nl_get_settings();
        $api_key  = hge_klaviyo_nl_resolve_api_key();
        $included = (array) ( $rule['included_list_ids'] ?? array() );
        $excluded = (array) ( $rule['excluded_list_ids'] ?? array() );

        if ( '' === $api_key || empty( $included ) ) {
            error_log( '[HgE Klaviyo NL] Missing settings (api_key or rule included_list_ids) for post ' . $post_id . ' rule slug ' . $rule['tag_slug'] );
            update_post_meta( $post_id, HGE_KLAVIYO_NL_META_ERROR, 'missing_settings' );
            return;
        }

        if ( ! add_post_meta( $post_id, HGE_KLAVIYO_NL_META_LOCK, time(), true ) ) {
            $lock_ts = (int) get_post_meta( $post_id, HGE_KLAVIYO_NL_META_LOCK, true );
            if ( $lock_ts && ( time() - $lock_ts ) < 15 * MINUTE_IN_SECONDS ) {
                return;
            }
            update_post_meta( $post_id, HGE_KLAVIYO_NL_META_LOCK, time() );
        }

        try {
            $excerpt_max   = (int) apply_filters( 'hge_klaviyo_excerpt_length', 120 );
            $title         = trim( wp_strip_all_tags( get_the_title( $post ) ) );
            $excerpt_full  = trim( wp_strip_all_tags( get_the_excerpt( $post ) ) );
            $excerpt_short = mb_substr( $excerpt_full, 0, $excerpt_max );
            if ( mb_strlen( $excerpt_full ) > $excerpt_max ) {
                $excerpt_short = rtrim( $excerpt_short ) . '…';
            }

            $image_url = get_the_post_thumbnail_url( $post_id, 'full' );
            if ( ! $image_url ) {
                $image_url = '';
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

            $is_web_feed = ! empty( $rule['use_web_feed'] ) && ! empty( $rule['template_id'] );

            if ( $is_web_feed ) {
                // Per-rule keyed transient (since 3.0.0). Keyed on web_feed_name so
                // each rule serves its own active post on /feed/klaviyo-current.json?name=<feed>.
                // The legacy unkeyed transient is also written when web_feed_name === 'fc_news'
                // (resolved by hge_klaviyo_nl_transient_key_for_feed). That branch preserves
                // Klaviyo Web Feed URLs from the original FC Rapid 1923 deployment that did
                // not carry a ?name= query parameter; new installs default to 'newsletter_feed'.
                $feed_name = (string) ( $rule['web_feed_name'] ?? '' );
                $keyed_key = function_exists( 'hge_klaviyo_nl_transient_key_for_feed' )
                    ? hge_klaviyo_nl_transient_key_for_feed( $feed_name )
                    : HGE_KLAVIYO_NL_TRANSIENT_CURRENT;
                set_transient( $keyed_key, (int) $post_id, HOUR_IN_SECONDS );

                $template_id   = (string) $rule['template_id'];
                $send_strategy = array( 'method' => 'immediate' );
                $plan          = array( 'mode' => 'immediate', 'time' => time() );
            } else {
                $rendered = hge_klaviyo_build_email_body( $post, $title, $excerpt_short, $image_url, $url_with_utm );
                $template_payload = array(
                    'data' => array(
                        'type'       => 'template',
                        'attributes' => array(
                            'name'        => 'NL ' . $title . ' [' . $post_id . ']',
                            'editor_type' => 'CODE',
                            'html'        => $rendered['html'],
                            'text'        => $rendered['text'],
                        ),
                    ),
                );
                $template = hge_klaviyo_api_request( 'POST', '/api/templates/', $template_payload );
                if ( is_wp_error( $template ) ) {
                    throw new RuntimeException( 'create_template: ' . $template->get_error_message() );
                }
                $template_id = isset( $template['data']['id'] ) ? (string) $template['data']['id'] : '';
                if ( '' === $template_id ) {
                    throw new RuntimeException( 'create_template: missing id' );
                }

                // Per-rule cooldown: timer keyed on the rule's tag_slug
                $plan = function_exists( 'hge_klaviyo_nl_compute_send_time_for_slug' )
                    ? hge_klaviyo_nl_compute_send_time_for_slug( $rule['tag_slug'] )
                    : array( 'mode' => 'immediate', 'time' => time() );
                if ( 'immediate' === $plan['mode'] ) {
                    $send_strategy = array( 'method' => 'immediate' );
                } else {
                    // Klaviyo Campaigns API 2024-10-15:
                    //   - method must be `static` (not `static_time`).
                    //   - `datetime` lives inside `options_static`.
                    //   - `send_past_recipients_immediately` is ONLY valid when
                    //     `is_local=true`; including it (even as false) when
                    //     `is_local=false` triggers HTTP 400.
                    $send_strategy = array(
                        'method'         => 'static',
                        'options_static' => array(
                            'datetime' => gmdate( DATE_ATOM, $plan['time'] ),
                            'is_local' => false,
                        ),
                    );
                }
            }

            // Creează campanie (auto-creează un campaign-message)
            // Filters expose extension points for the Pro plugin (Tier 2 / 3 features)
            $audience_included = apply_filters( 'hge_klaviyo_audience_included', array_values( array_map( 'strval', $included ) ), $post_id, $settings );
            $audience_excluded = apply_filters( 'hge_klaviyo_audience_excluded', array_values( array_map( 'strval', $excluded ) ), $post_id, $settings );
            $send_strategy     = apply_filters( 'hge_klaviyo_send_strategy', $send_strategy, $post_id, $settings );

            $message_content = array(
                'subject'      => hge_klaviyo_safe_subject( $title ),
                'preview_text' => $excerpt_short,
            );
            $reply_to = hge_klaviyo_nl_resolve_reply_to();
            if ( '' !== $reply_to ) {
                $message_content['reply_to_email'] = $reply_to;
            }
            // Note: from_email + from_label intentionally omitted — Klaviyo will use the
            // account default sender. Pro plugin can override via the filter below.
            $message_content = apply_filters( 'hge_klaviyo_message_content', $message_content, $post_id, $settings );

            $campaign_payload = array(
                'data' => array(
                    'type'       => 'campaign',
                    'attributes' => array(
                        'name'              => 'Știre: ' . $title . ' [' . $post_id . ']',
                        'audiences'         => array(
                            'included' => $audience_included,
                            'excluded' => $audience_excluded,
                        ),
                        'send_strategy'     => $send_strategy,
                        'send_options'      => array(
                            'use_smart_sending' => false,
                        ),
                        'tracking_options'  => array(
                            'add_tracking_params' => false,
                            'is_tracking_clicks'  => true,
                            'is_tracking_opens'   => true,
                        ),
                        'campaign-messages' => array(
                            'data' => array(
                                array(
                                    'type'       => 'campaign-message',
                                    'attributes' => array(
                                        'channel' => 'email',
                                        'label'   => 'Newsletter ' . $post_id,
                                        'content' => $message_content,
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            );
            $campaign_payload = apply_filters( 'hge_klaviyo_campaign_payload', $campaign_payload, $post_id, $settings );
            $campaign = hge_klaviyo_api_request( 'POST', '/api/campaigns/', $campaign_payload );
            if ( is_wp_error( $campaign ) ) {
                throw new RuntimeException( 'create_campaign: ' . $campaign->get_error_message() );
            }
            $campaign_id = isset( $campaign['data']['id'] ) ? (string) $campaign['data']['id'] : '';
            if ( '' === $campaign_id ) {
                throw new RuntimeException( 'create_campaign: missing id' );
            }
            $message_id = '';
            if ( ! empty( $campaign['data']['relationships']['campaign-messages']['data'][0]['id'] ) ) {
                $message_id = (string) $campaign['data']['relationships']['campaign-messages']['data'][0]['id'];
            }
            if ( '' === $message_id ) {
                throw new RuntimeException( 'create_campaign: missing message id' );
            }

            // Asignează template-ul la campaign-message
            $assign_payload = array(
                'data' => array(
                    'type' => 'campaign-message',
                    'id'   => $message_id,
                    'relationships' => array(
                        'template' => array(
                            'data' => array(
                                'type' => 'template',
                                'id'   => $template_id,
                            ),
                        ),
                    ),
                ),
            );
            $assign = hge_klaviyo_api_request( 'POST', '/api/campaign-message-assign-template/', $assign_payload );
            if ( is_wp_error( $assign ) ) {
                throw new RuntimeException( 'assign_template: ' . $assign->get_error_message() );
            }

            // Lansează campania
            $send_payload = array(
                'data' => array(
                    'type' => 'campaign-send-job',
                    'id'   => $campaign_id,
                ),
            );
            $send = hge_klaviyo_api_request( 'POST', '/api/campaign-send-jobs/', $send_payload );
            if ( is_wp_error( $send ) ) {
                throw new RuntimeException( 'send: ' . $send->get_error_message() );
            }

            // Marchează postul ca trimis + actualizează cooldown-ul per-rule (pe slug)
            update_post_meta( $post_id, HGE_KLAVIYO_NL_META_SENT, 'yes' );
            update_post_meta( $post_id, HGE_KLAVIYO_NL_META_CAMP_ID, $campaign_id );
            update_post_meta( $post_id, HGE_KLAVIYO_NL_META_SENT_AT, gmdate( DATE_ATOM ) );
            // Track which rule fired this dispatch (for the Status tab + audit)
            update_post_meta( $post_id, '_klaviyo_campaign_rule_slug', (string) $rule['tag_slug'] );
            if ( 'static_time' === $plan['mode'] ) {
                update_post_meta( $post_id, HGE_KLAVIYO_NL_META_SCHED_FOR, gmdate( DATE_ATOM, $plan['time'] ) );
            } else {
                delete_post_meta( $post_id, HGE_KLAVIYO_NL_META_SCHED_FOR );
            }
            // Per-rule cooldown — slug-keyed (survives reorder of rules in Settings)
            if ( function_exists( 'hge_klaviyo_nl_set_last_send_for_slug' ) ) {
                hge_klaviyo_nl_set_last_send_for_slug( $rule['tag_slug'], (int) $plan['time'] );
            }
            delete_post_meta( $post_id, HGE_KLAVIYO_NL_META_ERROR );

        } catch ( \Throwable $e ) {
            error_log( '[HgE Klaviyo NL] Post ' . $post_id . ': ' . $e->getMessage() );
            update_post_meta( $post_id, HGE_KLAVIYO_NL_META_ERROR, $e->getMessage() );
        } finally {
            delete_post_meta( $post_id, HGE_KLAVIYO_NL_META_LOCK );
        }
    }
}

if ( ! function_exists( 'hge_klaviyo_api_request' ) ) {
    function hge_klaviyo_api_request( $method, $path, $body = null ) {
        $api_key = function_exists( 'hge_klaviyo_nl_resolve_api_key' )
            ? hge_klaviyo_nl_resolve_api_key()
            : ( defined( 'KLAVIYO_API_PRIVATE_KEY' ) ? KLAVIYO_API_PRIVATE_KEY : '' );

        if ( '' === $api_key ) {
            return new WP_Error( 'klaviyo_api_no_key', 'Klaviyo API key not configured (Tools → Klaviyo Newsletter → Settings).' );
        }

        $args = array(
            'method'  => strtoupper( (string) $method ),
            'timeout' => 25,
            'headers' => array(
                'Authorization' => 'Klaviyo-API-Key ' . $api_key,
                'revision'      => HGE_KLAVIYO_NL_API_REVISION,
                'accept'        => 'application/vnd.api+json',
                'content-type'  => 'application/vnd.api+json',
            ),
        );
        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        }

        $response = wp_remote_request( 'https://a.klaviyo.com' . $path, $args );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code     = (int) wp_remote_retrieve_response_code( $response );
        $body_str = (string) wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'klaviyo_api_error',
                'HTTP ' . $code . ' ' . wp_remote_retrieve_response_message( $response ) . ' — ' . substr( $body_str, 0, 500 )
            );
        }

        if ( '' === $body_str ) {
            return array();
        }
        $decoded = json_decode( $body_str, true );
        return is_array( $decoded ) ? $decoded : array();
    }
}

if ( ! function_exists( 'hge_klaviyo_build_email_body' ) ) {
    function hge_klaviyo_build_email_body( WP_Post $post, $title, $excerpt, $image_url, $url ) {
        $site = get_bloginfo( 'name' );
        $date = get_the_date( '', $post );

        $vars_html = array(
            '{{title}}'   => esc_html( $title ),
            '{{excerpt}}' => esc_html( $excerpt ),
            '{{image}}'   => $image_url ? esc_url( $image_url ) : '',
            '{{url}}'     => esc_url( $url ),
            '{{date}}'    => esc_html( $date ),
            '{{site}}'    => esc_html( $site ),
        );

        $vars_text = array(
            '{{title}}'   => $title,
            '{{excerpt}}' => $excerpt,
            '{{image}}'   => $image_url,
            '{{url}}'     => $url,
            '{{date}}'    => $date,
            '{{site}}'    => $site,
        );

        if ( defined( 'KLAVIYO_NEWSLETTER_TEMPLATE_ID' ) && KLAVIYO_NEWSLETTER_TEMPLATE_ID ) {
            $master = hge_klaviyo_api_request( 'GET', '/api/templates/' . rawurlencode( (string) KLAVIYO_NEWSLETTER_TEMPLATE_ID ) . '/' );
            if ( is_wp_error( $master ) ) {
                throw new RuntimeException( 'fetch_template: ' . $master->get_error_message() );
            }
            $master_html = isset( $master['data']['attributes']['html'] ) ? (string) $master['data']['attributes']['html'] : '';
            $master_text = isset( $master['data']['attributes']['text'] ) ? (string) $master['data']['attributes']['text'] : '';
            if ( '' === trim( $master_html ) ) {
                throw new RuntimeException( 'fetch_template: empty html for template id ' . KLAVIYO_NEWSLETTER_TEMPLATE_ID );
            }
            $html = strtr( $master_html, $vars_html );
            $text = '' !== $master_text
                ? strtr( $master_text, $vars_text )
                : ( $title . "\n\n" . $excerpt . "\n\n" . $url );
            return array( 'html' => $html, 'text' => $text );
        }

        $html = hge_klaviyo_render_newsletter_html( $title, $excerpt, $image_url, $url );
        $text = $title . "\n\n" . $excerpt . "\n\n" . $url;
        return array( 'html' => $html, 'text' => $text );
    }
}

if ( ! function_exists( 'hge_klaviyo_render_newsletter_html' ) ) {
    function hge_klaviyo_render_newsletter_html( $title, $excerpt, $image_url, $url ) {
        $title_safe   = esc_html( $title );
        $excerpt_safe = esc_html( $excerpt );
        $image_safe   = $image_url ? esc_url( $image_url ) : '';
        $url_safe     = esc_url( $url );

        $image_block = '';
        if ( '' !== $image_safe ) {
            $image_block = '<tr><td style="padding:0;mso-line-height-rule:exactly;"><a href="' . $url_safe . '" target="_blank" style="display:block;text-decoration:none;"><img src="' . $image_safe . '" alt="' . $title_safe . '" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;"/></a></td></tr>';
        }

        $vml_button = '<!--[if mso]>'
            . '<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $url_safe . '" style="height:48px;v-text-anchor:middle;width:220px;" arcsize="13%" stroke="f" fillcolor="#c8102e">'
            . '<w:anchorlock/><center style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;">Citeste articolul</center>'
            . '</v:roundrect>'
            . '<![endif]-->';
        $html_button = '<!--[if !mso]><!-- --><a href="' . $url_safe . '" target="_blank" style="display:inline-block;background:#c8102e;color:#ffffff !important;text-decoration:none;font-weight:bold;padding:14px 28px;border-radius:6px;font-size:16px;font-family:Arial,Helvetica,sans-serif;mso-hide:all;">Citește articolul</a><!--<![endif]-->';

        $head = '<!doctype html>'
            . '<html lang="ro" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">'
            . '<head>'
            . '<meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta http-equiv="X-UA-Compatible" content="IE=edge">'
            . '<meta name="color-scheme" content="light dark">'
            . '<meta name="supported-color-schemes" content="light dark">'
            . '<title>' . $title_safe . '</title>'
            . '<!--[if mso]><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->'
            . '<style type="text/css">'
            . 'body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}'
            . 'table,td{mso-table-lspace:0pt;mso-table-rspace:0pt;}'
            . 'img{-ms-interpolation-mode:bicubic;border:0;height:auto;line-height:100%;outline:none;text-decoration:none;}'
            . 'table{border-collapse:collapse !important;}'
            . 'body{margin:0 !important;padding:0 !important;width:100% !important;}'
            . '@media screen and (max-width:600px){.container{width:100% !important;border-radius:0 !important;}.padded{padding:20px !important;}h1{font-size:22px !important;}}'
            . '@media (prefers-color-scheme: dark){.bg-page{background:#1a1a1a !important;}.bg-card{background:#2b2b2b !important;}.text-h1{color:#f5f5f5 !important;}.text-body{color:#dcdcdc !important;}.text-footer{color:#bdbdbd !important;}.bg-footer{background:#222 !important;}}'
            . '[data-ogsc] .bg-page{background:#1a1a1a !important;}[data-ogsc] .bg-card{background:#2b2b2b !important;}[data-ogsc] .text-h1{color:#f5f5f5 !important;}[data-ogsc] .text-body{color:#dcdcdc !important;}'
            . '</style>'
            . '</head>';

        $preheader = '<div style="display:none;max-height:0;overflow:hidden;font-size:1px;line-height:1px;color:#f4f4f4;opacity:0;">' . $excerpt_safe . '</div>';

        /**
         * Brand label rendered in the email footer next to the Klaviyo
         * {% unsubscribe %} merge tag. Default uses the WP site name so the
         * email automatically picks up whatever blog name is configured.
         *
         * @since 3.0.1
         * @param string $brand   Default brand label.
         * @param WP_Post $post   The post being dispatched.
         */
        $brand = (string) apply_filters(
            'hge_klaviyo_email_footer_brand',
            wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
            $post
        );
        $brand_safe  = esc_html( $brand );
        $footer_html = ( '' !== $brand_safe ) ? ( $brand_safe . ' — {% unsubscribe %}' ) : '{% unsubscribe %}';

        return $head
            . '<body class="bg-page" style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;color:#222;">'
            . $preheader
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-page" style="background:#f4f4f4;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="container bg-card" style="background:#ffffff;max-width:600px;width:100%;border-radius:8px;overflow:hidden;">'
            . $image_block
            . '<tr><td class="padded" style="padding:28px 28px 8px 28px;"><h1 class="text-h1" style="margin:0;font-size:24px;line-height:1.3;color:#111;font-family:Arial,Helvetica,sans-serif;">' . $title_safe . '</h1></td></tr>'
            . '<tr><td class="padded text-body" style="padding:8px 28px 24px 28px;font-size:16px;line-height:1.5;color:#333;font-family:Arial,Helvetica,sans-serif;">' . $excerpt_safe . '</td></tr>'
            . '<tr><td align="center" class="padded" style="padding:8px 28px 32px 28px;">' . $vml_button . $html_button . '</td></tr>'
            . '<tr><td class="bg-footer text-footer" style="padding:16px 28px;background:#fafafa;font-size:12px;color:#666;text-align:center;font-family:Arial,Helvetica,sans-serif;">' . $footer_html . '</td></tr>'
            . '</table></td></tr></table>'
            . '</body></html>';
    }
}

// Marker pentru theme legacy: dacă e definit, blocul Klaviyo NL din functions.php se dezactivează.
if ( ! defined( 'HGE_KLAVIYO_NL_DISPATCHER_LOADED' ) ) {
    define( 'HGE_KLAVIYO_NL_DISPATCHER_LOADED', true );
}
