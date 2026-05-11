# HgE Klaviyo Newsletter

Auto-trigger Klaviyo email campaigns from tagged WordPress posts. UI-driven, deliverability-hardened, designed to act as the **Free base** of a Free + Pro split (Pro extension distributed independently).

**Version 3.0.4** — current release. See [`CHANGELOG.md`](CHANGELOG.md) for the full version history; the table below summarises the relevant entries since v2.0.0.

| Version | Highlights |
|---------|------------|
| **3.0.4** | Docs consolidation — `readme.txt` removed; `README.md` is now the single source of truth. WP.org submission would require regenerating `readme.txt` from this file. |
| **3.0.3** | Klaviyo Segments appear alongside Lists in Recipient / Excluded selectors (grouped via `<optgroup>`). Cross-exclude UX disables an audience in one select when it's picked in the other. New helper `hge_klaviyo_api_list_segments()`. |
| **3.0.2** | Translation-ready. All admin UI strings wrapped in `__()` / `esc_html__()` / `_n()` with text domain `hge-klaviyo-newsletter`. Ships a `.pot` template (~160 entries) + Romanian `.po` for back-compat with pre-3.0.1 UX. |
| **3.0.1** | Branding neutralised — the two `FC Rapid 1923` literals are now filterable (`hge_klaviyo_safe_subject_fallback`, `hge_klaviyo_email_footer_brand`); defaults derive from `get_bloginfo('name')`. New-install seed values: `tag_slug='newsletter'`, `web_feed_name='newsletter_feed'`. |
| **3.0.0** | **BREAKING** — newsletter config rewritten as a tag-rules cards system (`tag_rules` array). Free 1 rule, Core 2, Pro 5 (with optional comma-separated multi-tag). Per-rule cooldown + per-rule Web Feed (`?name=`). Top-level v2.x keys silently dropped at read time. New filter `hge_klaviyo_nl_matching_rule`. See **Upgrade notes** below for the migration path. |
| 2.4.1 | Klaviyo API revision 2024-10-15 rejects `additional-fields[list]=profile_count` (HTTP 400); subscriber count is now opt-in via the `hge_klaviyo_lists_extra_query` filter for sites on Klaviyo accounts/revisions where the parameter is accepted. |
| 2.4.0 | Subscriber counts initial implementation (rolled back to opt-in in 2.4.1 due to Klaviyo API revision incompatibility). |
| 2.3.1 | Klaviyo Templates API page-size fix (`100` → `10`); friendly RO error notices for Klaviyo HTTP failures; auto-flush API cache on plugin upgrade. |
| 2.3.0 | Romanian tab labels (`Setări` / `Licență Pro` / `Status`); new `debug_mode` setting gating the Status tab; full Klaviyo Master Template list gated to Pro plan only. |
| 2.2.0 | Settings extension hooks for the Pro feature modules (defaults, sanitize, save partial, render extra). |
| 2.1.0 | `hge_klaviyo_admin_tabs` filter + `hge_klaviyo_render_tab_<slug>` action; full hooks contract in `HOOKS.md`. |
| 2.0.1 | Klaviyo Lists API page-size fix (`100` → `10`). |
| 2.0.0 | All configuration moved to DB (Settings tab); `from_email` / `from_label` removed from payload (Klaviyo account default sender). | See [`CHANGELOG.md`](CHANGELOG.md) for the breaking-change list and migration notes from v1.x.

## What's in the box

- **Tag-rules cards UI** — define rules mapping a tag slug to audience + template config (1 rule on Free, 2 on Core, 5 on Pro with optional comma-separated multi-tag).
- Settings tab populated dynamically from your Klaviyo account (lists, templates, account default sender).
- Action Scheduler-native dispatching (works with `DISABLE_WP_CRON=true`).
- Five-layer duplicate-send prevention.
- Per-rule cooldown (default 12h).
- Built-in HTML template (Outlook + dark-mode hardened) **or** Klaviyo master template + per-rule Web Feed mode for fully dynamic emails.
- ASCII-safe subject line, deliverability-safe defaults.
- Secure JSON feed endpoints (`/feed/klaviyo.json`, `/feed/klaviyo-current.json?name=<feed>`) with `hash_equals` token authentication.
- Six extension filters for the Pro plugin to layer Tier 2 / Tier 3 features without touching base code (including the new `hge_klaviyo_nl_matching_rule`).

