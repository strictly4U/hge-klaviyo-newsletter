# Staging test runbook

Two test plans:
- **Stage 1 parity test** (v1.0.0) — see Phases 1–7 below. Validates that the plugin replaces the in-theme legacy code without regression.
- **v2.0.0 upgrade test** — see Phase 8 (added below). Validates that `wp-config.php` constants migrate into the database option, that the Settings tab fully populates from Klaviyo, and that the dispatch payload no longer hardcodes `from_email`/`from_label`.

Run on a staging environment that mirrors production. Tick each ✅ as you go.

## Preconditions

- [ ] Staging is a clone of production (same WP version, same plugins, same theme).
- [ ] `wp-config.php` on staging contains all required constants (`KLAVIYO_API_PRIVATE_KEY`, `KLAVIYO_NEWSLETTER_LIST_ID`, `KLAVIYO_NEWSLETTER_FROM_EMAIL`, `KLAVIYO_NEWSLETTER_FROM_LABEL`, `KLAVIYO_FEED_TOKEN`).
- [ ] **Switch `KLAVIYO_NEWSLETTER_LIST_ID` on staging to a list with only your own email** so test sends don't hit real subscribers.
- [ ] You have FTP/SSH access to copy plugin files.
- [ ] You can flush opcache after each file change (PHP-FPM restart, or modify `wp-config.php`'s mtime as a trick).

## File deployment

- [ ] Upload `wp-content/plugins/hge-klaviyo-newsletter/` to staging (full directory).
- [ ] Upload the modified `wp-content/themes/fc-rapid-1923-child/functions.php` to staging.
- [ ] Flush opcache.

## Phase 1 — Both codes coexist (plugin **inactive**)

The plugin is uploaded but not yet activated. Theme legacy code should still own everything.

- [ ] **WP Admin → Plugins** — see "HgE Klaviyo Newsletter" listed, status: Inactive.
- [ ] **Tools → Klaviyo Newsletter** — page is reachable. Top heading shows version `2026.04.29.10-no-smart-exclude` (theme legacy version).
- [ ] **Diagnostic table → "Sursă cod activă"** row: shows `⚠ theme legacy` with file `wp-content/themes/fc-rapid-1923-child/functions.php`. **(This row exists only when plugin code runs; when plugin is inactive, the row is absent because the legacy renderer doesn't have it. → If you see "no row", that confirms plugin is inactive.)**
- [ ] `curl -i https://your-staging.example/feed/klaviyo.json?key=$TOKEN` → HTTP 200 + JSON.
- [ ] `curl -i https://your-staging.example/feed/klaviyo.json` (no token) → HTTP 401.
- [ ] Edit any post → see "Klaviyo Newsletter" meta box on the right sidebar.

## Phase 2 — Activate the plugin

- [ ] **WP Admin → Plugins → activate "HgE Klaviyo Newsletter"**.
- [ ] **No fatal error** on activation. If WC is missing you should see a clean wp_die message ("requires WooCommerce") — treat that as an expected outcome and re-test after activating WC.
- [ ] **Tools → Klaviyo Newsletter** — heading now shows version `1.0.0` (the plugin version, not the legacy `2026.04.29.10-...`). **This is the headline parity confirmation.**
- [ ] Diagnostic row "Sursă cod activă" shows `✓ plugin` with file `wp-content/plugins/hge-klaviyo-newsletter/includes/admin.php`.
- [ ] All other diagnostic rows render exactly as in Phase 1 (constants, AS, tag, web feed status, cooldown).
- [ ] No PHP warnings/notices in `wp-content/debug.log` from the plugin path.

## Phase 3 — Behavioural parity

### Feed endpoints

- [ ] `curl -i https://your-staging.example/feed/klaviyo.json?key=$TOKEN` → HTTP 200, JSON with `items` array of up to 8 posts. Compare top-level structure with Phase 1 output → identical fields.
- [ ] `curl -i https://your-staging.example/feed/klaviyo-current.json?key=$TOKEN` → HTTP 200, JSON with empty `items: []` (no campaign in flight).
- [ ] Both endpoints reject non-GET methods with HTTP 405.
- [ ] Both endpoints reject missing/invalid token with HTTP 401.

### Tools page actions

- [ ] In the articles table find a post that is `_klaviyo_campaign_sent === 'yes'`. Click "Reset" → admin notice "Status Klaviyo resetat" → refresh page, the row's "Trimis?" column is empty again.
- [ ] On the same post click "Trimite". Within 60s the dispatch runs synchronously. Three possible outcomes:
   - **Success**: notice "Newsletter trimis cu succes". Klaviyo → Campaigns → see new campaign for that post.
   - **Error**: notice "Eroare la trimiterea newsletter — vezi 'Ultima eroare' în meta box". Click into the post → meta box → "Ultima eroare" panel shows the exact API error.
   - **Unknown**: very rare — investigate.
- [ ] After a successful send, the option `hge_klaviyo_last_send_at` updates → "Următoarea trimitere" shows the cooldown countdown (12h by default).
- [ ] Click "Reset cooldown global" → option deleted → countdown disappears.

### Auto-trigger on post publish

- [ ] Publish a brand-new test post tagged `trimitenl`.
- [ ] Within ~30 seconds (Action Scheduler cycle), check **Tools → Action Scheduler** (provided by WC) → group `hge-klaviyo` → action `hge_klaviyo_dispatch_newsletter` runs → completes successfully.
- [ ] The post's meta box now shows "✓ Trimis" with Campaign ID.
- [ ] Klaviyo → Campaigns → corresponding campaign exists with audience including your test list and excluded `UBAKWB`.

## Phase 4 — Rollback test

This proves the plugin is non-destructive: deactivating returns the system to its pre-plugin state.

- [ ] **Deactivate the plugin** in WP Admin → Plugins.
- [ ] No fatal error during deactivation.
- [ ] **Tools → Klaviyo Newsletter** — version reverts to `2026.04.29.10-no-smart-exclude` (the legacy version that lives in the theme). The "Sursă cod activă" diagnostic row disappears because the legacy renderer doesn't include it.
- [ ] `curl https://your-staging.example/feed/klaviyo.json?key=$TOKEN` → still HTTP 200 (theme handler now serves it again).
- [ ] Edit a post → meta box still appears (theme renders it).
- [ ] Reactivate the plugin → version flips back to `1.0.0`. No fatal. State preserved (post meta `_klaviyo_campaign_sent` etc. is untouched by activation/deactivation).

## Phase 5 — Constants warning

- [ ] Comment out `KLAVIYO_API_PRIVATE_KEY` in `wp-config.php`.
- [ ] Deactivate, then reactivate the plugin.
- [ ] WP Admin dashboard shows a warning notice: **"HgE Klaviyo Newsletter: următoarele constante lipsesc din wp-config.php..."** with `KLAVIYO_API_PRIVATE_KEY` listed.
- [ ] Restore the constant in `wp-config.php`. Reactivate. Notice is gone.

## Phase 6 — Permalinks routing

This validates the activation hook flushed rewrite rules correctly.

- [ ] **Without** going through Settings → Permalinks → Save manually, both `/feed/klaviyo.json` and `/feed/klaviyo-current.json` return JSON (HTTP 200 with token, 401 without).

If either returns HTML or 404, click Settings → Permalinks → Save once and re-test. If it still 404s, the activation hook ran before `hge_klaviyo_register_feed_rewrites` was loaded — file an issue.

## Phase 7 — Hook-count integrity

Open the WP Admin → Tools → Site Health → Info → "WordPress Constants" and "Active Plugins" panels and confirm:

- [ ] `HGE_KLAVIYO_NL_PLUGIN_FILE`, `HGE_KLAVIYO_NL_DISPATCHER_LOADED`, `HGE_KLAVIYO_NL_FEEDS_LOADED`, `HGE_KLAVIYO_NL_ADMIN_LOADED` — all defined when plugin is active.
- [ ] None of the above defined when plugin is inactive.

For deeper debugging, drop this snippet temporarily at the top of an unused admin file and visit it as admin:

```php
<?php
require_once dirname( __FILE__, 4 ) . '/wp-load.php';
header( 'Content-Type: text/plain' );
foreach ( ['transition_post_status', 'save_post_post', 'admin_menu', 'add_meta_boxes_post', 'template_redirect'] as $h ) {
    global $wp_filter;
    if ( ! isset( $wp_filter[ $h ] ) ) continue;
    $hooked = array();
    foreach ( $wp_filter[ $h ] as $prio => $cbs ) {
        foreach ( $cbs as $cb ) {
            if ( is_string( $cb['function'] ) && strpos( $cb['function'], 'hge_klaviyo' ) === 0 ) {
                $hooked[] = "  prio=$prio cb=" . $cb['function'];
            }
        }
    }
    if ( $hooked ) {
        echo "$h:\n" . implode( "\n", $hooked ) . "\n\n";
    }
}
```

Expected: each plugin callback registered **exactly once**, no duplicates.

## Sign-off

When all phases pass:

- [ ] Document any unexpected behaviour in CHANGELOG.md under "Known issues" if found.
- [ ] Mark Beads `FcRapid1923-odl` as closed.
- [ ] Proceed to `FcRapid1923-5he` (delete the wrapped legacy block from `functions.php`) and `FcRapid1923-dul` (uninstall.php).
- [ ] When ready, deploy to production: copy plugin folder + updated `functions.php`, activate plugin, flush opcache, smoke-test.

## Failure escalation

If any phase fails, **deactivate the plugin immediately** to restore the legacy-only path. Document the failure with: phase number, expected behaviour, observed behaviour, screenshots, `debug.log` excerpt. Re-open the relevant Beads task (`b5f`, `38f`, `ck9`, `ykc`) for fix.

---

## Phase 8 — v2.0.0 upgrade (UI-driven config + Settings tab)

This phase tests the v1.x → v2.0.0 upgrade path on a site that already had the plugin running with `wp-config.php` constants.

### 8.1 Pre-upgrade state

- [ ] Plugin is currently running v1.x with constants in `wp-config.php` (`KLAVIYO_API_PRIVATE_KEY`, `KLAVIYO_NEWSLETTER_LIST_ID`, `KLAVIYO_NEWSLETTER_FROM_EMAIL`, `KLAVIYO_NEWSLETTER_FROM_LABEL`, `KLAVIYO_FEED_TOKEN`, optionally `KLAVIYO_NEWSLETTER_TEMPLATE_ID`, `KLAVIYO_NEWSLETTER_USE_WEB_FEED`, `KLAVIYO_NEWSLETTER_REPLY_TO`, `KLAVIYO_NEWSLETTER_EXCLUDED_LISTS`, `KLAVIYO_NEWSLETTER_MIN_INTERVAL_HOURS`).
- [ ] Take a DB snapshot for rollback.
- [ ] Note the option `hge_klaviyo_nl_settings` is currently absent (`SELECT * FROM wp_options WHERE option_name = 'hge_klaviyo_nl_settings';` returns 0 rows).

### 8.2 Deploy v2.0.0 files

- [ ] Upload the new plugin folder over the existing one (overwrites all files).
- [ ] Flush opcache.
- [ ] WP Admin → Plugins — confirm version reads **2.0.0**.

### 8.3 Trigger migration

- [ ] **Deactivate then reactivate** the plugin (activation hook runs the migration shim).
- [ ] Verify in DB: `SELECT option_value FROM wp_options WHERE option_name = 'hge_klaviyo_nl_settings';` — value is now a serialized array containing the migrated constants.
- [ ] Verify in DB: `SELECT option_value FROM wp_options WHERE option_name = 'hge_klaviyo_nl_migrated_from_wp_config';` — non-zero timestamp (one-shot flag).

### 8.4 Settings tab verification

- [ ] **Tools → Klaviyo Newsletter** — see two tabs: "Diagnostic" and "Settings".
- [ ] Click **Settings** — page loads without errors. All fields are populated:
   - API Key (masked, password-style input)
   - Feed Token
   - Audience list — dropdown shows the migrated list ID, pre-selected
   - Reply-to — populated if `KLAVIYO_NEWSLETTER_REPLY_TO` was defined
   - Master Template — pre-selected if `KLAVIYO_NEWSLETTER_TEMPLATE_ID` was defined
   - Web Feed mode — checked if `KLAVIYO_NEWSLETTER_USE_WEB_FEED=true`
   - Cooldown hours — populated from constant or default 12
- [ ] If API key is valid, the **Refresh from Klaviyo** button appears with "X lists, Y templates (cached 5 min)" status text.

### 8.5 List/template browser

- [ ] Click **Refresh from Klaviyo** → admin notice "Cache-ul API Klaviyo a fost golit".
- [ ] Reload Settings tab → list and template dropdowns populate from Klaviyo (verify list count matches what you see in Klaviyo → Lists & Segments).
- [ ] If Klaviyo returns an error (invalid key, scope missing), the page shows "⚠ HTTP 4xx ..." inline near the API key field. Plugin does not fatal.

### 8.6 Edit + save

- [ ] Change the cooldown to 24, click **Salvează setările** → admin notice "Setările au fost salvate" → reload → new value persists.
- [ ] Change the included list (single-select on Free) → save → next test send uses the new list.
- [ ] Set Reply-to to a custom email → save.

### 8.7 Dispatch with new sender semantics

- [ ] Reset a test post → click **Trimite acum**.
- [ ] In Klaviyo → Campaigns → click the campaign → **Email content** preview header:
   - From email = your **Klaviyo account default sender** (NOT the value previously hardcoded in `wp-config.php`)
   - From label = your **Klaviyo account default brand label**
   - Reply-to = the value you set in Settings (or empty/default if you left it blank)

### 8.8 Diagnostic tab refresh

- [ ] Diagnostic tab — "Configurare" row shows ✓ completă with a link to Settings.
- [ ] "Sursă cod activă" still shows ✓ plugin (Stage 1 carry-over).
- [ ] Version is 2.0.0.

### 8.9 Rollback test

- [ ] Deactivate plugin → no PHP fatal.
- [ ] Reactivate → migration does NOT run again (flag prevents repeat). DB option unchanged.
- [ ] Diagnostic tab still shows ✓ Configurare completă.

### 8.10 wp-config cleanup (optional, post-confirmation)

- [ ] Once 8.7 passes, comment out the old `KLAVIYO_*` constants in `wp-config.php`.
- [ ] Reload Settings tab → all fields still populated (DB is now the only source).
- [ ] Reload Diagnostic tab → still ✓ Configurare completă.
- [ ] Test send → still works correctly.

### 8.11 Sign-off

- [ ] All v2.0.0 phases pass.
- [ ] Document any unexpected behaviour in `CHANGELOG.md` under "Known issues".
- [ ] Mark Beads `FcRapid1923-cpo` (docs + bump) as closed if not already.

---

## Phase 9 — v3.0.0 tag-rules schema (BREAKING)

This phase validates the tag-rules cards system on a site upgrading from 2.x. Run on a staging clone where v2.x was previously installed with a real configured list/template.

### Preconditions

- [ ] Staging clone has v2.x configured: a single list + template + Web Feed name visible in `Setări` before upgrade.
- [ ] Note the current values of `included_list_ids`, `excluded_list_ids`, `template_id`, `web_feed_name` from the v2.x `Setări` tab — you will re-enter them as a rule after upgrade.
- [ ] Pro plugin (if installed) is currently at 1.0.x — confirm `HGE_KLAVIYO_PRO_MIN_FREE_VERSION = '2.2.0'`.

### 9.1 Code upgrade

- [ ] Upload v3.0.0 files over the existing plugin directory.
- [ ] Flush opcache.
- [ ] **Plugins** screen — confirm version shows `3.0.0`.

### 9.2 First-load read (legacy keys silently dropped)

- [ ] Open `Setări` — the page renders without PHP warnings.
- [ ] The new `Setări generale` section is at the top; the `Reguli newsletter` section below shows **1 empty rule card** with the default tag slug pre-seeded (`newsletter` on a fresh install; the saved value from v2.x for an upgrade — typically `trimitenl` on the original FC Rapid 1923 deployment).
- [ ] The legacy v2.x list / template / Web Feed selectors are GONE from the form.
- [ ] DB check (wp-cli `wp option get hge_klaviyo_nl_settings --format=json`): the JSON shows `tag_rules: [...]` with one entry; legacy top-level keys are absent.
- [ ] Status tab (debug mode on): `Reguli configurate` row shows `1 / 1` for Free.

### 9.3 Reconfigure the rule

- [ ] In the rule card, set:
  - `Tag declanșator` = the slug you noted before upgrade (e.g., `trimitenl`, `newsletter`, etc.)
  - `Listă(e) destinatari` = the same list you noted before upgrade
  - (Core+) `Listă(e) excluse` = same as before
  - (Pro) `Template Klaviyo` = same template_id
  - (Pro) `Mod Web Feed` = checked + same `web_feed_name`
- [ ] Save settings — admin notice `Setările au fost salvate.` appears.
- [ ] `Reguli active` table in Status tab now shows the rule with correct values.

### 9.4 Tier cap UX (Free 1 / Core 2 / Pro 5)

- [ ] On Free: `Adaugă regulă` button is disabled. Hover/inspect — `disabled` attribute present. Helper text reads "Ai atins limita planului GRATUIT (1 regulă)".
- [ ] Activate Pro Core plan (via dev override `HGE_KLAVIYO_PRO_DEV_PLAN=core`): button enables. Click → second card appears. Add → button disables after 2 cards.
- [ ] Activate Pro Pro plan (override `HGE_KLAVIYO_PRO_DEV_PLAN=pro`): button enables up to 5 cards.

### 9.5 Add / remove card client-side

- [ ] On Pro, add 2 cards. Set distinct tag_slugs (`stiri`, `promo`).
- [ ] Click `Șterge regula` on card 2. Confirm dialog. Card disappears, indexing renumbers.
- [ ] Save → DB shows only 1 rule remaining (wholesale replace works — no orphan index).

### 9.6 Multi-tag (Pro only)

- [ ] Pro plan active. In a rule card, set `Tag declanșator` = `stiri,promo,events`.
- [ ] Save. Status tab shows the comma-list rendered.
- [ ] Apply the `stiri` tag to a test post + publish → dispatch fires using THIS rule.
- [ ] Check post meta `_klaviyo_campaign_rule_matched_tag` = `stiri` (the SPECIFIC sub-tag that matched).
- [ ] Repeat with `promo` and `events` — each writes the correct specific tag to meta.

### 9.7 First-match-wins priority

- [ ] On Pro, configure 2 rules in order:
  - Rule #1: tag `urgent` → list A
  - Rule #2: tag `news` → list B
- [ ] Publish a post tagged with BOTH `urgent` and `news`.
- [ ] Expected: dispatch uses rule #1 (list A). Verify campaign payload in Klaviyo.
- [ ] Swap card order → publish another such post → now dispatch uses rule #2 (list B).

### 9.8 Per-rule cooldown

- [ ] Configure 2 rules with different tags. Cooldown = 12h.
- [ ] Publish a post that hits rule #1. Wait for dispatch.
- [ ] Immediately publish a post that hits rule #2.
- [ ] Expected: rule #2 fires IMMEDIATELY (its slug has independent timestamp). Rule #1 would queue against the 12h window.
- [ ] Verify `wp option get hge_klaviyo_last_send_at_by_slug --format=json` shows two distinct timestamps keyed by tag.

### 9.9 Per-rule Web Feed URLs

- [ ] On Pro, 2 rules each with Web Feed enabled and distinct `web_feed_name` (e.g., `fc_news_promo`, `fc_news_stiri`).
- [ ] Settings tab — each rule card shows its own feed URL with `?name=<feed_name>`.
- [ ] `curl` each URL with the feed token → returns JSON for that specific rule's active post.
- [ ] Without `?name=` query: falls back to legacy unkeyed transient (back-compat for pre-v3.0 Klaviyo Web Feed configs).

### 9.10 Pro module activation

- [ ] Pro plugin active + license valid for Pro plan.
- [ ] Configure a multi-tag rule with > 10 comma-separated tags. Save.
- [ ] Expected: admin notice `O regulă avea mai mult de 10 tag-uri ...`. DB shows only the first 10 tags retained.
- [ ] Verify constant `HGE_KLAVIYO_PRO_MULTI_TAG_RULE_MAX` = 10.

### 9.11 Sign-off

- [ ] All Phase 9 sub-phases pass.
- [ ] No PHP warnings in `debug.log` from the legacy keys dropping silently.
- [ ] Pro `MIN_FREE_VERSION` bump confirmed: deactivating Free 3.0.0 with Pro 1.1.0 active surfaces a clean admin notice ("requires Free 3.0.0+").
- [ ] Mark Beads `FcRapid1923-h4j` (TR-7 smoke test) closed once this phase signs off in production.
