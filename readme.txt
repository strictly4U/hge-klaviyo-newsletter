=== HgE Klaviyo Newsletter ===
Contributors: hge
Tags: klaviyo, newsletter, email, woocommerce, action-scheduler
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 3.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auto-trigger Klaviyo email campaigns from tagged WordPress posts. UI-driven settings, list selection, secure JSON feeds, deliverability hardening.

== Description ==

When a WordPress post tagged with a configured slug (default: `newsletter`) is published, the plugin queues an Action Scheduler job that creates a Klaviyo campaign and launches a send-job — fully automatic, no manual steps.

**Tier 1 (Free) features**

* **Tag-based trigger via newsletter rules** — define one rule mapping a tag slug → audience list + (Pro) template. Free is capped at 1 rule; Core 2 rules; Pro 5 rules with optional multi-tag (comma-separated) per rule
* Single audience list per rule (selectable from the Settings tab — populated dynamically from your Klaviyo account via `GET /api/lists/`)
* Optional reply-to override (the from address and from label come from the Klaviyo account default sender — no hardcoding)
* Cooldown between sends, applied **per rule** (default 12h, configurable in `Setări`)
* Action Scheduler-native dispatching — works with `DISABLE_WP_CRON=true`
* Built-in HTML email template (Outlook + dark-mode hardened); customers on the Pro plan additionally get the full Klaviyo Master Template list with Web Feed mode for fully dynamic content
* Idempotent — five layers of duplicate-send prevention (sent meta, Action Scheduler dedup, atomic post-meta lock, campaign-id idempotency check, dispatch-time re-validation)
* ASCII-safe subject (diacritics stripped, max 60 chars by default)
* Smart Sending OFF for full-list delivery
* Tools page (Tools → Klaviyo Newsletter) with Romanian tabs: `Setări` (default), `Licență Pro` (when the Pro plugin is active), and `Status` (debug, gated by a setting)
* Per-post meta box on the editor screen (admins only)
* Secure JSON feed endpoints: `/feed/klaviyo.json` (top 8 articles, 5-min cache) and `/feed/klaviyo-current.json` (single active article for Web Feed mode)
* Token authentication on feed endpoints (constant-time `hash_equals`)
* Friendly Romanian admin notices for Klaviyo API failures (HTTP 401 / 403 / 429 / 5xx / network), with raw error preserved in `error_log`

**Pro plan (separate plugin, sold outside WordPress.org)**

The base Free plugin handles a single list and one campaign at a time. The Pro extension plugin (`hge-klaviyo-newsletter-pro`, distributed independently) adds:

* **Core plan** — delay window, multiple selectable templates, manual excerpt/image override, dynamic UTM, retry with backoff, DB log table, exclude unsubscribed
* **Pro plan** — multi-list (up to 15 included + excluded combined, Klaviyo limit), dynamic segments, A/B testing, multi-article digest, template builder, analytics dashboard, WooCommerce product cross-sell, editorial workflow, multi-site, dead queue

== Requirements ==

* WordPress 6.0 or higher
* PHP 8.0 or higher
* WooCommerce active (provides Action Scheduler)
* A Klaviyo account with a Private API key (scopes required: `campaigns:write`, `templates:write`, `lists:read`, `segments:read`)

== Installation ==

1. Upload the `hge-klaviyo-newsletter` folder to `/wp-content/plugins/` or install via the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** menu.
3. Navigate to **Tools → Klaviyo Newsletter → Settings** and fill in the **Setări generale** section:
   * **Klaviyo API Key** — the Private API key from your Klaviyo account.
   * **Feed Token** — a 32+ character random string. Generate with `openssl rand -hex 32`.
   * (Optional) **Reply-to** — override the Klaviyo account default reply-to.
   * **Pauză minimă între trimiteri** — cooldown in hours (default 12), applied per rule.
4. In the **Reguli newsletter** section, configure at least one rule:
   * **Tag declanșator** — tag slug that triggers this rule (default: `newsletter`).
   * **Listă(e) destinatari** — Klaviyo list(s) for the campaign audience.
   * (Core+) **Listă(e) excluse** — Klaviyo lists to subtract from the audience.
   * (Pro) **Template Klaviyo** + **Mod Web Feed** — fully dynamic content with per-rule feed URL.
5. Tag posts with the configured slug to trigger newsletter campaigns automatically. First matching rule wins (rules evaluated in card order). The plugin uses Klaviyo's account default sender for the from address and label.

== Frequently Asked Questions ==