## Tier model (commercial)

| Plan | Distribution | Rule cap | Per-rule cap |
|------|-------------|----------|--------------|
| **Free** | This plugin (`hge-klaviyo-newsletter`) | 1 rule | 1 included list, 0 excluded, built-in HTML template only |
| **Core** | Pro extension plugin, license-gated to "Core" plan | 2 rules | 1 included + 1 excluded list, full template selector, Web Feed |
| **Pro**  | Pro extension plugin, license-gated to "Pro" plan | 5 rules | Up to 15 lists per rule (Klaviyo limit), comma-separated multi-tag, all Tier-3 features |

The Free plugin works standalone. When the Pro plugin is active and licensed, additional UI controls and dispatch behaviour activate via `apply_filters` hooks.

## Requirements

- WordPress ≥ 6.0
- PHP ≥ 8.0
- WooCommerce active (Action Scheduler dependency)
- Klaviyo account with a Private API key (scopes: `campaigns:write`, `templates:write`, `lists:read`, `segments:read`)

## Installation

1. Drop the plugin folder into `wp-content/plugins/hge-klaviyo-newsletter/` (or upload the zip via **WP Admin → Plugins → Add New**).
2. Activate the plugin in **WP Admin → Plugins**.
3. Go to **Tools → Klaviyo Newsletter → Settings** and fill in the **General settings** section:
   - **Klaviyo API Key** — Private API key from your Klaviyo account.
   - **Feed Token** — 32+ character random string. Generate with `openssl rand -hex 32`.
   - (Optional) **Reply-to** — override the Klaviyo account default reply-to.
   - **Minimum interval between sends** — cooldown in hours (default 12), applied per rule.
4. In the **Newsletter rules** section, configure at least one rule:
   - **Trigger tag** — tag slug that triggers this rule (default: `newsletter`).
   - **Recipient list(s)** — Klaviyo list(s) / segment(s) for the campaign audience.
   - (Core+) **Excluded list(s)** — Klaviyo lists / segments to subtract from the audience.
   - (Pro) **Klaviyo template** + **Web Feed mode** — fully dynamic content with per-rule feed URL.
5. Tag posts with the configured slug to trigger newsletter campaigns automatically. First matching rule wins (rules evaluated in card order). The plugin uses Klaviyo's account default sender for the from address and label.

## FAQ

**Does this work with `DISABLE_WP_CRON`?**
Yes. Action Scheduler runs through its own loopback HTTP queue runner, independent of wp-cron. WooCommerce ships Action Scheduler.

**Will publishing many posts at once spam my list?**
No. The cooldown chains sends sequentially: 5 quick publications result in sends spaced at the configured interval (default 12h), applied per rule.

**How do I prevent duplicate sends?**
Five protection layers: post meta `_klaviyo_campaign_sent`, Action Scheduler dedup, atomic post-meta lock, campaign-id idempotency check, re-validation at dispatch.

**Where do the from email and from label come from?**
Klaviyo's account default sender. The plugin doesn't hardcode them; configure them in your Klaviyo account (Settings → Brand). Override only the reply-to address from the plugin's Settings tab if needed.

**Can I migrate from a wp-config-based v1.x setup?**
Yes. On activation the plugin migrates `KLAVIYO_API_PRIVATE_KEY`, `KLAVIYO_NEWSLETTER_LIST_ID`, `KLAVIYO_FEED_TOKEN`, etc. from `wp-config.php` into the database options. The constants remain as a read-only fallback. After confirming Settings shows the migrated values, you can remove them from `wp-config.php` manually.

**Can I send to multiple lists / segments?**
Not in the Free plugin (1 audience per rule). The Pro extension supports up to 15 included + excluded audiences combined per rule (Klaviyo per-campaign limit).

**Can I have multiple rules (different tags → different audiences)?**
Free is capped at 1 rule. Core lifts the cap to 2 rules; Pro allows up to 5 rules. Pro additionally lets each rule fire on multiple tags separated by commas (e.g., `stiri,promo,events` — OR semantics: any tag in the list fires the rule).

