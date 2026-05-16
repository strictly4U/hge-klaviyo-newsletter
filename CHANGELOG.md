# Changelog

All notable changes to HgE Klaviyo Newsletter are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.11] — 2026-05-16

### Changed — Free tier UX consistency (`5rn`, `1bi`, P2)

- **Segments optgroup hidden on Free + Core (`5rn`).** Dynamic segments are
  a Pro-only feature (`dynamic_segments` in the Pro tier-manager registry);
  prior versions populated the Recipient / Excluded `<select>` with a
  `<optgroup label="Segments">` regardless of plan, so a Free admin could
  pick segment IDs that the Pro module would not accept at dispatch time.
  `hge_klaviyo_render_rule_card` now threads `$plan` into the audience-
  options closure and only emits the Segments optgroup when `$plan === 'pro'`.
  Selected segment IDs on a downgrade are still surfaced as a warning line
  elsewhere — they are not silently kept in the dropdown.

- **Web Feed name input editable on every tier (`1bi`).** Until now both
  the "Use Web Feed" toggle AND the "Web Feed name in Klaviyo" text input
  were rendered only on Pro; on Free the entire row collapsed to a single
  upgrade CTA. Split the gating so the name input is always present and
  editable while only the toggle remains Pro-gated. Admins can stage the
  feed name they intend to use in Klaviyo before upgrading; the sanitiser
  still drops `use_web_feed=1` on save when the plan does not unlock the
  toggle, so the name alone has no effect at dispatch time. Description
  line picks up a "Pre-configurable on this plan — only activates after
  upgrade." suffix on the non-Pro path.



### Fixed — Klaviyo `send_strategy` schema (`4tu`, P0)

- **Critical:** any dispatch that took the cooldown-deferred path emitted a
  Klaviyo Campaigns API payload that violated the 2024-10-15 schema and was
  rejected with HTTP 400. Three concurrent schema errors fixed in one shot:
  1. `send_strategy.method` was `static_time` — not a valid Klaviyo value.
     Renamed to `static` (the only API-accepted name for a future-dated send).
  2. `send_strategy.datetime` was emitted at the wrong nesting level.
     Moved into `send_strategy.options_static.datetime` (the schema-required
     location).
  3. `send_strategy.options_static.send_past_recipients_immediately` was
     included when `is_local=false`. Klaviyo rejects that combination
     (the field is only valid alongside `is_local=true`). Removed entirely.
- Impact before fix: posts deferred by the per-rule cooldown silently failed
  — no campaign was created in Klaviyo, the dispatcher's error was logged on
  the post but never surfaced to the customer. `_klaviyo_dispatch_last_error`
  on the post carried the Klaviyo error JSON.
- Impact after fix: deferred posts produce a populated `_klaviyo_campaign_id`
  and appear in Klaviyo Dashboard → Campaigns → Scheduled at the cooldown's
  next-allowed time. Confirmed end-to-end on dev1 with three back-to-back
  posts (1 immediate + 2 scheduled).
- Internal `$plan['mode'] = 'static_time'` sentinel kept unchanged — never
  leaves PHP, used only as a future-vs-now marker by the cooldown planner.
  Inline comment in `includes/settings.php` now documents that the internal
  sentinel and the API value `method = 'static'` are distinct concepts.
- `HOOKS.md` updated with the corrected `hge_klaviyo_send_strategy` example
  payload (including a warning about the `send_past_recipients_immediately`
  constraint) so external integrators see the right shape.

### Fixed — Template combobox sentinel display (`y30`, P1)

- Selecting `— use the built-in HTML template —` from the combobox cleared
  the visible input, surfacing the `Choose or search a Klaviyo template…`
  placeholder again — making it look like the choice did not stick.
- The visible input now mirrors whichever option is currently selected
  (template name OR the sentinel label). The hidden form field continues to
  carry an empty string for the sentinel, so the DB shape is unchanged.
- Sentinel `<li data-name>` now carries the lowercased label so the filter's
  "selected option matches current term → show the full list" branch keeps
  working when the sentinel is the active choice.
- Focusing the input now pre-selects its text (`HTMLInputElement.select()`),
  so the user can type to filter without manually clearing the displayed
  selection.

### Added — multi-select Ctrl+click hint on Recipient list(s) (`144`)

- When the Recipient list(s) `<select>` is multi-select (Pro plans where
  `max_included > 1`), the field description now ends with:
  > Hold Ctrl (Windows) / Cmd (Mac) and click to add or remove items in the multi-select.
- Discoverability win for Windows users who otherwise don't realise the
  field accepts more than one selection.

### Added — Quick-start modal "Alternative path" callout (`144`)

- The Web Feed Quick-start modal now opens with a blue-bordered callout
  pointing users at Klaviyo's [Template editor options](https://help.klaviyo.com/hc/en-us/articles/115005258768)
  article. Users who already have a Klaviyo template built with
  Global Blocks (drag-and-drop) can pick it directly in step 4 and skip
  the manual HTML in steps 2 and 3.

### i18n

- Four new translatable strings (multi-select hint + three modal-callout
  fragments). `languages/hge-klaviyo-newsletter.pot` regenerated;
  `languages/hge-klaviyo-newsletter-ro_RO.po` updated with Romanian
  translations.

## [3.0.9] — 2026-05-13

### Added — Web Feed quick-start modal (`xef`)

- New **Quick start** button rendered under the Web Feed mode row on each
  Pro rule card. Opens a single shared in-page modal (rendered once at the
  bottom of the Setări tab and reused by every card) with a 5-step guide:
  1. Create the Web Feed in Klaviyo (Name + URL pre-substituted with this
     rule's `web_feed_name` + per-rule feed URL — both copyable).
  2. Create a Code template in Klaviyo (copyable starter HTML — ~25-line
     responsive baseline with image, title, excerpt, CTA, footer).
  3. Render multiple articles (digest layout — copyable Jinja
     `{% for item in web_feeds.NAME.items[:3] %}` loop with the available
     item field reference).
  4. Wire the template back to this rule (in-page step list).
  5. Test (publish a tagged post, watch Klaviyo for the draft campaign).
- The `NAME` placeholder in copy-paste snippets is substituted at copy
  time with the rule's actual Web Feed name (single source of truth, no
  manual find-replace by the user).