= Does this work with DISABLE_WP_CRON? =

Yes. Action Scheduler runs through its own loopback HTTP queue runner, independent of wp-cron. WooCommerce ships Action Scheduler.

= Will publishing many posts at once spam my list? =

No. The cooldown chains sends sequentially: 5 quick publications result in sends spaced at the configured interval (default 12h).

= How do I prevent duplicate sends? =

Five protection layers: post meta `_klaviyo_campaign_sent`, Action Scheduler dedup, atomic post-meta lock, campaign-id idempotency check, re-validation at dispatch.

= Where does the from email and from label come from? =

Klaviyo's account default sender. The plugin no longer hardcodes these; you configure them in your Klaviyo account (Settings → Brand). Override the reply-to address in the plugin's Settings tab if needed.

= Can I migrate from a wp-config-based v1.x setup? =

Yes. On activation the plugin migrates `KLAVIYO_API_PRIVATE_KEY`, `KLAVIYO_NEWSLETTER_LIST_ID`, `KLAVIYO_FEED_TOKEN`, etc. from `wp-config.php` constants into the database options. The constants are kept as a read-only fallback. After confirming Settings shows the migrated values, you may remove them from `wp-config.php` manually.

= Can I send to multiple lists? =

Not in the Free plugin (1 list per rule). The Pro extension supports up to 15 included + excluded lists combined per rule (Klaviyo per-campaign limit).

= Can I have multiple rules (different tags → different lists)? =

Free is capped at 1 rule. The Core plan lifts the cap to 2 rules; Pro allows up to 5 rules. Pro additionally lets each rule fire on multiple tags separated by commas (e.g., `stiri,promo,events` — OR semantics: any tag in the list fires the rule).

= I see "0 templates" in Settings even though I have templates in Klaviyo. =

Three things to verify in order:

1. Open <https://www.klaviyo.com/email-templates> — confirm at least one template exists.
2. Confirm your Klaviyo API key has the `templates:read` (or `templates:write`) scope. Without it, the API returns 0 results silently.
3. Click `Reîncarcă din Klaviyo` in the Settings tab to bypass the 5-minute transient cache.

If the count remains 0 after all three, expand `Status → Răspuns server` (debug mode must be on) to see the raw API response.

= Why does my admin show a HTTP 401 / 403 / 429 error blob from Klaviyo? =

Starting with v2.3.1, raw Klaviyo API errors are translated into short Romanian admin notices with action steps. The full raw error is still recorded in `wp-content/debug.log` if `WP_DEBUG_LOG` is on.

= How is the active license plan detected? =

The Pro plugin's License Manager makes a `POST /api/v1/check` call to the license server. The plan is read from the response in this order:
1. Explicit `plan` / `tier` / `plan_tier` / `product_tier` / `level` field (recursive search through any nesting depth).
2. Suffix on `product_id` — `-pro` or `-core`.
3. Inferred from a `features[]` array (any Tier 3 feature flag implies Pro).

The result is stored in `hge_klaviyo_pro_license_plan` and read by `hge_klaviyo_active_plan()`.

== Privacy ==

This plugin sends post titles, excerpts, featured images and post URLs to Klaviyo over HTTPS. No subscriber data is collected by the plugin itself; subscribers are managed entirely in your Klaviyo account.

== Changelog ==

= 3.0.5 =
* Performance: Settings tab loads in ~3s on cold cache (was 10-15s). Three causes addressed:
   1. Templates API cache TTL extended to 1 hour (was 5 min) — templates rarely change, and the 56-template paginate was the bulk of cold-fetch time. Lists + segments cache TTL extended to 30 min.
   2. Cache no longer cleared on every plugin patch / minor version bump — only on **major** version bump. Patch/minor bumps don't change the API client behaviour, so keeping cache across them removes the cold-fetch storm after each upgrade.
   3. Cache no longer cleared on every Settings save — only when the **API key** actually changes. Rule edits, cooldown tweaks, etc. now leave the cache warm.
* Reverted: `readme.txt` restored as the WP-format readme; `README.md` removed. Single source of truth = `readme.txt` (WP.org-compatible).

= 3.0.4 =
* Removed: `readme.txt` (later reverted in 3.0.5 — see above).

