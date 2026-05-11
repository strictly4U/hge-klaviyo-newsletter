#!/usr/bin/env python3
"""
Build a Romanian .po from the .pot using a hand-curated translation dictionary.

The dictionary preserves the v3.0.0 Romanian UX that shipped on the original
FC Rapid 1923 deployment, so the FC site keeps its Romanian admin labels
after the v3.0.1 i18n refactor (English-as-source).

For any msgid not in TRANSLATIONS, msgstr stays empty — WordPress falls back
to the English source string.

Usage:  python bin/build-ro-po.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

PLUGIN_ROOT = Path(__file__).resolve().parent.parent
POT_PATH = PLUGIN_ROOT / "languages" / "hge-klaviyo-newsletter.pot"
PO_PATH = PLUGIN_ROOT / "languages" / "hge-klaviyo-newsletter-ro_RO.po"

# msgid → Romanian msgstr. Strings here mirror the Romanian UX used pre-v3.0.1
# on the original FC Rapid 1923 deployment.
TRANSLATIONS = {
    # tier.php
    "Available in Pro plan": "Disponibil în planul Pro",
    "Available in Core plan": "Disponibil în planul Core",

    # activation.php
    "HgE Klaviyo Newsletter requires <strong>WooCommerce</strong> to be active (it provides Action Scheduler).<br>%s":
        "HgE Klaviyo Newsletter necesită <strong>WooCommerce</strong> activ (oferă Action Scheduler).<br>%s",
    "Back to Plugins": "Înapoi la Plugin-uri",
    "Plugin dependency missing": "Dependență plugin lipsă",
    "configuration is incomplete (API key, feed token, or recipient list is missing). Go to %s to complete it.":
        "configurarea nu este completă (lipsește API key, feed token sau lista de trimitere). Mergi la %s pentru a completa.",
    "Tools → Klaviyo Newsletter → Settings": "Unelte → Klaviyo Newsletter → Setări",

    # admin.php — meta box
    "Klaviyo Newsletter": "Klaviyo Newsletter",
    "Status: ": "Status: ",
    "Sent": "Trimis",
    "Campaign ID:": "Campaign ID:",
    "At:": "La:",
    "Queued (Action Scheduler)": "În coadă (Action Scheduler)",
    "Not sent": "Netrimis",
    "Matched rule — tag": "Regulă potrivită — tag",
    "No active rule tag is present on this post": "Niciun tag al regulilor active prezent pe articol",
    "Status:": "Status:",
    "Plugin configuration": "Configurare plugin",
    "incomplete — see %s": "incompletă — vezi %s",
    "Settings": "Setări",
    "not loaded": "neîncărcat",
    "Active lock since:": "Lock activ din:",
    "Last error:": "Ultima eroare:",
    "Send the newsletter to the configured Klaviyo list now?": "Trimit newsletter-ul către lista Klaviyo configurată acum?",
    "Send now": "Trimite acum",
    "Reset the Klaviyo status for this post? This allows re-sending.": "Resetez statusul Klaviyo pentru articol? Permite re-trimitere.",
    "Reset status": "Reset status",

    # admin.php — notices
    "Newsletter sent successfully via Klaviyo.": "Newsletter trimis cu succes prin Klaviyo.",
    "Error sending newsletter — see \"Last error\" in the meta box.": "Eroare la trimiterea newsletter — vezi „Ultima eroare\" în meta box.",
    "Uncertain status — check Custom Fields manually.": "Status incert — verifică Custom Fields manual.",
    "Klaviyo status reset. You can re-send.": "Status Klaviyo resetat. Poți retrimite.",
    "Global cooldown reset. The next publish sends immediately.": "Cooldown global resetat. Următoarea publicare se trimite imediat.",

    # admin.php — Status tab
    "Status": "Status",
    "Code version (constant)": "Versiune cod (constant)",
    "Active code source": "Sursă cod activă",
    "theme legacy": "theme legacy",
    "Configuration": "Configurare",
    "complete": "completă",
    "Settings tab": "tab-ul Setări",
    "loaded": "încărcat",
    "not loaded (check WooCommerce)": "neîncărcat (verifică WooCommerce)",
    "Configured rules": "Reguli configurate",
    "plan": "plan",
    "Feed token": "Token Feed",
    "configured": "configurat",
    "characters": "caractere",
    "not defined — Klaviyo cannot authenticate to the feed": "nedefinit — Klaviyo nu se poate autentifica la feed",
    "Excerpt length": "Lungime descriere scurtă",
    "Subject length (ASCII only)": "Lungime subiect (doar ASCII)",
    "characters, no diacritics": "caractere, fără diacritice",
    "OFF": "OPRIT",
    "all list recipients receive the campaign": "toți destinatarii din listă primesc",
    "Minimum interval between sends": "Pauză minimă între trimiteri",
    "hours": "ore",
    "per rule": "per regulă",
    "Active rules": "Reguli active",
    "Tag(s)": "Tag(uri)",
    "Included": "Incluse",
    "Excluded": "Excluse",
    "Template": "Template",
    "Web Feed (name)": "Web Feed (nume)",
    "Active post": "Articol activ",
    "Last send (UTC)": "Ultima trimitere (UTC)",
    "post not found, id=": "post inexistent, id=",
    "built-in": "built-in",
    "ACTIVE": "ACTIV",
    "Reset the legacy global cooldown? Per-rule cooldowns remain untouched.": "Resetez cooldown-ul global legacy? Cooldown-urile per-regulă rămân neatinse.",
    "Reset legacy global cooldown": "Reset cooldown global legacy",
    "resets the v2.x legacy option. Per-rule cooldowns remain in": "resetează opțiunea v2.x legacy. Cooldown-urile per-regulă rămân în",
    "Placeholders available in the Klaviyo template": "Placeholder-e disponibile în template-ul Klaviyo",
    "Drop any of these into your Klaviyo template HTML (selected in Settings); they are replaced per post before the campaign is dispatched.":
        "Pune oricare dintre acestea în HTML-ul template-ului tău Klaviyo (selectat în Setări); le înlocuim per articol înainte să trimitem campania.",
    "Post title (HTML escaped)": "Titlul articolului (HTML escaped)",
    "Short description (max 120 chars, HTML escaped)": "Descrierea scurtă (max 120 caractere, HTML escaped)",
    'Featured image URL (use inside <code>src=""</code>)': 'URL-ul imaginii featured (folosește în <code>src=""</code>)',
    'Post URL with UTM (use inside <code>href=""</code>)': 'URL-ul articolului cu UTM (folosește în <code>href=""</code>)',
    "Publication date (WP-formatted)": "Data publicării (formatat WP)",
    "Site name": "Numele site-ului",
    "No rule with a <code>tag_slug</code> configured. Set at least one rule in %s.":
        "Nicio regulă cu <code>tag_slug</code> configurat. Setează cel puțin o regulă în %s.",
    "Posts with configured tags (%s) — last 20": "Articole cu tag-uri configurate (%s) — ultimele 20",
    "No posts found with any of the configured tags.": "Niciun articol găsit cu vreunul dintre tag-urile configurate.",
    "Title": "Titlu",
    "WP status": "Status WP",
    "Sent?": "Trimis?",
    "Campaign ID": "Campaign ID",
    "Scheduled / Sent at (UTC)": "Programat / Trimis la (UTC)",
    "Error": "Eroare",
    "Actions": "Acțiuni",
    "dispatch:": "dispatch:",
    "Send newsletter to the Klaviyo list?": "Trimit newsletter către lista Klaviyo?",
    "Reset Klaviyo status?": "Reset status Klaviyo?",
    "Send": "Trimite",
    "Reset": "Reset",

    # admin.php — friendly_api_error
    "No Klaviyo API key configured. Fill in the <strong>Klaviyo API Key</strong> field above and click <strong>Save settings</strong>.":
        "Nicio cheie API Klaviyo configurată. Completează câmpul <strong>Cheie API Klaviyo</strong> de mai sus și apasă <strong>Salvează setările</strong>.",
    "The Klaviyo API key is invalid or has been revoked. Generate a new key in Klaviyo &rarr; Settings &rarr; API Keys, replace it in the <strong>Klaviyo API Key</strong> field above and click <strong>Save settings</strong>.":
        "Cheia API Klaviyo este invalidă sau a fost revocată. Generează o cheie nouă din Klaviyo &rarr; Settings &rarr; API Keys, înlocuiește-o în câmpul <strong>Cheie API Klaviyo</strong> de mai sus și apasă <strong>Salvează setările</strong>.",
    "The Klaviyo API key lacks the required scopes. Required: <code>campaigns:write</code>, <code>templates:write</code>, <code>lists:read</code>, <code>segments:read</code>. Generate a new key with all scopes checked and save.":
        "Cheia API Klaviyo nu are scope-urile necesare. Trebuie: <code>campaigns:write</code>, <code>templates:write</code>, <code>lists:read</code>, <code>segments:read</code>. Generează o cheie nouă cu toate scope-urile bifate și salvează.",
    "Klaviyo applied rate-limiting (too many requests in a short window). Wait a few minutes and try again.":
        "Klaviyo a aplicat rate-limiting (prea multe cereri într-un interval scurt). Așteaptă câteva minute și încearcă din nou.",
    'The Klaviyo server is not responding correctly (5xx). Try again in a few minutes. If the issue persists, check <a href="https://status.klaviyo.com/" target="_blank" rel="noopener">status.klaviyo.com</a>.':
        'Server-ul Klaviyo nu răspunde corect (5xx). Încearcă din nou peste câteva minute. Dacă persistă, verifică <a href="https://status.klaviyo.com/" target="_blank" rel="noopener">status.klaviyo.com</a>.',
    "Network error. The WordPress server cannot reach <code>a.klaviyo.com</code>. Check DNS, the firewall, or whether an outbound proxy is in place on this install.":
        "Eroare de rețea. Server-ul WordPress nu poate ajunge la <code>a.klaviyo.com</code>. Verifică DNS-ul, firewall-ul sau dacă există un proxy de ieșire pe această instalare.",

    # admin.php — Settings tab
    "General settings": "Setări generale",
    "Klaviyo API Key": "Cheie API Klaviyo",
    "Private API key (Klaviyo → Settings → API Keys). Required scopes: <code>campaigns:write</code>, <code>templates:write</code>, <code>lists:read</code>, <code>segments:read</code>.":
        "Cheie API privată (Klaviyo → Settings → API Keys). Scopes necesare: <code>campaigns:write</code>, <code>templates:write</code>, <code>lists:read</code>, <code>segments:read</code>.",
    "Random string (32+ chars) used to authenticate requests to <code>/feed/klaviyo*.json</code>. Generate with <code>openssl rand -hex 32</code>.":
        "String aleator (32+ caractere) folosit pentru autentificarea cererilor către <code>/feed/klaviyo*.json</code>. Generează cu <code>openssl rand -hex 32</code>.",
    "Klaviyo data": "Date Klaviyo",
    "Reload from Klaviyo": "Reîncarcă din Klaviyo",
    "%1$d lists, %2$d templates (5 min cache)": "%1$d liste, %2$d template-uri (cache 5 min)",
    "%1$d lists, %2$d segments, %3$d templates (5 min cache)": "%1$d liste, %2$d segmente, %3$d template-uri (cache 5 min)",
    "Templates:": "Template-uri:",
    "Segments:": "Segmente:",
    "Lists": "Liste",
    "Segments": "Segmente",
    "choose a list or segment": "alege listă sau segment",
    "for up to 15 lists/segments per rule.": "pentru până la 15 liste/segmente per regulă.",
    'No template saved in your Klaviyo account. Create one in <a href="https://www.klaviyo.com/email-templates" target="_blank" rel="noopener">Klaviyo &rarr; Email Templates</a> (any name + Code/HTML or Drag & Drop editor), then click <strong>Reload from Klaviyo</strong>.':
        'Niciun template salvat în contul Klaviyo. Creează unul în <a href="https://www.klaviyo.com/email-templates" target="_blank" rel="noopener">Klaviyo &rarr; Email Templates</a> (orice nume + editor Code/HTML sau Drag & Drop), apoi apasă <strong>Reîncarcă din Klaviyo</strong>.',
    "Reply-to address (optional)": "Adresă răspuns (opțional)",
    "When set, overrides the reply-to configured in Klaviyo. Leave empty to use the Klaviyo account default.":
        "Dacă e completat, suprascrie adresa de răspuns setată în Klaviyo. Lasă gol pentru a folosi cea implicită din contul Klaviyo.",
    "Minimum interval between sends (hours)": "Pauză minimă între trimiteri (ore)",
    "Default 12. Cooldown is applied <strong>per rule</strong> (per tag). Set 0 to disable.":
        "Implicit 12. Cooldown-ul se aplică <strong>per regulă</strong> (per tag). Setează 0 pentru a dezactiva.",
    "Debug mode": "Mod debug",
    "Enable the <strong>Status</strong> tab (diagnostic + activity logs + raw server responses)":
        "Activează tab-ul <strong>Status</strong> (diagnostic + activity logs + raw server responses)",
    "Leave off in production. Turn on when you need to inspect the webhook / dispatch / API response flow.":
        "Lasă oprit în producție. Pornește când ai nevoie să verifici fluxul webhook / dispatch / API responses.",
    "PRO": "PRO",
    "CORE": "CORE",
    "FREE": "GRATUIT",
    "Newsletter rules": "Reguli newsletter",
    "Each rule maps a post <strong>tag</strong> to a configuration: <em>recipient list(s)</em>, <em>excluded list(s)</em> (Core+), <em>Klaviyo template</em> (Pro) and <em>Web Feed mode</em> (Pro). When a post is published, the plugin matches the first rule whose tag is present on the post (card order = priority) and dispatches using that rule. Cooldown is applied separately per rule (per tag).":
        "Fiecare regulă mapează un <strong>tag</strong> de pe articol la o configurație: <em>liste destinatari</em>, <em>liste excluse</em> (Core+), <em>template Klaviyo</em> (Pro) și <em>Mod Web Feed</em> (Pro). La publicarea unui articol, plugin-ul caută prima regulă a cărei tag este prezent pe articol (ordinea din pagină = prioritate) și trimite folosind acea regulă. Cooldown-ul se aplică separat per regulă (per tag).",
    "Current plan:": "Plan curent:",
    "Save the <strong>Klaviyo API Key</strong> above first so that lists and templates can be loaded into the rule cards.":
        "Salvează mai întâi <strong>Cheie API Klaviyo</strong> mai sus, pentru ca listele și template-urile să poată fi încărcate în card-urile regulilor.",
    "Could not load lists from Klaviyo:": "Nu s-au putut încărca listele din Klaviyo:",
    "Add rule": "Adaugă regulă",
    "Save settings": "Salvează setările",
    "Settings saved.": "Setările au fost salvate.",
    "Klaviyo API cache cleared. The next render will fetch fresh data.":
        "Cache-ul API Klaviyo a fost golit. Următorul render va fetch-ui date proaspete.",
    "This is the only rule. Deleting it stops all automatic sends. Continue?": "Aceasta este singura regulă. Ștergerea va opri toate trimiterile automate. Continui?",
    "Delete this rule? The change takes effect after Save.": "Ștergi această regulă? Modificarea devine efectivă după Salvează.",

    # admin.php — rule card
    "Rule": "Regulă",
    "Delete rule": "Șterge regula",
    "Trigger tag(s)": "Tag(uri) declanșator(i)",
    "Trigger tag": "Tag declanșator",
    "WordPress tag slug that triggers this rule. <strong>Pro:</strong> multiple comma-separated tags, e.g. <code>news,promo,events</code> (any present tag fires the rule — OR semantics).":
        "Slug-ul tag-ului WordPress care declanșează această regulă. <strong>Pro:</strong> mai multe tag-uri separate prin virgulă, ex: <code>news,promo,events</code> (orice tag prezent declanșează regula — semantică OR).",
    "WordPress tag slug that triggers this rule. Ex: <code>newsletter</code>.":
        "Slug-ul tag-ului WordPress care declanșează această regulă. Ex: <code>newsletter</code>.",
    "for multi-tag (comma-separated).": "pentru multi-tag (comma-separated).",
    "Recipient list(s)": "Listă(e) destinatari",
    "Save the API Key to load the lists.": "Salvează API Key pentru a încărca listele.",
    "choose a list": "alege listă",
    "for up to 15 lists per rule.": "pentru până la 15 liste/regulă.",
    "Excluded list(s)": "Listă(e) excluse",
    "to be able to exclude lists from the audience.": "pentru a putea exclude liste din audiență.",
    "Klaviyo limit: included + excluded ≤ 15.": "Limită Klaviyo: incluse + excluse ≤ 15.",
    "Klaviyo template": "Template Klaviyo",
    "Built-in HTML template": "Template HTML încorporat",
    "to pick a template from your Klaviyo account.": "pentru a alege un template din contul Klaviyo.",
    "use the built-in HTML template": "folosește template-ul HTML încorporat",
    "In Web Feed mode, your template must use <code>{{ web_feeds.NAME.items.0.* }}</code>.":
        "Pentru modul Web Feed, template-ul trebuie să folosească <code>{{ web_feeds.NAME.items.0.* }}</code>.",
    "Web Feed mode": "Mod Web Feed",
    "Unavailable": "Indisponibil",
    "for Web Feed mode (1 template + dynamic data).": "pentru Mod Web Feed (1 template + date dinamice).",
    "Use Web Feed (1 master template + dynamic data)": "Folosește Web Feed (1 template master + date dinamice)",
    "Web Feed name in Klaviyo:": "Numele Web Feed-ului în Klaviyo:",
    "Exact name configured in Klaviyo → Settings → Web Feeds.": "Numele exact configurat în Klaviyo → Settings → Web Feeds.",
    "URL for Klaviyo Web Feed (this rule):": "URL pentru Klaviyo Web Feed (această regulă):",
}

# Plural forms: msgid → (msgstr[0], msgstr[1])
PLURAL_TRANSLATIONS = {
    ("subscriber", "subscribers"): ("abonat", "abonați"),
    ("max %d rule", "max %d rules"): ("maxim %d regulă", "maxim %d reguli"),
    ("You have reached the plan limit for <strong>%1$s</strong> (%2$d rule).",
     "You have reached the plan limit for <strong>%1$s</strong> (%2$d rules)."):
        ("Ai atins limita planului <strong>%1$s</strong> (%2$d regulă).",
         "Ai atins limita planului <strong>%1$s</strong> (%2$d reguli)."),
    ("Max <strong>%d</strong> list per rule.", "Max <strong>%d</strong> lists or segments per rule."):
        ("Maxim <strong>%d</strong> listă/segment pe regulă.", "Maxim <strong>%d</strong> liste sau segmente pe regulă."),
    ("Max <strong>%d</strong> excluded list.", "Max <strong>%d</strong> excluded lists or segments."):
        ("Maxim <strong>%d</strong> exclusă.", "Maxim <strong>%d</strong> excluse (liste sau segmente)."),
}


def po_escape(s: str) -> str:
    return s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n")


def parse_pot(path: Path):
    """Parse the .pot. Returns (singular_entries, plural_entries) where each entry is
    a list of (refs:list[str], msgid:str, [msgid_plural:str|None])."""
    text = path.read_text(encoding="utf-8")
    # Drop header section (first empty msgid stanza).
    parts = re.split(r"\n\n+", text.strip())
    entries: list[tuple[list[str], str, str | None]] = []
    for stanza in parts[1:]:
        refs = []
        msgid = None
        msgid_plural = None
        msgid_lines: list[str] = []
        capture: str | None = None
        for line in stanza.split("\n"):
            if line.startswith("#: "):
                refs.append(line[3:].strip())
            elif line.startswith("msgid_plural "):
                if msgid is None and msgid_lines:
                    msgid = "".join(msgid_lines)
                msgid_lines = [_strip_quoted(line[len("msgid_plural ") :])]
                capture = "plural"
            elif line.startswith("msgid "):
                if msgid is None and msgid_lines:
                    msgid = "".join(msgid_lines)
                msgid_lines = [_strip_quoted(line[len("msgid ") :])]
                capture = "singular"
            elif line.startswith('"') and capture:
                msgid_lines.append(_strip_quoted(line))
            elif line.startswith("msgstr"):
                if capture == "plural":
                    if msgid_plural is None:
                        msgid_plural = "".join(msgid_lines)
                else:
                    if msgid is None:
                        msgid = "".join(msgid_lines)
                # Stop accumulating msgid/plural buffer; further msgstr[N] lines
                # (e.g., msgstr[2] for languages with 3 plural forms) just close.
                capture = None
                msgid_lines = []
        if msgid is None and msgid_lines:
            msgid = "".join(msgid_lines)
        if msgid is not None:
            # Unescape PO → Python
            msgid_py = po_unescape(msgid)
            plural_py = po_unescape(msgid_plural) if msgid_plural else None
            entries.append((refs, msgid_py, plural_py))
    return entries


def _strip_quoted(s: str) -> str:
    s = s.strip()
    if s.startswith('"') and s.endswith('"'):
        return s[1:-1]
    return s


def po_unescape(s: str) -> str:
    s = s.replace("\\\\", "\x00")
    s = s.replace('\\"', '"')
    s = s.replace("\\n", "\n")
    s = s.replace("\x00", "\\")
    return s


def main() -> int:
    if not POT_PATH.exists():
        print(f"ERROR: {POT_PATH} not found. Run bin/extract-pot.py first.", file=sys.stderr)
        return 1

    entries = parse_pot(POT_PATH)

    out: list[str] = []
    out.append(
        "# Romanian translation for HgE Klaviyo Newsletter.\n"
        "# Preserves the pre-v3.0.1 admin UX for ro_RO locales (notably the\n"
        "# original FC Rapid 1923 deployment).\n"
        "msgid \"\"\n"
        "msgstr \"\"\n"
        "\"Project-Id-Version: HgE Klaviyo Newsletter\\n\"\n"
        "\"Language: ro_RO\\n\"\n"
        "\"MIME-Version: 1.0\\n\"\n"
        "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
        "\"Content-Transfer-Encoding: 8bit\\n\"\n"
        "\"Plural-Forms: nplurals=3; plural=(n==1 ? 0 : (n==0 || (n%100 > 0 && n%100 < 20)) ? 1 : 2);\\n\"\n"
        "\n"
    )

    translated = 0
    untranslated = 0
    for refs, msgid, msgid_plural in entries:
        if refs:
            out.append("#: " + " ".join(refs) + "\n")
        if msgid_plural is None:
            tr = TRANSLATIONS.get(msgid, "")
            if tr:
                translated += 1
            else:
                untranslated += 1
            out.append(f'msgid "{po_escape(msgid)}"\n')
            out.append(f'msgstr "{po_escape(tr)}"\n\n')
        else:
            tr_tuple = PLURAL_TRANSLATIONS.get((msgid, msgid_plural))
            if tr_tuple:
                tr_one, tr_many = tr_tuple
                translated += 1
            else:
                tr_one, tr_many = "", ""
                untranslated += 1
            out.append(f'msgid "{po_escape(msgid)}"\n')
            out.append(f'msgid_plural "{po_escape(msgid_plural)}"\n')
            out.append(f'msgstr[0] "{po_escape(tr_one)}"\n')
            out.append(f'msgstr[1] "{po_escape(tr_many)}"\n')
            out.append(f'msgstr[2] "{po_escape(tr_many)}"\n\n')

    PO_PATH.write_text("".join(out), encoding="utf-8")
    print(f"Wrote {PO_PATH.relative_to(PLUGIN_ROOT)} — "
          f"{translated} translated, {untranslated} untranslated "
          f"(fall back to English source).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