- Modal a11y: `role="dialog"` + `aria-modal="true"` + `aria-labelledby`;
  Esc / outside-click / `×` / "Got it" all close; focus moves to the
  close button on open.
- Clipboard fallback: `navigator.clipboard.writeText` with `execCommand`
  legacy fallback for non-HTTPS dev sites.
- Supersedes the inline help line `In Web Feed mode, your template must
  use {{ web_feeds.NAME.items.0.* }}` — replaced by a short pointer to
  the Quick start button.

### Changed — Klaviyo template combobox (`isf`)

- The v3.0.7 separate `<input type="search">` + `<select>` pair is replaced
  by a single combobox component (vanilla, ~210 lines of JS, zero external
  dependency):
  - One visible `<input role="combobox" aria-autocomplete="list">` doubles
    as search + selection display.
  - One `<button class="hge-tpl-clear">×</button>` clears the selection
    back to the built-in HTML template.
  - One hidden `<input>` carries the actual `template_id` through form
    submit (same `name` as the v3.0.0 `<select>` — sanitizer + DB shape
    unchanged for backward compat).
  - One `<ul role="listbox">` with `<li role="option" data-value="…"
    data-name="…" aria-selected="…">` per template.
  - Per-rule count `<span class="hge-tpl-count">` to the right —
    "200 templates" idle, "Showing 12 / 200" while filtering.
- Behaviour: focus opens listbox; typing filters by lowercased `data-name`
  substring; ↑ ↓ navigate visible items (wrap-around); Home/End jump to
  extremes; Enter selects highlighted (or first visible if none active);
  Esc closes (restoring selected name into input); Tab closes naturally;
  click-outside closes all open lists across cards.
- `reindex()` (the function that renumbers cards after add/remove) gains
  awareness of `aria-controls`, `data-list`, `data-count` attributes so
  cross-element references stay coherent when cards shuffle.
- A "no results" row appears inside the listbox when the filter excludes
  every option, with `aria-hidden="true"` so screen readers skip it.

### i18n

- 18 new translatable strings (12 for the quick-start modal, 3 for the
  combobox, 3 for the help-line replacement). All wrapped with text
  domain `hge-klaviyo-newsletter`.
- `.pot` regenerated: 177 singular + 6 plural entries.
- `ro_RO.po` fully translated (183/183).

## [3.0.8] — 2026-05-13

### Changed

- **Feed token hidden by default.** The Setări tab now renders the Feed
  token input only when **Mod debug** is on; otherwise the value rides
  along through a hidden `<input>` so form submit preserves it.
  Production admins never need to touch it — the token is internally
  consumed by `/feed/klaviyo*.json` endpoints and customers don't paste
  it anywhere in the Klaviyo UI.
- **Feed token auto-generated on first save** when empty. New helper
  in `hge_klaviyo_nl_sanitize_settings()` falls back to
  `wp_generate_password(64, false, false)` (or `bin2hex(random_bytes(32))`)
  so the token never has to be hand-rolled by the customer.
- **Debug mode toggle scope expanded.** Now gates the Status tab **and**
  the visibility of internal credentials (Feed token + Pro webhook
  secret). Label + description updated to match.

### Cross-plugin

- **Pro plugin's Webhook secret row** (License tab) follows the same Free
  `debug_mode` toggle via `hge_klaviyo_nl_get_setting('debug_mode')`. No
  separate Pro debug flag — single source of truth.

## [3.0.7] — 2026-05-12

### Added

- **Typeahead search above each Klaviyo template dropdown.** Each rule
  card now renders an `<input type="search" class="hge-tpl-search">` above
  its `<option>` list. As the user types, options whose `data-name`
  (lowercased Klaviyo template name) doesn't contain the substring are
  hidden via the standard HTML `hidden` attribute. The currently-selected
  option and the empty placeholder are never hidden so form submit
  always carries a valid value even mid-filter.
- **Live count badge.** Right of the dropdown — `200 templates` idle,
  `Showing 12 / 200` while a search term is active. Plural-aware via
  `_n()`.
- Vanilla JS implementation (~40 lines, single delegated `input` listener
  on the rules container). No new external asset, no build pipeline,
  no jQuery dependency.

### Why this matters

The Klaviyo Templates API caps `page[size]` at 10 (revision 2024-10-15),
so accounts with 200+ templates already pay ~20 paginated API calls on
the cache warmup. Rendering all of them into a single `<select>` was
fine at 56 templates (current FC Rapid 1923 deployment) but degrades
visibly around the 300+ mark. The client-side filter keeps the dropdown
usable at any catalogue size.

### Translatable strings

`Search templates by name…` (placeholder), `template` / `templates`
(count badge plural), `Showing` (count prefix while filtering).
Bundled `ro_RO.po` updated.

## [3.0.6] — 2026-05-12

### Performance

- **Background Klaviyo API cache warmup.** Tools → Klaviyo Newsletter
  navigation no longer pays the cold-fetch tax (5–15s of paginated round
  trips to Lists + Segments + Templates) on the admin's critical path.
  A new recurring Action Scheduler job, `hge_klaviyo_nl_api_cache_warmup`,
  fires every 25 minutes (comfortably below the 30-min lists/segments TTL
  and 60-min template TTL) and refreshes all three caches in the queue
  worker. Result: cold-cache navigation cut from 10–40s down to sub-second
  once the first warmup completes.
- The schedule is queued on the first admin pageview after an upgrade
  (no plugin re-activation needed) and is cancelled on plugin
  deactivation via `as_unschedule_all_actions`.

## [3.0.5] — 2026-05-11

### Performance