**I see "0 templates" in Settings even though I have templates in Klaviyo.**
Three things to verify, in order:
1. Open <https://www.klaviyo.com/email-templates> — confirm at least one template exists.
2. Confirm your Klaviyo API key has the `templates:read` (or `templates:write`) scope. Without it, the API returns 0 results silently.
3. Click `Reload from Klaviyo` in the Settings tab to bypass the 5-minute transient cache.

If the count remains 0 after all three, enable **Debug mode** in Settings and inspect the raw API response under the **Status** tab.

**Why does my admin show an HTTP 401 / 403 / 429 error from Klaviyo?**
Since v2.3.1, raw Klaviyo API errors are translated into short admin notices with action steps. The full raw error is logged to `wp-content/debug.log` if `WP_DEBUG_LOG` is on.

## Privacy

This plugin sends **post titles, excerpts, featured images and post URLs** to Klaviyo over HTTPS. No subscriber data is collected by the plugin itself; subscribers are managed entirely in your Klaviyo account.

## Configuration source of truth

Since v3.0.0, configuration lives in the database under a single autoload-disabled option key, with newsletter rules nested under `tag_rules`:

```
option_name:  hge_klaviyo_nl_settings
shape: array(
    'api_key'             => string,
    'feed_token'          => string,
    'reply_to_email'      => string,        // empty = use Klaviyo default
    'min_interval_hours'  => int,           // cooldown per rule
    'debug_mode'          => bool,
    'tag_rules'           => array<int, array{
        tag_slug:          string,   // single slug for Free/Core; comma-separated allowed for Pro
        included_list_ids: array<string>,
        excluded_list_ids: array<string>,
        template_id:       string,
        use_web_feed:      bool,
        web_feed_name:     string,
    }>,
)
```

Per-rule send timestamps live in a separate option `hge_klaviyo_last_send_at_by_slug` keyed by `tag_slug` (cooldown survives rule reorder).

`from_email` and `from_label` are **not** stored — Klaviyo uses the account default sender (Klaviyo → Settings → Brand).

`KLAVIYO_API_PRIVATE_KEY`, `KLAVIYO_FEED_TOKEN`, etc. constants in `wp-config.php` continue to work as a read-only fallback for upgrades from v1.x. A one-shot migration on activation copies them into the DB option. After confirming Settings shows the right values, you can remove them from `wp-config.php`.

## Architecture

```
Post published with the configured tag (default "newsletter")
        │
        ▼
transition_post_status / save_post_post hook
        │
        ▼
hge_klaviyo_maybe_enqueue($post)
   ├── checks: is post, is publish, has tag, not already sent
   └── enqueue Action Scheduler action (now or now+cooldown)
        │
        ▼ (Action Scheduler runs this at scheduled time)
hge_klaviyo_dispatch_newsletter($post_id)
   ├── lock acquire (atomic post-meta unique=true)
   ├── re-validate, idempotency check
   ├── prepare title/excerpt/image/UTM URL
   ├── build campaign payload:
   │     audiences via filter `hge_klaviyo_audience_included/excluded`
   │     send_strategy via filter `hge_klaviyo_send_strategy`
   │     content via filter `hge_klaviyo_message_content`
   │     full payload via filter `hge_klaviyo_campaign_payload`
   ├── POST /api/templates/ (or assign master template in web-feed mode)
   ├── POST /api/campaigns/
   ├── POST /api/campaign-message-assign-template/
   ├── POST /api/campaign-send-jobs/
   └── update_post_meta + lock release
        │
        ▼
Klaviyo handles fanout to subscribers (default sender from account)
```

### Files

