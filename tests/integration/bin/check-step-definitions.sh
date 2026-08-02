#!/usr/bin/env bash
#
# Fast structural checks on the Behat step definitions, so two mistakes that cost a
# FULL CI CYCLE each are caught in a second instead.
#
# Both have already happened on this repo, and both fail in a way that points nowhere
# near the cause:
#
#   1. DUPLICATE STEP TEXT. Behat ignores the keyword when matching, so the same
#      sentence under @Given and @When is ONE step registered twice. Behat refuses the
#      second and then fails EVERY scenario in the suite — including ones that never
#      mention it — reporting "already defined" against whatever step happened to run
#      first. It reads as "the app is broken".
#
#   2. PARENTHESES IN A PATTERN. Behat reads `(...)` as an optional group, so
#      `Grafana is not contacted (already deleted)` also matches the bare
#      `Grafana is not contacted` and collides with it. Reported as an ambiguous match
#      on a line resembling neither pattern. The one legitimate use is the optional
#      plural, `file(s)`.
#
# Runs in the PHP Quality job, which finishes in seconds — the integration job takes
# minutes and needs a live Nextcloud and Grafana to tell you the same thing.

set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")/../bootstrap" && pwd)"
fail=0

patterns="$(grep -rhoP '^\s*\*?\s*@(?:Given|When|Then)\s+\K.+?(?=\s*$)' "$here" || true)"

dupes="$(printf '%s\n' "$patterns" | sort | uniq -d)"
if [ -n "$dupes" ]; then
  echo "✘ DUPLICATE STEP TEXT — Behat ignores the keyword, so these register twice and fail the whole suite:"
  printf '    %s\n' "$dupes"
  echo "  One function may carry several phrasings, but never the same phrasing twice."
  fail=1
fi

parens="$(printf '%s\n' "$patterns" | grep -F '(' | grep -vF '(s)' || true)"
if [ -n "$parens" ]; then
  echo "✘ PARENTHESES IN A STEP PATTERN — Behat treats these as an OPTIONAL group:"
  printf '    %s\n' "$parens"
  echo "  Keep asides in a comment above the scenario. Only the optional plural '(s)' is allowed."
  fail=1
fi

[ "$fail" -eq 0 ] && echo "✓ step definitions: no duplicate text, no stray optional groups"
exit "$fail"