- **Settings tab load time cut from 10–15s to ~3s on cold cache.** Three root causes addressed:
  1. **Templates cache TTL extended to 1 hour** (was 5 min). New constant `HGE_KLAVIYO_NL_API_TEMPLATES_CACHE_TTL`. Templates rarely change once configured, and the 56-template paginate (6 round-trips at ~500ms each) was the bulk of cold-fetch time.
  2. **Lists + segments cache TTL extended to 30 min** (was 5 min). Same `HGE_KLAVIYO_NL_API_CACHE_TTL` constant.
  3. **No auto-clear on patch / minor version bumps** — only on **major**. Multiple-patch days (e.g. 3.0.2 → 3.0.3 → 3.0.4 on the same day) no longer leave admins with a cold cache after each upgrade.
  4. **Settings-save no longer clears API cache** unless the **API key** actually changed. Rule edits, cooldown tweaks, etc. now leave the cache warm.

  Manual cache flush is still available via the `Reload from Klaviyo` button in Settings.

### Reverted

- **`readme.txt` restored, `README.md` removed.** Reverses the v3.0.4 decision. The plugin now ships `readme.txt` as the single source of truth (WP.org-compatible) — single readme file across the plugin, matching the Free + Pro convention.

## [3.0.4] — 2026-05-11

### Removed

- **`readme.txt`** — WordPress.org-format readme is no longer shipped. `README.md` is now the single source of truth for documentation (Description, Installation, FAQ, Privacy, Changelog pointers). All content from `readme.txt` was merged into `README.md` first. Submitting to WP.org later would require regenerating `readme.txt` from this `README.md`.

### Changed

- Architecture file tree in `README.md` refreshed: removes the stale `readme.txt` entry and surfaces the `LICENSE`, `HOOKS.md`, `.github/workflows/`, `bin/`, `languages/` directories that ship with the plugin since v3.0.x.

## [3.0.3] — 2026-05-11

### Added

- **Klaviyo Segments as audience members.** Both `Recipient list(s)` and `Excluded list(s)` selectors in rule cards now show Segments alongside Lists, grouped into `<optgroup>` blocks. Klaviyo's Campaigns API accepts segment IDs interchangeably with list IDs in `audiences.included` / `audiences.excluded`, so no payload change is needed — the new IDs flow through the existing keys (`included_list_ids`, `excluded_list_ids`).
- **`hge_klaviyo_api_list_segments( $force_refresh = false )`** — new API client helper (mirror of `hge_klaviyo_api_list_lists`). Paginates `GET /api/segments/` with the same 10-per-page cap, caches the result for 5 minutes, sorts alphabetically.
- **`hge_klaviyo_segments_extra_query`** filter — opt-in `additional-fields[segment]=profile_count` for sites on a Klaviyo revision that accepts it (same pattern as `hge_klaviyo_lists_extra_query`).
- **Cross-exclude UX in rule cards.** Selecting a list/segment as Included automatically disables it in the Excluded select of the same card (and vice versa). Disabled options stay visible (greyed out) so the user understands why they can't be picked. Server-side fail-safe in `hge_klaviyo_nl_sanitize_rules()` strips duplicates from Excluded if direct DB writes ever conflict.
- **Refresh counter** in Settings now reads `N lists, M segments, K templates (5 min cache)` so users can confirm Segments fetched correctly.

### Internal

- `hge_klaviyo_render_rule_card()` signature gains `$segments_data` between `$lists_data` and `$templates_data`. The function only has 2 callers, both internal — see the `@since 3.0.3` tag in the phpdoc.
- API cache clear now also drops `hge_klaviyo_nl_api_segments`.

## [3.0.2] — 2026-05-11

### Added

- **Translation-ready.** All user-facing admin strings wrapped in `__()` / `esc_html__()` / `_n()` with text domain `hge-klaviyo-newsletter`. `load_plugin_textdomain` is called on `init` so sideloaded `.mo` files in `/languages/` resolve automatically.
- **`languages/hge-klaviyo-newsletter.pot`** — translation template (~160 entries) generated by the `bin/extract-pot.py` script or the `.github/workflows/i18n.yml` CI workflow (uses `wp i18n make-pot`).
- **`languages/hge-klaviyo-newsletter-ro_RO.po`** — Romanian translation. Preserves the pre-3.0.1 admin UX for `ro_RO` locales (notably the original FC Rapid 1923 deployment).
- **`.github/workflows/i18n.yml`** — auto-regenerates the `.pot` on every push to `main` via wp-cli.
- **`bin/extract-pot.py`** + **`bin/build-ro-po.py`** — minimal Python alternatives so contributors without wp-cli can refresh translations locally.

### Changed

- Source strings are now **English-as-master** (was implicit Romanian-in-code before). All Romanian-language UX is preserved via the bundled `.po`.

### Note for non-Romanian sites

WordPress will display the English source strings out of the box. To add another language, copy the `.pot` to `languages/hge-klaviyo-newsletter-<locale>.po` (e.g., `-fr_FR.po`), translate via Poedit / Loco Translate, and ship the compiled `.mo` alongside.

## [3.0.1] — 2026-05-11

### Changed

- **Branding neutralised for public distribution.** The two hardcoded `"FC Rapid 1923"` references are now filterable:
  - `hge_klaviyo_safe_subject_fallback` — replaces the empty-title fallback subject. Default derives from the WP site name.
  - `hge_klaviyo_email_footer_brand` — replaces the brand label in the built-in HTML template footer. Default is `get_bloginfo( 'name' )`.
- **Default seed values changed:** new installs ship with `tag_slug = 'newsletter'` and `web_feed_name = 'newsletter_feed'` (were `'trimitenl'` and `'fc_news'`). Existing v3.0.0 installs keep their saved values; only fresh installs see the new defaults.
- The literal `'fc_news'` string in `hge_klaviyo_nl_transient_key_for_feed()` and `uninstall.php` is preserved as legacy back-compat for Klaviyo Web Feed URLs from the original FC Rapid 1923 deployment. Marked `@legacy` in phpdoc; new installs do not hit this branch.
- Admin UI placeholders updated to use the new generic defaults.

### Migration note for the original FC Rapid 1923 site

To keep the existing email branding after upgrade, drop this in `wp-content/themes/fc-rapid-1923-child/functions.php`:

```php
add_filter( 'hge_klaviyo_safe_subject_fallback', fn() => 'Newsletter FC Rapid 1923' );
add_filter( 'hge_klaviyo_email_footer_brand',    fn() => 'FC Rapid 1923', 10, 2 );
```

