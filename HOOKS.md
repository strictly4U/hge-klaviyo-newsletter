# Hooks API — HgE Klaviyo Newsletter (Free) + Pro extension

This document is the **canonical contract** between the Free base plugin and:
- The Pro extension plugin (`hge-klaviyo-newsletter-pro`)
- Third-party site customisations (themes, must-use plugins)

The Free plugin guarantees that every hook listed here will keep the same name, signature, and timing across **minor versions** (semver). Breaking changes are bundled into major releases (`3.0.0` etc.) and flagged in `CHANGELOG.md` under the **BREAKING** section.

> Quick reference: filters are at the top, actions follow. Each entry lists the file/line of the call site so callers can find the exact place where data flows through.

## Schema note (since 3.0.0)

The DB-backed settings option `hge_klaviyo_nl_settings` now holds a `tag_rules` array — each rule maps a tag to a list/template config. Hooks that operate on settings (`hge_klaviyo_nl_settings_defaults`, `hge_klaviyo_nl_sanitize_settings`, `hge_klaviyo_settings_save_partial`) should preserve the `tag_rules` shape and respect the tier caps from `hge_klaviyo_nl_rule_caps()`. Hooks that operate on a matched rule (`hge_klaviyo_nl_matching_rule`, `hge_klaviyo_audience_*`) receive the resolved rule from `hge_klaviyo_nl_get_matching_rule()`.

---

## Filters (12 in Free, 2 in Pro)

### `hge_klaviyo_nl_settings_defaults`

**Type**: filter
**Source**: `includes/settings.php` — `hge_klaviyo_nl_settings_defaults()`
**Since**: Free 2.2.0

Lets feature modules register additional settings keys with defaults. Used by Pro Tier 2 modules to declare config they need (e.g., `send_delay_minutes`, `send_window_start_hour`).

```php
add_filter( 'hge_klaviyo_nl_settings_defaults', function ( $defaults ) {
    $defaults['my_extra_key'] = '';
    return $defaults;
} );
```

---

### `hge_klaviyo_nl_sanitize_settings`

**Type**: filter
**Source**: `includes/settings.php` — applied at end of `hge_klaviyo_nl_sanitize_settings()`
**Since**: Free 2.2.0

Receives `( array $out, array $input )`. Lets modules sanitise their custom fields after the core fields are processed.

```php
add_filter( 'hge_klaviyo_nl_sanitize_settings', function ( $out, $input ) {
    if ( isset( $input['my_extra_key'] ) ) {
        $out['my_extra_key'] = sanitize_text_field( $input['my_extra_key'] );
    }
    return $out;
}, 10, 2 );
```

---

### `hge_klaviyo_settings_save_partial`

**Type**: filter
**Source**: `includes/admin.php` — `hge_klaviyo_handle_save_settings()`
**Since**: Free 2.2.0

Receives `( array $partial, array $input )`. Lets modules pull their POST keys into the persist payload before `hge_klaviyo_nl_update_settings()` is called.

---

### `hge_klaviyo_lists_extra_query`

**Type**: filter
**Source**: `includes/api-client.php` — `hge_klaviyo_api_list_lists()`
**Since**: Free 2.4.1

Appends extra query-string parameters to `GET /api/lists/`. Default value is an empty array. Used to opt into Klaviyo's `additional-fields[list]=profile_count` (which the 2024-10-15 revision rejects by default — see CHANGELOG 2.4.1).

```php
add_filter( 'hge_klaviyo_lists_extra_query', function ( $extra ) {
    $extra['additional-fields[list]'] = 'profile_count';
    return $extra;
} );
```

The result is URL-encoded with `http_build_query( ..., PHP_QUERY_RFC3986 )` before being appended to the request URL.

---

### `hge_klaviyo_nl_matching_rule`

**Type**: filter
**Source**: `includes/settings.php` — end of `hge_klaviyo_nl_get_matching_rule()`
**Since**: Free 3.0.0

Receives `( array|null $matched, WP_Post $post, array $rules )`. Lets Pro / theme code override the default first-tag-wins resolution — useful for AND semantics, custom scoring, or matching against a taxonomy other than `post_tag`.

The default `$matched` is either:
- `null` — no configured rule's `tag_slug` is present on `$post` (split on comma for Pro), or
- The matched rule array, augmented with two internal keys:
  - `_rule_idx` — zero-based index in `$rules` (priority).
  - `_rule_tag_matched` — the specific tag slug that matched (relevant for Pro multi-tag rules).