= 3.0.3 =
* New: Klaviyo Segments appear in the recipient/excluded selectors alongside Lists (grouped in `<optgroup>`). Same Campaigns API payload — Klaviyo accepts segment IDs in `audiences.included` / `audiences.excluded` interchangeably with list IDs.
* New: cross-exclude UX — picking a list/segment as Included disables it in the Excluded select of the same card. Server-side fail-safe strips duplicates from Excluded on save.
* New: `hge_klaviyo_api_list_segments()` helper + `hge_klaviyo_segments_extra_query` filter (mirror of the Lists variant).
* Refresh counter in Settings now shows `N lists, M segments, K templates`.

= 3.0.2 =
* Translation-ready. All admin UI strings wrapped in `__()` / `esc_html__()` / `_n()` with text domain `hge-klaviyo-newsletter`. English is now the source language.
* New: `languages/hge-klaviyo-newsletter.pot` translation template (~160 entries) + `languages/hge-klaviyo-newsletter-ro_RO.po` Romanian translation (preserves pre-3.0.1 admin UX).
* New: `.github/workflows/i18n.yml` regenerates the `.pot` automatically via wp-cli on every push.
* New: `bin/extract-pot.py` + `bin/build-ro-po.py` — Python alternatives for contributors without wp-cli.

= 3.0.1 =
* Branding neutralised for public distribution. The two hardcoded `FC Rapid 1923` strings are now filterable: `hge_klaviyo_safe_subject_fallback` (empty-title fallback subject) and `hge_klaviyo_email_footer_brand` (built-in HTML template footer). Both default to `get_bloginfo('name')`.
* New install defaults: `tag_slug = 'newsletter'`, `web_feed_name = 'newsletter_feed'` (were `'trimitenl'` and `'fc_news'`). Existing installs keep their saved values.
* `'fc_news'` legacy literal is preserved in the per-feed transient-key resolver as back-compat for Klaviyo Web Feed URLs from the original deployment; marked `@legacy` in phpdoc.

= 3.0.0 =
* **Breaking:** newsletter configuration is now organised as **tag rules** instead of a single global list/template. Each rule maps `tag_slug` → audience list(s) → (Pro) template → (Pro) Web Feed config. Free is capped at 1 rule, Core 2, Pro 5.
* **Breaking:** top-level settings keys `included_list_ids`, `excluded_list_ids`, `template_id`, `use_web_feed`, `web_feed_name` are removed. Existing values are silently dropped on first read after upgrade (hard migration — no shim). Reconfigure your rule in **Setări → Reguli newsletter** immediately after upgrade.
* **Breaking:** cooldown is now per-rule (per tag_slug) instead of global. New option key `hge_klaviyo_last_send_at_by_slug` replaces `hge_klaviyo_last_send_at` for new sends; legacy value preserved for diagnostic.
* New: `Tools → Klaviyo Newsletter → Setări` UI rewritten as a cards system. Add / remove rule cards directly in the admin; client-side reindexing keeps the form payload gapless. The Add button is tier-gated and disabled when the cap is reached.
* New: per-rule Web Feed support. Each rule has its own `web_feed_name` and serves its active post on `/feed/klaviyo-current.json?key=<TOKEN>&name=<web_feed_name>`. Empty / `fc_news` names map to the legacy unkeyed URL for pre-v3.0 Klaviyo Web Feed configurations.
* New: Pro plan accepts comma-separated `tag_slug` per rule (OR semantics — any tag fires the rule). Free / Core remain single-tag per rule.
* New: filter `hge_klaviyo_nl_matching_rule( ?array $matched, WP_Post $post, array $rules )` — Pro / theme code can override the default first-tag-wins resolution.
* New: helpers `hge_klaviyo_nl_default_rule()`, `hge_klaviyo_nl_max_rules()`, `hge_klaviyo_nl_rule_caps()`, `hge_klaviyo_nl_supports_multi_tag_rule()`, `hge_klaviyo_nl_get_matching_rule()`, `hge_klaviyo_nl_compute_send_time_for_slug()`, `hge_klaviyo_nl_transient_key_for_feed()`, `hge_klaviyo_nl_all_feed_names()`.
* New: post meta `_klaviyo_campaign_rule_slug` records which rule fired for each campaign (for audit). Pro adds `_klaviyo_campaign_rule_matched_tag` for the specific tag that matched in a multi-tag rule.
* Deprecated: helpers `hge_klaviyo_use_web_feed()`, `hge_klaviyo_excluded_list_ids()`, `hge_klaviyo_compute_send_time()` — kept as thin wrappers for now; will be removed in a later major version.
* Internal: dispatcher signature is now `hge_klaviyo_dispatch_newsletter( int $post_id, string $tag_slug = '' )`. The optional `$tag_slug` carries the rule identity through Action Scheduler; when omitted the dispatcher re-resolves the matching rule from the post.