## [3.0.0] — 2026-05-11

### BREAKING

- **Schema rewrite — tag rules system.** Newsletter configuration is now organised as an array of rules under `tag_rules`, each shaped as `{ tag_slug, included_list_ids, excluded_list_ids, template_id, use_web_feed, web_feed_name }`. The previous top-level keys `included_list_ids`, `excluded_list_ids`, `template_id`, `use_web_feed`, `web_feed_name` are silently dropped at read time via `array_intersect_key` (we are at T0 — no shim). Reconfigure your rule(s) in **Setări → Reguli newsletter** immediately after upgrade.
- **Per-rule cooldown.** New option `hge_klaviyo_last_send_at_by_slug` stores per-tag-slug timestamps. Legacy `hge_klaviyo_last_send_at` is preserved for diagnostic but no longer drives scheduling.
- **Dispatcher signature.** `hge_klaviyo_dispatch_newsletter( $post_id )` becomes `hge_klaviyo_dispatch_newsletter( $post_id, $tag_slug = '' )`. When the optional `$tag_slug` is omitted, the dispatcher re-resolves the matching rule from the post.
- **Tier caps now enforced at the rule layer.** Free 1 rule (1 list, 0 excluded), Core 2 rules (1+1 lists), Pro 5 rules (15+15 combined, Klaviyo limit) with optional comma-separated `tag_slug` (OR semantics).

### Added

- **Cards-based admin UI** in `Tools → Klaviyo Newsletter → Setări`. Each rule is a card with `tag_slug` + `included_list_ids` + `excluded_list_ids` (Core+) + `template_id` (Pro) + Web Feed (Pro) fields. The `Adaugă regulă` button is tier-gated and disabled when the cap is reached. Vanilla-JS add/remove with client-side reindexing to keep the POST payload gapless.
- **Filter `hge_klaviyo_nl_matching_rule( ?array $matched, WP_Post $post, array $rules )`** — Pro / theme code can override the default first-tag-wins resolution.
- **Per-rule Web Feed.** Each rule has its own `web_feed_name`; the endpoint `/feed/klaviyo-current.json` accepts an optional `?name=<web_feed_name>` query param. Empty / `fc_news` names map to the legacy unkeyed URL so any pre-v3.0 Klaviyo Web Feed configuration keeps resolving.
- **Helpers** (settings.php): `hge_klaviyo_nl_default_rule()`, `hge_klaviyo_nl_max_rules()`, `hge_klaviyo_nl_rule_caps()`, `hge_klaviyo_nl_supports_multi_tag_rule()`, `hge_klaviyo_nl_get_matching_rule()`, `hge_klaviyo_nl_get_last_send_for_slug()`, `hge_klaviyo_nl_set_last_send_for_slug()`, `hge_klaviyo_nl_compute_send_time_for_slug()`, `hge_klaviyo_nl_sanitize_rules()`, `hge_klaviyo_nl_sanitize_tag_slug()`.
- **Helpers** (config.php): `hge_klaviyo_nl_transient_key_for_feed( $feed_name )`, `hge_klaviyo_nl_all_feed_names()` — feed-name-keyed transients survive card reorder without breaking Klaviyo Web Feed URLs.
- **Post meta `_klaviyo_campaign_rule_slug`** — records which rule fired for each dispatch (for audit / multi-rule analytics).
- **Status tab `Reguli active` table** — diagnostic view with per-rule active-post column reading the keyed transient.

### Changed

- Meta box on the post edit screen now uses `hge_klaviyo_nl_get_matching_rule()` to report whether any active rule's tag is present on the post (previously hardcoded `HGE_KLAVIYO_NL_TAG_SLUG`).
- `hge_klaviyo_nl_update_settings()` now wholesale-replaces `tag_rules` (when present in the partial) instead of running `array_replace_recursive`, so removing a rule card in the UI actually deletes it from the DB.

### Deprecated

- `hge_klaviyo_use_web_feed()` — returns true if any rule has Web Feed enabled with a template. Kept for diagnostic UI; dispatcher reads the per-rule field directly.
- `hge_klaviyo_excluded_list_ids()` — returns an empty array. Direct callers should query the matched rule from `hge_klaviyo_nl_get_matching_rule( $post )` instead.
- `hge_klaviyo_compute_send_time()` — wraps the legacy global cooldown. New code calls `hge_klaviyo_nl_compute_send_time_for_slug( $tag_slug )`.

### Internal

- Inline JS contract documented in `admin.php` (naming pattern `hge_klaviyo[tag_rules][N][field]`, regex-based reindexing, gapless-array invariant).
- `<script type="text/template">` cloning pattern for blank-card injection. Safe because the embedded content is server-escaped HTML with no `</script>` payload.

## [2.4.1] — 2026-05-05

### Fixed
- `GET /api/lists/?additional-fields[list]=profile_count` returns `HTTP 400 Invalid input` on Klaviyo API revision 2024-10-15. The default behaviour added in 2.4.0 (always send the parameter) is rolled back to keep Settings tab functional out of the box.

### Changed
- Subscriber count is now opt-in. Sites on a Klaviyo account / API revision that accepts the parameter can re-enable it via a new filter:
  ```php
  add_filter( 'hge_klaviyo_lists_extra_query', function ( $extra ) {
      $extra['additional-fields[list]'] = 'profile_count';
      return $extra;
  } );
  ```
  The `format_list_count` helper still degrades gracefully (returns `''`) when no count is present, so the UI stays clean for both modes.

### Internal
- Lists query string is now built with `http_build_query( $query, '', '&', PHP_QUERY_RFC3986 )` so any future `extra_query` additions are URL-encoded correctly without manual `%5B` / `%5D` escapes.

## [2.4.0] — 2026-05-05

### Added
- Subscriber counts surfaced in the `Listă(e) destinatari` and `Listă(e) excluse` dropdowns. Each `<option>` label now reads `<list name> — <count> abonați (<list_id>)`. Implementation uses Klaviyo's `additional-fields[list]=profile_count` query parameter on `GET /api/lists/`; the field is captured into each item's `profile_count` key (returned as `int|null`).
- New helper `hge_klaviyo_format_list_count( $count )` — locale-aware formatting via `number_format_i18n`, plural form (`abonat` / `abonați`). Returns `''` when the count is `null` so the UI degrades gracefully if the API revision drops the field.