```php
// Example: skip a rule if the post is in category "sponsored".
add_filter( 'hge_klaviyo_nl_matching_rule', function ( $matched, $post, $rules ) {
    if ( $matched && in_category( 'sponsored', $post ) ) {
        return null; // suppress the campaign for this post
    }
    return $matched;
}, 10, 3 );
```

**Used by**: Pro `multi-tag-rule.php` reads `_rule_tag_matched` to persist a per-tag attribution post meta (`_klaviyo_campaign_rule_matched_tag`).

---

### `hge_klaviyo_safe_subject_fallback`

**Type**: filter
**Source**: `includes/config.php` — `hge_klaviyo_safe_subject()`
**Since**: Free 3.0.1

Receives `( string $fallback )`. The default fallback is built from the WP site name (`get_bloginfo( 'name' )`) stripped to printable ASCII and prefixed with `"Newsletter "`. Override to hardcode a brand string, e.g. for sites that want a constant subject when a post is published without a title.

```php
add_filter( 'hge_klaviyo_safe_subject_fallback', function () {
    return 'Acme — Daily Update';
} );
```

The fallback is only used when the post title is empty / unprintable after sanitisation. Posts with valid titles bypass this filter.

---

### `hge_klaviyo_email_footer_brand`

**Type**: filter
**Source**: `includes/dispatcher.php` — `hge_klaviyo_build_email_body()`
**Since**: Free 3.0.1

Receives `( string $brand, WP_Post $post )`. The default brand label is the WP site name (`get_bloginfo( 'name' )`). The rendered footer is `"<brand> — {% unsubscribe %}"`; when the filter returns an empty string the email shows only the Klaviyo `{% unsubscribe %}` merge tag.

```php
add_filter( 'hge_klaviyo_email_footer_brand', function ( $brand, $post ) {
    return 'Acme Sports — Powered by HgE';
}, 10, 2 );
```

Use this on the FC Rapid 1923 production site (or any host that wants a fixed sender label) to keep the original branding even after upgrading to the neutralised plugin baseline.

---

### `hge_klaviyo_admin_tabs`

**Type**: filter
**Source**: `includes/admin.php` — `hge_klaviyo_render_tools_page()`
**Since**: Free 2.1.0

Lets external code register additional tabs on **Tools → Klaviyo Newsletter**.

```php
$tabs = apply_filters( 'hge_klaviyo_admin_tabs', array(
    'diagnostic' => 'Diagnostic',
    'settings'   => 'Settings',
) );
```

**Return value**: `array<string,string>` — keys are tab slugs (used in `?tab=<slug>`), values are display labels.

**Used by**: Pro plugin's License Manager registers `'license' => 'License'`.

```php
add_filter( 'hge_klaviyo_admin_tabs', function ( $tabs ) {
    $tabs['license'] = 'License';
    return $tabs;
} );
```

---

### `hge_klaviyo_admin_notice_messages`

**Type**: filter
**Source**: `includes/admin.php` — `hge_klaviyo_admin_notices()`
**Since**: Free 2.0.0

Adds entries to the admin notice dispatcher. Each entry is `array( $type, $text )` keyed by the `klaviyo_msg` query argument.

```php
$messages = apply_filters( 'hge_klaviyo_admin_notice_messages', array(
    'klaviyo_sent'  => array( 'success', 'Newsletter trimis...' ),
    // ...
) );
```

**Add a custom message**: redirect with `?klaviyo_msg=my_event` then register the message text via this filter.

---

### `hge_klaviyo_excerpt_length`

**Type**: filter
**Source**: `includes/dispatcher.php`, `includes/feed-endpoints.php`, `includes/admin.php`
**Since**: Free 1.0.0

Override the maximum excerpt length used in email body, JSON feeds, and the admin diagnostic display.

```php
$max = (int) apply_filters( 'hge_klaviyo_excerpt_length', 120 );
```

**Default**: 120 characters.

---

### `hge_klaviyo_subject_length`

**Type**: filter
**Source**: `includes/config.php` — `hge_klaviyo_safe_subject()`, also referenced in `admin.php`
**Since**: Free 1.0.0

Override the maximum subject line length. The plugin still strips diacritics and non-ASCII before truncating.