= 2.4.1 =
* Fixed: Klaviyo API revision 2024-10-15 returns `HTTP 400 Invalid input` when `additional-fields[list]=profile_count` is supplied on `GET /api/lists/`. The 2.4.0 implementation that always sent this parameter is rolled back.
* Changed: subscriber count is now opt-in via the `hge_klaviyo_lists_extra_query` filter. Sites on a Klaviyo revision that supports the parameter (later revisions, or accounts with the appropriate API key scopes) can re-enable counts in their `functions.php`:
   `add_filter( 'hge_klaviyo_lists_extra_query', function ( $extra ) { $extra['additional-fields[list]'] = 'profile_count'; return $extra; } );`
* Internal: Lists API URL is now built with `http_build_query( $query, '', '&', PHP_QUERY_RFC3986 )` so any future `extra_query` additions are URL-encoded correctly.

= 2.4.0 =
* New: subscriber counts shown next to each Klaviyo list in the `Listă(e) destinatari` and `Listă(e) excluse` dropdowns. Implementation uses Klaviyo's `additional-fields[list]=profile_count` query parameter — the count appears in the option label as `<list name> — <count> abonați (<list_id>)`.
* New: `hge_klaviyo_format_list_count( $count )` helper for locale-aware formatting (uses `number_format_i18n`, plural form `abonați` / `abonat`). Falls back gracefully to no suffix when the API doesn't include `profile_count`.
* Behaviour: API cache auto-flushes on the next admin pageview after upgrade so the new query parameter is sent immediately (no manual `Reîncarcă din Klaviyo` needed).

= 2.3.1 =
* Fixed: Klaviyo Templates API page size — was hitting `HTTP 400 Page size must be between 1 and 10`. Now uses `page[size]=10` (same hard cap as Lists API on revision 2024-10-15). Pagination guard raised to 50 pages × 10 = 500 templates.
* New: friendly Romanian admin notices for Klaviyo API errors (HTTP 401/403/429/5xx and network failures). Raw error blob still logged to `error_log` for debugging.
* New: separate template-error capture in Settings tab — if Lists API succeeds but Templates API fails, the error surfaces inline instead of being swallowed.
* New: helpful inline hint when the Klaviyo account legitimately has zero templates (links to Klaviyo Email Templates).
* New: one-shot API cache invalidation tied to plugin version (option `hge_klaviyo_nl_api_cache_codever`). Stale cache after a code update is now flushed automatically on the next admin pageview.

= 2.3.0 =
* Tab labels translated to Romanian and reordered: `Setări` (default landing tab) — `Licență Pro` (when the Pro extension registers it) — `Status` (only when debug mode is on).
* New setting: `debug_mode` (boolean, default off) — gates the visibility of the `Status` tab. Production sites no longer see a diagnostic tab cluttering the admin UI.
* New action `hge_klaviyo_render_status_extra` — Pro feature modules use it to render audit / debug widgets (webhook activity, raw server response) inside the Status tab.
* Klaviyo Master Template list now gated to the Pro plan only. Free / Core users continue to see the built-in template option (and any previously-saved template_id is preserved for backward compatibility).

= 2.2.0 =
* New extension hooks for Pro feature modules: `hge_klaviyo_nl_settings_defaults`, `hge_klaviyo_nl_sanitize_settings`, `hge_klaviyo_settings_save_partial`, `hge_klaviyo_render_settings_extra`. Pro Tier 2 modules (delay window, list validation) hook these to register settings keys, sanitise them, and render their UI sections inside the existing Settings form.

= 2.1.0 =
* New filter `hge_klaviyo_admin_tabs` and dynamic action `hge_klaviyo_render_tab_<slug>` — Pro plugin uses these to register a `License` tab without modifying base code.
* Documentation: full hooks contract published in `HOOKS.md` (10 Free hooks + 3 Pro hooks).

= 2.0.1 =
* Fixed: Klaviyo Lists API page size — was hitting `HTTP 400 Page size must be between 1 and 10`. Now uses `page[size]=10`.