### Internal
- API cache invalidates automatically when the plugin version changes (mechanism shipped in 2.3.1) — the new query parameter takes effect on the first admin pageview after upgrade without any manual cache flush.

## [2.3.1] — 2026-05-05

### Fixed
- `hge_klaviyo_api_list_templates()` now uses `page[size]=10` (Klaviyo Templates API hard limit; `100` returned `HTTP 400 "Page size must be an integer between 1 and 10"`). Pagination guard raised to 50 pages × 10 = 500 templates.
- v2.0.1 fixed the same bug for Lists API. Templates was missed at the time because we believed the cap differed per resource — it doesn't, both Lists and Templates APIs share the 10-item cap on revision 2024-10-15.

### Added
- One-shot API cache invalidation tied to plugin version (`hge_klaviyo_nl_api_cache_codever` option). When the version constant changes, all `hge_klaviyo_nl_api_*` transients are flushed automatically on the next admin pageview. Prevents stale "0 templates" results from a previous (buggy) page-size value continuing to serve out of the 5-minute cache after a code update.
- Friendly RO error helper `hge_klaviyo_friendly_api_error()` translates raw HTTP 401 / 403 / 429 / 5xx / network errors from Klaviyo into short, action-oriented Romanian messages in the Settings tab UI. Raw error stays in `error_log` for debugging.
- Separate template-error capture: when Lists API succeeds but Templates API fails, the Settings tab now shows the template-specific error instead of swallowing it.
- Helpful inline hint when the Klaviyo account genuinely returns 0 templates (success case, empty array): links the user to <https://www.klaviyo.com/email-templates> to create one.

## [2.3.0] — 2026-05-05

### Changed
- **Tab labels in Romanian + reordered**. Tools → Klaviyo Newsletter tabs are now `Setări` (default), `Licență Pro` (when Pro is active), `Status` (only when debug mode is on). Default landing tab changed from `diagnostic` to `settings`.
- **`Status` tab is gated by a new `debug_mode` setting** in Settings. Off by default — production sites no longer see a Diagnostic tab cluttering the UI. Toggle on when investigating dispatch/webhook/API behaviour.

### Added
- Setting `debug_mode` (boolean, default false) in `hge_klaviyo_nl_settings`.
- Action `hge_klaviyo_render_status_extra` — fired at the bottom of the Status tab. Pro feature modules use it to render audit/debug widgets (webhook activity, raw server response) inside Status instead of cluttering Licență Pro.

## [2.2.0] — 2026-05-04

### Added
- **`hge_klaviyo_nl_settings_defaults`** filter — Pro feature modules can register additional settings keys with defaults.
- **`hge_klaviyo_nl_sanitize_settings`** filter — applied at the end of the core sanitiser so modules can sanitise their custom fields.
- **`hge_klaviyo_settings_save_partial`** filter — lets modules pull their POST keys into the persist payload.
- **`hge_klaviyo_render_settings_extra`** action — fires inside the Settings tab form, just before the submit button. Pro feature modules render their own `<table class="form-table">` sections here.
- All 4 hooks documented in `HOOKS.md`. The Tier 2 `delay-window` and `list-validation` modules in the Pro plugin use this stack.

## [2.1.0] — 2026-05-04

### Added
- **Tabs registry exposed via filter** — the Tools → Klaviyo Newsletter page now exposes `apply_filters( 'hge_klaviyo_admin_tabs', $tabs )` and `do_action( 'hge_klaviyo_render_tab_<key>' )`. The Pro plugin uses these to register a "License" tab without modifying base code. Default tabs (`diagnostic`, `settings`) keep their original render path; only externally registered tabs flow through the action.
- **`HOOKS.md`** — canonical hooks API contract documenting all 9 Free filters + 1 Free action (and the 2 Pro filters + 1 Pro action that consume them). Includes call signatures, since-versions, default values, file/line of each call site, and the stability policy (signatures stable across minor versions; breaking changes bundled into majors). Each new hook added in future releases must be registered in this file as part of the same change.

## [2.0.1] — 2026-05-04

### Fixed
- `hge_klaviyo_api_list_lists()` now uses `page[size]=10` (Klaviyo Lists API hard limit; `100` returned `HTTP 400 Invalid input` per `page_size` parameter source). Pagination guard raised to 50 pages × 10 = 500 lists.
- Note: `hge_klaviyo_api_list_templates()` is unaffected — Templates API allows `page[size]=100`. Guard raised to 10 pages = 1000 items.

## [2.0.0] — 2026-05-04

### BREAKING
- All configuration migrates from `wp-config.php` constants to a single database option (`hge_klaviyo_nl_settings`, `autoload=false`). The plugin auto-migrates known v1.x constants the first time it is activated after the upgrade and writes a one-shot flag (`hge_klaviyo_nl_migrated_from_wp_config`) so the migration never repeats. Constants are kept as a read-only fallback in the resolver helpers — safe rollback path.
- `from_email` and `from_label` are no longer sent in the Klaviyo `campaign-message.content` payload. Klaviyo populates them from the account default sender configured at **Klaviyo → Settings → Brand**. The plugin no longer hardcodes any sender info.
- Replaced the v1.x diagnostic row "Constante wp-config.php" with "Configurare" — driven by `hge_klaviyo_nl_settings_complete()`.