```
hge-klaviyo-newsletter/
├── hge-klaviyo-newsletter.php   Main plugin file (header + bootstrap + activation hooks)
├── README.md                    This file (GitHub-format docs — also displayed in WP admin)
├── CHANGELOG.md                 Iteration history (Keep a Changelog format)
├── HOOKS.md                     Canonical filters/actions contract for the Pro extension
├── TESTING.md                   Staging-test runbook
├── LICENSE                      GPLv2 text
├── uninstall.php                OPT-IN cleanup (see HGE_KLAVIYO_NL_FULL_UNINSTALL)
├── .gitignore
├── .github/workflows/           PHP lint matrix + i18n .pot regeneration
├── bin/                         extract-pot.py + build-ro-po.py (wp-cli-free alternatives)
├── languages/                   .pot template + bundled .po translations
└── includes/
    ├── config.php               Constants + helpers (use_web_feed, safe_subject, etc.)
    ├── tier.php                 Free's view of the Pro extension (is_pro_active, active_plan, upgrade CTAs)
    ├── settings.php             DB schema, sanitizers, migration shim, resolver helpers
    ├── api-client.php           list_lists / list_templates with transient cache
    ├── dispatcher.php           transition_post_status hook → Action Scheduler → Klaviyo Campaigns API
    ├── feed-endpoints.php       /feed/klaviyo.json + /feed/klaviyo-current.json handlers
    ├── admin.php                Tools page (Diagnostic + Settings tabs), post meta box, admin-post handlers
    └── activation.php           Activation/deactivation hooks + WC dependency check + migration trigger
```

## Public API for the Pro extension

See **[`HOOKS.md`](HOOKS.md)** for the canonical hooks contract (11 filters + 2 actions, with full call signatures, since-versions, and Pro-side consumers documented).

Quick summary — the Pro plugin layers Tier 2 / Tier 3 behaviour by hooking these filters:

| Filter | Args | Purpose |
|--------|------|---------|
| `hge_klaviyo_audience_included` | `array $list_ids, int $post_id, array $settings` | Override the included audience array (Tier 3 multi-list) |
| `hge_klaviyo_audience_excluded` | `array $list_ids, int $post_id, array $settings` | Override the excluded audience array (Tier 2 exclude unsubscribed; Tier 3 multi-exclude) |
| `hge_klaviyo_send_strategy` | `array $strategy, int $post_id, array $settings` | Replace the `immediate` / `static_time` strategy (Tier 2 delay window; Tier 3 smart_send_time) |
| `hge_klaviyo_message_content` | `array $content, int $post_id, array $settings` | Replace subject / preview_text / reply_to / from_email / from_label (Tier 2 dynamic) |
| `hge_klaviyo_campaign_payload` | `array $payload, int $post_id, array $settings` | Final mutation point for the campaign creation payload |

The Free plugin tier helpers also expose:

```php
hge_klaviyo_is_pro_active()           // bool — Pro plugin loaded?
hge_klaviyo_active_plan()             // 'free' | 'core' | 'pro'
hge_klaviyo_upgrade_cta_html('pro')   // HTML badge "Available in Pro plan"
```

## WordPress configuration filters

```php
// Override excerpt length used in the email body (default: 120)
add_filter( 'hge_klaviyo_excerpt_length', fn() => 200 );

// Override subject length (default: 60, ASCII-safe)
add_filter( 'hge_klaviyo_subject_length', fn() => 80 );
```

## Security

- **Input sanitization** at the boundary — `sanitize_text_field`, `sanitize_email`, `sanitize_key`, plus a custom whitelist regex `[^A-Za-z0-9_\-]` for Klaviyo IDs.
- **CSRF on every state-changing URL** — `wp_nonce_url` + `wp_nonce_field` + `check_admin_referer`.
- **Capability gates** — every admin handler requires `manage_options`.
- **Output escaping** — `esc_html`, `esc_url`, `esc_attr` consistently.
- **Timing-safe token comparison** — `hash_equals` on feed endpoints.
- **No raw SQL** — uses WordPress API exclusively (`update_option`, `$wpdb->delete` only in `uninstall.php` with format specifiers).
- **HTTP method restriction** — feed endpoints reject anything except `GET`/`HEAD`.
- **`X-Robots-Tag: noindex, nofollow`** on feed responses.
- **Settings option** uses `autoload=false` — not loaded on every page request.

## License

GPLv2 or later. See main plugin file for the license header.

## Support / Issues

Report issues at <https://github.com/strictly4U/hge-klaviyo-newsletter/issues>.
