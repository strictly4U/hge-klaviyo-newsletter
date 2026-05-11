#!/usr/bin/env python3
"""
Extract translatable strings from the plugin's PHP files and write a .pot file.

This is a minimal substitute for `wp i18n make-pot`; it covers the WordPress
i18n functions we actually use here (__, esc_html__, esc_attr__, _e, _n).
The CI workflow at .github/workflows/i18n.yml regenerates the file via wp-cli
on every push to main — this script exists so contributors without wp-cli
can refresh the .pot locally too.

Usage:  python bin/extract-pot.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

PLUGIN_ROOT = Path(__file__).resolve().parent.parent
POT_PATH = PLUGIN_ROOT / "languages" / "hge-klaviyo-newsletter.pot"
TEXTDOMAIN = "hge-klaviyo-newsletter"

# Match: __( 'string', 'textdomain' ), esc_html__( "string", 'textdomain' ), etc.
# Captures: 1=quote, 2=msgid, 3=textdomain (when present)
SINGULAR_RE = re.compile(
    r"\b(?:__|esc_html__|esc_attr__|_e|_x|esc_html_x|esc_attr_x)\s*\(\s*"
    r"(['\"])((?:\\\1|(?!\1).)*)\1\s*,\s*['\"]" + TEXTDOMAIN + r"['\"]\s*\)",
    re.DOTALL,
)

# _n( 'one', 'many', $n, 'textdomain' )
PLURAL_RE = re.compile(
    r"\b_n\s*\(\s*"
    r"(['\"])((?:\\\1|(?!\1).)*)\1\s*,\s*"
    r"(['\"])((?:\\\3|(?!\3).)*)\3\s*,\s*"
    r"[^,]+,\s*['\"]" + TEXTDOMAIN + r"['\"]\s*\)",
    re.DOTALL,
)


def unescape(s: str, quote: str) -> str:
    """Unescape the captured PHP string content (only backslash-quote + backslash-backslash)."""
    s = s.replace("\\\\", "\x00")
    s = s.replace("\\" + quote, quote)
    s = s.replace("\x00", "\\")
    return s


def po_escape(s: str) -> str:
    """Escape for use inside a quoted PO msgid/msgstr."""
    return s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n")


def scan(root: Path):
    singular: dict[str, list[str]] = {}
    plural: dict[tuple[str, str], list[str]] = {}

    for php in sorted(root.rglob("*.php")):
        rel = php.relative_to(root).as_posix()
        # skip vendor/ + node_modules/ + the legacy index.php silence files
        if rel.startswith(("vendor/", "node_modules/", "bin/")):
            continue
        text = php.read_text(encoding="utf-8")
        for m in SINGULAR_RE.finditer(text):
            msgid = unescape(m.group(2), m.group(1))
            if not msgid:
                continue
            line = text[: m.start()].count("\n") + 1
            singular.setdefault(msgid, []).append(f"{rel}:{line}")
        for m in PLURAL_RE.finditer(text):
            msgid = unescape(m.group(2), m.group(1))
            msgid_plural = unescape(m.group(4), m.group(3))
            line = text[: m.start()].count("\n") + 1
            plural.setdefault((msgid, msgid_plural), []).append(f"{rel}:{line}")
    return singular, plural


def write_pot(singular, plural, path: Path) -> None:
    # Header is deterministic — no POT-Creation-Date, so re-running the script
    # produces no diff unless source strings actually changed. Translators and
    # CI alike rely on stable bytes here (Poedit shows the file as "modified"
    # otherwise even when nothing translatable moved).
    header = (
        "# Copyright (C) 2026 HgE\n"
        "# This file is distributed under the GPLv2 or later.\n"
        'msgid ""\n'
        'msgstr ""\n'
        '"Project-Id-Version: HgE Klaviyo Newsletter\\n"\n'
        '"Report-Msgid-Bugs-To: https://github.com/strictly4U/hge-klaviyo-newsletter/issues\\n"\n'
        '"MIME-Version: 1.0\\n"\n'
        '"Content-Type: text/plain; charset=UTF-8\\n"\n'
        '"Content-Transfer-Encoding: 8bit\\n"\n'
        '"X-Domain: ' + TEXTDOMAIN + '\\n"\n'
        "\n"
    )

    lines: list[str] = [header]
    for msgid in sorted(singular):
        refs = " ".join(singular[msgid])
        lines.append(f"#: {refs}\n")
        lines.append(f'msgid "{po_escape(msgid)}"\n')
        lines.append('msgstr ""\n\n')

    for (s, p) in sorted(plural):
        refs = " ".join(plural[(s, p)])
        lines.append(f"#: {refs}\n")
        lines.append(f'msgid "{po_escape(s)}"\n')
        lines.append(f'msgid_plural "{po_escape(p)}"\n')
        lines.append('msgstr[0] ""\n')
        lines.append('msgstr[1] ""\n\n')

    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("".join(lines), encoding="utf-8")


def main() -> int:
    singular, plural = scan(PLUGIN_ROOT)
    write_pot(singular, plural, POT_PATH)
    print(f"Wrote {POT_PATH.relative_to(PLUGIN_ROOT)} "
          f"({len(singular)} singular, {len(plural)} plural entries)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