```php
$max = (int) apply_filters( 'hge_klaviyo_subject_length', 60 );
```

**Default**: 60 characters.

---

### `hge_klaviyo_audience_included`

**Type**: filter
**Source**: `includes/dispatcher.php` — `hge_klaviyo_dispatch_newsletter()`
**Since**: Free 2.0.0

Last-mile mutation of the **included** audience list IDs sent to Klaviyo's Campaigns API.

```php
$audience_included = apply_filters(
    'hge_klaviyo_audience_included',
    array_values( array_map( 'strval', $included ) ),  // from settings
    $post_id,
    $settings
);
```

| Arg | Type | Notes |
|-----|------|-------|
| `$audience_included` | `array<string>` | Klaviyo list IDs |
| `$post_id` | `int` | The post about to be sent |
| `$settings` | `array` | Full settings array (`hge_klaviyo_nl_get_settings()`) |

**Pro plugin uses this for**: multi-list (Tier 3) — replaces the single-list array with up to 15 lists from the Pro Settings UI.

---

### `hge_klaviyo_audience_excluded`

**Type**: filter
**Source**: `includes/dispatcher.php`
**Since**: Free 2.0.0

Mutation of the **excluded** audience list IDs.

```php
$audience_excluded = apply_filters(
    'hge_klaviyo_audience_excluded',
    array_values( array_map( 'strval', $excluded ) ),
    $post_id,
    $settings
);
```

**Pro plugin uses this for**: auto-exclude unsubscribed (Tier 2), multi-exclude (Tier 3).

**Klaviyo limit**: combined `included + excluded ≤ 15`. Free's sanitiser enforces this; Pro filters must respect it (it's still validated server-side by Klaviyo, but a polite client trims first).

---

### `hge_klaviyo_send_strategy`

**Type**: filter
**Source**: `includes/dispatcher.php`
**Since**: Free 2.0.0

Replace the `send_strategy` block in the campaign payload. Free emits one of:

```php
// immediate
array( 'method' => 'immediate' )

// or static-time (12h cooldown chained)
array(
    'method'         => 'static_time',
    'datetime'       => '2026-05-04T22:00:00+00:00',
    'options_static' => array( 'is_local' => false, 'send_past_recipients_immediately' => false ),
)
```

**Pro plugin uses this for**:
- Tier 2: **delay window** (e.g., delay 30 min, send only 08:00–22:00) — replaces `immediate` with `static_time` shifted to the next valid window slot.
- Tier 3: `smart_send_time` strategy (Klaviyo per-recipient optimisation).

**Args**: `( array $strategy, int $post_id, array $settings )`.

---

### `hge_klaviyo_message_content`

**Type**: filter
**Source**: `includes/dispatcher.php`
**Since**: Free 2.0.0

Replace the `content` sub-array of the campaign-message attributes.

Free emits:
```php
array(
    'subject'        => 'ASCII-safe truncated title',
    'preview_text'   => 'first 120 chars of excerpt',
    // 'reply_to_email' => 'only if configured in Settings',
    // from_email + from_label intentionally omitted — Klaviyo uses account default sender
)
```

**Pro plugin uses this for**:
- Tier 2: manual subject override per post.
- Tier 3: A/B testing — emit `subject_a` + `subject_b` arrays.

**Args**: `( array $content, int $post_id, array $settings )`.

---

### `hge_klaviyo_campaign_payload`

**Type**: filter
**Source**: `includes/dispatcher.php` — applied last, just before `POST /api/campaigns/`
**Since**: Free 2.0.0

Final mutation point. Receives the **entire JSON:API payload** about to be POSTed to Klaviyo.

**Use this filter only when** the more granular filters (audiences / strategy / content) don't reach the field you need (e.g., adding `tracking_options.utm_*`, `send_options.is_tracking_clicks_to_external_url`, custom Klaviyo flow attribution).

**Args**: `( array $payload, int $post_id, array $settings )`.

```php
add_filter( 'hge_klaviyo_campaign_payload', function ( $payload, $post_id, $settings ) {
    $payload['data']['attributes']['tracking_options']['add_tracking_params'] = true;
    return $payload;
}, 10, 3 );
```

---

### Pro filters

#### `hge_klaviyo_pro_feature_registry`

**Source**: `includes/tier-manager.php`
**Since**: Pro 1.0.0