### Added
- **Settings tab** under Tools → Klaviyo Newsletter → Settings. Native WP form (`<form method="post" action="admin-post.php">` with `wp_nonce_field` + `check_admin_referer`). Fields: API key, feed token, included list(s), excluded list(s), reply-to, master template, web feed mode + name, cooldown hours.
- **API list browsers** — `hge_klaviyo_api_list_lists()` and `hge_klaviyo_api_list_templates()` with cursor pagination (up to 5 pages × 100 = 500 items) and 5-minute transient cache. The cache auto-invalidates on `update_option_hge_klaviyo_nl_settings`. A "Refresh from Klaviyo" button on the Settings tab clears it on demand.
- **Tier helpers** — `hge_klaviyo_is_pro_active()` and `hge_klaviyo_active_plan()` (returns `free`/`core`/`pro`). The Settings UI hides multi-list controls and shows upgrade CTAs when the active plan does not unlock them.
- **Five extension filters** for the Pro plugin to inject Tier 2 / Tier 3 behaviour without touching base code:
  - `hge_klaviyo_audience_included` (array, post_id, settings)
  - `hge_klaviyo_audience_excluded` (array, post_id, settings)
  - `hge_klaviyo_send_strategy` (array, post_id, settings)
  - `hge_klaviyo_message_content` (array, post_id, settings)
  - `hge_klaviyo_campaign_payload` (array, post_id, settings)
- **Resolver helpers** — `hge_klaviyo_nl_resolve_api_key()`, `hge_klaviyo_nl_resolve_feed_token()`, `hge_klaviyo_nl_resolve_reply_to()` (DB → wp-config fallback → empty string). All runtime code uses these, no direct constant reads.
- **Sanitization layer** — `hge_klaviyo_nl_sanitize_settings()` enforces email validation, list ID alphanumeric/dash whitelist, sanitize_key on the web feed name, integer range 0–168 for the cooldown, and the Klaviyo per-campaign limit (`included` + `excluded` ≤ 15).
- **Klaviyo limit enforcement** — automatic truncation of `included_list_ids` if the combined count would exceed 15.
- Diagnostic row "Sursă cod activă" continues to identify whether the renderer is the plugin or the legacy theme fallback (Stage 1 carry-over).

### Changed
- `hge_klaviyo_use_web_feed()`, `hge_klaviyo_excluded_list_ids()`, `hge_klaviyo_min_interval_seconds()` now read from the DB option with a graceful fallback to v1.x constants if the settings module hasn't loaded yet (defensive ordering).
- `hge_klaviyo_api_request()` reads the API key via `hge_klaviyo_nl_resolve_api_key()` and returns a `WP_Error('klaviyo_api_no_key')` if no key is configured (instead of fataling on undefined constant).
- Feed endpoint handlers (`/feed/klaviyo.json`, `/feed/klaviyo-current.json`) read the token via `hge_klaviyo_nl_resolve_feed_token()`.
- Activation hook now calls `hge_klaviyo_nl_migrate_from_wp_config()` once and replaces the v1.x "missing constants" admin notice with one driven by `hge_klaviyo_nl_settings_complete()` linking directly to the new Settings tab.
- The Tools page diagnostic header is now rendered once at the top with a `nav-tab` switcher; both Diagnostic and Settings tabs share the wrap container.

### Internal
- Plugin loads include files in dependency order: `config.php` → `tier.php` → `settings.php` → `api-client.php` → `dispatcher.php` → `feed-endpoints.php` → `admin.php` → `activation.php`. Every function is wrapped in `function_exists` guards (47/47 verified) to support hot-loading and avoid fatals on partial inclusion.
- File structure: 9 PHP files, 2219 LOC. Lint clean on PHP 8.2.
- Audit pass: 9 ABSPATH guards (or `WP_UNINSTALL_PLUGIN` for uninstall.php), 5 `check_admin_referer`, 6 `wp_nonce_url`, 1 `wp_nonce_field`, 8 `current_user_can`, 5 `wp_safe_redirect`, 48 `esc_html`, 18 `esc_url`, 11 `esc_attr`, 2 `hash_equals`, 1 `is_email`.

## [1.0.0] — 2026-04-30

### Plugin extraction (Stage 1) — completed

#### dg5 — Plugin scaffold (done)
- Plugin scaffold created at `wp-content/plugins/hge-klaviyo-newsletter/`.
- Main plugin file with WP header, `ABSPATH` guard, plugin path/url constants.
- WC HPOS compatibility declared via `before_woocommerce_init`.
- `readme.txt` (WP.org format), `README.md` (developer/GitHub format), `CHANGELOG.md`, `.gitignore`.
- `index.php` silence files in plugin root + `includes/` + `languages/`.

#### b5f — Core migration (done)
- `includes/config.php` (105 lines): 13 plugin constants + 5 helpers (`use_web_feed`, `excluded_list_ids`, `safe_subject`, `min_interval_seconds`, `compute_send_time`). All wrapped in `function_exists` guards.
- `includes/dispatcher.php` (473 lines): all dispatch logic — `transition_post_status` + `save_post_post` hooks, `maybe_queue` / `maybe_enqueue`, `dispatch_newsletter` (210-line orchestrator), `api_request` Klaviyo client, `build_email_body`, `render_newsletter_html` HTML template (Outlook + dark mode). Each function wrapped in `function_exists` guard.
- Marker constant `HGE_KLAVIYO_NL_DISPATCHER_LOADED` defined at the end of `dispatcher.php` (after all hooks register and functions declare).
- Theme `fc-rapid-1923-child/functions.php`: legacy core block (lines 3782–4309, ~530 lines) wrapped in `if ( ! defined( 'HGE_KLAVIYO_NL_DISPATCHER_LOADED' ) ) { ... }`. When the plugin is active the marker is set and the legacy block silently skips. When the plugin is deactivated the legacy block runs as before — instant rollback.
- Verified: PHP 8.2 lint clean on all 4 modified files; all 12 migrated functions have guards; load-order for marker confirmed (post-hooks, post-functions); body of `dispatch_newsletter` byte-identical to legacy except 4 inline comment lines removed during migration.

#### 38f — Feed endpoints (done)
- `includes/feed-endpoints.php`: rewrite rules and query vars for `/feed/klaviyo.json` and `/feed/klaviyo-current.json`, both handlers (`hge_klaviyo_feed_handler` + `hge_klaviyo_current_feed_handler`), and the cache-invalidation hook (`hge_klaviyo_feed_invalidate`). All 5 functions wrapped in `function_exists` guards. The two anonymous closures from the legacy version (the `init` rewrite registrar and the `query_vars` filter) refactored to named functions to allow guarding.
- Marker constant `HGE_KLAVIYO_NL_FEEDS_LOADED` defined at the end of the file. The legacy feed block in `fc-rapid-1923-child/functions.php` (lines 3376–3633) is now wrapped in `if ( ! defined( 'HGE_KLAVIYO_NL_FEEDS_LOADED' ) ) { ... }` so it auto-disables when the plugin is active.
- The `source` field in both feed payloads switched from hardcoded `fcrapid.ro` to the dynamic site host (`parse_url( home_url(), PHP_URL_HOST )`) so the plugin is portable.
- Verified: PHP 8.2 lint clean on all files, all 5 functions guarded, 6 add_action + 1 add_filter calls registered, marker defined post-registration.

