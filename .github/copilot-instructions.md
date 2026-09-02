<!--
  SPDX-FileCopyrightText: 2026 kubed-io
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Copilot code review — Grafana Sync (a Nextcloud app)

## Purpose & scope

You are reviewing pull requests for a **Nextcloud app**: a PHP backend under
`lib/` (namespace `OCA\GrafanaSync`) and a small vanilla-JS frontend under `js/`
and `src/`. Review changes against **this project's** standards below, not generic
ones.

**Read these repo files first — they are the source of truth, and you should back
your comments with them:**

- **`AGENTS.md`** — cold-start orientation + the architectural non-negotiables.
- **`CONTRIBUTING.md`** — conventions, PR rules, testing policy.
- **`SECURITY.md`** — the deliberate trust boundary (esp. network egress).
- **`saga/`** (current, open chapter) — the "why" behind the design. If the saga
  locks a decision, do not suggest relitigating it.

Prefer these over assumptions. When a convention is undocumented here, the mature
sibling repo `kubed-io/nextcloud-n8n` is the reference — this app is a deliberate
copy of it with the backend swapped to Grafana, so **keep the two in parity**.

## The principle that dominates every review: be Nextcloud-native

This is a Nextcloud app, **not "a PHP project that runs inside Nextcloud."** The
most valuable comment you can make here finds code that reinvents something the
framework already provides. In priority order:

- **Flag anything hand-rolled that a Nextcloud primitive already does**, and name
  the primitive. The common ones in this codebase:
  - HTTP out → `OCP\Http\Client\IClientService` — never `curl`, `file_get_contents`, or a raw Guzzle client.
  - Config → `OCP\IAppConfig` (with `sensitive` for secrets) — never files or custom tables.
  - Secret encryption → `OCP\Security\ICrypto` — never plaintext, base64, or a homemade cipher.
  - Background work → `OCP\BackgroundJob\*` — never raw cron, `sleep` loops, or shelling out.
  - Settings UI → the declarative settings / admin section pattern — not a bespoke controller+route.
  - File ↔ dashboard link → the Files-Metadata / WebDAV API — not ad-hoc DB tables or filename parsing.
  - Console → `OCP\…\Command` registered in `info.xml` — not custom entrypoints.
- **Actively look for code that could be deleted in favour of core.** A helper that
  duplicates framework behaviour is a finding — say so and point at the native path.
- **When the native path isn't obvious, match a mature first-party app** (Deck,
  Files, integration_openai) rather than inventing a new pattern.

## Signal over volume — the most important rule for this reviewer

Fewer, higher-value comments beat exhaustive nitpicking. A review with 3 real
findings is better than one with 15 where 12 are cosmetic. Noise trains the team to
ignore you.

- **Worth a comment:** correctness, security, and Nextcloud-nativeness — always.
- **Usually skip:** a minor wording tweak, a slightly-stale docblock comment, or a
  non-user-facing string that isn't translated — unless the file is otherwise clean
  or it's egregious. Don't open a separate thread for each cosmetic nit.
- **Verify a bug is real before flagging it.** Before raising a possible
  crash/throw/edge case, trace the guards in the *same function* and confirm the
  failure path is actually reachable. Don't file speculative "this could break if X"
  without a concrete path to X.
- **Assume the framework is correct before calling something "unsafe".** If a
  Nextcloud/OCP helper plausibly already handles the concern (escaping, encryption,
  SSRF), assume it does unless the code shows otherwise — don't ask to "also" do what
  a primitive already does.

## Review priorities (highest first)

1. **Security** — hardcoded/committed secrets or tokens; a credential written to a
   log, response, or exception message; missing input validation; a `sensitive`
   config field that loses its encryption. Network egress: this app sets
   `allow_local_address` **on purpose** (single trusted Grafana target — see
   `SECURITY.md`); flag *new, undocumented* SSRF surface, not that documented use.
2. **Nextcloud-nativeness** — the section above.
3. **Correctness** — does the change do what the PR/spec says? Error paths, edge
   cases, and re-derive test expectations from the spec, not from current behaviour.
4. **Dead code & simplification** — unused code/imports, redundant abstractions,
   anything removable now that a native API covers it.
5. **Tests** — a `lib/` change should carry unit tests (`tests/unit/`), and if the
   behaviour is user-observable, a Behat step + scenario (`features/` +
   `tests/integration/`). Flag missing coverage.

## Project non-negotiables — do not approve changes that break these

- The file↔dashboard link is the **`grafana_uid`** stored in Files-Metadata, **not
  the filename**. Renames and moves must preserve it.
- Auth is a **single service-account token** sent as `Authorization: Bearer`. There
  is no second channel and no `X-N8N-*` header (that's the n8n sibling).
- The connection test hits an **authenticated** endpoint (`GET /api/folders`), never
  the unauthenticated `/api/health` — a green result must prove the token, not just
  reachability.
- The managed-file extension is a **single segment, `.grafana`**. Nextcloud reads only
  the last extension (`detectPath()` uses `strrchr`, and the collision counter goes
  immediately before it), so a compound `.grafana.json` costs a filecache correction on
  every write and makes a copy the app cannot recognise. Do not re-add a `.json` tail,
  and do not "simplify" to plain `.json` either.
- A mapping binds a **Grafana folder → a Nextcloud folder** (real folders, no tag
  scheme).
- **No `External Storage` / `OCP\Files\Storage` backend** — wrong tool, already rejected.

## Review style

- Be specific and actionable: cite the file/line and name the exact native API or fix.
- Explain the "why" in one line; acknowledge good native patterns when you see them.
- Stay within the diff and its blast radius.

## What not to flag (known-safe here — these are settled, and are the recurring false positives)

- **`OCP\Util::sanitizeHTML()` already escapes with `htmlspecialchars(…, ENT_QUOTES)`** —
  its output is safe in HTML-attribute context. Don't ask to "also escape" it.
- **The frontend targets Nextcloud's supported (evergreen) browsers.** ES2019+ syntax —
  optional `catch {}`, `?.`, `??` — is fully supported. Don't raise old-JS-engine
  compatibility.
- The single-segment `.grafana` extension, the deliberate `allow_local_address`
  egress, and testing against an authenticated endpoint are all intentional and
  documented — don't suggest "fixing" them.
- Don't ask for an `appinfo/info.xml` `<version>` bump — the release flow owns versions.
- The app-store publish step is intentionally deferred while the app is pre-1.0 —
  don't flag its absence.