Lets future modules register additional gated features without modifying the core registry.

```php
$registry = apply_filters( 'hge_klaviyo_pro_feature_registry', array(
    'delay_window' => 'core',
    'multi_list'   => 'pro',
    // ...
) );
```

**Return value**: `array<string,string>` — `feature_key => 'core' | 'pro'`.

#### `hge_klaviyo_pro_webhook_client_ip`

**Source**: `includes/class-hge-klaviyo-pro-license-webhook.php`
**Since**: Pro 1.0.0

Override the IP used for rate-limiting on `POST /wp-json/hge-klaviyo-pro/v1/license-webhook`. Useful when the WP install sits behind Cloudflare / a reverse proxy (where `REMOTE_ADDR` is the proxy, not the real client).

```php
add_filter( 'hge_klaviyo_pro_webhook_client_ip', function ( $ip ) {
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip;
} );
```

---

## Actions (1 in Free, 1 in Pro)

### `hge_klaviyo_render_settings_extra`

**Type**: action
**Source**: `includes/admin.php` — fired inside the Settings tab `<form>`, just before the submit button
**Since**: Free 2.2.0

Lets feature modules render extra settings sections (their own `<table class="form-table">` blocks) inside the same form. Persistence is wired via `hge_klaviyo_settings_save_partial` + `hge_klaviyo_nl_sanitize_settings`.

```php
add_action( 'hge_klaviyo_render_settings_extra', function ( $s ) {
    if ( ! hge_klaviyo_pro_has_feature( 'my_feature' ) ) {
        return;
    }
    echo '<h3>My Tier 2 Feature</h3><table class="form-table">…</table>';
} );
```

---

### `hge_klaviyo_render_tab_<slug>`

**Type**: action
**Source**: `includes/admin.php`
**Since**: Free 2.1.0

Fired when the Tools page renders an **externally registered** tab (one added via `hge_klaviyo_admin_tabs`). The slug from the registered tab is appended to the action name.

```php
do_action( 'hge_klaviyo_render_tab_' . $active_tab );
```

**Pro plugin uses this for**: rendering the License tab body.

```php
add_action( 'hge_klaviyo_render_tab_license', array( 'HGE_Klaviyo_Pro_License_Manager', 'render_license_tab' ) );
```

The renderer must escape its own output and check `current_user_can( 'manage_options' )` itself — the Free plugin only routes the request, it does not enforce capabilities for external tabs.

---

### `hge_klaviyo_pro_license_webhook_received`

**Type**: action
**Source**: `includes/class-hge-klaviyo-pro-license-webhook.php`
**Since**: Pro 1.0.0

Fires after a license webhook is authenticated and applied. Lets feature modules react (e.g., flush feature module caches when the plan changes from Core to Pro).

```php
do_action( 'hge_klaviyo_pro_license_webhook_received', $event, $payload, $applied );
```

| Arg | Type | Notes |
|-----|------|-------|
| `$event` | `string` | One of `license.activated`, `license.reactivated`, `license.plan_changed`, `license.suspended`, `license.expired`, `license.payment_failed`, `license.deleted` |
| `$payload` | `array` | Full decoded JSON body sent by the license server |
| `$applied` | `array` | Summary of what changed locally (status, plan, reason) |

---

## Stability policy

| Hook namespace | Stability |
|----------------|-----------|
| `hge_klaviyo_*` (Free, no `pro`) | Stable. Signature changes only on major version bumps (`3.x`). |
| `hge_klaviyo_pro_*` (Pro) | Stable from Pro 1.0.0. Signature changes only on Pro major bumps. |
| Internal helpers prefixed with `_hge_*` (none currently) | Reserved for internal use; do not rely on them. |

When a hook is deprecated, the Free plugin will keep firing it for **two minor versions** while logging a `_doing_it_wrong` warning, then remove it on the third minor.

## Adding new hooks

Both plugins follow the same pattern when adding a hook:

1. Filter name uses underscores, lower-case, prefix `hge_klaviyo_` (Free) or `hge_klaviyo_pro_` (Pro).
2. Filters take the value as the first arg, then context args (post_id, settings, etc.).
3. Document the new hook in this file in the same format.
4. Add a `@since` line to the docblock at the call site.
5. Add a CHANGELOG.md entry under the next version's **Added** section.