#### ck9 — Admin UI (done)
- `includes/admin.php`: full admin layer migrated. Post editor meta box (`register_meta_box` + `render_meta_box`), three admin-post handlers (`handle_send_now`, `handle_reset`, `handle_reset_cooldown`), `admin_notices` for action results, and the **Tools → Klaviyo Newsletter** page (`register_tools_page` + `render_tools_page` with diagnostic table, Web Feed status, cooldown info, placeholders documentation, and 20-row articles table with per-row Send/Reset actions).
- All 8 functions wrapped in `function_exists` guards. 6 `add_action` calls register hooks. Marker `HGE_KLAVIYO_NL_ADMIN_LOADED` defined at the end.
- Legacy block in `fc-rapid-1923-child/functions.php` (lines 4328–4706) wrapped in `if ( ! defined( 'HGE_KLAVIYO_NL_ADMIN_LOADED' ) ) { ... }`.

#### ykc — Activation / deactivation hooks (done)
- `includes/activation.php`:
  - `hge_klaviyo_nl_activate()`: deactivates the plugin and shows a helpful error if WooCommerce isn't active (Action Scheduler dependency). Calls `hge_klaviyo_register_feed_rewrites` and then `flush_rewrite_rules( false )` so feed URLs are routable immediately without manual Permalinks → Save. Stores `hge_klaviyo_nl_activated_at` option. If any required `wp-config.php` constants are missing, sets a transient that triggers a one-hour admin notice.
  - `hge_klaviyo_nl_deactivate()`: flushes rewrites, deletes the `hge_klaviyo_current_post_id` transient (avoid stale post leaking on re-activation), and unschedules all pending Action Scheduler jobs in the `hge-klaviyo` group.
  - `hge_klaviyo_nl_activation_notice()`: dashboard notice listing missing constants when present.
- `register_activation_hook` and `register_deactivation_hook` wired in main plugin file.

#### dul — Deactivation / uninstall (pending)
- Cleanup: flush rewrite, delete current-post transient, unschedule `hge-klaviyo` AS group. Persistent data (post meta, `last_send_at` option) untouched unless `HGE_KLAVIYO_NL_FULL_UNINSTALL` constant is set.

#### odl — Parity test (runbook delivered)
- Static parity verification completed locally:
  - 28 unique `hge_klaviyo_*` functions across 6 plugin files, zero duplicates.
  - All 3 wrap blocks in `fc-rapid-1923-child/functions.php` correctly opened and closed (`HGE_KLAVIYO_NL_FEEDS_LOADED` L3380→L3638, `HGE_KLAVIYO_NL_DISPATCHER_LOADED` L3799→L4326, `HGE_KLAVIYO_NL_ADMIN_LOADED` L4331→L4708).
  - Function bodies verified byte-identical between plugin and legacy (only difference: marker definitions and migration comments).
  - PHP 8.2 lint clean on all 6 plugin files + theme.
- Tools page enhanced with a "Sursă cod activă" diagnostic row that prints the actual file path of the running renderer — gives at-a-glance confirmation of which copy of the code is in control (plugin vs theme legacy fallback).
- `TESTING.md` runbook delivered: 7-phase staging checklist (deployment, plugin-inactive smoke, activation, behavioural parity, rollback, constants warning, permalinks, hook count integrity) with curl examples and a debug snippet for hook-count introspection.
- The runbook is the deliverable for `odl`: actual end-to-end testing must be executed by an operator on staging and the checklist signed off before `5he` (cleanup of legacy block from theme) can run.

#### dul — Uninstall handler (done)
- `uninstall.php` at plugin root: runs only under `WP_UNINSTALL_PLUGIN` context (rejects direct HTTP access). **Default behaviour is NO-OP** — all options, transients, post meta, and Action Scheduler queue are preserved across uninstall+reinstall cycles.
- Full wipe is opt-in via `define( 'HGE_KLAVIYO_NL_FULL_UNINSTALL', true )` in `wp-config.php`. When enabled, the handler removes:
  - `hge_klaviyo_last_send_at` and `hge_klaviyo_nl_activated_at` options
  - `hge_klaviyo_current_post_id`, `hge_klaviyo_feed_v1`, `hge_klaviyo_nl_activation_missing` transients
  - All Action Scheduler jobs in the `hge-klaviyo` group (best-effort, guarded by `function_exists`)
  - All 6 post meta keys (`_klaviyo_campaign_sent`, `_klaviyo_campaign_lock`, `_klaviyo_campaign_id`, `_klaviyo_campaign_sent_at`, `_klaviyo_campaign_scheduled_for`, `_klaviyo_campaign_last_error`) using `$wpdb->delete()` with format specifiers (no raw SQL).

#### 5he — functions.php cleanup (done)
- All three wrapped legacy blocks (`HGE_KLAVIYO_NL_FEEDS_LOADED`, `HGE_KLAVIYO_NL_DISPATCHER_LOADED`, `HGE_KLAVIYO_NL_ADMIN_LOADED`) deleted from `fc-rapid-1923-child/functions.php`.
- 1174 lines removed (file: 4709 → 3534 → 3527 lines after consolidation).
- 0 `hge_klaviyo_*` function declarations remain in the theme.
- 0 `HGE_KLAVIYO_NL_*` `define()` calls remain.
- 0 wrap markers remain.
- Replaced with two short pointer comments (one above each former block) listing the plugin path and module breakdown for future readers.
- Verified via PHP 8.2 lint (clean) and grep audit.
- **The plugin is now the single source of truth.** The theme can no longer fall back to in-theme legacy if the plugin is deactivated — operators must keep the plugin active.