= 2.0.0 =
* **Breaking:** all configuration moved from `wp-config.php` constants to the database (Settings tab). On first activation the plugin auto-migrates existing constants — they remain as a fallback so you can roll back, then remove them manually once you confirm Settings is correct.
* **Breaking:** `from_email` and `from_label` removed from the campaign payload — Klaviyo now uses your account default sender (Klaviyo Settings → Brand). Reply-to remains overridable from the plugin's Settings tab.
* New: **Tools → Klaviyo Newsletter → Settings** tab with native WP UI for API key, feed token, list selection (populated from `GET /api/lists/`), template selection (populated from `GET /api/templates/`), reply-to, web feed mode, and cooldown.
* New: tier-aware UI — single list in Free, multi-list (up to 15) when the Pro extension is active with the right plan.
* New: Klaviyo API list/template browsers with 5-minute transient cache and an explicit "Refresh from Klaviyo" button.
* New: extension filters (`hge_klaviyo_audience_included`, `hge_klaviyo_audience_excluded`, `hge_klaviyo_send_strategy`, `hge_klaviyo_message_content`, `hge_klaviyo_campaign_payload`) used by the Pro plugin to layer Tier 2 / Tier 3 features without modifying base.
* Improved: settings sanitization enforces Klaviyo's per-campaign limit (included + excluded ≤ 15).

= 1.0.0 =
* Initial release. Code extracted from a parent theme's `functions.php` into a standalone plugin. No behavioural changes vs the in-theme implementation.

== Upgrade Notice ==

= 3.0.5 =
Settings tab loads ~3-5× faster on cold cache (TTL extended, smarter invalidation). `readme.txt` restored as the canonical readme; `README.md` no longer ships. No DB schema change.

= 3.0.4 =
Withdrawn — removed `readme.txt` but the decision was reverted in 3.0.5. Skip directly to 3.0.5+.

= 3.0.3 =
Klaviyo Segments now appear alongside Lists in the Recipient / Excluded selectors. Cross-exclude UX prevents adding the same audience to both Included and Excluded in a single rule. No DB schema change; existing rules continue to work.

= 3.0.2 =
i18n release. Admin UI is now English-as-source with a bundled Romanian translation (`ro_RO.po`). Romanian sites keep their UX unchanged. Non-Romanian sites see English out of the box; copy the `.pot` and translate via Poedit/Loco Translate for additional locales. No DB schema change.

= 3.0.1 =
Branding neutralisation. Two `FC Rapid 1923` literals removed from code; replaced with filterable defaults sourced from `get_bloginfo('name')`. Sites that need to preserve the original brand should add overrides for `hge_klaviyo_safe_subject_fallback` and `hge_klaviyo_email_footer_brand` (see CHANGELOG for snippet). No DB schema change.

= 3.0.0 =
**Action required.** Schema rewrite — newsletter config moves from a single list/template into a tag-rule cards system. Top-level keys `included_list_ids`, `excluded_list_ids`, `template_id`, `use_web_feed`, `web_feed_name` are silently dropped on first read after upgrade. Open **Tools → Klaviyo Newsletter → Setări** immediately and configure your rule under the new **Reguli newsletter** section. Per-rule cooldown now uses a new option key `hge_klaviyo_last_send_at_by_slug`. If you use Pro, update to Pro 1.1.0+ which requires Free 3.0.0+.

= 2.4.1 =
Patch fix for HTTP 400 from Klaviyo Lists API on revision 2024-10-15 (`additional-fields[list]=profile_count` rejected). Subscriber count display becomes opt-in via the `hge_klaviyo_lists_extra_query` filter — see Changelog for the snippet. The API cache auto-flushes after upgrade.

= 2.4.0 =
Klaviyo lists now show their subscriber count in the Settings dropdowns (rolled back to opt-in in 2.4.1).

= 2.3.1 =
Patch fix for the Klaviyo Templates API page-size cap (HTTP 400). Friendly Romanian error notices replace raw JSON-API blobs in the admin UI. No action required — the API cache is auto-flushed on first admin page view after the upgrade.

= 2.3.0 =
Romanian tab labels and a new `debug_mode` setting. Default landing tab is now `Setări` (was `Diagnostic`). Diagnostic content moves to a `Status` tab that only appears when debug mode is on. Klaviyo Master Template list is now gated to the Pro plan; users on Free / Core retain the built-in template option.

= 2.2.0 =
Settings extension hooks for the Pro plugin. No user-facing changes if you don't have Pro installed.

= 2.1.0 =
New tabs system in Tools → Klaviyo Newsletter, exposed via filter so the Pro plugin can register additional tabs (License, etc.).

= 2.0.0 =
Configuration moves from `wp-config.php` to the database. Existing constants are auto-migrated on activation; the Settings tab is the new source of truth. Klaviyo account default sender is used for from email/label (no longer in code).