### Stage 1 status: 8/9 tasks complete (m8x optional, separate repo init)

## [2026.04.29.10-no-smart-exclude] — 2026-04-29

### Changed
- `send_options.use_smart_sending` forced to `false` for all campaigns (delivery to all subscribers regardless of recent emails).
- `audiences.excluded` defaults to `['UBAKWB']`, configurable via `KLAVIYO_NEWSLETTER_EXCLUDED_LISTS` constant (string CSV or array).

### Added
- Tools page rows showing Smart Sending status and excluded lists.

## [2026.04.29.9-web-feed-mode] — 2026-04-29

### Added
- **Web Feed mode** (opt-in via `KLAVIYO_NEWSLETTER_USE_WEB_FEED=true`): a single Klaviyo master template can pull post data dynamically via Jinja from a secure endpoint.
- New endpoint `/feed/klaviyo-current.json` returns the single active article based on a transient `hge_klaviyo_current_post_id` (1h TTL).
- In Web Feed mode, the cooldown is enforced server-side via Action Scheduler delay (`as_schedule_single_action`), since Klaviyo `static_time` would outlive the transient.
- Tools page rows: Web Feed mode status, feed URL, currently active post in transient.

### Changed
- Dispatcher: in Web Feed mode, master template is assigned directly to the campaign (no per-campaign template creation). Legacy mode unchanged.

## [2026.04.29.8-lock-ttl-15m] — 2026-04-29

### Changed
- Post-meta lock TTL extended from 5 to 15 minutes to safely cover API timeouts (25s) plus retry windows.

## [2026.04.29.7-hardening] — 2026-04-29

### Added
- `hge_klaviyo_safe_subject()` — strips diacritics (`remove_accents`), removes non-ASCII, collapses whitespace, smart-truncates at 60 chars (configurable via `hge_klaviyo_subject_length` filter), preserves whole words.
- Idempotency check before campaign creation: if `_klaviyo_campaign_id` already exists for the post, mark as sent and skip re-creation (prevents duplicate Klaviyo campaigns from partial-failure retries).
- Built-in HTML template hardening: `color-scheme: light dark` meta, MSO conditional styles, VML button for Outlook desktop, `mso-line-height-rule`, `[data-ogsc]` Outlook.com dark mode selectors, `prefers-color-scheme` media query, hidden preview text.

### Changed
- Email subject now ASCII-safe by default for deliverability across older mail clients.

## [2026.04.29.6-throttle-12h] — 2026-04-29

### Added
- Global cooldown of **12 hours** between sends (configurable via `KLAVIYO_NEWSLETTER_MIN_INTERVAL_HOURS`).
- Option `hge_klaviyo_last_send_at` tracks the last scheduled send time. Subsequent posts published within the cooldown window are scheduled with `send_strategy: static_time` at `last_send + 12h` (chained for multiple rapid publications).
- Tools page rows: cooldown duration, last send, next allowed send (with countdown), reset cooldown button.
- Per-post meta `_klaviyo_campaign_scheduled_for` shows in the article table when a campaign is scheduled (not yet sent).

## [2026.04.29.5-template-id] — 2026-04-29

### Added
- `KLAVIYO_NEWSLETTER_TEMPLATE_ID` constant: when defined, the dispatcher fetches the master template from Klaviyo, substitutes placeholders (`{{title}}`, `{{excerpt}}`, `{{image}}`, `{{url}}`, `{{date}}`, `{{site}}`), and creates a rendered per-campaign template.
- Tools page rows: template ID status, available placeholders documentation table.

### Changed
- Default excerpt length increased from 40 to **120 characters** (configurable via `hge_klaviyo_excerpt_length` filter).

## [2026.04.29.4-fix-scope] — 2026-04-29

### Fixed
- **Critical**: the entire Klaviyo Newsletter block was accidentally encapsulated inside the Cloudflare cache-purge anonymous callback because a closing `}, 99);` was missing in the right place. Code only executed on `save_post` events, and on the second save would fatal with "Cannot redeclare function". Closed the Cloudflare callback at the correct line and removed the orphan `}, 99);` from the end of the file.

## [2026.04.29.3] — 2026-04-29

### Added — Auto-newsletter pipeline
- `transition_post_status` and `save_post_post` hooks queue a dispatch action (Action Scheduler preferred, WP-Cron fallback) when a post tagged `trimitenl` enters publish.
- `hge_klaviyo_dispatch_newsletter()` orchestrates: fetch post data, build email body, create Klaviyo template, create campaign, assign template to message, launch send-job. All wrapped in try/catch with error logging to post meta.
- Atomic post-meta lock (`unique=true`) prevents concurrent dispatches for the same post.
- 5 layers of duplicate-send prevention.

### Added — Diagnostics & control
- Per-post meta box on the editor screen with checklist (tag, publish, config, AS, lock), status, last error, manual "Send now" / "Reset" buttons.
- Tools admin page (Tools → Klaviyo Newsletter) showing version, full diagnostic table, and the last 20 tagged articles with per-row actions.
- Admin notices for action results.

## [2026.04.29.2] — 2026-04-29

### Added — Feed hardening
- `/feed/klaviyo.json` secured with token authentication (`KLAVIYO_FEED_TOKEN` constant).
- Constant-time token comparison via `hash_equals`.
- Two auth channels: `X-Feed-Token` header (preferred) or `?key=` query parameter.
- GET / HEAD only (405 on others).
- Security headers: `X-Robots-Tag: noindex, nofollow, noarchive`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`.
- 5-minute transient cache invalidated on `save_post_post` / `deleted_post` / `trashed_post`.
- Idempotent attachment of RSS filters to avoid closure accumulation across multiple `WP_Query` runs in the same request.
- Categories AND tags emitted in RSS `<category>` elements (was: only first category).

## [2026.04.29.1] — 2026-04-29

### Added
- WordPress RSS feed filter `the_category_rss` now emits both the first category and all post tags as valid `<category><![CDATA[...]]></category>` XML elements.

### Fixed
- Previous filter returned plain category name (not wrapped XML) which produced malformed RSS.
